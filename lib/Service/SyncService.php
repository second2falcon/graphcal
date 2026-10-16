<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\GraphCal\BackgroundJob\DeltaJob;
use OCA\GraphCal\Db\Store;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * Exchange is the source of truth. pull() mirrors Graph into Nextcloud (CalDavBackend, no scheduling);
 * push() sends one changed Nextcloud object to Graph, so Exchange sends invitations, updates and replies.
 */
class SyncService {
	/** True while we write Graph data into Nextcloud, so the change listener doesn't echo it back. */
	public static bool $writing = false;

	/** calendarView/delta needs a window; it is re-based every 30 days. */
	private const WINDOW_PAST = 'P1Y';
	private const WINDOW_FUTURE = 'P2Y';
	private const REBASE_AFTER = 30 * 86400;

	private const EVENT_FIELDS = 'id,iCalUId,changeKey,type,seriesMasterId,subject,body,start,end,isAllDay,location,attendees,organizer,isOrganizer,responseStatus,recurrence,originalStartTimeZone,sensitivity,showAs,categories,isReminderOn,reminderMinutesBeforeStart,isCancelled,lastModifiedDateTime';

	public function __construct(
		private Microsoft $ms,
		private Store $store,
		private CalDavBackend $calDav,
		private IJobList $jobList,
		private IUserConfig $userConfig,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/* ================================================================ calendars */

	/** Read the user's Outlook calendars; own calendars are enabled on first sight, shared ones are opt-in. */
	public function refreshCalendars(string $userId): void {
		foreach ($this->ms->graphAll($userId, '/me/calendars?$select=id,name,hexColor,canEdit,isSharedWithMe,owner&$top=100') as $c) {
			$row = $this->store->upsertCalendar($userId, (string)$c['id'], '', (string)$c['name'], (bool)($c['isSharedWithMe'] ?? false), (bool)($c['canEdit'] ?? true), !($c['isSharedWithMe'] ?? false));
			if ($row['enabled'] && !$row['nc_calendar_id']) {
				$this->enable($userId, (int)$row['id'], (string)($c['hexColor'] ?? ''));
			}
		}
	}

	/** Add another person's calendar by address (they must have shared or delegated it). */
	public function addSharedCalendar(string $userId, string $email): array {
		$owner = strtolower(trim($email));
		$c = $this->ms->graph($userId, 'GET', '/users/' . rawurlencode($owner) . '/calendar?$select=id,name,hexColor,canEdit');
		$row = $this->store->upsertCalendar($userId, (string)$c['id'], $owner, $owner, true, (bool)($c['canEdit'] ?? false), true);
		$this->enable($userId, (int)$row['id'], (string)($c['hexColor'] ?? ''));
		return $row;
	}

	public function setEnabled(string $userId, int $id, bool $enabled): void {
		$row = $this->store->calendar($id);
		if (!$row || $row['user_id'] !== $userId) {
			throw new \InvalidArgumentException('Unknown calendar');
		}
		$enabled ? $this->enable($userId, $id, '') : $this->disable($row);
	}

	private function enable(string $userId, int $id, string $color): void {
		$row = $this->store->calendar($id);
		$ncId = $row['nc_calendar_id'];
		if (!$ncId) {
			$props = [
				'{DAV:}displayname' => $row['name'],
				'{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
			];
			if (preg_match('/^#[0-9a-f]{6}$/i', $color)) {
				$props['{http://apple.com/ns/ical/}calendar-color'] = $color;
			}
			$ncId = $this->calDav->createCalendar('principals/users/' . $userId, 'graphcal-' . substr(md5($row['owner'] . $row['graph_id']), 0, 12), $props);
		}
		$this->store->updateCalendar($id, ['enabled' => true, 'nc_calendar_id' => (int)$ncId, 'delta_link' => null]);
		$this->queuePull($userId);
	}

	private function disable(array $row): void {
		if ($row['nc_calendar_id']) {
			$this->calDav->deleteCalendar($row['nc_calendar_id'], true);
			$this->store->deleteEventsOfCalendar($row['nc_calendar_id']);
		}
		$this->store->updateCalendar($row['id'], ['enabled' => false, 'nc_calendar_id' => 0, 'delta_link' => null]);
	}

	/** Remove everything this app created for a user (disconnect, user deletion). */
	public function forget(string $userId, bool $deleteCalendars = true): void {
		try {
			$this->deleteSubscription($userId);
		} catch (\Throwable $e) {
			$this->logger->info('Could not delete Graph subscription', ['app' => Microsoft::APP, 'exception' => $e]);
		}
		if ($deleteCalendars) {
			foreach ($this->store->calendars($userId) as $row) {
				if ($row['nc_calendar_id']) {
					$this->calDav->deleteCalendar($row['nc_calendar_id'], true);
				}
			}
		}
		$this->store->deleteUser($userId);
		$this->ms->disconnect($userId);
	}

	public function queuePull(string $userId): void {
		if (!$this->jobList->has(DeltaJob::class, $userId)) {
			$this->jobList->add(DeltaJob::class, $userId);
		}
	}

	/* ================================================================ Graph -> Nextcloud */

	public function pull(string $userId): void {
		$me = $this->ms->msEmail($userId);
		foreach ($this->store->syncedCalendars($userId) as $cal) {
			try {
				$this->pullCalendar($userId, $me, $cal);
			} catch (GraphError $e) {
				if ($e->status === 410 || in_array($e->graphCode, ['syncStateNotFound', 'resyncRequired', 'SyncStateInvalid'], true)) {
					$this->store->updateCalendar($cal['id'], ['delta_link' => null]);
					$this->pullCalendar($userId, $me, $this->store->calendar($cal['id']));
				} else {
					throw $e;
				}
			}
		}
	}

	private function pullCalendar(string $userId, string $me, array $cal): void {
		$ncId = $cal['nc_calendar_id'];
		$base = $this->base($cal);
		[$link, $since] = $this->deltaState($cal);
		if ($link === '' || $this->time->getTime() - $since > self::REBASE_AFTER) {
			$now = new DateTimeImmutable('@' . $this->time->getTime());
			$link = $base . '/calendars/' . rawurlencode($cal['graph_id']) . '/calendarView/delta?' . http_build_query([
				'startDateTime' => $now->sub(new \DateInterval(self::WINDOW_PAST))->format('Y-m-d\TH:i:s\Z'),
				'endDateTime' => $now->add(new \DateInterval(self::WINDOW_FUTURE))->format('Y-m-d\TH:i:s\Z'),
			]);
			$since = $this->time->getTime();
		}

		$masters = [];
		$unknownRemoval = false;
		$prefer = ['Prefer' => 'IdType="ImmutableId", outlook.body-content-type="text", odata.maxpagesize=100'];
		while (true) {
			$page = $this->ms->graph($userId, 'GET', $link, null, $prefer);
			foreach ($page['value'] ?? [] as $item) {
				if (isset($item['@removed'])) {
					$row = $this->store->eventByGraphId($ncId, (string)$item['id']);
					if ($row) {
						$this->deleteLocal($ncId, $row['nc_uri']);
					} else {
						$unknownRemoval = true; // an occurrence; its series master is refetched below
					}
				} elseif (($item['type'] ?? '') === 'singleInstance') {
					$this->writeLocal($userId, $me, $ncId, $item, false);
				} elseif (!empty($item['seriesMasterId'])) {
					$masters[$item['seriesMasterId']] = true;
				}
			}
			if (isset($page['@odata.nextLink'])) {
				$link = $page['@odata.nextLink'];
				continue;
			}
			$link = (string)($page['@odata.deltaLink'] ?? '');
			break;
		}
		if ($unknownRemoval) {
			// ponytail: refetch every known series of the calendar; fine for tens of series, track occurrence ids if it isn't.
			foreach ($this->store->seriesOfCalendar($ncId) as $row) {
				$masters[$row['graph_id']] = true;
			}
		}
		foreach (array_keys($masters) as $masterId) {
			try {
				$master = $this->ms->graph($userId, 'GET', $base . '/events/' . rawurlencode((string)$masterId)
					. '?$select=' . self::EVENT_FIELDS . ',cancelledOccurrences&$expand=exceptionOccurrences');
				$this->writeLocal($userId, $me, $ncId, $master, true);
			} catch (GraphError $e) {
				if ($e->status !== 404) {
					throw $e;
				}
				$row = $this->store->eventByGraphId($ncId, (string)$masterId);
				if ($row) {
					$this->deleteLocal($ncId, $row['nc_uri']);
				}
			}
		}
		$this->store->updateCalendar($cal['id'], ['delta_link' => json_encode(['link' => $link, 'since' => $since])]);
	}

	private function writeLocal(string $userId, string $me, int $ncId, array $event, bool $isSeries): void {
		$row = $this->store->eventByGraphId($ncId, (string)$event['id']);
		$inboundHash = Converter::hash($event + ['_series' => $isSeries]);
		$snapshot = $row ? (json_decode((string)$row['snapshot'], true) ?: []) : [];
		if ($row && ($snapshot['inbound'] ?? '') === $inboundHash) {
			return; // unchanged, or our own push echoing back
		}
		$existing = $row ? $this->calDav->getCalendarObject($ncId, $row['nc_uri']) : null;
		$old = $existing ? Reader::read($existing['calendardata']) : null;
		$uid = $old instanceof VCalendar ? (string)(self::master($old)?->UID ?? '') : '';
		$uid = $uid !== '' ? $uid : (string)($event['iCalUId'] ?? $event['id']);
		$uri = $row['nc_uri'] ?? (substr(preg_replace('/[^A-Za-z0-9_-]/', '', $uid), 0, 200) ?: md5((string)$event['id'])) . '.ics';

		$vcal = Converter::toVCalendar($event, $uid, $me);
		self::$writing = true;
		try {
			if ($existing) {
				$this->calDav->updateCalendarObject($ncId, $uri, $vcal->serialize());
			} else {
				$this->calDav->createCalendarObject($ncId, $uri, $vcal->serialize());
			}
		} finally {
			self::$writing = false;
		}
		$this->store->saveEvent($userId, $ncId, $uri, (string)$event['id'], $event['changeKey'] ?? null, $isSeries,
			$this->snapshot($vcal, $me) + ['inbound' => $inboundHash]);
	}

	private function deleteLocal(int $ncId, string $uri): void {
		self::$writing = true;
		try {
			if ($this->calDav->getCalendarObject($ncId, $uri)) {
				// Permanently: Exchange keeps its own "Deleted Items"; a Nextcloud trash restore would re-invite.
				$this->calDav->deleteCalendarObject($ncId, $uri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
			}
		} finally {
			self::$writing = false;
		}
		$this->store->deleteEvent($ncId, $uri);
	}

	/* ================================================================ Nextcloud -> Graph */

	/** Push the current state of one Nextcloud object (created, changed or gone) to Graph. */
	public function push(int $ncId, string $uri): void {
		$cal = $this->store->calendarByNcId($ncId);
		if (!$cal) {
			return;
		}
		$userId = $cal['user_id'];
		$me = $this->ms->msEmail($userId);
		$base = $this->base($cal);
		$row = $this->store->event($ncId, $uri);
		$object = $this->calDav->getCalendarObject($ncId, $uri);

		if (!$object || !empty($object['deleted_at'])) {
			if ($row) {
				$this->deleteRemote($userId, $base . '/events/' . rawurlencode($row['graph_id']));
				$this->store->deleteEvent($ncId, $uri);
			}
			return;
		}

		$vcal = Reader::read($object['calendardata']);
		$master = self::master($vcal);
		if (!$master) {
			return;
		}
		$snapshot = $row ? (json_decode((string)$row['snapshot'], true) ?: []) : [];
		$organizer = isset($master->ORGANIZER) ? Converter::address((string)$master->ORGANIZER) : '';
		$isMeeting = isset($master->ATTENDEE);
		$iOrganize = !$isMeeting || $organizer === '' || $organizer === $me || in_array($organizer, $this->ncAddresses($userId), true);

		if (!$iOrganize) {
			// Someone else's meeting: Exchange owns it. Only our own reply travels back.
			$partstat = self::myPartstat($master, $me);
			if ($row && $partstat !== ($snapshot['partstat'] ?? null)) {
				$action = match ($partstat) { 'ACCEPTED' => 'accept', 'DECLINED' => 'decline', 'TENTATIVE' => 'tentativelyAccept', default => null };
				if ($action) {
					$this->ms->graph($userId, 'POST', $base . '/events/' . rawurlencode($row['graph_id']) . '/' . $action, ['sendResponse' => true]);
				}
				$snapshot['partstat'] = $partstat;
				$this->store->saveEvent($userId, $ncId, $uri, $row['graph_id'], $row['change_key'], $row['is_series'], $snapshot);
			}
			return;
		}

		$new = $this->snapshot($vcal, $me);
		$payload = Converter::toGraph($master, $me, false, $isMeeting);
		$recurrence = isset($master->RRULE) ? Converter::recurrenceToGraph($master) : null;
		if (!$row) {
			$created = $this->ms->graph($userId, 'POST', $base . '/calendars/' . rawurlencode($cal['graph_id']) . '/events',
				$payload + ($recurrence ? ['recurrence' => $recurrence] : []) + ['transactionId' => md5($ncId . $uri . $object['etag'] . $object['lastmodified'])]);
			$graphId = (string)$created['id'];
			$snapshot = ['overrides' => [], 'exdates' => []];
		} else {
			$graphId = $row['graph_id'];
			$patch = [];
			if (($snapshot['master'] ?? '') !== $new['master']) {
				$patch = $payload;
			}
			if (($snapshot['rrule'] ?? '') !== $new['rrule']) {
				$patch['recurrence'] = $recurrence; // null turns a series back into a single event
			}
			if ($patch) {
				$this->ms->graph($userId, 'PATCH', $base . '/events/' . rawurlencode($graphId), $patch);
			}
		}

		if ($recurrence) {
			$this->pushOccurrences($userId, $base, $graphId, $vcal, $me, $snapshot, $new);
		}
		$latest = $this->ms->graph($userId, 'GET', $base . '/events/' . rawurlencode($graphId) . '?$select=id,changeKey');
		$this->store->saveEvent($userId, $ncId, $uri, $graphId, $latest['changeKey'] ?? null, $recurrence !== null, $new + ['inbound' => $snapshot['inbound'] ?? '']);
	}

	/** Changed single occurrences become PATCHes of that instance; new EXDATEs delete it. */
	private function pushOccurrences(string $userId, string $base, string $graphId, VCalendar $vcal, string $me, array $old, array $new): void {
		$changed = array_keys(array_diff_assoc($new['overrides'], $old['overrides'] ?? []));
		$removed = array_values(array_diff($new['exdates'], $old['exdates'] ?? []));
		if (!$changed && !$removed) {
			return;
		}
		$overrides = [];
		foreach ($vcal->select('VEVENT') as $ve) {
			if (isset($ve->{'RECURRENCE-ID'})) {
				$overrides[self::utc($ve->{'RECURRENCE-ID'}->getDateTime())] = $ve;
			}
		}
		foreach (array_merge($changed, $removed) as $when) {
			$instance = $this->findInstance($userId, $base, $graphId, $when);
			if (!$instance) {
				continue;
			}
			$path = $base . '/events/' . rawurlencode($instance);
			if (in_array($when, $removed, true)) {
				$this->deleteRemote($userId, $path);
			} elseif (isset($overrides[$when])) {
				$this->ms->graph($userId, 'PATCH', $path, Converter::toGraph($overrides[$when], $me, false, isset($overrides[$when]->ATTENDEE)));
			}
		}
	}

	private function findInstance(string $userId, string $base, string $graphId, string $whenUtc): ?string {
		$at = new DateTimeImmutable($whenUtc);
		$instances = $this->ms->graph($userId, 'GET', $base . '/events/' . rawurlencode($graphId) . '/instances?' . http_build_query([
			'startDateTime' => $at->modify('-1 day')->format('Y-m-d\TH:i:s\Z'),
			'endDateTime' => $at->modify('+2 days')->format('Y-m-d\TH:i:s\Z'),
			'$select' => 'id,originalStart',
		]));
		foreach ($instances['value'] ?? [] as $i) {
			if (isset($i['originalStart']) && self::utc(new DateTimeImmutable($i['originalStart'])) === $whenUtc) {
				return (string)$i['id'];
			}
		}
		return null;
	}

	private function deleteRemote(string $userId, string $path): void {
		try {
			$this->ms->graph($userId, 'DELETE', $path);
		} catch (GraphError $e) {
			if ($e->status !== 404) {
				throw $e;
			}
		}
	}

	/* ================================================================ change notifications */

	/** Create or renew the user's /me/events subscription. Failures (e.g. no public HTTPS) leave polling in charge. */
	public function ensureSubscription(string $userId): void {
		$id = $this->userConfig->getValueString($userId, Microsoft::APP, 'sub_id');
		$expires = $this->userConfig->getValueInt($userId, Microsoft::APP, 'sub_expires');
		$until = (new DateTimeImmutable('@' . ($this->time->getTime() + 6 * 86400)))->format('Y-m-d\TH:i:s\Z');
		if (($id !== '' && $expires > $this->time->getTime() + 2 * 86400)
			|| $this->userConfig->getValueInt($userId, Microsoft::APP, 'sub_retry_after') > $this->time->getTime()) {
			return;
		}
		if ($id !== '') {
			try {
				$this->ms->graph($userId, 'PATCH', '/subscriptions/' . rawurlencode($id), ['expirationDateTime' => $until]);
				$this->userConfig->setValueInt($userId, Microsoft::APP, 'sub_expires', strtotime($until));
				return;
			} catch (GraphError $e) {
				$this->logger->info('Renewing Graph subscription failed, creating a new one', ['app' => Microsoft::APP, 'exception' => $e]);
			}
		}
		$secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$url = $this->urlGenerator->linkToRouteAbsolute('graphcal.webhook.notify', ['user' => $userId]);
		try {
			$sub = $this->ms->graph($userId, 'POST', '/subscriptions', [
				'changeType' => 'created,updated,deleted',
				'notificationUrl' => $url,
				'lifecycleNotificationUrl' => $url,
				'resource' => '/me/events',
				'expirationDateTime' => $until,
				'clientState' => $secret,
			]);
		} catch (GraphError $e) {
			// Usually: Nextcloud isn't reachable over public HTTPS. Polling keeps working; retry in a few hours.
			$this->userConfig->setValueInt($userId, Microsoft::APP, 'sub_retry_after', $this->time->getTime() + 6 * 3600);
			throw $e;
		}
		$this->userConfig->setValueString($userId, Microsoft::APP, 'sub_secret', $secret, false, IUserConfig::FLAG_SENSITIVE);
		$this->userConfig->setValueString($userId, Microsoft::APP, 'sub_id', (string)$sub['id']);
		$this->userConfig->setValueInt($userId, Microsoft::APP, 'sub_expires', strtotime($until));
	}

	public function forceSubscriptionRenewal(string $userId): void {
		$this->userConfig->setValueInt($userId, Microsoft::APP, 'sub_expires', 0);
		$this->userConfig->deleteUserConfig($userId, Microsoft::APP, 'sub_retry_after');
	}

	private function deleteSubscription(string $userId): void {
		$id = $this->userConfig->getValueString($userId, Microsoft::APP, 'sub_id');
		if ($id !== '' && $this->ms->isConnected($userId)) {
			$this->deleteRemote($userId, '/subscriptions/' . rawurlencode($id));
		}
	}

	/* ================================================================ helpers */

	/** What we last synced, per part, so push() only sends what changed. */
	private function snapshot(VCalendar $vcal, string $me): array {
		$snap = ['master' => '', 'rrule' => '', 'overrides' => [], 'exdates' => [], 'partstat' => null];
		foreach ($vcal->select('VEVENT') as $ve) {
			if (isset($ve->{'RECURRENCE-ID'})) {
				$snap['overrides'][self::utc($ve->{'RECURRENCE-ID'}->getDateTime())] = Converter::hash(Converter::toGraph($ve, $me, false, isset($ve->ATTENDEE)));
				continue;
			}
			$snap['master'] = Converter::hash(Converter::toGraph($ve, $me, false, isset($ve->ATTENDEE)));
			try {
				$snap['rrule'] = isset($ve->RRULE) ? Converter::hash(Converter::recurrenceToGraph($ve)) : '';
			} catch (UnsupportedEvent) {
				$snap['rrule'] = 'unsupported';
			}
			foreach ($ve->select('EXDATE') as $ex) {
				foreach ($ex->getDateTimes() as $dt) {
					$snap['exdates'][] = self::utc($dt);
				}
			}
			$snap['partstat'] = self::myPartstat($ve, $me);
		}
		return $snap;
	}

	public static function master(VCalendar $vcal): ?VEvent {
		foreach ($vcal->select('VEVENT') as $ve) {
			if (!isset($ve->{'RECURRENCE-ID'})) {
				return $ve;
			}
		}
		return null;
	}

	private static function myPartstat(VEvent $ve, string $me): ?string {
		foreach ($ve->select('ATTENDEE') as $a) {
			if (Converter::address((string)$a) === $me) {
				return strtoupper((string)($a['PARTSTAT'] ?? 'NEEDS-ACTION'));
			}
		}
		return null;
	}

	private static function utc(\DateTimeInterface $dt): string {
		return DateTimeImmutable::createFromInterface($dt)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}

	/** The user's own addresses as Nextcloud knows them (organizer check for events created in Nextcloud). */
	private function ncAddresses(string $userId): array {
		$email = $this->userManager->get($userId)?->getEMailAddress();
		return $email ? [strtolower($email)] : [];
	}

	private function base(array $cal): string {
		return $cal['owner'] === '' ? '/me' : '/users/' . rawurlencode($cal['owner']);
	}

	/** @return array{0: string, 1: int} */
	private function deltaState(array $cal): array {
		$state = json_decode((string)($cal['delta_link'] ?? ''), true);
		return [(string)($state['link'] ?? ''), (int)($state['since'] ?? 0)];
	}
}
