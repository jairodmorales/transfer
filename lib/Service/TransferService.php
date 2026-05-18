<?php

declare(strict_types=1);

namespace OCA\Transfer\Service;

use GuzzleHttp\Exception\BadResponseException;
use OCA\Transfer\Activity\Providers\TransferFailedProvider;
use OCA\Transfer\Activity\Providers\TransferStartedProvider;
use OCA\Transfer\Activity\Providers\TransferSucceededProvider;
use OCA\Transfer\Db\TransferJobEntity;
use OCA\Transfer\Db\TransferJobMapper;
use OCP\Activity\IManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

class TransferService {
	private const APP_NAME = 'transfer';

	public function __construct(
		private IManager $activityManager,
		private IClientService $clientService,
		private IRootFolder $rootFolder,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
		private TransferJobMapper $mapper,
	) {
	}

	/**
	 * Download a remote URL and save the result to the user's file storage.
	 *
	 * Runs inside a background job. Publishes an Activity event and updates the
	 * transfer_jobs table for each outcome so the frontend panel can poll status.
	 *
	 * @param string $userId   Nextcloud user ID that owns the destination folder
	 * @param string $path     Absolute path within the user's file space
	 * @param string $url      Remote URL — must be http/https (validated by controller)
	 * @param string $hashAlgo Hash algorithm to verify the file, or empty string to skip
	 * @param string $hash     Expected hex hash value, or empty string to skip
	 * @param string $token    Tracking token created by the controller; used to update job status
	 *
	 * @return bool Whether the download and save succeeded
	 */
	public function transfer(
		string $userId,
		string $path,
		string $url,
		string $hashAlgo,
		string $hash,
		string $token,
	): bool {
		$userFolder = $this->rootFolder->getUserFolder($userId);

		$this->mapper->updateStatus($token, TransferJobEntity::STATUS_RUNNING);
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
				'sink' => $tmpPath,
			]);
		} catch (BadResponseException $e) {
			$msg = 'Server returned HTTP ' . $e->getResponse()->getStatusCode();
			$this->logger->warning('Transfer failed: {msg} for {url}', [
				'msg' => $msg,
				'url' => $this->sanitizeUrlForLog($url),
				'app' => self::APP_NAME,
			]);
			$this->cleanupTempFile($tmpPath);
			$this->mapper->updateStatus($token, TransferJobEntity::STATUS_FAILED, $msg);
			$this->publishFailedEvent($userId, $url);
			return false;
		} catch (LocalServerException $e) {
			$this->cleanupTempFile($tmpPath);
			$this->mapper->updateStatus($token, TransferJobEntity::STATUS_FAILED, 'Blocked: local address');
			$this->publishBlockedEvent($userId, $url);
			return false;
		} catch (\Exception $e) {
			// Catches ConnectException (unreachable host), SSL errors,
			// TooManyRedirectsException, and other network-level failures
			// not covered by BadResponseException.
			$msg = $this->sanitizeErrorMessage($e->getMessage());
			$this->logger->warning('Transfer failed: network error for {url}: {message}', [
				'url' => $this->sanitizeUrlForLog($url),
				'message' => $msg,
				'app' => self::APP_NAME,
			]);
			$this->cleanupTempFile($tmpPath);
			$this->mapper->updateStatus($token, TransferJobEntity::STATUS_FAILED, $msg);
			$this->publishFailedEvent($userId, $url);
			return false;
		}

		if (!$this->integrityCheckPasses($hashAlgo, $hash, $tmpPath)) {
			$this->cleanupTempFile($tmpPath);
			$this->mapper->updateStatus($token, TransferJobEntity::STATUS_FAILED, 'Checksum mismatch');
			$this->publishHashFailedEvent($userId, $url);
			return false;
		}

		return $this->saveToUserFolder($userId, $path, $url, $tmpPath, $token, $userFolder);
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
	 * Write the downloaded temp file into the user's Nextcloud folder and
	 * mark the job as done.
	 *
	 * Uses getNonExistingName() to avoid overwriting files that already exist
	 * (the actual filename may differ from $path if a collision is detected).
	 */
	private function saveToUserFolder(
		string $userId,
		string $path,
		string $url,
		string $tmpPath,
		string $token,
		Folder $userFolder,
	): bool {
		$dirPath = dirname($path);
		$filename = basename($path);

		try {
			$dir = $userFolder->get($dirPath);
		} catch (NotFoundException $e) {
			$this->cleanupTempFile($tmpPath);
			$this->mapper->updateStatus($token, TransferJobEntity::STATUS_FAILED, 'Destination folder not found');
			$this->publishFailedEvent($userId, $url);
			return false;
		}

		if (!$dir instanceof Folder) {
			$this->cleanupTempFile($tmpPath);
			$this->mapper->updateStatus($token, TransferJobEntity::STATUS_FAILED, 'Destination path is not a folder');
			$this->publishFailedEvent($userId, $url);
			return false;
		}

		$filename = $dir->getNonExistingName($filename);
		$file = $dir->newFile($filename);

		$stream = fopen($tmpPath, 'r');
		if ($stream === false) {
			$this->logger->warning('Transfer failed: could not open temp file for {url}', [
				'url' => $this->sanitizeUrlForLog($url),
				'app' => self::APP_NAME,
			]);
			$this->cleanupTempFile($tmpPath);
			$this->mapper->updateStatus($token, TransferJobEntity::STATUS_FAILED, 'Could not read downloaded file');
			$this->publishFailedEvent($userId, $url);
			return false;
		}

		try {
			$file->putContent($stream);
		} catch (\Exception $e) {
			$this->logger->warning('Transfer failed: could not write file for {url}: {message}', [
				'url'     => $this->sanitizeUrlForLog($url),
				'message' => $e->getMessage(),
				'app'     => self::APP_NAME,
			]);
			$this->cleanupTempFile($tmpPath);
			$this->mapper->updateStatus($token, TransferJobEntity::STATUS_FAILED, 'Could not write file');
			$this->publishFailedEvent($userId, $url);
			return false;
		} finally {
			fclose($stream);
		}
		$this->cleanupTempFile($tmpPath);

		$actualPath = $dirPath . '/' . $filename;
		$this->mapper->updateStatus($token, TransferJobEntity::STATUS_DONE);
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

	/**
	 * Strip embedded credentials from any URL-like strings in an exception
	 * message before storing it in the DB. Guzzle's ConnectException and SSL
	 * error messages may include the full request URL including `user:pass@`.
	 */
	private function sanitizeErrorMessage(string $msg): string {
		return (string) preg_replace('#([a-z][a-z0-9+\-.]*://)([^@/\s]+@)#i', '$1', $msg);
	}

	// -------------------------------------------------------------------------
	// Activity event helpers
	// -------------------------------------------------------------------------

	private function publishStartedEvent(string $userId, string $url): void {
		$this->publishEvent(
			$userId,
			TransferStartedProvider::TYPE_TRANSFER_STARTED,
			TransferStartedProvider::SUBJECT_TRANSFER_STARTED,
			['url' => $url],
		);
	}

	private function publishFailedEvent(string $userId, string $url): void {
		$this->publishEvent(
			$userId,
			TransferFailedProvider::TYPE_TRANSFER_FAILED,
			TransferFailedProvider::SUBJECT_TRANSFER_FAILED,
			['url' => $url],
		);
	}

	private function publishHashFailedEvent(string $userId, string $url): void {
		$this->publishEvent(
			$userId,
			TransferFailedProvider::TYPE_TRANSFER_FAILED,
			TransferFailedProvider::SUBJECT_TRANSFER_HASH_FAILED,
			['url' => $url],
		);
	}

	private function publishBlockedEvent(string $userId, string $url): void {
		$this->publishEvent(
			$userId,
			TransferFailedProvider::TYPE_TRANSFER_FAILED,
			TransferFailedProvider::SUBJECT_TRANSFER_BLOCKED,
			['url' => $url],
		);
	}

	private function publishSucceededEvent(string $userId, string $path, string $url, int $fileId): void {
		$this->publishEvent(
			$userId,
			TransferSucceededProvider::TYPE_TRANSFER_SUCCEEDED,
			TransferSucceededProvider::SUBJECT_TRANSFER_SUCCEEDED,
			['url' => $url],
			'files',
			$fileId,
			$path,
		);
	}

	/**
	 * Build and publish an Activity event. The five typed helpers above use
	 * this to avoid repeating the generateEvent / setApp / setAffectedUser /
	 * publish boilerplate for every outcome.
	 *
	 * @param array<string, mixed> $subjectParams
	 */
	private function publishEvent(
		string $userId,
		string $type,
		string $subject,
		array $subjectParams,
		?string $objectType = null,
		?int $objectId = null,
		?string $objectName = null,
	): void {
		$event = $this->activityManager->generateEvent();
		$event->setApp(self::APP_NAME);
		$event->setType($type);
		$event->setAffectedUser($userId);
		$event->setSubject($subject, $subjectParams);
		if ($objectType !== null && $objectId !== null && $objectName !== null) {
			$event->setObject($objectType, $objectId, $objectName);
		}
		$this->activityManager->publish($event);
	}
}
