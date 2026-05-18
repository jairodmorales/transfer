<?php

declare(strict_types=1);

namespace OCA\Transfer\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public const SUBJECT_DONE   = 'transfer_done';
	public const SUBJECT_FAILED = 'transfer_failed';

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return 'transfer';
	}

	public function getName(): string {
		return $this->l10nFactory->get('transfer')->t('Transfer');
	}

	/**
	 * @throws UnknownNotificationException When the subject is not handled by this app
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'transfer') {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get('transfer', $languageCode);
		$params = $notification->getSubjectParameters();

		switch ($notification->getSubject()) {
			case self::SUBJECT_DONE:
				$notification->setParsedSubject(
					$l->t('"%1$s" downloaded successfully', [$params['filename'] ?? ''])
				);
				$notification->setLink(
					$this->urlGenerator->linkToRouteAbsolute('files.view.index')
				);
				break;

			case self::SUBJECT_FAILED:
				$notification->setParsedSubject(
					$l->t('Download of "%1$s" failed: %2$s', [
						$params['filename'] ?? '',
						$params['error']    ?? '',
					])
				);
				break;

			default:
				throw new UnknownNotificationException();
		}

		return $notification;
	}
}
