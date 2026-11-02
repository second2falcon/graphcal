<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Service;

use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Run work right after the HTTP response has been sent, so changes reach Exchange (and Nextcloud) in
 * seconds without a separate worker process. Needs PHP-FPM (or LiteSpeed) to release the client first;
 * callers fall back to background jobs, which Nextcloud's cron runs.
 */
class Deferred {
	/** @var list<callable> */
	private static array $queue = [];

	public static function canFinishEarly(): bool {
		return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
	}

	public static function afterResponse(callable $work): void {
		if (!self::$queue) {
			register_shutdown_function([self::class, 'run']);
		}
		self::$queue[] = $work;
	}

	/** @internal shutdown handler */
	public static function run(): void {
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		} elseif (function_exists('litespeed_finish_request')) {
			litespeed_finish_request();
		}
		while ($work = array_shift(self::$queue)) {
			try {
				$work();
			} catch (Throwable $e) {
				Server::get(LoggerInterface::class)->warning('GraphCal: deferred sync failed, the background job will retry', ['app' => Microsoft::APP, 'exception' => $e]);
			}
		}
	}
}
