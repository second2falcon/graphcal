<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Dav;

use OCA\DAV\CalDAV\Calendar;
use OCA\GraphCal\Db\Store;
use OCA\GraphCal\Service\Converter;
use OCA\GraphCal\Service\SyncService;
use OCA\GraphCal\Service\UnsupportedEvent;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * For calendars mirrored from Exchange:
 *  - Nextcloud must not schedule (no iMIP mails, no internal delivery): Exchange sends invitations.
 *  - Reject what Exchange can't store (unsupported repeat rules) instead of silently diverging.
 *  - Meetings can't move between calendars: Graph has no move, so it would cancel and re-invite.
 * Runs at priority 50, before Nextcloud's schedule plugin (100).
 */
class SchedulingGuard extends ServerPlugin {
	private Server $server;

	public function __construct(
		private Store $store,
	) {
	}

	public function getPluginName(): string {
		return 'graphcal-scheduling-guard';
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		$server->on('calendarObjectChange', [$this, 'onChange'], 50);
		$server->on('beforeUnbind', fn (string $path) => $this->suppressIfSynced(dirname($path)), 50);
		$server->on('beforeMove', [$this, 'onMove'], 50);
	}

	public function onChange($request, $response, VCalendar $vCal, string $calendarPath): void {
		if (!$this->suppressIfSynced($calendarPath)) {
			return;
		}
		$master = SyncService::master($vCal);
		if ($master && isset($master->RRULE)) {
			try {
				Converter::recurrenceToGraph($master);
			} catch (UnsupportedEvent $e) {
				throw new Forbidden($e->getMessage());
			}
		}
	}

	public function onMove(string $source, string $destination): void {
		$from = $this->synced(dirname($source));
		$to = $this->synced(dirname($destination));
		if (!$from && !$to) {
			return;
		}
		$this->suppress();
		$node = $this->server->tree->getNodeForPath($source);
		if (method_exists($node, 'get') && ($from || $to)) {
			$vcal = Reader::read($node->get());
			$master = $vcal instanceof VCalendar ? SyncService::master($vcal) : null;
			if ($master && isset($master->ATTENDEE)) {
				throw new Forbidden("Meetings can't be moved between Outlook calendars. Change the meeting in its own calendar instead.");
			}
		}
	}

	private function suppressIfSynced(string $calendarPath): bool {
		if (!$this->synced($calendarPath)) {
			return false;
		}
		$this->suppress();
		return true;
	}

	private function suppress(): void {
		// Both are honoured by OCA\DAV\CalDAV\Schedule\Plugin (calendarObjectChange and beforeUnbind).
		$this->server->httpRequest->setHeader('Schedule-Reply', 'F');
		$this->server->httpRequest->setHeader('x-nc-scheduling', 'false');
	}

	private function synced(string $calendarPath): bool {
		if (!str_starts_with($calendarPath, 'calendars/')) {
			return false;
		}
		try {
			$node = $this->server->tree->getNodeForPath($calendarPath);
		} catch (NotFound) {
			return false;
		}
		return $node instanceof Calendar && $this->store->calendarByNcId((int)$node->getResourceId()) !== null;
	}
}
