<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\BackgroundJob;

use OCA\GraphCal\Service\SyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Sends one changed Nextcloud object to Graph. For a few seconds of latency run a dedicated worker:
 *   occ background-job:worker 'OCA\GraphCal\BackgroundJob\PushJob' 'OCA\GraphCal\BackgroundJob\DeltaJob'
 */
class PushJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private SyncService $sync,
	) {
		parent::__construct($time);
	}

	/** @param array{calendar: int, uri: string} $argument */
	protected function run($argument): void {
		$this->sync->push((int)$argument['calendar'], (string)$argument['uri']);
	}
}
