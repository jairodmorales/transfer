<?php

declare(strict_types=1);

namespace OCA\Transfer\Service;

use GuzzleHttp\Exception\BadResponseException;
use OCA\Transfer\Activity\Providers\TransferFailedProvider;
use OCA\Transfer\Activity\Providers\TransferStartedProvider;
use OCA\Transfer\Activity\Providers\TransferSucceededProvider;
use OCP\Activity\IManager;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

class TransferService {
	public function __construct(
		private IManager $activityManager,
		private IClientService $clientService,
		private IRootFolder $rootFolder,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Download a remote URL and save the result to the user's file storage.
	 *
	 * Runs inside a background job. Publishes an Activity event for each
	 * outcome (started, succeeded, failed, hash mismatch, blocked).
	 *
	 * @param string $userId   Nextcloud user ID that owns the destination folder
	 * @param string $path     Absolute path within the user's file space
	 * @param string $url      Remote URL — must be http/https (validated by controller)
	 * @param string $hashAlgo Hash algorithm to verify the file, or empty string to skip
	 * @param string $hash     Expected hex hash value, or empty string to skip
	 *
	 * @return bool Whether the download and save succeeded
	 */
	public function transfer(string $userId, string $path, string $url, string $hashAlgo, string $hash): bool {
		$userFolder = $this->rootFolder->getUserFolder($userId);

		$this->publishStartedEvent($userId, $url);

		$tmpPath = $this->tempManager->getTemporaryFile();

		$client = $this->clientService->newClient();

		try {
			$client->get($url, [
				// No overall transfer timeout so large files can complete,
				// but abort the TCP handshake after 30 s if the host is unreachable.
				'timeout' => 0,
				'connect_timeout' => 30,
				'allow_redirects' => [
					'max' => 10,
					// RFC-compliant: preserve the request method on 307/308
					'strict' => true,
					// Do not forward Referer headers to redirect targets
					'referer' => false,
					// Guzzle-level safeguard: only follow redirects to http/https.
					// Nextcloud's HTTP client middleware also re-validates each
					// redirect target against the local-address blocklist.
					'protocols' => ['http', 'https'],
					'track_redirects' => false,
				],
				'headers' => [
					// Some institutional servers reject requests without a browser-like
					// User-Agent string (e.g. government document portals).
					'User-Agent' => 'Mozilla/5.0 (compatible; Nextcloud)',
					'Accept' => '*/*',
					'Accept-Language' => 'en-US,en;q=0.9',
				],
			]);
		} catch (BadResponseException $e) {
			$this->logger->warning('Transfer failed: server returned an error for {url}: {message}', [
				'url' => $this->sanitizeUrlForLog($url),
				'message' => $e->getMessage(),
				'app' => 'transfer',
			]);
			$this->cleanupTempFile($tmpPath);
			$this->publishFailedEvent($userId, $url);
			return false;
		} catch (LocalServerException $e) {
			$this->cleanupTempFile($tmpPath);
			$this->publishBlockedEvent($userId, $url);
			return false;
		} catch (\Exception $e) {
			// Catches ConnectException (unreachable host), SSL errors,
			// TooManyRedirectsException, and other network-level failures
			// that are not covered by BadResponseException.
			$this->logger->warning('Transfer failed: network error for {url}: {message}', [
				'url' => $this->sanitizeUrlForLog($url),
				'message' => $e->getMessage(),
				'app' => 'transfer',
			]);
			$this->cleanupTempFile($tmpPath);
			$this->publishFailedEvent($userId, $url);
			return false;
		}

		if (!$this->integrityCheckPasses($hashAlgo, $hash, $tmpPath)) {
			$this->cleanupTempFile($tmpPath);
			$this->publishHashFailedEvent($userId, $url);
			return false;
		}

		return $this->saveToUserFolder($userId, $path, $url, $tmpPath, $userFolder);
	}

	/**
	 * Returns true if integrity verification is disabled (no hash provided) or
	 * if the file's computed hash matches the expected value.
	 *
	 * Uses hash_equals() for constant-time comparison to avoid timing leaks,
	 * and normalises the expected hash to lowercase so the comparison is
	 * case-insensitive (callers may paste either upper- or lowercase hashes).
	 */
	private function integrityCheckPasses(string $hashAlgo, string $hash, string $tmpPath): bool {
		if ($hash === '') {
			return true;
		}
		$computed = hash_file($hashAlgo, $tmpPath);
		if ($computed === false) {
			return false;
		}
		return hash_equals($computed, strtolower(trim($hash)));
	}

	/**
	 * Write the downloaded temp file into the user's Nextcloud folder.
	 *
	 * Uses getNonExistingName() to avoid overwriting files that already exist
	 * (the actual filename may differ from $path if a collision is detected).
	 *
	 * @param \OCP\Files\Folder $userFolder
	 */
	private function saveToUserFolder(
		string $userId,
		string $path,
		string $url,
		string $tmpPath,
		$userFolder,
	): bool {
		$dirPath = dirname($path);
		$filename = basename($path);

		try {
			$dir = $userFolder->get($dirPath);
		} catch (NotFoundException $e) {
			$this->cleanupTempFile($tmpPath);
			$this->publishFailedEvent($userId, $url);
			return false;
		}

		$filename = $dir->getNonExistingName($filename);
		$file = $dir->newFile($filename);

		$stream = fopen($tmpPath, 'r');
		if ($stream === false) {
			$this->logger->warning('Transfer failed: could not open temp file for {url}', [
				'url' => $this->sanitizeUrlForLog($url),
				'app' => 'transfer',
			]);
			$this->cleanupTempFile($tmpPath);
			$this->publishFailedEvent($userId, $url);
			return false;
		}

		$file->putContent($stream);
		fclose($stream);
		$this->cleanupTempFile($tmpPath);

		$actualPath = $dirPath . '/' . $filename;
		$this->publishSucceededEvent($userId, $actualPath, $url, $file->getId());
		return true;
	}

	/**
	 * Remove the temp file if it still exists.
	 * Called on every error path to avoid orphaned files in the system temp dir.
	 */
	private function cleanupTempFile(string $tmpPath): void {
		if (file_exists($tmpPath)) {
			unlink($tmpPath);
		}
	}

	/**
	 * Strip the userinfo component (user:password@) from a URL before writing
	 * it to logs. Credentials embedded in URLs must not appear in log files
	 * where other administrators or monitoring systems could read them.
	 */
	private function sanitizeUrlForLog(string $url): string {
		$parsed = parse_url($url);
		if ($parsed === false || !isset($parsed['host'])) {
			return '[invalid URL]';
		}
		return ($parsed['scheme'] ?? 'https') . '://'
			. $parsed['host']
			. (isset($parsed['port']) ? ':' . $parsed['port'] : '')
			. ($parsed['path'] ?? '');
	}

	// -------------------------------------------------------------------------
	// Activity event helpers
	// -------------------------------------------------------------------------

	private function publishStartedEvent(string $userId, string $url): void {
		$event = $this->activityManager->generateEvent();
		$event->setApp('transfer');
		$event->setType(TransferStartedProvider::TYPE_TRANSFER_STARTED);
		$event->setAffectedUser($userId);
		$event->setSubject(TransferStartedProvider::SUBJECT_TRANSFER_STARTED, ['url' => $url]);
		$this->activityManager->publish($event);
	}

	private function publishFailedEvent(string $userId, string $url): void {
		$event = $this->activityManager->generateEvent();
		$event->setApp('transfer');
		$event->setType(TransferFailedProvider::TYPE_TRANSFER_FAILED);
		$event->setAffectedUser($userId);
		$event->setSubject(TransferFailedProvider::SUBJECT_TRANSFER_FAILED, ['url' => $url]);
		$this->activityManager->publish($event);
	}

	private function publishHashFailedEvent(string $userId, string $url): void {
		$event = $this->activityManager->generateEvent();
		$event->setApp('transfer');
		$event->setType(TransferFailedProvider::TYPE_TRANSFER_FAILED);
		$event->setAffectedUser($userId);
		$event->setSubject(TransferFailedProvider::SUBJECT_TRANSFER_HASH_FAILED, ['url' => $url]);
		$this->activityManager->publish($event);
	}

	private function publishBlockedEvent(string $userId, string $url): void {
		$event = $this->activityManager->generateEvent();
		$event->setApp('transfer');
		$event->setType(TransferFailedProvider::TYPE_TRANSFER_FAILED);
		$event->setAffectedUser($userId);
		$event->setSubject(TransferFailedProvider::SUBJECT_TRANSFER_BLOCKED, ['url' => $url]);
		$this->activityManager->publish($event);
	}

	private function publishSucceededEvent(string $userId, string $path, string $url, int $fileId): void {
		$event = $this->activityManager->generateEvent();
		$event->setApp('transfer');
		$event->setType(TransferSucceededProvider::TYPE_TRANSFER_SUCCEEDED);
		$event->setAffectedUser($userId);
		$event->setSubject(TransferSucceededProvider::SUBJECT_TRANSFER_SUCCEEDED, ['url' => $url]);
		$event->setObject('files', $fileId, $path);
		$this->activityManager->publish($event);
	}
}
