<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Controller;

use OCA\GraphCal\Service\Microsoft;
use OCA\GraphCal\Service\SyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;
use Throwable;

/** The sign-in popup: start redirects to Microsoft, callback stores the tokens and closes the popup. */
class OauthController extends Controller {
	public function __construct(
		IRequest $request,
		private Microsoft $ms,
		private SyncService $sync,
		private ISession $session,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(Microsoft::APP, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/oauth/start')]
	public function start(): RedirectResponse|TemplateResponse {
		if (!$this->ms->isConfigured()) {
			return $this->done('GraphCal is not configured yet. Ask your administrator.');
		}
		$auth = $this->ms->authorizeRequest((string)$this->userSession->getUser()?->getEMailAddress());
		$this->session->set('graphcal_oauth', ['state' => $auth['state'], 'verifier' => $auth['verifier']]);
		return new RedirectResponse($auth['url']);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession]
	#[FrontpageRoute(verb: 'GET', url: '/oauth/callback')]
	public function callback(string $code = '', string $state = '', string $error = '', string $error_description = ''): TemplateResponse {
		$pending = $this->session->get('graphcal_oauth');
		$this->session->remove('graphcal_oauth');
		if ($error !== '') {
			return $this->done($error_description ?: $error);
		}
		if (!is_array($pending) || $code === '' || !hash_equals((string)$pending['state'], $state)) {
			return $this->done('The sign-in request expired or did not match. Please try again.');
		}
		$userId = (string)$this->userSession->getUser()?->getUID();
		try {
			$this->ms->finishAuthorization($userId, $code, (string)$pending['verifier']);
			$this->sync->refreshCalendars($userId);
		} catch (Throwable $e) {
			$this->logger->error('Microsoft sign-in failed', ['app' => Microsoft::APP, 'exception' => $e]);
			return $this->done('Signing in to Microsoft 365 failed: ' . $e->getMessage());
		}
		try {
			$this->sync->ensureSubscription($userId);
		} catch (Throwable $e) {
			$this->logger->info('Graph change notifications unavailable, polling only', ['app' => Microsoft::APP, 'exception' => $e]);
		}
		return $this->done('');
	}

	private function done(string $error): TemplateResponse {
		Util::addScript(Microsoft::APP, 'oauth-done');
		return new TemplateResponse(Microsoft::APP, 'oauth-done', ['error' => $error], TemplateResponse::RENDER_AS_GUEST);
	}
}
