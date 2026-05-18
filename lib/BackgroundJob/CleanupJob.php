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
	private const WEEK_IN_SECONDS = 7 * 24 * 3600;

	public function __construct(
		ITimeFactory $time,
		private TransferJobMapper $mapper,
		private IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval(self::WEEK_IN_SECONDS);
	}

	protected function run($argument): void {
		// Clamp to ≥1 day so a zero/negative config value cannot produce a
		// future cutoff and accidentally delete all rows.
		$retentionDays = max(1, $this->appConfig->getAppValueInt('retention_days', 30));
		$cutoff = time() - ($retentionDays * 24 * 3600);
		$this->mapper->deleteOlderThan($cutoff);
	}
}
