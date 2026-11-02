<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\BackgroundJob;

use OCA\GraphCal\Db\Store;
use OCA\GraphCal\Service\Microsoft;
use OCA\GraphCal\Service\SyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Safety net every 5 minutes: queue a pull for every connected user (covers lost notifications and
 * shared calendars, which Graph can't notify about with delegated permissions) and keep subscriptions alive.
 */
class PollJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Store $store,
		private Microsoft $ms,
		private SyncService $sync,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	#[\Override]
	protected function run($argument): void {
		foreach ($this->store->users() as $userId) {
			if (!$this->ms->isConnected($userId)) {
				continue;
			}
			$this->sync->queuePull($userId);
			try {
				$this->sync->ensureSubscription($userId);
			} catch (Throwable $e) {
				$this->logger->info('Graph change notifications unavailable, polling only', ['app' => Microsoft::APP, 'user' => $userId, 'exception' => $e]);
			}
		}
	}
}
