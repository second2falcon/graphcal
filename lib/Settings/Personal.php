<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Settings;

use OCA\GraphCal\Service\Microsoft;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Microsoft::APP, 'personal');
		return new TemplateResponse(Microsoft::APP, 'personal');
	}

	public function getSection(): string {
		return 'graphcal';
	}

	public function getPriority(): int {
		return 50;
	}
}
