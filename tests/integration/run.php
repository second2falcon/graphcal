<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * End-to-end test against a real Nextcloud (php -S) and the Graph mock (tests/integration/graph-mock.php).
 * Usage: NC_DIR=/path/to/server NC_URL=http://127.0.0.1:8080 MOCK_URL=http://127.0.0.1:8090 php tests/integration/run.php
 * Expects users alice/bob (password "Graphcal-Test-Pass-7731") and the app configured against the mock (see setup.sh).
 */

$nc = rtrim(getenv('NC_URL') ?: 'http://127.0.0.1:8080', '/');
$mock = rtrim(getenv('MOCK_URL') ?: 'http://127.0.0.1:8090', '/');
$dir = getenv('NC_DIR') ?: '';
getenv('OCC') || $dir || die("NC_DIR or OCC not set\n");
const PASS = 'Graphcal-Test-Pass-7731';
// Behind PHP-FPM, pulls run right after the response; under php -S they wait for cron.
$fpm = getenv('NC_FPM') === '1';
$failures = 0;

function http(string $method, string $url, ?string $body = null, array $headers = [], ?string $auth = null, ?string $cookies = null): array {
	$ch = curl_init($url);
	curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
	if ($body !== null) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
	}
	if ($auth) {
		curl_setopt($ch, CURLOPT_USERPWD, $auth);
	}
	if ($cookies) {
		curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $cookies, CURLOPT_COOKIEFILE => $cookies]);
	}
	$raw = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	return ['status' => $status, 'headers' => substr((string)$raw, 0, $headerSize), 'body' => substr((string)$raw, $headerSize)];
}

function check(bool $ok, string $what, string $detail = ''): void {
	global $failures;
	echo ($ok ? "  ok   " : "  FAIL ") . $what . ($ok || $detail === '' ? '' : "\n       $detail") . "\n";
	$failures += $ok ? 0 : 1;
}

function occ(string $args): string {
	global $dir;
	$occ = getenv('OCC') ?: 'php ' . escapeshellarg("$dir/occ");
	return (string)shell_exec("$occ $args 2>&1");
}

/** Run GraphCal's queued background jobs, as Nextcloud's cron would. */
function cron(): void {
	occ("background-job:worker --once 'OCA\\GraphCal\\BackgroundJob\\PushJob' 'OCA\\GraphCal\\BackgroundJob\\DeltaJob'");
}

/** Behind PHP-FPM, wait for work done after the response; otherwise run the queued jobs like cron. */
function settle(callable $done): void {
	global $fpm;
	if (!$fpm) {
		cron();
		return;
	}
	for ($i = 0; $i < 40 && !$done(); $i++) {
		usleep(250000);
	}
}

/** Pushes never wait for cron: under php -S they finish before the response, behind PHP-FPM just after. */
function pushed(): void {
	global $fpm;
	for ($i = 0; $fpm && $i < 40 && str_contains(occ('background-job:list'), 'PushJob'); $i++) {
		usleep(250000);
	}
}

function mockState(): array {
	global $mock;
	return json_decode(http('GET', "$mock/_state")['body'], true);
}

function mockRequests(string $method, string $pathPattern): array {
	return array_values(array_filter(mockState()['requests'], fn ($r) => $r['method'] === $method && preg_match($pathPattern, $r['path'])));
}

function outlook(array $event): array {
	global $mock;
	return json_decode(http('POST', "$mock/_event", json_encode($event), ['Content-Type: application/json'])['body'], true);
}

function dav(string $method, string $user, string $path, ?string $body = null, array $headers = []): array {
	global $nc;
	return http($method, "$nc/remote.php/dav/calendars/$user/$path", $body, $headers, "$user:" . PASS);
}

function objects(string $user, string $calendar): array {
	$r = dav('REPORT', $user, "$calendar/", '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"/></c:filter></c:calendar-query>', ['Depth: 1', 'Content-Type: application/xml']);
	preg_match_all('#<cal:calendar-data>(.*?)</cal:calendar-data>#s', $r['body'], $m);
	// Unfold iCalendar lines so tests can match whole properties.
	return array_map(fn ($s) => preg_replace('/\r?\n[ \t]/', '', html_entity_decode($s, ENT_QUOTES | ENT_XML1)), $m[1]);
}

function ics(string $uid, string $props): string {
	return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20261016T000000Z\r\n"
		. str_replace("\n", "\r\n", trim($props)) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
}

$soon = gmdate('Ymd', time() + 7 * 86400);

echo "Seed Outlook with events\n";
outlook(['id' => 'single1', 'subject' => 'Dentist', 'isAllDay' => false, 'showAs' => 'busy',
	'start' => ['dateTime' => gmdate('Y-m-d', time() + 86400) . 'T08:00:00.0000000', 'timeZone' => 'UTC'],
	'end' => ['dateTime' => gmdate('Y-m-d', time() + 86400) . 'T09:00:00.0000000', 'timeZone' => 'UTC'],
	'originalStartTimeZone' => 'W. Europe Standard Time']);
outlook(['id' => 'series1', 'subject' => 'Weekly sync', 'isAllDay' => false, 'type' => 'seriesMaster',
	'start' => ['dateTime' => gmdate('Y-m-d', time() + 2 * 86400) . 'T07:00:00.0000000', 'timeZone' => 'UTC'],
	'end' => ['dateTime' => gmdate('Y-m-d', time() + 2 * 86400) . 'T07:30:00.0000000', 'timeZone' => 'UTC'],
	'originalStartTimeZone' => 'W. Europe Standard Time',
	'recurrence' => ['pattern' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => [strtolower(gmdate('l', time() + 2 * 86400))], 'firstDayOfWeek' => 'monday'], 'range' => ['type' => 'noEnd', 'startDate' => gmdate('Y-m-d', time() + 2 * 86400)]],
	'cancelledOccurrences' => ['OID.series1.' . gmdate('Y-m-d', time() + 9 * 86400)]]);
outlook(['id' => 'invite1', 'subject' => 'Budget review', 'isAllDay' => false, 'isOrganizer' => false,
	'start' => ['dateTime' => gmdate('Y-m-d', time() + 3 * 86400) . 'T10:00:00.0000000', 'timeZone' => 'UTC'],
	'end' => ['dateTime' => gmdate('Y-m-d', time() + 3 * 86400) . 'T11:00:00.0000000', 'timeZone' => 'UTC'],
	'organizer' => ['emailAddress' => ['address' => 'boss@contoso.test', 'name' => 'Boss']],
	'responseStatus' => ['response' => 'notResponded'],
	'attendees' => [['type' => 'required', 'emailAddress' => ['address' => 'alice@contoso.test', 'name' => 'Alice'], 'status' => ['response' => 'none']]]]);

echo "Sign in through the popup flow (start -> Microsoft -> callback)\n";
$jar = tempnam(sys_get_temp_dir(), 'jar');
$start = http('GET', "$nc/index.php/apps/graphcal/oauth/start", null, [], 'alice:' . PASS, $jar);
preg_match('/^Location:\s*(\S+)/mi', $start['headers'], $loc);
$authorize = $loc[1] ?? '';
parse_str((string)parse_url($authorize, PHP_URL_QUERY), $q);
check($start['status'] === 303 && str_starts_with($authorize, "$mock/tenant/oauth2/v2.0/authorize"), 'start redirects to the authorize endpoint', $start['status'] . ' ' . $authorize);
check(($q['code_challenge_method'] ?? '') === 'S256' && strlen($q['code_challenge'] ?? '') === 43, 'authorize request uses PKCE S256');
check(str_contains($q['scope'] ?? '', 'Calendars.ReadWrite') && str_contains($q['scope'] ?? '', 'offline_access'), 'authorize request asks for calendar scopes');
$bad = http('GET', "$nc/index.php/apps/graphcal/oauth/callback?code=x&state=wrong", null, [], 'alice:' . PASS, $jar);
check(str_contains($bad['body'], 'did not match'), 'callback rejects a wrong state');
$start = http('GET', "$nc/index.php/apps/graphcal/oauth/start", null, [], 'alice:' . PASS, $jar);
preg_match('/^Location:\s*(\S+)/mi', $start['headers'], $loc);
parse_str((string)parse_url($loc[1], PHP_URL_QUERY), $q);
$cb = http('GET', "$nc/index.php/apps/graphcal/oauth/callback?code=the-code&state=" . urlencode($q['state']), null, [], 'alice:' . PASS, $jar);
check(str_contains($cb['body'], 'Connected to Microsoft 365'), 'callback connects the account', substr(strip_tags($cb['body']), 0, 400));
$token = mockRequests('POST', '#/oauth2/v2.0/token$#')[0]['body'] ?? [];
check(($token['grant_type'] ?? '') === 'authorization_code' && ($token['code_verifier'] ?? '') !== '' && hash_equals(rtrim(strtr(base64_encode(hash('sha256', $token['code_verifier'], true)), '+/', '-_'), '='), $q['code_challenge']), 'code is redeemed with the matching PKCE verifier');
check(count(mockState()['subscriptions']) === 1, 'change-notification subscription created (webhook validation echoed)');

$calendar = 'graphcal-' . substr(md5('cal1'), 0, 12);

echo "Outlook -> Nextcloud\n";
settle(fn () => count(objects('alice', $calendar)) >= 3);
$objs = objects('alice', $calendar);
$all = implode("\n", $objs);
check(count($objs) === 3, 'three events mirrored into Nextcloud', count($objs) . " objects:\n" . $all);
check(str_contains($all, 'SUMMARY:Dentist'), 'single event');
check(str_contains($all, 'RRULE:FREQ=WEEKLY') && str_contains($all, 'EXDATE;TZID=Europe/Berlin'), 'series with RRULE and EXDATE');
check((bool)preg_match('/ATTENDEE;[^\r\n]*PARTSTAT=NEEDS-ACTION[^\r\n]*SCHEDULE-STATUS=1.1/', $all), 'invite shows as awaiting response');
check(!str_contains(occ('background-job:list'), 'PushJob'), 'mirroring does not echo back as a push');

echo "Nextcloud -> Outlook, with Exchange (not Nextcloud) sending the invitation\n";
dav('PROPFIND', 'bob', '', null, ['Depth: 1']); // first login creates bob's default calendar
$meeting = ics('meeting-1', "DTSTART;TZID=Europe/Amsterdam:{$soon}T140000\nDTEND;TZID=Europe/Amsterdam:{$soon}T150000\nSUMMARY:Project kickoff\nORGANIZER;CN=Alice:mailto:alice@contoso.test\nATTENDEE;CN=Bob;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:bob@contoso.test\nATTENDEE;CN=Carol;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:carol@external.test");
$put = dav('PUT', 'alice', "$calendar/meeting-1.ics", $meeting, ['Content-Type: text/calendar']);
check($put['status'] === 201, 'meeting created in the synced calendar', (string)$put['status']);
check(!str_contains(implode('', objects('bob', 'personal')), 'Project kickoff'), 'Nextcloud did not deliver the invitation itself');
check(!str_contains(implode('', objects('alice', $calendar)), 'SCHEDULE-STATUS=1.2'), 'Nextcloud did not schedule (no SCHEDULE-STATUS written)');
// No job runs here: the push happens right after the request, without a worker or cron.
pushed();
check(!str_contains(occ('background-job:list'), 'PushJob'), 'pushed right after saving, nothing left for cron');
$created = mockRequests('POST', '#^/v1\.0/me/calendars/cal1/events$#');
$attendees = array_map(fn ($a) => $a['emailAddress']['address'], $created[0]['body']['attendees'] ?? []);
check(count($created) === 1 && $attendees === ['bob@contoso.test', 'carol@external.test'], 'meeting created in Exchange with both attendees', json_encode($created));
check(($created[0]['body']['start'] ?? []) === ['dateTime' => substr($soon, 0, 4) . '-' . substr($soon, 4, 2) . '-' . substr($soon, 6, 2) . 'T14:00:00', 'timeZone' => 'W. Europe Standard Time'], 'start time sent in the Windows time zone');

echo "Control: the same meeting in a local (not synced) calendar is scheduled by Nextcloud\n";
dav('PUT', 'alice', 'personal/meeting-2.ics', str_replace(['meeting-1', 'Project kickoff'], ['meeting-2', 'Local meeting'], $meeting), ['Content-Type: text/calendar']);
check(str_contains(implode('', objects('bob', 'personal')), 'Local meeting'), 'Nextcloud delivers invitations for non-synced calendars (test is meaningful)');

echo "Changes and replies\n";
$put = dav('PUT', 'alice', "$calendar/meeting-1.ics", str_replace('Project kickoff', 'Project kickoff (moved)', $meeting), ['Content-Type: text/calendar']);
pushed();
$patches = mockRequests('PATCH', '#^/v1\.0/me/events/ev\d+$#');
check(count($patches) === 1 && ($patches[0]['body']['subject'] ?? '') === 'Project kickoff (moved)', 'update sent as PATCH', json_encode($patches));

$inviteObj = array_values(array_filter(objects('alice', $calendar), fn ($o) => str_contains($o, 'Budget review')))[0] ?? '';
$accepted = str_replace('PARTSTAT=NEEDS-ACTION', 'PARTSTAT=ACCEPTED', $inviteObj);
$put = dav('PUT', 'alice', "$calendar/ical-invite1.ics", $accepted, ['Content-Type: text/calendar']);
pushed();
check(count(mockRequests('POST', '#^/v1\.0/me/events/invite1/accept$#')) === 1, 'accepting an invitation sends the reply through Exchange', (string)$put['status']);

echo "Repeat patterns Microsoft 365 can't store are refused\n";
$createdBefore = count(mockRequests('POST', '#^/v1\.0/me/calendars/cal1/events$#'));
foreach ([
	'several days of the month' => "RRULE:FREQ=MONTHLY;BYMONTHDAY=1,15",
	'second-to-last weekday' => "RRULE:FREQ=MONTHLY;BYDAY=WE;BYSETPOS=-2",
	'fifth weekday of the month' => "RRULE:FREQ=MONTHLY;BYDAY=5FR",
	'last day of the month' => "RRULE:FREQ=MONTHLY;BYMONTHDAY=-1",
	'several months a year' => "RRULE:FREQ=YEARLY;BYMONTH=1,7",
	'every few hours' => "RRULE:FREQ=HOURLY;INTERVAL=4",
	'extra dates (RDATE)' => "RRULE:FREQ=WEEKLY\nRDATE:{$soon}T150000Z",
] as $what => $rule) {
	$uid = 'odd-' . md5($rule);
	$r = dav('PUT', 'alice', "$calendar/$uid.ics", ics($uid, "DTSTART:{$soon}T090000Z\nDTEND:{$soon}T100000Z\nSUMMARY:Odd repeat\n$rule"), ['Content-Type: text/calendar']);
	check($r['status'] === 403 && str_contains($r['body'], "isn't supported by Microsoft 365"), "refused: $what", $r['status'] . ' ' . strip_tags($r['body']));
}
pushed();
check(!str_contains(implode('', objects('alice', $calendar)), 'Odd repeat'), 'refused events are not stored in Nextcloud');
check(count(mockRequests('POST', '#^/v1\.0/me/calendars/cal1/events$#')) === $createdBefore, 'refused events are not sent to Exchange');
$r = dav('PUT', 'alice', "$calendar/board.ics", ics('board', "DTSTART;TZID=Europe/Amsterdam:{$soon}T160000\nDTEND;TZID=Europe/Amsterdam:{$soon}T170000\nSUMMARY:Board meeting\nRRULE:FREQ=MONTHLY;BYDAY=2TU;COUNT=6"), ['Content-Type: text/calendar']);
pushed();
$created = mockRequests('POST', '#^/v1\.0/me/calendars/cal1/events$#');
check($r['status'] === 201 && (end($created)['body']['recurrence']['pattern'] ?? null) === ['type' => 'relativeMonthly', 'interval' => 1, 'daysOfWeek' => ['tuesday'], 'index' => 'second'],
	'a supported pattern (2nd Tuesday) is accepted and sent to Exchange', $r['status'] . ' ' . json_encode(end($created)['body']['recurrence'] ?? null));

$move = dav('MOVE', 'alice', "$calendar/meeting-1.ics", null, ['Destination: ' . $nc . '/remote.php/dav/calendars/alice/personal/meeting-1.ics']);
check($move['status'] === 403, 'meetings cannot be moved out of an Outlook calendar', (string)$move['status']);

echo "Outlook changes come back\n";
outlook(['id' => 'single1', 'subject' => 'Dentist (rescheduled)']);
http('POST', "$mock/_remove", json_encode(['id' => 'series1']), ['Content-Type: application/json']);
$hook = http('POST', "$nc/index.php/apps/graphcal/webhook/alice", json_encode(['value' => [['clientState' => 'wrong']]]), ['Content-Type: application/json']);
check($hook['status'] === 403, 'webhook rejects a wrong clientState');
$sub = array_values(mockState()['subscriptions'])[0];
$hook = http('POST', "$nc/index.php/apps/graphcal/webhook/alice", json_encode(['value' => [['clientState' => $sub['clientState'], 'changeType' => 'updated']]]), ['Content-Type: application/json']);
check($hook['status'] === 202, 'webhook accepts a valid notification', (string)$hook['status']);
settle(fn () => !str_contains(implode('', objects('alice', $calendar)), 'Weekly sync'));
$all = implode("\n", objects('alice', $calendar));
if ($fpm) {
	check(!str_contains(occ('background-job:list'), 'DeltaJob'), 'pulled right after the notification, nothing left for cron');
}
check(str_contains($all, 'Dentist (rescheduled)'), 'Outlook edit reached Nextcloud');
check(!str_contains($all, 'Weekly sync'), 'series deleted in Outlook is removed from Nextcloud');
check(!str_contains(occ('background-job:list'), 'PushJob'), 'no echo pushes after mirroring');

echo "Delete in Nextcloud\n";
dav('DELETE', 'alice', "$calendar/meeting-1.ics");
pushed();
check(count(mockRequests('DELETE', '#^/v1\.0/me/events/ev\d+$#')) === 1, 'delete sent to Exchange (which sends the cancellation)');

echo "Disconnect\n";
$csrf = json_decode(http('GET', "$nc/index.php/csrftoken", null, [], 'alice:' . PASS, $jar)['body'], true)['token'] ?? '';
$off = http('POST', "$nc/index.php/apps/graphcal/settings/personal/disconnect", '{}', ['Content-Type: application/json', "requesttoken: $csrf"], 'alice:' . PASS, $jar);
$state = json_decode($off['body'], true);
check($off['status'] === 200 && ($state['connected'] ?? true) === false && ($state['calendars'] ?? null) === [], 'disconnect removes tokens and calendars', $off['status'] . ' ' . $off['body']);
check(dav('PROPFIND', 'alice', "$calendar/", null, ['Depth: 0'])['status'] === 404, 'synced calendar removed from Nextcloud');
check(mockState()['subscriptions'] === [], 'subscription deleted at Microsoft');

echo $failures === 0 ? "\nAll integration checks passed\n" : "\n$failures integration check(s) failed\n";
exit($failures === 0 ? 0 : 1);
