<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Tests\Core\Controller;

use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Exceptions\PasswordlessTokenException;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OC\Core\Controller\ClientFlowLoginController;
use OCA\OAuth2\Db\AccessTokenMapper;
use OCA\OAuth2\Db\Client;
use OCA\OAuth2\Db\ClientMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StandaloneTemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Defaults;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\Session\Exceptions\SessionNotAvailableException;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ClientFlowLoginControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private IL10N&MockObject $l10n;
	private Defaults&MockObject $defaults;
	private ISession&MockObject $session;
	private IProvider&MockObject $tokenProvider;
	private ISecureRandom&MockObject $random;
	private IURLGenerator&MockObject $urlGenerator;
	private ClientMapper&MockObject $clientMapper;
	private AccessTokenMapper&MockObject $accessTokenMapper;
	private ICrypto&MockObject $crypto;
	private IEventDispatcher&MockObject $eventDispatcher;
	private ITimeFactory&MockObject $timeFactory;
	private IConfig&MockObject $config;

	private ClientFlowLoginController $clientFlowLoginController;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n
			->expects($this->any())
			->method('t')
			->willReturnCallback(function ($text, $parameters = []) {
				return vsprintf($text, $parameters);
			});
		$this->defaults = $this->createMock(Defaults::class);
		$this->session = $this->createMock(ISession::class);
		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->clientMapper = $this->createMock(ClientMapper::class);
		$this->accessTokenMapper = $this->createMock(AccessTokenMapper::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->config = $this->createMock(IConfig::class);

		$this->clientFlowLoginController = new ClientFlowLoginController(
			'core',
			$this->request,
			$this->userSession,
			$this->l10n,
			$this->defaults,
			$this->session,
			$this->tokenProvider,
			$this->random,
			$this->urlGenerator,
			$this->clientMapper,
			$this->accessTokenMapper,
			$this->crypto,
			$this->eventDispatcher,
			$this->timeFactory,
			$this->config,
		);
	}

	public function testShowAuthPickerPageNoClientOrOauthRequest(): void {
		$expected = new StandaloneTemplateResponse(
			'core',
			'error',
			[
				'errors'
					=> [
						[
							'error' => 'Access Forbidden',
							'hint' => 'Invalid request',
						],
					],
			],
			'guest'
		);

		$this->assertEquals($expected, $this->clientFlowLoginController->showAuthPickerPage());
	}

	public function testShowAuthPickerPageWithOcsHeader(): void {
		$this->request
			->method('getHeader')
			->willReturnMap([
				['user-agent', 'Mac OS X Sync Client'],
				['OCS-APIREQUEST', 'true'],
			]);
		$this->random
			->expects($this->once())
			->method('generate')
			->with(
				64,
				ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS
			)
			->willReturn('StateToken');
		$this->session
			->expects($this->once())
			->method('set')
			->with('client.flow.state.token', 'StateToken');
		$this->session
			->expects($this->once())
			->method('get')
			->with('oauth.state')
			->willReturn('OauthStateToken');
		$this->defaults
			->expects($this->once())
			->method('getName')
			->willReturn('ExampleCloud');
		$this->request
			->expects($this->once())
			->method('getServerHost')
			->willReturn('example.com');
		$this->request
			->method('getServerProtocol')
			->willReturn('https');

		$expected = new StandaloneTemplateResponse(
			'core',
			'loginflow/authpicker',
			[
				'client' => 'Mac OS X Sync Client',
				'clientIdentifier' => '',
				'instanceName' => 'ExampleCloud',
				'urlGenerator' => $this->urlGenerator,
				'stateToken' => 'StateToken',
				'serverHost' => 'https://example.com',
				'oauthState' => 'OauthStateToken',
				'user' => '',
				'direct' => 0,
				'providedRedirectUri' => '',
			],
			'guest'
		);
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFormActionDomain('nc://*');
		$expected->setContentSecurityPolicy($csp);
		$this->assertEquals($expected, $this->clientFlowLoginController->showAuthPickerPage());
	}

	public function testShowAuthPickerPageWithOauth(): void {
		$this->request
			->method('getHeader')
			->willReturnMap([
				['user-agent', 'Mac OS X Sync Client'],
				['OCS-APIREQUEST', 'false'],
			]);
		$client = new Client();
		$client->setName('My external service');
		$client->setRedirectUri('https://example.com/redirect.php');
		$this->clientMapper
			->expects($this->once())
			->method('getByIdentifier')
			->with('MyClientIdentifier')
			->willReturn($client);
		$this->random
			->expects($this->once())
			->method('generate')
			->with(
				64,
				ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS
			)
			->willReturn('StateToken');
		$this->session
			->expects($this->once())
			->method('set')
			->with('client.flow.state.token', 'StateToken');
		$this->session
			->expects($this->once())
			->method('get')
			->with('oauth.state')
			->willReturn('OauthStateToken');
		$this->defaults
			->expects($this->once())
			->method('getName')
			->willReturn('ExampleCloud');
		$this->request
			->expects($this->once())
			->method('getServerHost')
			->willReturn('example.com');
		$this->request
			->method('getServerProtocol')
			->willReturn('https');

		$expected = new StandaloneTemplateResponse(
			'core',
			'loginflow/authpicker',
			[
				'client' => 'My external service',
				'clientIdentifier' => 'MyClientIdentifier',
				'instanceName' => 'ExampleCloud',
				'urlGenerator' => $this->urlGenerator,
				'stateToken' => 'StateToken',
				'serverHost' => 'https://example.com',
				'oauthState' => 'OauthStateToken',
				'user' => '',
				'direct' => 0,
				'providedRedirectUri' => '',
			],
			'guest'
		);
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFormActionDomain('https://example.com/redirect.php');
		$expected->setContentSecurityPolicy($csp);
		$this->assertEquals($expected, $this->clientFlowLoginController->showAuthPickerPage('MyClientIdentifier'));
	}

	public function testGenerateAppPasswordWithInvalidToken(): void {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn('OtherToken');
		$this->session
			->expects($this->once())
			->method('remove')
			->with('client.flow.state.token');

		$expected = new StandaloneTemplateResponse(
			'core',
			'403',
			[
				'message' => 'State token does not match',
			],
			'guest'
		);
		$expected->setStatus(Http::STATUS_FORBIDDEN);
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken'));
	}

	public function testGenerateAppPasswordWithSessionNotAvailableException(): void {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn('MyStateToken');
		$this->session
			->expects($this->once())
			->method('remove')
			->with('client.flow.state.token');
		$this->session
			->expects($this->once())
			->method('getId')
			->willThrowException(new SessionNotAvailableException());

		$expected = new Response();
		$expected->setStatus(Http::STATUS_FORBIDDEN);
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken'));
	}

	public function testGenerateAppPasswordWithInvalidTokenException(): void {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn('MyStateToken');
		$this->session
			->expects($this->once())
			->method('remove')
			->with('client.flow.state.token');
		$this->session
			->expects($this->once())
			->method('getId')
			->willReturn('SessionId');
		$this->tokenProvider
			->expects($this->once())
			->method('getToken')
			->with('SessionId')
			->willThrowException(new InvalidTokenException());

		$expected = new Response();
		$expected->setStatus(Http::STATUS_FORBIDDEN);
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken'));
	}

	public function testGeneratePasswordWithPassword(): void {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn('MyStateToken');
		$this->session
			->expects($this->once())
			->method('remove')
			->with('client.flow.state.token');
		$this->session
			->expects($this->once())
			->method('getId')
			->willReturn('SessionId');
		$myToken = $this->createMock(IToken::class);
		$myToken
			->expects($this->once())
			->method('getLoginName')
			->willReturn('MyLoginName');
		$this->tokenProvider
			->expects($this->once())
			->method('getToken')
			->with('SessionId')
			->willReturn($myToken);
		$this->tokenProvider
			->expects($this->once())
			->method('getPassword')
			->with($myToken, 'SessionId')
			->willReturn('MyPassword');
		$this->random
			->expects($this->once())
			->method('generate')
			->with(72)
			->willReturn('MyGeneratedToken');
		$user = $this->createMock(IUser::class);
		$user
			->expects($this->once())
			->method('getUID')
			->willReturn('MyUid');
		$this->userSession
			->expects($this->once())
			->method('getUser')
			->willReturn($user);
		$this->tokenProvider
			->expects($this->once())
			->method('generateToken')
			->with(
				'MyGeneratedToken',
				'MyUid',
				'MyLoginName',
				'MyPassword',
				'unknown',
				IToken::PERMANENT_TOKEN,
				IToken::DO_NOT_REMEMBER
			);
		$this->request
			->expects($this->once())
			->method('getServerProtocol')
			->willReturn('http');
		$this->request
			->expects($this->once())
			->method('getServerHost')
			->willReturn('example.com');
		$this->request
			->expects($this->any())
			->method('getHeader')
			->willReturn('');

		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped');

		$expected = new RedirectResponse('nc://login/server:http://example.com&user:MyLoginName&password:MyGeneratedToken');
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken'));
	}

	/**
	 * @param string $redirectUri
	 * @param string $redirectUrl
	 *
	 * @testWith
	 * ["https://example.com/redirect.php", "https://example.com/redirect.php?state=MyOauthState&code=MyAccessCode"]
	 * ["https://example.com/redirect.php?hello=world", "https://example.com/redirect.php?hello=world&state=MyOauthState&code=MyAccessCode"]
	 *
	 */
	public function testGeneratePasswordWithPasswordForOauthClient($redirectUri, $redirectUrl): void {
		$this->session
			->method('get')
			->willReturnMap([
				['client.flow.state.token', 'MyStateToken'],
				['oauth.state', 'MyOauthState'],
			]);
		$calls = [
			'client.flow.state.token',
			'oauth.state',
		];
		$this->session
			->method('remove')
			->willReturnCallback(function ($key) use (&$calls): void {
				$expected = array_shift($calls);
				$this->assertEquals($expected, $key);
			});
		$this->session
			->expects($this->once())
			->method('getId')
			->willReturn('SessionId');
		$myToken = $this->createMock(IToken::class);
		$myToken
			->expects($this->once())
			->method('getLoginName')
			->willReturn('MyLoginName');
		$this->tokenProvider
			->expects($this->once())
			->method('getToken')
			->with('SessionId')
			->willReturn($myToken);
		$this->tokenProvider
			->expects($this->once())
			->method('getPassword')
			->with($myToken, 'SessionId')
			->willReturn('MyPassword');
		$this->random
			->method('generate')
			->willReturnMap([
				[72, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS, 'MyGeneratedToken'],
				[128, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS, 'MyAccessCode'],
			]);
		$user = $this->createMock(IUser::class);
		$user
			->expects($this->once())
			->method('getUID')
			->willReturn('MyUid');
		$this->userSession
			->expects($this->once())
			->method('getUser')
			->willReturn($user);
		$token = $this->createMock(IToken::class);
		$this->tokenProvider
			->expects($this->once())
			->method('generateToken')
			->with(
				'MyGeneratedToken',
				'MyUid',
				'MyLoginName',
				'MyPassword',
				'My OAuth client',
				IToken::PERMANENT_TOKEN,
				IToken::DO_NOT_REMEMBER
			)
			->willReturn($token);
		$client = new Client();
		$client->setName('My OAuth client');
		$client->setRedirectUri($redirectUri);
		$this->clientMapper
			->expects($this->once())
			->method('getByIdentifier')
			->with('MyClientIdentifier')
			->willReturn($client);

		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped');

		$expected = new RedirectResponse($redirectUrl);
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken', 'MyClientIdentifier'));
	}

	public function testGeneratePasswordWithoutPassword(): void {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn('MyStateToken');
		$this->session
			->expects($this->once())
			->method('remove')
			->with('client.flow.state.token');
		$this->session
			->expects($this->once())
			->method('getId')
			->willReturn('SessionId');
		$myToken = $this->createMock(IToken::class);
		$myToken
			->expects($this->once())
			->method('getLoginName')
			->willReturn('MyLoginName');
		$this->tokenProvider
			->expects($this->once())
			->method('getToken')
			->with('SessionId')
			->willReturn($myToken);
		$this->tokenProvider
			->expects($this->once())
			->method('getPassword')
			->with($myToken, 'SessionId')
			->willThrowException(new PasswordlessTokenException());
		$this->random
			->expects($this->once())
			->method('generate')
			->with(72)
			->willReturn('MyGeneratedToken');
		$user = $this->createMock(IUser::class);
		$user
			->expects($this->once())
			->method('getUID')
			->willReturn('MyUid');
		$this->userSession
			->expects($this->once())
			->method('getUser')
			->willReturn($user);
		$this->tokenProvider
			->expects($this->once())
			->method('generateToken')
			->with(
				'MyGeneratedToken',
				'MyUid',
				'MyLoginName',
				null,
				'unknown',
				IToken::PERMANENT_TOKEN,
				IToken::DO_NOT_REMEMBER
			);
		$this->request
			->expects($this->once())
			->method('getServerProtocol')
			->willReturn('http');
		$this->request
			->expects($this->once())
			->method('getServerHost')
			->willReturn('example.com');
		$this->request
			->expects($this->any())
			->method('getHeader')
			->willReturn('');

		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped');

		$expected = new RedirectResponse('nc://login/server:http://example.com&user:MyLoginName&password:MyGeneratedToken');
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken'));
	}

	public static function dataGeneratePasswordWithHttpsProxy(): array {
		return [
			[
				[
					['X-Forwarded-Proto', 'http'],
					['X-Forwarded-Ssl', 'off'],
					['user-agent', ''],
				],
				'http',
				'http',
			],
			[
				[
					['X-Forwarded-Proto', 'http'],
					['X-Forwarded-Ssl', 'off'],
					['user-agent', ''],
				],
				'https',
				'https',
			],
			[
				[
					['X-Forwarded-Proto', 'https'],
					['X-Forwarded-Ssl', 'off'],
					['user-agent', ''],
				],
				'http',
				'https',
			],
			[
				[
					['X-Forwarded-Proto', 'https'],
					['X-Forwarded-Ssl', 'on'],
					['user-agent', ''],
				],
				'http',
				'https',
			],
			[
				[
					['X-Forwarded-Proto', 'http'],
					['X-Forwarded-Ssl', 'on'],
					['user-agent', ''],
				],
				'http',
				'https',
			],
		];
	}

	/**
	 * @param array $headers
	 * @param string $protocol
	 * @param string $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataGeneratePasswordWithHttpsProxy')]
	public function testGeneratePasswordWithHttpsProxy(array $headers, $protocol, $expected): void {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn('MyStateToken');
		$this->session
			->expects($this->once())
			->method('remove')
			->with('client.flow.state.token');
		$this->session
			->expects($this->once())
			->method('getId')
			->willReturn('SessionId');
		$myToken = $this->createMock(IToken::class);
		$myToken
			->expects($this->once())
			->method('getLoginName')
			->willReturn('MyLoginName');
		$this->tokenProvider
			->expects($this->once())
			->method('getToken')
			->with('SessionId')
			->willReturn($myToken);
		$this->tokenProvider
			->expects($this->once())
			->method('getPassword')
			->with($myToken, 'SessionId')
			->willReturn('MyPassword');
		$this->random
			->expects($this->once())
			->method('generate')
			->with(72)
			->willReturn('MyGeneratedToken');
		$user = $this->createMock(IUser::class);
		$user
			->expects($this->once())
			->method('getUID')
			->willReturn('MyUid');
		$this->userSession
			->expects($this->once())
			->method('getUser')
			->willReturn($user);
		$this->tokenProvider
			->expects($this->once())
			->method('generateToken')
			->with(
				'MyGeneratedToken',
				'MyUid',
				'MyLoginName',
				'MyPassword',
				'unknown',
				IToken::PERMANENT_TOKEN,
				IToken::DO_NOT_REMEMBER
			);
		$this->request
			->expects($this->once())
			->method('getServerProtocol')
			->willReturn($protocol);
		$this->request
			->expects($this->once())
			->method('getServerHost')
			->willReturn('example.com');
		$this->request
			->expects($this->atLeastOnce())
			->method('getHeader')
			->willReturnMap($headers);

		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped');

		$expected = new RedirectResponse('nc://login/server:' . $expected . '://example.com&user:MyLoginName&password:MyGeneratedToken');
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken'));
	}
}
