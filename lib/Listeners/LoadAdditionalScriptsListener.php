<?php

declare(strict_types=1);

namespace OCA\Transfer\Listeners;

use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

class LoadAdditionalScriptsListener implements IEventListener {
	public function __construct(
		private IAppConfig $appConfig,
		private IInitialState $initialState,
	) {
	}

	public function handle(Event $event): void {
		$this->initialState->provideInitialState(
			'maxUrls',
			$this->appConfig->getAppValueInt('max_urls', 3),
		);
		Util::addInitScript('transfer', 'transfer-main');
	}
}
