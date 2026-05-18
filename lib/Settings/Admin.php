<?php

declare(strict_types=1);

namespace OCA\Transfer\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse('transfer', 'admin', [
			'maxUrls'       => $this->appConfig->getAppValueInt('max_urls', 3),
			'retentionDays' => $this->appConfig->getAppValueInt('retention_days', 30),
		], TemplateResponse::RENDER_AS_BLANK);
	}

	/**
	 * The admin panel section this settings page belongs to.
	 * 'additional' is the generic bucket for app-specific settings.
	 */
	public function getSection(): string {
		return 'additional';
	}

	public function getPriority(): int {
		return 50;
	}
}
