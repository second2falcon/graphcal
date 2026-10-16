<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Controller;

use OCA\GraphCal\Db\Store;
use OCA\GraphCal\Service\Microsoft;
use OCA\GraphCal\Service\SyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Throwable;

class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private Microsoft $ms,
		private SyncService $sync,
		private Store $store,
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private IUserSession $userSession,
		private ICrypto $crypto,
	) {
		parent::__construct(Microsoft::APP, $request);
	}

	/* ------------------------------------------------------------ admin (admin-only by default) */

	#[FrontpageRoute(verb: 'POST', url: '/settings/admin')]
	public function saveAdmin(string $tenant_id, string $client_id, string $client_secret = ''): JSONResponse {
		$this->appConfig->setValueString(Microsoft::APP, 'tenant_id', trim($tenant_id));
		$this->appConfig->setValueString(Microsoft::APP, 'client_id', trim($client_id));
		if ($client_secret !== '') {
			$this->appConfig->setValueString(Microsoft::APP, 'client_secret', $client_secret, sensitive: true);
		}
		return new JSONResponse(['configured' => $this->ms->isConfigured()]);
	}

	/** Copy the Microsoft app registration that Nextcloud Mail already uses (optional convenience). */
	#[FrontpageRoute(verb: 'POST', url: '/settings/admin/from-mail')]
	public function importFromMail(): JSONResponse {
		$clientId = $this->appConfig->getValueString('mail', 'microsoft_oauth_client_id');
		$encrypted = $this->appConfig->getValueString('mail', 'microsoft_oauth_client_secret');
		if ($clientId === '' || $encrypted === '') {
			return new JSONResponse(['error' => 'Nextcloud Mail has no Microsoft integration configured.'], Http::STATUS_NOT_FOUND);
		}
		try {
			$secret = $this->crypto->decrypt($encrypted);
		} catch (Throwable) {
			return new JSONResponse(['error' => 'Could not read the client secret stored by Nextcloud Mail.'], Http::STATUS_CONFLICT);
		}
		$tenant = $this->appConfig->getValueString('mail', 'microsoft_oauth_tenant_id', 'common');
		return $this->saveAdmin($tenant === 'common' ? 'organizations' : $tenant, $clientId, $secret);
	}

	/* ------------------------------------------------------------ personal */

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/settings/personal')]
	public function state(): JSONResponse {
		$userId = $this->userId();
		return new JSONResponse([
			'configured' => $this->ms->isConfigured(),
			'connected' => $this->ms->isConnected($userId),
			'account' => $this->ms->msEmail($userId),
			'error' => $this->userConfig->getValueString($userId, Microsoft::APP, 'auth_error'),
			'calendars' => array_map(fn ($c) => [
				'id' => $c['id'], 'name' => $c['name'], 'enabled' => $c['enabled'],
				'shared' => $c['is_shared'], 'canEdit' => $c['can_edit'], 'owner' => $c['owner'],
			], $this->store->calendars($userId)),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/settings/personal/refresh')]
	public function refresh(): JSONResponse {
		return $this->run(fn () => $this->sync->refreshCalendars($this->userId()));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/settings/personal/calendar/{id}')]
	public function toggle(int $id, bool $enabled): JSONResponse {
		return $this->run(fn () => $this->sync->setEnabled($this->userId(), $id, $enabled));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/settings/personal/shared')]
	public function addShared(string $email): JSONResponse {
		return $this->run(fn () => $this->sync->addSharedCalendar($this->userId(), $email));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/settings/personal/disconnect')]
	public function disconnect(): JSONResponse {
		return $this->run(fn () => $this->sync->forget($this->userId()));
	}

	private function run(callable $action): JSONResponse {
		try {
			$action();
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return $this->state();
	}

	private function userId(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}
}
