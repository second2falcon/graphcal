<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\BackgroundJob;

use OCA\GraphCal\Service\SyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/** Pulls Exchange changes for one user; fallback for SyncService::pullSoon, run by Nextcloud's cron. */
class DeltaJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private SyncService $sync,
	) {
		parent::__construct($time);
	}

	/** @param string $argument user id */
	#[\Override]
	protected function run($argument): void {
		$this->sync->pull((string)$argument);
	}
}
