<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * On Calendar app pages, load the script that explains GraphCal's refusals
 * (the Calendar app itself only says "Failed to save event").
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 */
class CalendarPageListener implements IEventListener {
	public function handle(Event $event): void {
		if ($event instanceof BeforeTemplateRenderedEvent && $event->isLoggedIn() && $event->getResponse()->getApp() === 'calendar') {
			Util::addScript('graphcal', 'calendar-errors');
		}
	}
}
