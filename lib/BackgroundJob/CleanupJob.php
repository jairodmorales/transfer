<?php

declare(strict_types=1);

namespace OCA\Transfer\BackgroundJob;

use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

use OCA\Transfer\Db\TransferJobMapper;

/**
 * Weekly job that deletes old transfer_jobs rows.
 *
 * Retention window is controlled by the admin setting `retention_days`
 * (default 30). This keeps the table small without losing recent history.
 */
class CleanupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private TransferJobMapper $mapper,
		private IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval(7 * 24 * 3600); // once a week
	}

	protected function run($argument): void {
		$retentionDays = $this->appConfig->getAppValueInt('retention_days', 30);
		$cutoff = time() - ($retentionDays * 86400);
		$this->mapper->deleteOlderThan($cutoff);
	}
}
