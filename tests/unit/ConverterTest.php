<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Tests\Unit;

use OCA\GraphCal\Service\Converter;
use OCA\GraphCal\Service\Microsoft;
use OCA\GraphCal\Service\UnsupportedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

class ConverterTest extends TestCase {
	private const ME = 'me@contoso.com';

	private static function vevent(string $props): VEvent {
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nBEGIN:VEVENT\r\nUID:abc\r\nDTSTAMP:20261016T000000Z\r\n"
			. str_replace("\n", "\r\n", trim($props)) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		return Reader::read($ics)->VEVENT;
	}

	public static function supportedRules(): array {
		return [
			'daily' => ['FREQ=DAILY;INTERVAL=2', ['type' => 'daily', 'interval' => 2]],
			'weekdays as daily' => ['FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR', ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'firstDayOfWeek' => 'monday']],
			'weekly' => ['FREQ=WEEKLY;BYDAY=MO,WE;WKST=SU', ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['monday', 'wednesday'], 'firstDayOfWeek' => 'sunday']],
			'weekly without BYDAY uses DTSTART' => ['FREQ=WEEKLY', ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['friday'], 'firstDayOfWeek' => 'monday']],
			'monthly day' => ['FREQ=MONTHLY;BYMONTHDAY=15', ['type' => 'absoluteMonthly', 'interval' => 1, 'dayOfMonth' => 15]],
			'monthly from DTSTART' => ['FREQ=MONTHLY', ['type' => 'absoluteMonthly', 'interval' => 1, 'dayOfMonth' => 16]],
			'second tuesday' => ['FREQ=MONTHLY;BYDAY=2TU', ['type' => 'relativeMonthly', 'interval' => 1, 'daysOfWeek' => ['tuesday'], 'index' => 'second']],
			'last friday via setpos' => ['FREQ=MONTHLY;BYDAY=FR;BYSETPOS=-1', ['type' => 'relativeMonthly', 'interval' => 1, 'daysOfWeek' => ['friday'], 'index' => 'last']],
			'yearly' => ['FREQ=YEARLY;BYMONTH=3;BYMONTHDAY=1', ['type' => 'absoluteYearly', 'interval' => 1, 'month' => 3, 'dayOfMonth' => 1]],
			'yearly relative' => ['FREQ=YEARLY;BYMONTH=11;BYDAY=4TH', ['type' => 'relativeYearly', 'interval' => 1, 'month' => 11, 'daysOfWeek' => ['thursday'], 'index' => 'fourth']],
		];
	}

	#[DataProvider('supportedRules')]
	public function testSupportedRules(string $rrule, array $pattern): void {
		$ve = self::vevent("DTSTART;TZID=Europe/Amsterdam:20261016T090000\nDTEND;TZID=Europe/Amsterdam:20261016T100000\nRRULE:$rrule;COUNT=5");
		$r = Converter::recurrenceToGraph($ve);
		$this->assertSame($pattern, $r['pattern']);
		$this->assertSame(['startDate' => '2026-10-16', 'recurrenceTimeZone' => 'W. Europe Standard Time', 'type' => 'numbered', 'numberOfOccurrences' => 5], $r['range']);
	}

	public static function unsupportedRules(): array {
		return [
			'two days of month' => ['FREQ=MONTHLY;BYMONTHDAY=1,15'],
			'second to last' => ['FREQ=MONTHLY;BYDAY=WE;BYSETPOS=-2'],
			'fifth' => ['FREQ=MONTHLY;BYDAY=5WE'],
			'two months' => ['FREQ=YEARLY;BYMONTH=1,7'],
			'hourly' => ['FREQ=HOURLY'],
			'last day of month' => ['FREQ=MONTHLY;BYMONTHDAY=-1'],
		];
	}

	#[DataProvider('unsupportedRules')]
	public function testUnsupportedRules(string $rrule): void {
		$this->expectException(UnsupportedEvent::class);
		Converter::recurrenceToGraph(self::vevent("DTSTART:20261016T090000Z\nRRULE:$rrule"));
	}

	public function testUntilBecomesEndDate(): void {
		$r = Converter::recurrenceToGraph(self::vevent("DTSTART;TZID=Europe/Amsterdam:20261016T090000\nRRULE:FREQ=DAILY;UNTIL=20261031T080000Z"));
		$this->assertSame(['type' => 'endDate', 'endDate' => '2026-10-31'], array_intersect_key($r['range'], ['type' => 1, 'endDate' => 1]));
	}

	public function testGraphRecurrenceRoundTrips(): void {
		$tz = new \DateTimeZone('Europe/Amsterdam');
		foreach (self::supportedRules() as [$rrule, $pattern]) {
			$back = Converter::recurrenceFromGraph(['pattern' => $pattern, 'range' => ['type' => 'noEnd']], $tz, false);
			$ve = self::vevent("DTSTART;TZID=Europe/Amsterdam:20261016T090000\nRRULE:$back");
			$this->assertSame($pattern, Converter::recurrenceToGraph($ve)['pattern'], $rrule);
		}
	}

	public function testToGraphMeeting(): void {
		$ve = self::vevent(<<<ICS
DTSTART;TZID=Europe/Amsterdam:20261016T090000
DTEND;TZID=Europe/Amsterdam:20261016T100000
SUMMARY:Planning
LOCATION:Room 1
DESCRIPTION:Agenda
CLASS:PRIVATE
ORGANIZER;CN=Me:mailto:me@contoso.com
ATTENDEE;CN=Alice;PARTSTAT=NEEDS-ACTION:mailto:Alice@Example.com
ATTENDEE;ROLE=OPT-PARTICIPANT:mailto:bob@example.com
ATTENDEE;CUTYPE=ROOM:mailto:room@contoso.com
ATTENDEE;PARTSTAT=ACCEPTED:mailto:me@contoso.com
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT10M
END:VALARM
ICS);
		$g = Converter::toGraph($ve, self::ME, true, true);
		$this->assertSame('Planning', $g['subject']);
		$this->assertSame(['dateTime' => '2026-10-16T09:00:00', 'timeZone' => 'W. Europe Standard Time'], $g['start']);
		$this->assertSame('private', $g['sensitivity']);
		$this->assertSame('busy', $g['showAs']);
		$this->assertTrue($g['isReminderOn']);
		$this->assertSame(10, $g['reminderMinutesBeforeStart']);
		$this->assertSame([
			['emailAddress' => ['address' => 'alice@example.com', 'name' => 'Alice'], 'type' => 'required'],
			['emailAddress' => ['address' => 'bob@example.com'], 'type' => 'optional'],
			['emailAddress' => ['address' => 'room@contoso.com'], 'type' => 'resource'],
		], $g['attendees']);
		$this->assertArrayNotHasKey('recurrence', $g);
	}

	public function testToGraphAllDay(): void {
		$g = Converter::toGraph(self::vevent("DTSTART;VALUE=DATE:20261016\nDTEND;VALUE=DATE:20261018\nTRANSP:TRANSPARENT"), self::ME, false, false);
		$this->assertTrue($g['isAllDay']);
		$this->assertSame(['dateTime' => '2026-10-16T00:00:00', 'timeZone' => 'UTC'], $g['start']);
		$this->assertSame(['dateTime' => '2026-10-18T00:00:00', 'timeZone' => 'UTC'], $g['end']);
		$this->assertSame('free', $g['showAs']);
		$this->assertArrayNotHasKey('attendees', $g);
	}

	public function testFromGraphSeriesWithExceptions(): void {
		$event = [
			'id' => 'AAA', 'subject' => 'Standup', 'isAllDay' => false, 'isOrganizer' => false,
			'start' => ['dateTime' => '2026-10-16T07:00:00.0000000', 'timeZone' => 'UTC'],
			'end' => ['dateTime' => '2026-10-16T07:15:00.0000000', 'timeZone' => 'UTC'],
			'originalStartTimeZone' => 'W. Europe Standard Time',
			'showAs' => 'tentative', 'sensitivity' => 'normal',
			'isReminderOn' => true, 'reminderMinutesBeforeStart' => 5,
			'organizer' => ['emailAddress' => ['address' => 'Boss@contoso.com', 'name' => 'Boss']],
			'responseStatus' => ['response' => 'tentativelyAccepted'],
			'attendees' => [
				['emailAddress' => ['address' => 'me@contoso.com', 'name' => 'Me'], 'type' => 'required', 'status' => ['response' => 'none']],
				['emailAddress' => ['address' => 'eve@contoso.com', 'name' => 'Eve'], 'type' => 'optional', 'status' => ['response' => 'accepted']],
			],
			'recurrence' => [
				'pattern' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['friday'], 'firstDayOfWeek' => 'monday'],
				'range' => ['type' => 'endDate', 'startDate' => '2026-10-16', 'endDate' => '2026-12-31'],
			],
			'cancelledOccurrences' => ['OID.AAA.2026-10-23'],
			'exceptionOccurrences' => [[
				'subject' => 'Standup (moved)', 'isAllDay' => false,
				'originalStart' => '2026-10-30T07:00:00Z',
				'start' => ['dateTime' => '2026-10-30T09:00:00.0000000', 'timeZone' => 'UTC'],
				'end' => ['dateTime' => '2026-10-30T09:15:00.0000000', 'timeZone' => 'UTC'],
			]],
		];
		$vcal = Converter::toVCalendar($event, 'uid-1', self::ME);
		$ics = $vcal->serialize();
		$this->assertStringContainsString('DTSTART;TZID=Europe/Berlin:20261016T090000', $ics);
		$this->assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=FR;WKST=MO;UNTIL=20261231T225959Z', $ics);
		$this->assertStringContainsString('EXDATE;TZID=Europe/Berlin:20261023T090000', $ics);
		$this->assertStringContainsString('RECURRENCE-ID;TZID=Europe/Berlin:20261030T080000', $ics);
		$this->assertStringContainsString('X-MICROSOFT-CDO-BUSYSTATUS:TENTATIVE', $ics);
		$this->assertStringContainsString('TRIGGER:-PT5M', $ics);
		$this->assertStringContainsString('ORGANIZER;CN=Boss:mailto:boss@contoso.com', $ics);
		$this->assertMatchesRegularExpression('/ATTENDEE;CN=Me;[^:]*PARTSTAT=TENTATIVE[^:]*:mailto:me@contoso.com/', str_replace("\r\n ", '', $ics));
		$this->assertMatchesRegularExpression('/ATTENDEE;CN=Eve;[^:]*ROLE=OPT-PARTICIPANT;PARTSTAT=ACCEPTED/', str_replace("\r\n ", '', $ics));
		$this->assertCount(2, $vcal->select('VEVENT'));
		$this->assertSame([], $vcal->validate(), 'generated iCalendar must be valid');

		// And the master converts back to an equivalent Graph recurrence.
		$back = Converter::toGraph($vcal->VEVENT, self::ME, true, false);
		$this->assertSame($event['recurrence']['pattern'], $back['recurrence']['pattern']);
		$this->assertSame('2026-12-31', $back['recurrence']['range']['endDate']);
		$this->assertSame('tentative', $back['showAs']);
	}

	public function testFromGraphAllDay(): void {
		$vcal = Converter::toVCalendar([
			'subject' => 'Holiday', 'isAllDay' => true,
			'start' => ['dateTime' => '2026-12-24T00:00:00.0000000', 'timeZone' => 'UTC'],
			'end' => ['dateTime' => '2026-12-26T00:00:00.0000000', 'timeZone' => 'UTC'],
			'originalStartTimeZone' => 'Pacific Standard Time', 'showAs' => 'free',
		], 'uid-2', self::ME);
		$ics = $vcal->serialize();
		$this->assertStringContainsString('DTSTART;VALUE=DATE:20261224', $ics);
		$this->assertStringContainsString('DTEND;VALUE=DATE:20261226', $ics);
		$this->assertStringContainsString('TRANSP:TRANSPARENT', $ics);
		$this->assertStringNotContainsString('ATTENDEE', $ics);
	}

	public function testPkceChallengeMatchesRfc7636Example(): void {
		$this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Microsoft::pkceChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
	}
}
