<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * Tiny stand-in for login.microsoftonline.com + graph.microsoft.com, for integration tests:
 *   MOCK_STATE=/tmp/state.json php -S 127.0.0.1:8090 tests/integration/graph-mock.php
 * Only the calls GraphCal makes are implemented. Every request is logged in state.requests.
 * Test control: GET/PUT /_state, POST /_event (create or update an event as if done in Outlook).
 */

// Only ever run by PHP's built-in test server, never by a real web server serving the apps folder.
if (PHP_SAPI !== 'cli-server') {
	http_response_code(404);
	header('Content-Type: text/plain');
	exit("graph-mock.php is a test helper and only runs under PHP's built-in server: see tests/integration/setup.sh\n");
}

$file = getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/graphcal-mock.json';
$me = 'alice@contoso.test';

$lock = fopen($file . '.lock', 'c');
flock($lock, LOCK_EX);
$state = is_file($file) ? json_decode(file_get_contents($file), true) : null;
$state ??= ['seq' => 0, 'calendars' => [['id' => 'cal1', 'name' => 'Calendar', 'hexColor' => '#0078d4', 'canEdit' => true, 'isSharedWithMe' => false]], 'events' => [], 'removed' => [], 'requests' => [], 'subscriptions' => []];

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];
if (!str_starts_with($path, '/_')) {
	$state['requests'][] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => $body ?: $_POST];
}

$base = 'http://' . $_SERVER['HTTP_HOST'] . '/v1.0';
$out = function (int $status, $data = null, ?string $location = null) use (&$state, $file, $lock) {
	file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	flock($lock, LOCK_UN);
	http_response_code($status);
	if ($location !== null) {
		header('Location: ' . $location);
	}
	if ($data !== null) {
		header('Content-Type: application/json');
		echo json_encode($data, JSON_UNESCAPED_SLASHES);
	}
	exit;
};
$public = fn (array $e) => array_diff_key($e, ['_v' => 1, 'calendarId' => 1]);
$touch = function (array $e) use (&$state) {
	$e['_v'] = ++$state['seq'];
	$e['changeKey'] = 'ck' . $state['seq'];
	$e['lastModifiedDateTime'] = gmdate('Y-m-d\TH:i:s\Z');
	$state['events'][$e['id']] = $e;
	return $e;
};

switch (true) {
	case $path === '/_state' && $method === 'GET':
		$out(200, $state);
	case $path === '/_state' && $method === 'PUT':
		$state = $body;
		$out(204);
	case $path === '/_event' && $method === 'POST':
		$e = ($state['events'][$body['id']] ?? []) + ['iCalUId' => 'ical-' . $body['id'], 'calendarId' => 'cal1', 'type' => isset($body['recurrence']) ? 'seriesMaster' : 'singleInstance'];
		$out(200, $public($touch(array_replace($e, $body))));
	case $path === '/_remove' && $method === 'POST':
		$e = $state['events'][$body['id']];
		unset($state['events'][$body['id']]);
		// A removed series shows up as removed occurrences in calendarView delta.
		$state['removed'][] = ['id' => $e['type'] === 'seriesMaster' ? $body['id'] . '_occ' : $body['id'], 'calendarId' => $e['calendarId'], '_v' => ++$state['seq']];
		$out(204);

	case preg_match('#^/[^/]+/oauth2/v2\.0/authorize$#', $path) === 1:
		// The user "signs in" instantly and Microsoft redirects back with a code.
		$out(302, null, $query['redirect_uri'] . '?' . http_build_query(['code' => 'browser-code', 'state' => $query['state']]));
	case preg_match('#^/[^/]+/oauth2/v2\.0/token$#', $path) === 1:
		$out(200, ['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'at-' . ++$state['seq'], 'refresh_token' => 'rt-' . $state['seq']]);

	case $path === '/v1.0/me':
		$out(200, ['displayName' => 'Alice', 'mail' => $me, 'userPrincipalName' => $me]);
	case $path === '/v1.0/me/calendars':
		$out(200, ['value' => $state['calendars']]);

	case preg_match('#^/v1\.0/me/calendars/([^/]+)/calendarView/delta$#', $path, $m) === 1:
		$since = (int)($query['$deltatoken'] ?? 0);
		$items = [];
		foreach ($state['events'] as $e) {
			if ($e['calendarId'] !== $m[1] || $e['_v'] <= $since) {
				continue;
			}
			$items[] = $e['type'] === 'seriesMaster'
				? ['id' => $e['id'] . '_occ', 'type' => 'occurrence', 'seriesMasterId' => $e['id'], 'start' => $e['start'], 'end' => $e['end']]
				: $public($e);
		}
		foreach ($state['removed'] as $r) {
			if ($r['calendarId'] === $m[1] && $r['_v'] > $since) {
				$items[] = ['id' => $r['id'], '@removed' => ['reason' => 'deleted']];
			}
		}
		$out(200, ['value' => $items, '@odata.deltaLink' => "$base/me/calendars/{$m[1]}/calendarView/delta?\$deltatoken={$state['seq']}"]);

	case preg_match('#^/v1\.0/me/calendars/([^/]+)/events$#', $path, $m) === 1 && $method === 'POST':
		$id = 'ev' . ($state['seq'] + 1);
		$e = $body + ['id' => $id, 'iCalUId' => 'ical-' . $id, 'calendarId' => $m[1], 'isOrganizer' => true,
			'type' => isset($body['recurrence']) ? 'seriesMaster' : 'singleInstance',
			'organizer' => ['emailAddress' => ['address' => $me, 'name' => 'Alice']],
			'originalStartTimeZone' => $body['start']['timeZone'] ?? 'UTC'];
		foreach ($e['attendees'] ?? [] as $i => $a) {
			$e['attendees'][$i]['status'] = ['response' => 'none'];
		}
		$out(201, $public($touch($e)));

	case preg_match('#^/v1\.0/me/events/([^/]+)/(accept|decline|tentativelyAccept)$#', $path, $m) === 1:
		$e = $state['events'][$m[1]] ?? $out(404, ['error' => ['code' => 'ErrorItemNotFound', 'message' => 'not found']]);
		$e['responseStatus'] = ['response' => ['accept' => 'accepted', 'decline' => 'declined', 'tentativelyAccept' => 'tentativelyAccepted'][$m[2]]];
		$touch($e);
		$out(202);

	case preg_match('#^/v1\.0/me/events/([^/]+)/instances$#', $path) === 1:
		$out(200, ['value' => []]);

	case preg_match('#^/v1\.0/me/events/([^/]+)$#', $path, $m) === 1:
		$e = $state['events'][$m[1]] ?? $out(404, ['error' => ['code' => 'ErrorItemNotFound', 'message' => 'not found']]);
		if ($method === 'GET') {
			$out(200, $public($e));
		}
		if ($method === 'PATCH') {
			$out(200, $public($touch(array_replace($e, $body))));
		}
		if ($method === 'DELETE') {
			unset($state['events'][$m[1]]);
			$state['removed'][] = ['id' => $m[1], 'calendarId' => $e['calendarId'], '_v' => ++$state['seq']];
			$out(204);
		}
		break;

	case $path === '/v1.0/subscriptions' && $method === 'POST':
		// Like Graph: validate the endpoint before creating the subscription.
		$token = 'validate-' . bin2hex(random_bytes(4));
		$ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: text/plain', 'content' => '', 'ignore_errors' => true, 'timeout' => 10]]);
		$echo = @file_get_contents($body['notificationUrl'] . (str_contains($body['notificationUrl'], '?') ? '&' : '?') . 'validationToken=' . urlencode($token), false, $ctx);
		if ($echo !== $token) {
			$out(400, ['error' => ['code' => 'ValidationError', 'message' => 'Subscription validation request failed. Got: ' . var_export($echo, true)]]);
		}
		$sub = $body + ['id' => 'sub' . ++$state['seq']];
		$state['subscriptions'][$sub['id']] = $sub;
		$out(201, $sub);
	case preg_match('#^/v1\.0/subscriptions/([^/]+)$#', $path, $m) === 1:
		if ($method === 'DELETE') {
			unset($state['subscriptions'][$m[1]]);
			$out(204);
		}
		$out(200, array_replace($state['subscriptions'][$m[1]] ?? [], $body));
}
$out(404, ['error' => ['code' => 'NotImplemented', 'message' => "$method $path is not mocked"]]);
