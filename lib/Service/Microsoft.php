<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * OAuth 2.0 (authorization code + PKCE) against Entra ID, and authenticated Microsoft Graph calls.
 * Same mechanism as Nextcloud Mail's Microsoft integration, but with Graph scopes and our own tokens.
 */
class Microsoft {
	public const APP = 'graphcal';
	/** Pages followed per listing at most: 2000 × 100 items covers the busiest calendar's 3-year window. */
	public const MAX_PAGES = 2000;
	public const SCOPES = 'openid profile email offline_access https://graph.microsoft.com/User.Read https://graph.microsoft.com/Calendars.ReadWrite https://graph.microsoft.com/Calendars.ReadWrite.Shared';

	public function __construct(
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private IClientService $clientService,
		private IURLGenerator $urlGenerator,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function isConfigured(): bool {
		return $this->clientId() !== '' && $this->clientSecret() !== '';
	}

	public function isConnected(string $userId): bool {
		return $this->userConfig->getValueString($userId, self::APP, 'refresh_token') !== '';
	}

	public function redirectUri(): string {
		return $this->urlGenerator->linkToRouteAbsolute('graphcal.oauth.callback');
	}

	/** @return array{url: string, verifier: string, state: string} */
	public function authorizeRequest(string $loginHint): array {
		$verifier = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$state = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$query = [
			'client_id' => $this->clientId(),
			'response_type' => 'code',
			'response_mode' => 'query',
			'redirect_uri' => $this->redirectUri(),
			'scope' => self::SCOPES,
			'state' => $state,
			'code_challenge' => self::pkceChallenge($verifier),
			'code_challenge_method' => 'S256',
		];
		if ($loginHint !== '') {
			$query['login_hint'] = $loginHint;
		}
		return [
			'url' => $this->authority() . '/oauth2/v2.0/authorize?' . http_build_query($query),
			'verifier' => $verifier,
			'state' => $state,
		];
	}

	public static function pkceChallenge(string $verifier): string {
		return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}

	/** Redeem the authorization code, store the tokens and remember who signed in. */
	public function finishAuthorization(string $userId, string $code, string $verifier): void {
		$this->storeTokens($userId, $this->tokenRequest([
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $this->redirectUri(),
			'code_verifier' => $verifier,
		]));
		$me = $this->graph($userId, 'GET', '/me?$select=displayName,mail,userPrincipalName');
		$this->userConfig->setValueString($userId, self::APP, 'ms_email', strtolower((string)($me['mail'] ?? $me['userPrincipalName'] ?? '')));
		$this->userConfig->setValueString($userId, self::APP, 'ms_name', (string)($me['displayName'] ?? ''));
		$this->userConfig->deleteUserConfig($userId, self::APP, 'auth_error');
	}

	public function msEmail(string $userId): string {
		return $this->userConfig->getValueString($userId, self::APP, 'ms_email');
	}

	public function disconnect(string $userId): void {
		foreach ($this->userConfig->getKeys($userId, self::APP) as $key) {
			$this->userConfig->deleteUserConfig($userId, self::APP, $key);
		}
	}

	public function accessToken(string $userId): string {
		$token = $this->userConfig->getValueString($userId, self::APP, 'access_token', '', true);
		$expires = $this->userConfig->getValueInt($userId, self::APP, 'token_expires', 0, true);
		if ($token !== '' && $expires > $this->time->getTime() + 60) {
			return $token;
		}
		$refresh = $this->userConfig->getValueString($userId, self::APP, 'refresh_token');
		if ($refresh === '') {
			throw new ReauthRequired('Not connected to Microsoft 365');
		}
		try {
			$tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'scope' => self::SCOPES]);
		} catch (ReauthRequired $e) {
			$this->userConfig->setValueString($userId, self::APP, 'auth_error', $e->getMessage());
			throw $e;
		}
		$this->storeTokens($userId, $tokens);
		return (string)$tokens['access_token'];
	}

	/**
	 * Call Microsoft Graph. $path is relative to the Graph base URL ("/me/calendars") or an absolute
	 * nextLink/deltaLink. Returns the decoded JSON body ([] for empty responses).
	 * Throws GraphError (with status) on failures; 429/503/504 are retried honouring Retry-After.
	 */
	public function graph(string $userId, string $method, string $path, ?array $body = null, array $headers = []): array {
		$url = str_contains($path, '://') ? $path : $this->graphUrl() . $path;
		// next/delta links come from responses (and are stored): never send the token anywhere but Graph.
		if (!$this->isGraphUrl($url)) {
			throw new RuntimeException('Refusing to call a non-Graph URL: ' . (string)parse_url($url, PHP_URL_HOST));
		}
		$options = [
			'http_errors' => false,
			'timeout' => 30,
			'headers' => $headers + [
				'Authorization' => 'Bearer ' . $this->accessToken($userId),
				'Accept' => 'application/json',
				'Prefer' => 'IdType="ImmutableId", outlook.body-content-type="text"',
			],
		];
		if ($body !== null) {
			$options['body'] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
			$options['headers']['Content-Type'] = 'application/json';
		}
		for ($attempt = 0; ; $attempt++) {
			$response = $this->clientService->newClient()->request($method, $url, $options);
			$status = $response->getStatusCode();
			if (in_array($status, [429, 503, 504], true) && $attempt < 3) {
				// Known limit: blocking sleep inside the job; fine for per-user serial work, queue-based backoff if it hurts.
				sleep(min(30, max(1, (int)$response->getHeader('Retry-After'))));
				continue;
			}
			break;
		}
		$raw = (string)$response->getBody();
		$data = $raw === '' ? [] : (json_decode($raw, true) ?? []);
		if ($status >= 400) {
			throw new GraphError($status, (string)($data['error']['code'] ?? ''), (string)($data['error']['message'] ?? $raw));
		}
		return $data;
	}

	/** GET all pages of a collection. @return list<array> */
	public function graphAll(string $userId, string $path): array {
		$items = [];
		$seen = [];
		while ($path !== '') {
			$page = $this->graph($userId, 'GET', self::follow($seen, $path));
			array_push($items, ...($page['value'] ?? []));
			$path = (string)($page['@odata.nextLink'] ?? '');
		}
		return $items;
	}

	/** Whether $url is on the configured Graph origin (scheme, host and port; no user info). */
	public function isGraphUrl(string $url): bool {
		$base = parse_url($this->graphUrl());
		$origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
		return str_starts_with($url, $origin . '/');
	}

	/**
	 * Track a page link before requesting it: fails on a link seen before (a loop) or past MAX_PAGES,
	 * so a misbehaving upstream can't keep a job paging forever. Callers keep their old cursor on failure.
	 */
	public static function follow(array &$seen, string $link): string {
		if (isset($seen[$link]) || count($seen) >= self::MAX_PAGES) {
			throw new RuntimeException('Microsoft Graph paging did not end (' . count($seen) . ' pages)');
		}
		$seen[$link] = true;
		return $link;
	}

	private function tokenRequest(array $params): array {
		$response = $this->clientService->newClient()->post($this->authority() . '/oauth2/v2.0/token', [
			'http_errors' => false,
			'form_params' => $params + ['client_id' => $this->clientId(), 'client_secret' => $this->clientSecret()],
		]);
		$data = json_decode((string)$response->getBody(), true);
		$data = is_array($data) ? $data : [];
		if ($response->getStatusCode() >= 400 || empty($data['access_token'])) {
			$this->logger->warning('Microsoft token request failed', ['app' => self::APP, 'error' => $data['error'] ?? $response->getStatusCode(), 'description' => $data['error_description'] ?? '']);
			if (in_array($data['error'] ?? '', ['invalid_grant', 'interaction_required', 'consent_required'], true)) {
				throw new ReauthRequired((string)($data['error_description'] ?? $data['error']));
			}
			throw new RuntimeException('Microsoft token request failed: ' . ($data['error_description'] ?? $response->getStatusCode()));
		}
		return $data;
	}

	private function storeTokens(string $userId, array $tokens): void {
		$flags = IUserConfig::FLAG_SENSITIVE;
		$this->userConfig->setValueString($userId, self::APP, 'access_token', (string)$tokens['access_token'], true, $flags);
		$this->userConfig->setValueInt($userId, self::APP, 'token_expires', $this->time->getTime() + (int)($tokens['expires_in'] ?? 3600), true);
		// Refresh tokens rotate: always keep the newest one (Nextcloud Mail loses it, see nextcloud/mail#13852).
		if (!empty($tokens['refresh_token'])) {
			$this->userConfig->setValueString($userId, self::APP, 'refresh_token', (string)$tokens['refresh_token'], false, $flags);
		}
	}

	private function authority(): string {
		$base = rtrim($this->appConfig->getValueString(self::APP, 'login_url', 'https://login.microsoftonline.com'), '/');
		return $base . '/' . rawurlencode($this->appConfig->getValueString(self::APP, 'tenant_id', 'organizations') ?: 'organizations');
	}

	private function graphUrl(): string {
		return rtrim($this->appConfig->getValueString(self::APP, 'graph_url', 'https://graph.microsoft.com/v1.0'), '/');
	}

	private function clientId(): string {
		return $this->appConfig->getValueString(self::APP, 'client_id');
	}

	private function clientSecret(): string {
		return $this->appConfig->getValueString(self::APP, 'client_secret');
	}
}
