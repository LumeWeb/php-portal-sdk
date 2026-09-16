<?php

declare(strict_types=1);

/*
 * Integration-style unit tests for the auth flows of the account client
 * (login with/without 2FA, API-key login, OTP validation), driven by a
 * Guzzle MockHandler.
 */

namespace LumeWeb\Portal\Tests\Account;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Portal\Account\Client;
use LumeWeb\Portal\Account\LoginResult;
use LumeWeb\Portal\Http\ForbiddenException;
use LumeWeb\Portal\Http\UnauthorizedException;
use PHPUnit\Framework\TestCase;

final class ClientAuthTest extends TestCase
{
    private function clientWith(array $responses): Client
    {
        $mock = new MockHandler($responses);
        $http = new GuzzleClient(['handler' => HandlerStack::create($mock)]);

        return new Client('https://account.pinner.xyz', null, $http);
    }

    public function testLoginReturnsTokenWithOtpRequired(): void
    {
        $client = $this->clientWith([
            new Response(200, [], json_encode(['token' => 'jwt-abc', 'otp' => true])),
        ]);

        $result = $client->login('a@b.com', 'pw');

        self::assertInstanceOf(LoginResult::class, $result);
        self::assertSame('jwt-abc', $result->token);
        self::assertTrue($result->otpRequired);
        self::assertSame('jwt-abc', $result->intermediateJwt);
    }

    public function testLoginWithoutOtpReturnsNoOtpRequired(): void
    {
        $client = $this->clientWith([
            new Response(200, [], json_encode(['token' => 'jwt-abc'])),
        ]);

        $result = $client->login('a@b.com', 'pw');

        self::assertFalse($result->otpRequired);
    }

    public function testLoginExtractsTokenFromSetCookieOn302(): void
    {
        $client = $this->clientWith([
            new Response(302, [
                'Set-Cookie' => 'auth_token=cookie-jwt; Path=/; HttpOnly',
                'Location' => 'https://portal.example/dashboard',
            ], ''),
        ]);

        $result = $client->login('a@b.com', 'pw');

        self::assertFalse($result->otpRequired);
        self::assertSame('cookie-jwt', $result->token);
        self::assertSame('cookie-jwt', $result->intermediateJwt);
    }

    public function testLoginExtractsTokenFromLocationQueryOn302(): void
    {
        $client = $this->clientWith([
            new Response(302, [
                'Location' => 'https://portal.example/dashboard?auth_token=loc-jwt',
            ], ''),
        ]);

        $result = $client->login('a@b.com', 'pw');

        self::assertSame('loc-jwt', $result->token);
    }

    public function testLoginThrowsUnauthorizedException(): void
    {
        $client = $this->clientWith([
            new Response(401, [], '{"error":"invalid credentials"}'),
        ]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('invalid login credentials');

        $client->login('a@b.com', 'bad');
    }

    public function testLoginWithApiKeyReturnsToken(): void
    {
        $client = $this->clientWith([
            new Response(200, [], json_encode(['token' => 'api-jwt'])),
        ]);

        self::assertSame('api-jwt', $client->loginWithApiKey('ak-123'));
    }

    public function testLoginWithApiKeyThrowsUnauthorizedException(): void
    {
        $client = $this->clientWith([
            new Response(401, [], '{}'),
        ]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('invalid API key');

        $client->loginWithApiKey('ak-bad');
    }

    public function testLoginWithApiKeyThrowsForbiddenWhenPendingDeletion(): void
    {
        $client = $this->clientWith([
            new Response(403, [], '{}'),
        ]);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('account is pending deletion');

        $client->loginWithApiKey('ak-bad');
    }

    public function testValidateOtpReturnsTokenFromRedirect(): void
    {
        $client = $this->clientWith([
            new Response(302, [
                'Set-Cookie' => 'auth_token=final-jwt; Path=/',
            ], ''),
        ]);

        self::assertSame('final-jwt', $client->validateOtp('intermediate-jwt', '123456'));
    }

    public function testValidateOtpThrowsForInvalidOtpCode(): void
    {
        $client = $this->clientWith([
            new Response(400, [], '{}'),
        ]);

        $this->expectExceptionMessage('invalid OTP code');

        $client->validateOtp('intermediate-jwt', '000000');
    }

    public function testPingSucceedsOn200(): void
    {
        $client = $this->clientWith([new Response(200, [], '{}')]);

        $client->ping();
        $this->addToAssertionCount(1);
    }

    public function testPingThrowsWhenUnauthorized(): void
    {
        $client = $this->clientWith([new Response(401, [], '{}')]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('invalid JWT token');

        $client->ping();
    }

    public function testGenerateOtpReturnsSecret(): void
    {
        $client = $this->clientWith([
            new Response(200, [], json_encode(['otp' => 'secret-123'])),
        ]);

        self::assertSame('secret-123', $client->generateOtp());
    }

    public function testVerifyOtpSucceedsOn204(): void
    {
        $client = $this->clientWith([new Response(204, [], '')]);

        $client->verifyOtp('123456');
        $this->addToAssertionCount(1);
    }

    public function testVerifyOtpThrowsOn401(): void
    {
        $client = $this->clientWith([new Response(401, [], '{}')]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('authentication required');

        $client->verifyOtp('123456');
    }

    public function testDisableOtpSucceedsOn204(): void
    {
        $client = $this->clientWith([new Response(204, [], '')]);

        $client->disableOtp('pw');
        $this->addToAssertionCount(1);
    }

    public function testRegisterSucceedsOn201(): void
    {
        $client = $this->clientWith([new Response(201, [], '{}')]);

        $client->register('a@b.com', 'Jane', 'Doe', 'pw');
        $this->addToAssertionCount(1);
    }

    public function testRegisterThrowsConflictWhenUserExists(): void
    {
        $client = $this->clientWith([new Response(409, [], '{}')]);

        $this->expectExceptionMessage('user already exists with this email');

        $client->register('a@b.com', 'Jane', 'Doe', 'pw');
    }
}
