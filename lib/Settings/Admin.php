<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Settings;

use OCA\GraphCal\Service\Microsoft;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private IAppConfig $appConfig,
		private Microsoft $ms,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Microsoft::APP, 'admin');
		return new TemplateResponse(Microsoft::APP, 'admin', [
			'tenant_id' => $this->appConfig->getValueString(Microsoft::APP, 'tenant_id'),
			'client_id' => $this->appConfig->getValueString(Microsoft::APP, 'client_id'),
			'has_secret' => $this->appConfig->getValueString(Microsoft::APP, 'client_secret') !== '',
			'redirect_uri' => $this->ms->redirectUri(),
			'mail_configured' => $this->appConfig->getValueString('mail', 'microsoft_oauth_client_id') !== '',
		]);
	}

	public function getSection(): string {
		return 'groupware';
	}

	public function getPriority(): int {
		return 60;
	}
}
