<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\GraphCal\Tests\Unit;

use OCA\GraphCal\Service\GraphError;
use OCA\GraphCal\Service\Microsoft;
use OCA\GraphCal\Service\ReauthRequired;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class MicrosoftTest extends TestCase {
	private const SECRETS = ['the-refresh-token', 'the-access-token', 'the-client-secret', 'the-code', 'the-verifier'];

	/** A Microsoft service whose HTTP client fails the way $http says. */
	private function microsoft(callable $http): Microsoft {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($app, $key, $default = '') => $key === 'client_secret' ? 'the-client-secret' : $default);
		$userConfig = $this->createStub(IUserConfig::class);
		$userConfig->method('getValueString')->willReturnCallback(fn ($user, $app, $key) => $key === 'refresh_token' ? 'the-refresh-token' : '');
		$client = $this->createStub(IClient::class);
		$client->method('post')->willReturnCallback($http);
		$client->method('request')->willReturnCallback($http);
		$clients = $this->createStub(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		return new Microsoft($appConfig, $userConfig, $clients, $this->createStub(IURLGenerator::class),
			$this->createStub(ISecureRandom::class), $this->createStub(ITimeFactory::class), $this->createStub(LoggerInterface::class));
	}

	private function response(int $status, array $body): IResponse {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(json_encode($body));
		return $response;
	}

	public static function failures(): array {
		return [
			'token endpoint unreachable' => ['unreachable', false],
			'token endpoint refuses (e.g. expired client secret)' => ['invalid_client', false],
			'refresh token revoked' => ['invalid_grant', false],
			'sign-in: code not redeemed' => ['invalid_client', true],
			'Graph unreachable' => ['graph', false],
		];
	}

	/** Nextcloud logs exceptions with their stack trace arguments: none of them may hold a credential. */
	#[DataProvider('failures')]
	public function testFailuresDontCarryCredentialsInTheirTrace(string $failure, bool $signIn): void {
		ini_set('zend.exception_ignore_args', '0'); // PHP's built-in default; php.ini-production hides all arguments
		$ms = $this->microsoft(function (...$args) use ($failure) {
			$url = is_string($args[1] ?? null) ? $args[1] : $args[0]; // request(method, url, options) or post(url, options)
			if ($failure === 'graph' && str_contains($url, '/token')) {
				return $this->response(200, ['access_token' => 'the-access-token']);
			}
			if ($failure === 'unreachable' || $failure === 'graph') {
				// Like Guzzle: the options (headers, form fields) travel through its stack frames.
				throw (fn (array $options) => new RuntimeException('cURL error 6: Could not resolve host'))(['Authorization' => 'Bearer the-access-token', 'client_secret' => 'the-client-secret']);
			}
			return $this->response(400, ['error' => $failure, 'error_description' => 'AADSTS: refused']);
		});
		try {
			$signIn ? $ms->finishAuthorization('alice', 'the-code', 'the-verifier') : $ms->graph('alice', 'GET', '/me');
			$this->fail('expected a failure');
		} catch (Throwable $e) {
			$this->assertNull($e->getPrevious());
			$logged = $e->getMessage() . json_encode($e->getTrace());
			foreach (self::SECRETS as $secret) {
				$this->assertStringNotContainsString($secret, $logged);
			}
		}
	}

	public function testUsersSeeMicrosoftsAnswersButNoInternals(): void {
		$this->assertSame('Graph 403 ErrorAccessDenied: no', Microsoft::userMessage(new GraphError(403, 'ErrorAccessDenied', 'no')));
		$this->assertSame('sign in again', Microsoft::userMessage(new ReauthRequired('sign in again')));
		$this->assertSame('Unknown calendar', Microsoft::userMessage(new \InvalidArgumentException('Unknown calendar')));
		$this->assertStringNotContainsString('oc_graphcal', Microsoft::userMessage(new RuntimeException('SQLSTATE[42S02]: no such table: oc_graphcal_calendars')));
	}
}
