<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Controller;

use OCA\GraphCal\Service\Microsoft;
use OCA\GraphCal\Service\SyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Config\IUserConfig;
use OCP\IRequest;

/**
 * Microsoft Graph change and lifecycle notifications. Must answer within 3 seconds,
 * so it only verifies the clientState and queues a pull.
 */
class WebhookController extends Controller {
	public function __construct(
		IRequest $request,
		private SyncService $sync,
		private IUserConfig $userConfig,
	) {
		parent::__construct(Microsoft::APP, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/webhook/{user}')]
	public function notify(string $user, string $validationToken = ''): Response {
		if ($validationToken !== '') {
			// Subscription handshake: echo the token as plain text.
			return new DataDisplayResponse($validationToken, Http::STATUS_OK, ['Content-Type' => 'text/plain']);
		}
		$secret = $this->userConfig->getValueString($user, Microsoft::APP, 'sub_secret');
		$accepted = false;
		foreach ((array)$this->request->getParam('value', []) as $n) {
			if ($secret === '' || !is_array($n) || !hash_equals($secret, (string)($n['clientState'] ?? ''))) {
				continue;
			}
			$accepted = true;
			if (in_array($n['lifecycleEvent'] ?? '', ['reauthorizationRequired', 'subscriptionRemoved'], true)) {
				$this->sync->forceSubscriptionRenewal($user);
			}
		}
		if (!$accepted) {
			return new Response(Http::STATUS_FORBIDDEN);
		}
		$this->sync->queuePull($user);
		return new Response(Http::STATUS_ACCEPTED);
	}
}
