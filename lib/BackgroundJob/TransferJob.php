<?php

declare(strict_types=1);

namespace OCA\Transfer\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

use OCA\Transfer\Db\TransferJobEntity;
use OCA\Transfer\Db\TransferJobMapper;
use OCA\Transfer\Service\TransferService;

class TransferJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private TransferService $service,
		private TransferJobMapper $mapper,
	) {
		parent::__construct($time);
	}

	protected function run($arguments): void {
		try {
			$this->service->transfer(
				$arguments['userId'],
				$arguments['path'],
				$arguments['url'],
				$arguments['hashAlgo'],
				$arguments['hash'],
				$arguments['token'],
			);
		} catch (\Throwable $e) {
			// Catch any uncaught exception so the job does not stay in 'running'
			// state permanently. TransferService handles expected failures itself;
			// this is a last-resort guard for unexpected errors (e.g. DI failure).
			$this->mapper->updateStatus($arguments['token'], TransferJobEntity::STATUS_FAILED, 'Unexpected error: ' . $e->getMessage());
		}
	}
}
