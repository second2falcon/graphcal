<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\AppInfo;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\GraphCal\Listener\CalendarPageListener;
use OCA\GraphCal\Listener\Listener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectRestoredEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'graphcal';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		foreach ([
			SabrePluginAuthInitEvent::class,
			CalendarObjectCreatedEvent::class,
			CalendarObjectUpdatedEvent::class,
			CalendarObjectMovedToTrashEvent::class,
			CalendarObjectDeletedEvent::class,
			CalendarObjectRestoredEvent::class,
			CalendarObjectMovedEvent::class,
			UserDeletedEvent::class,
		] as $event) {
			/** @psalm-suppress InvalidArgument one listener class handles all of them */
			$context->registerEventListener($event, Listener::class);
		}
		// Separate, dependency-free listener: it runs on every page render.
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, CalendarPageListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
