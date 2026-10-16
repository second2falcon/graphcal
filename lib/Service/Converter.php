<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProp;
use Sabre\VObject\TimeZoneUtil;
use Throwable;

/**
 * Microsoft Graph event JSON <-> iCalendar VEVENT. Pure functions, no I/O.
 * Mapping reference: [MS-OXCICAL]; field list in docs/RESEARCH.md §3.5.
 */
class Converter {
	private const DAYS = ['sunday' => 'SU', 'monday' => 'MO', 'tuesday' => 'TU', 'wednesday' => 'WE', 'thursday' => 'TH', 'friday' => 'FR', 'saturday' => 'SA'];
	private const INDEX = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'last' => -1];
	private const SHOW_AS = ['free' => 'FREE', 'tentative' => 'TENTATIVE', 'busy' => 'BUSY', 'oof' => 'OOF', 'workingElsewhere' => 'WORKINGELSEWHERE'];
	private const PARTSTAT = ['accepted' => 'ACCEPTED', 'declined' => 'DECLINED', 'tentativelyAccepted' => 'TENTATIVE', 'organizer' => 'ACCEPTED'];

	/* ---------------------------------------------------------------- Graph -> iCalendar */

	/**
	 * Build a VCALENDAR from a Graph series master (or single event) plus its exception occurrences.
	 * $me is the signed-in Microsoft address; it decides where `responseStatus` goes.
	 */
	public static function toVCalendar(array $event, string $uid, string $me): VCalendar {
		$vcal = new VCalendar(['PRODID' => '-//GraphCal//Nextcloud//EN']);
		$tz = self::zone($event['originalStartTimeZone'] ?? 'UTC');
		$master = self::fillVEvent($vcal->add('VEVENT', []), $event, $uid, $tz, $me);

		if (!empty($event['recurrence'])) {
			$master->add('RRULE', self::recurrenceFromGraph($event['recurrence'], $tz, (bool)($event['isAllDay'] ?? false)));
			// cancelledOccurrences look like "OID.<masterId>.2026-10-20"; the occurrence starts at the master's local start time.
			$start = $master->DTSTART->getDateTime();
			foreach ($event['cancelledOccurrences'] ?? [] as $oid) {
				if (preg_match('/(\d{4}-\d{2}-\d{2})$/', (string)$oid, $m)) {
					$day = new DateTimeImmutable($m[1] . ' ' . $start->format('H:i:s'), $start->getTimezone());
					$master->add('EXDATE', $day, ($event['isAllDay'] ?? false) ? ['VALUE' => 'DATE'] : []);
				}
			}
			foreach ($event['exceptionOccurrences'] ?? [] as $ex) {
				$override = self::fillVEvent($vcal->add('VEVENT', []), $ex, $uid, $tz, $me);
				$original = self::graphTime(['dateTime' => $ex['originalStart'] ?? $ex['start']['dateTime'], 'timeZone' => 'UTC'], $tz);
				$override->add('RECURRENCE-ID', ($event['isAllDay'] ?? false) ? $original->format('Ymd') : $original, ($event['isAllDay'] ?? false) ? ['VALUE' => 'DATE'] : []);
			}
		}
		return $vcal;
	}

	private static function fillVEvent(VEvent $ve, array $e, string $uid, DateTimeZone $tz, string $me): VEvent {
		$ve->UID = $uid;
		$ve->DTSTAMP = new DateTimeImmutable($e['lastModifiedDateTime'] ?? 'now', new DateTimeZone('UTC'));
		$ve->SUMMARY = (string)($e['subject'] ?? '');
		$allDay = (bool)($e['isAllDay'] ?? false);
		foreach (['DTSTART' => 'start', 'DTEND' => 'end'] as $prop => $key) {
			if (!isset($e[$key])) {
				continue;
			}
			$raw = (string)$e[$key]['dateTime'];
			if ($allDay) {
				// All-day values come as midnight; take the date as-is unless it was shifted into another zone.
				$date = str_contains($raw, 'T00:00:00') ? substr($raw, 0, 10) : self::graphTime($e[$key], $tz)->format('Y-m-d');
				$ve->add($prop, str_replace('-', '', $date), ['VALUE' => 'DATE']);
			} else {
				$ve->add($prop, self::graphTime($e[$key], $tz));
			}
		}
		$body = trim((string)($e['body']['content'] ?? ''));
		if ($body !== '') {
			$ve->DESCRIPTION = $body;
		}
		$location = (string)($e['location']['displayName'] ?? '');
		if ($location !== '') {
			$ve->LOCATION = $location;
		}
		$ve->CLASS = match ($e['sensitivity'] ?? 'normal') {
			'private', 'personal' => 'PRIVATE',
			'confidential' => 'CONFIDENTIAL',
			default => 'PUBLIC',
		};
		$showAs = (string)($e['showAs'] ?? 'busy');
		$ve->TRANSP = $showAs === 'free' ? 'TRANSPARENT' : 'OPAQUE';
		$ve->{'X-MICROSOFT-CDO-BUSYSTATUS'} = self::SHOW_AS[$showAs] ?? 'BUSY';
		if (!empty($e['categories'])) {
			$ve->CATEGORIES = $e['categories'];
		}
		if (!empty($e['isCancelled'])) {
			$ve->STATUS = 'CANCELLED';
		}
		if (!empty($e['isReminderOn'])) {
			$alarm = $ve->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => 'Reminder']);
			$alarm->add('TRIGGER', '-PT' . (int)($e['reminderMinutesBeforeStart'] ?? 15) . 'M');
		}
		$attendees = $e['attendees'] ?? [];
		if ($attendees) {
			$organizer = strtolower((string)($e['organizer']['emailAddress']['address'] ?? ''));
			if (!empty($e['isOrganizer'])) {
				$organizer = $me;
			}
			$ve->add('ORGANIZER', 'mailto:' . $organizer, array_filter(['CN' => $e['organizer']['emailAddress']['name'] ?? null]));
			foreach ($attendees as $a) {
				$address = strtolower((string)($a['emailAddress']['address'] ?? ''));
				if ($address === '' || $address === $organizer) {
					continue;
				}
				$response = $address === $me && empty($e['isOrganizer'])
					? ($e['responseStatus']['response'] ?? 'none')
					: ($a['status']['response'] ?? 'none');
				$partstat = self::PARTSTAT[$response] ?? 'NEEDS-ACTION';
				$params = [
					'CN' => $a['emailAddress']['name'] ?? $address,
					'CUTYPE' => ($a['type'] ?? '') === 'resource' ? 'RESOURCE' : 'INDIVIDUAL',
					'ROLE' => match ($a['type'] ?? 'required') { 'optional' => 'OPT-PARTICIPANT', 'resource' => 'NON-PARTICIPANT', default => 'REQ-PARTICIPANT' },
					'PARTSTAT' => $partstat,
					'RSVP' => 'TRUE',
				];
				if ($partstat === 'NEEDS-ACTION') {
					// Lets the Calendar app show "Awaiting response" instead of "Invitation will be sent".
					$params['SCHEDULE-STATUS'] = '1.1';
				}
				$ve->add('ATTENDEE', 'mailto:' . $address, $params);
			}
		}
		return $ve;
	}

	public static function recurrenceFromGraph(array $r, DateTimeZone $tz, bool $allDay): string {
		$p = $r['pattern'];
		$parts = [];
		$days = implode(',', array_map(fn ($d) => self::DAYS[$d], $p['daysOfWeek'] ?? []));
		$setPos = isset($p['index']) ? (self::INDEX[$p['index']] ?? 1) : 1;
		switch ($p['type']) {
			case 'daily':
				$parts = ['FREQ' => 'DAILY'];
				break;
			case 'weekly':
				$parts = ['FREQ' => 'WEEKLY', 'BYDAY' => $days, 'WKST' => self::DAYS[$p['firstDayOfWeek'] ?? 'monday'] ?? 'MO'];
				break;
			case 'absoluteMonthly':
				$parts = ['FREQ' => 'MONTHLY', 'BYMONTHDAY' => (int)$p['dayOfMonth']];
				break;
			case 'relativeMonthly':
				$parts = ['FREQ' => 'MONTHLY', 'BYDAY' => $days, 'BYSETPOS' => $setPos];
				break;
			case 'absoluteYearly':
				$parts = ['FREQ' => 'YEARLY', 'BYMONTH' => (int)$p['month'], 'BYMONTHDAY' => (int)$p['dayOfMonth']];
				break;
			case 'relativeYearly':
				$parts = ['FREQ' => 'YEARLY', 'BYMONTH' => (int)$p['month'], 'BYDAY' => $days, 'BYSETPOS' => $setPos];
				break;
		}
		if (($p['interval'] ?? 1) > 1) {
			$parts['INTERVAL'] = (int)$p['interval'];
		}
		$range = $r['range'] ?? [];
		if (($range['type'] ?? '') === 'endDate' && !empty($range['endDate'])) {
			$parts['UNTIL'] = $allDay
				? str_replace('-', '', $range['endDate'])
				: (new DateTimeImmutable($range['endDate'] . ' 23:59:59', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
		} elseif (($range['type'] ?? '') === 'numbered') {
			$parts['COUNT'] = (int)$range['numberOfOccurrences'];
		}
		return implode(';', array_map(fn ($k, $v) => "$k=$v", array_keys($parts), $parts));
	}

	/* ---------------------------------------------------------------- iCalendar -> Graph */

	/**
	 * Graph payload for one VEVENT (series master, single event or one overridden occurrence).
	 * Attendees are included only when $withAttendees (the user organizes the meeting).
	 * @throws UnsupportedEvent
	 */
	public static function toGraph(VEvent $ve, string $me, bool $withRecurrence, bool $withAttendees): array {
		$allDay = !$ve->DTSTART->hasTime();
		$start = $ve->DTSTART->getDateTime();
		$end = isset($ve->DTEND) ? $ve->DTEND->getDateTime()
			: (isset($ve->DURATION) ? $start->add($ve->DURATION->getDateInterval()) : $start->add(new DateInterval($allDay ? 'P1D' : 'PT0S')));
		$zone = self::windowsZone($ve->DTSTART);

		$g = [
			'subject' => (string)($ve->SUMMARY ?? ''),
			'body' => ['contentType' => 'text', 'content' => (string)($ve->DESCRIPTION ?? '')],
			'location' => ['displayName' => (string)($ve->LOCATION ?? '')],
			'isAllDay' => $allDay,
			'start' => self::graphDateTime($start, $allDay, $zone),
			'end' => self::graphDateTime($end, $allDay, $zone),
			'sensitivity' => match (strtoupper((string)($ve->CLASS ?? 'PUBLIC'))) { 'PRIVATE' => 'private', 'CONFIDENTIAL' => 'confidential', default => 'normal' },
			'showAs' => self::showAs($ve),
			'categories' => isset($ve->CATEGORIES) ? array_values(array_map('strval', $ve->CATEGORIES->getParts())) : [],
			'isReminderOn' => false,
		];
		foreach ($ve->select('VALARM') as $alarm) {
			$trigger = $alarm->TRIGGER ?? null;
			if ($trigger !== null && $trigger->getValueType() === 'DURATION' && strtoupper((string)($trigger['RELATED'] ?? 'START')) === 'START') {
				$interval = $trigger->getDateInterval();
				$minutes = $interval->days !== false ? $interval->days * 1440 : $interval->d * 1440;
				$minutes += $interval->h * 60 + $interval->i;
				$g['isReminderOn'] = true;
				$g['reminderMinutesBeforeStart'] = $interval->invert ? $minutes : 0;
				break; // Graph keeps one reminder only.
			}
		}
		if ($withAttendees) {
			$g['attendees'] = [];
			$organizer = isset($ve->ORGANIZER) ? self::address((string)$ve->ORGANIZER) : '';
			foreach ($ve->select('ATTENDEE') as $a) {
				$address = self::address((string)$a);
				if ($address === '' || $address === $organizer || $address === $me) {
					continue;
				}
				$cutype = strtoupper((string)($a['CUTYPE'] ?? ''));
				$g['attendees'][] = [
					'emailAddress' => array_filter(['address' => $address, 'name' => isset($a['CN']) ? (string)$a['CN'] : null]),
					'type' => in_array($cutype, ['RESOURCE', 'ROOM'], true) ? 'resource' : (strtoupper((string)($a['ROLE'] ?? '')) === 'OPT-PARTICIPANT' ? 'optional' : 'required'),
				];
			}
		}
		if ($withRecurrence && isset($ve->RRULE)) {
			$g['recurrence'] = self::recurrenceToGraph($ve);
		}
		return $g;
	}

	/** @throws UnsupportedEvent when the RRULE has no Microsoft 365 equivalent. */
	public static function recurrenceToGraph(VEvent $ve): array {
		$unsupported = static fn (string $why) => new UnsupportedEvent("This repeat pattern isn't supported by Microsoft 365 ($why).");
		if (isset($ve->RDATE)) {
			throw $unsupported('extra dates (RDATE)');
		}
		$rule = array_change_key_case($ve->RRULE->getParts(), CASE_UPPER);
		foreach (['BYSECOND', 'BYMINUTE', 'BYHOUR', 'BYWEEKNO', 'BYYEARDAY'] as $part) {
			if (isset($rule[$part])) {
				throw $unsupported($part);
			}
		}
		$start = $ve->DTSTART->getDateTime();
		$list = static fn ($v) => $v === null ? [] : array_values(array_filter(array_map('trim', is_array($v) ? $v : explode(',', (string)$v)), fn ($x) => $x !== ''));
		$byDay = $list($rule['BYDAY'] ?? null);
		$byMonthDay = $list($rule['BYMONTHDAY'] ?? null);
		$byMonth = $list($rule['BYMONTH'] ?? null);
		$bySetPos = $list($rule['BYSETPOS'] ?? null);
		$interval = (int)($rule['INTERVAL'] ?? 1);
		$freq = strtoupper((string)$rule['FREQ']);

		$dayName = static function (string $code) use ($unsupported): string {
			$name = array_search(strtoupper($code), self::DAYS, true);
			if ($name === false) {
				throw $unsupported("day $code");
			}
			return $name;
		};
		$index = static function (int $pos) use ($unsupported): string {
			$name = array_search($pos, self::INDEX, true);
			if ($name === false) {
				throw $unsupported('only first, second, third, fourth or last are possible');
			}
			return $name;
		};
		// Monthly/yearly "which day": BYMONTHDAY=n, BYDAY=2TU, or BYDAY=MO,..;BYSETPOS=n.
		$relative = static function () use ($byDay, $byMonthDay, $bySetPos, $dayName, $index, $unsupported, $start): array {
			if ($byMonthDay) {
				if (count($byMonthDay) > 1 || (int)$byMonthDay[0] < 1 || $byDay || $bySetPos) {
					throw $unsupported('several or negative days of the month');
				}
				return ['dayOfMonth' => (int)$byMonthDay[0]];
			}
			if (!$byDay) {
				return ['dayOfMonth' => (int)$start->format('j')];
			}
			if (count($byDay) === 1 && preg_match('/^([+-]?\d+)([A-Z]{2})$/i', $byDay[0], $m)) {
				if ($bySetPos) {
					throw $unsupported('BYSETPOS with a numbered day');
				}
				return ['daysOfWeek' => [$dayName($m[2])], 'index' => $index((int)$m[1])];
			}
			if (count($bySetPos) !== 1) {
				throw $unsupported('weekdays without a single position');
			}
			return ['daysOfWeek' => array_map($dayName, $byDay), 'index' => $index((int)$bySetPos[0])];
		};

		switch ($freq) {
			case 'DAILY':
				if ($byDay || $byMonthDay || $byMonth || $bySetPos) {
					if ($interval !== 1 || $byMonthDay || $byMonth || $bySetPos) {
						throw $unsupported('daily with filters');
					}
					$pattern = ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => array_map($dayName, $byDay), 'firstDayOfWeek' => 'monday'];
				} else {
					$pattern = ['type' => 'daily', 'interval' => $interval];
				}
				break;
			case 'WEEKLY':
				if ($byMonthDay || $byMonth || $bySetPos || preg_grep('/\d/', $byDay)) {
					throw $unsupported('weekly with filters');
				}
				$days = $byDay ? array_map($dayName, $byDay) : [strtolower($start->format('l'))];
				$pattern = ['type' => 'weekly', 'interval' => $interval, 'daysOfWeek' => $days, 'firstDayOfWeek' => $dayName($rule['WKST'] ?? 'MO')];
				break;
			case 'MONTHLY':
				if ($byMonth) {
					throw $unsupported('monthly with months');
				}
				$which = $relative();
				$pattern = ['type' => isset($which['index']) ? 'relativeMonthly' : 'absoluteMonthly', 'interval' => $interval] + $which;
				break;
			case 'YEARLY':
				if (count($byMonth) > 1) {
					throw $unsupported('several months');
				}
				$which = $relative();
				$pattern = ['type' => isset($which['index']) ? 'relativeYearly' : 'absoluteYearly', 'interval' => $interval, 'month' => (int)($byMonth[0] ?? $start->format('n'))] + $which;
				break;
			default:
				throw $unsupported(strtolower($freq));
		}

		$range = ['startDate' => $start->format('Y-m-d'), 'recurrenceTimeZone' => self::windowsZone($ve->DTSTART)];
		if (isset($rule['COUNT'])) {
			$range += ['type' => 'numbered', 'numberOfOccurrences' => (int)$rule['COUNT']];
		} elseif (isset($rule['UNTIL'])) {
			$until = \Sabre\VObject\DateTimeParser::parse((string)$rule['UNTIL'], $start->getTimezone());
			$range += ['type' => 'endDate', 'endDate' => $until->setTimezone($start->getTimezone())->format('Y-m-d')];
		} else {
			$range += ['type' => 'noEnd'];
		}
		return ['pattern' => $pattern, 'range' => $range];
	}

	/* ---------------------------------------------------------------- helpers */

	public static function address(string $calAddress): string {
		return strtolower(trim(preg_replace('/^mailto:/i', '', $calAddress)));
	}

	/** Hash of what we would send to Graph; used to detect which parts of an object changed. */
	public static function hash(array $payload): string {
		return md5(json_encode($payload, JSON_THROW_ON_ERROR));
	}

	/** Graph returns times in UTC unless a Prefer header says otherwise. */
	private static function graphTime(array $dt, DateTimeZone $tz): DateTimeImmutable {
		$value = substr((string)$dt['dateTime'], 0, 19);
		return (new DateTimeImmutable($value, self::zone($dt['timeZone'] ?? 'UTC')))->setTimezone($tz);
	}

	private static function graphDateTime(DateTimeInterface $dt, bool $allDay, string $zone): array {
		if ($allDay) {
			return ['dateTime' => $dt->format('Y-m-d') . 'T00:00:00', 'timeZone' => 'UTC'];
		}
		$local = $zone === 'UTC' ? DateTimeImmutable::createFromInterface($dt)->setTimezone(new DateTimeZone('UTC')) : $dt;
		return ['dateTime' => $local->format('Y-m-d\TH:i:s'), 'timeZone' => $zone];
	}

	/** Windows time zone name for a DTSTART (Graph's native format), or UTC when there is no mapping. */
	private static function windowsZone(DateTimeProp $prop): string {
		if (!$prop->hasTime() || $prop->isFloating() || !class_exists(\IntlTimeZone::class)) {
			return 'UTC';
		}
		$name = $prop->getDateTime()->getTimezone()->getName();
		$windows = \IntlTimeZone::getWindowsID($name);
		return is_string($windows) && $windows !== '' ? $windows : 'UTC';
	}

	/** Resolve a Windows or IANA zone name; unknown/custom zones fall back to UTC. */
	public static function zone(string $name): DateTimeZone {
		try {
			/** @psalm-suppress InvalidArgument the docblock says false, the code accepts true (throw when unknown) */
			return TimeZoneUtil::getTimeZone($name, null, true);
		} catch (Throwable) {
			return new DateTimeZone('UTC');
		}
	}

	private static function showAs(VEvent $ve): string {
		if (strtoupper((string)($ve->TRANSP ?? 'OPAQUE')) === 'TRANSPARENT') {
			return 'free';
		}
		$cdo = strtoupper((string)($ve->{'X-MICROSOFT-CDO-BUSYSTATUS'} ?? 'BUSY'));
		$showAs = array_search($cdo, self::SHOW_AS, true);
		return $showAs === false || $showAs === 'free' ? 'busy' : $showAs;
	}
}
