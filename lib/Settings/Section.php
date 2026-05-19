<?php

declare(strict_types=1);

namespace OCA\Transfer\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class Section implements IIconSection {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
	}

	public function getID(): string {
		return 'transfer';
	}

	public function getName(): string {
		return $this->l->t('Transfer');
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('transfer', 'app-dark.svg');
	}
}
