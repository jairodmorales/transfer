<?php
namespace OCA\Transfer\Service;

use OCA\Transfer\Activity\Providers\TransferFailedProvider;
use OCA\Transfer\Activity\Providers\TransferStartedProvider;
use OCA\Transfer\Activity\Providers\TransferSucceededProvider;

use GuzzleHttp\Exception\BadResponseException;
use OCP\Activity\IManager;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

class TransferService {
	protected $activityManager;
	protected $clientService;
	protected $rootFolder;
	protected $tempManager;
	protected $logger;

	public function __construct(
		IManager $activityManager,
		IClientService $clientService,
		IRootFolder $rootFolder,
		ITempManager $tempManager,
		LoggerInterface $logger
	) {
		$this->activityManager = $activityManager;
		$this->clientService = $clientService;
		$this->rootFolder = $rootFolder;
		$this->tempManager = $tempManager;
		$this->logger = $logger;
	}

	/**
	 * @return Whether the transfer succeeded.
	 */
	public function transfer(string $userId, string $path, string $url, string $hashAlgo, string $hash) {
		$userFolder = $this->rootFolder->getUserFolder($userId);

		$this->generateStartedEvent($userId, $path, $url);

		$tmpPath = $this->tempManager->getTemporaryFile();

		$client = $this->clientService->newClient();

		try {
			$client->get($url, [
				"sink" => $tmpPath,
				"timeout" => 0,
				"connect_timeout" => 30,
				"allow_redirects" => ["max" => 10, "strict" => false, "track_redirects" => false],
				"headers" => [
					"User-Agent" => "Mozilla/5.0 (compatible; Nextcloud)",
					"Accept" => "*/*",
					"Accept-Language" => "en-US,en;q=0.9",
				],
			]);
		} catch (BadResponseException $exception) {
			$this->logger->warning('Transfer failed with HTTP error for URL {url}: {message}', [
				'url' => $url,
				'message' => $exception->getMessage(),
				'app' => 'transfer',
			]);
			if (file_exists($tmpPath)) {
				unlink($tmpPath);
			}
			$this->generateFailedEvent($userId, $path, $url);
			return false;
		} catch (LocalServerException $exception) {
			if (file_exists($tmpPath)) {
				unlink($tmpPath);
			}
			$this->generateBlockedEvent($userId, $path, $url);
			return false;
		} catch (\Exception $exception) {
			$this->logger->warning('Transfer failed with network error for URL {url}: {message}', [
				'url' => $url,
				'message' => $exception->getMessage(),
				'app' => 'transfer',
			]);
			if (file_exists($tmpPath)) {
				unlink($tmpPath);
			}
			$this->generateFailedEvent($userId, $path, $url);
			return false;
		}

		if ($hash == "" || hash_file($hashAlgo, $tmpPath) == $hash) {
			$dirPath = dirname($path);
			$filename = basename($path);

			try {
				$dir = $userFolder->get($dirPath);
			} catch (NotFoundException $e) {
				unlink($tmpPath);
				$this->generateFailedEvent($userId, $path, $url);
				return false;
			}

			$filename = $dir->getNonExistingName($filename);
			$file = $dir->newFile($filename);
			$file->putContent(fopen($tmpPath, 'r'));
			unlink($tmpPath);

			$actualPath = $dirPath . '/' . $filename;
			$this->generateSucceededEvent($userId, $actualPath, $url, $file->getId());
			return true;
		} else {
			unlink($tmpPath);

			$this->generateHashFailedEvent($userId, $path, $url);
			return false;
		}
	}

	protected function generateStartedEvent(string $userId, string $path, string $url) {
		$event = $this->activityManager->generateEvent();
		$event->setApp("transfer");
		$event->setType(TransferStartedProvider::TYPE_TRANSFER_STARTED);
		$event->setAffectedUser($userId);
		$event->setSubject(TransferStartedProvider::SUBJECT_TRANSFER_STARTED, ["url" => $url]);
		$this->activityManager->publish($event);
	}

	protected function generateFailedEvent(string $userId, string $path, string $url) {
		$event = $this->activityManager->generateEvent();
		$event->setApp("transfer");
		$event->setType(TransferFailedProvider::TYPE_TRANSFER_FAILED);
		$event->setAffectedUser($userId);
		$event->setSubject(TransferFailedProvider::SUBJECT_TRANSFER_FAILED, ["url" => $url]);
		$this->activityManager->publish($event);
	}

	protected function generateHashFailedEvent(string $userId, string $path, string $url) {
		$event = $this->activityManager->generateEvent();
		$event->setApp("transfer");
		$event->setType(TransferFailedProvider::TYPE_TRANSFER_FAILED);
		$event->setAffectedUser($userId);
		$event->setSubject(TransferFailedProvider::SUBJECT_TRANSFER_HASH_FAILED, ["url" => $url]);
		$this->activityManager->publish($event);
	}

	protected function generateBlockedEvent(string $userId, string $path, string $url) {
		$event = $this->activityManager->generateEvent();
		$event->setApp("transfer");
		$event->setType(TransferFailedProvider::TYPE_TRANSFER_FAILED);
		$event->setAffectedUser($userId);
		$event->setSubject(TransferFailedProvider::SUBJECT_TRANSFER_BLOCKED, ["url" => $url]);
		$this->activityManager->publish($event);
	}

	protected function generateSucceededEvent(string $userId, string $path, string $url, int $fileId) {
		$event = $this->activityManager->generateEvent();
		$event->setApp("transfer");
		$event->setType(TransferSucceededProvider::TYPE_TRANSFER_SUCCEEDED);
		$event->setAffectedUser($userId);
		$event->setSubject(TransferSucceededProvider::SUBJECT_TRANSFER_SUCCEEDED, ["url" => $url]);
		$event->setObject("files", $fileId, $path);
		$this->activityManager->publish($event);
	}
}
