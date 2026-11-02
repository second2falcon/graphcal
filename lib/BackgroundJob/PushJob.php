<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\BackgroundJob;

use OCA\GraphCal\Service\SyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Sends one changed Nextcloud object to Graph. Normally done right after the request that changed it
 * (SyncService::pushSoon); this queued job is the fallback that Nextcloud's cron runs.
 */
class PushJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private SyncService $sync,
	) {
		parent::__construct($time);
	}

	/** @param array{calendar: int, uri: string} $argument */
	#[\Override]
	protected function run($argument): void {
		$this->sync->push((int)$argument['calendar'], (string)$argument['uri']);
	}
}
