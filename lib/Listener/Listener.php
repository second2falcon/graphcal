<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Listener;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\GraphCal\Dav\SchedulingGuard;
use OCA\GraphCal\Db\Store;
use OCA\GraphCal\Service\SyncService;
use OCP\Calendar\Events\AbstractCalendarObjectEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * - Sabre server init: add the scheduling guard (all three DAV servers dispatch this event).
 * - Calendar object changes in synced calendars: queue a push (atomic with the write, we're inside
 *   the DB transaction) and run it right after the response; never call Graph here.
 * - User deleted: drop sync state.
 * @template-implements IEventListener<Event>
 */
class Listener implements IEventListener {
	public function __construct(
		private Store $store,
		private SyncService $sync,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof SabrePluginAuthInitEvent) {
			$event->getServer()->addPlugin(new SchedulingGuard($this->store));
		} elseif ($event instanceof CalendarObjectMovedEvent) {
			$uri = (string)$event->getObjectData()['uri'];
			$this->queue($event->getSourceCalendarId(), $uri);
			$this->queue($event->getTargetCalendarId(), $uri);
		} elseif ($event instanceof AbstractCalendarObjectEvent) {
			$this->queue($event->getCalendarId(), (string)$event->getObjectData()['uri']);
		} elseif ($event instanceof UserDeletedEvent) {
			$this->sync->forget($event->getUser()->getUID(), false);
		}
	}

	private function queue(int $calendarId, string $uri): void {
		if (SyncService::$writing || $this->store->calendarByNcId($calendarId) === null) {
			return;
		}
		$this->sync->pushSoon($calendarId, $uri);
	}
}
