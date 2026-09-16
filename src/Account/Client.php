<?php

declare(strict_types=1);

/*
 * Handwritten account client wrapping the generated DefaultApi.
 *
 * Exposes the core account operations: login (with or without 2FA), API-key
 * login, OTP validation, ping, OTP generate/verify/disable and registration.
 *
 * Individual calls never repeat status/error checking: every operation
 * delegates to a single OpHandler that holds the operation -> status error
 * maps and maps non-success status codes to typed PortalException subclasses
 * (UnauthorizedException, ForbiddenException, BadRequestException,
 * ConflictException, ...).
 */

namespace LumeWeb\Portal\Account;

use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\ClientInterface;
use LumeWeb\Portal\Generated\Account\Api\DefaultApi;
use LumeWeb\Portal\Generated\Account\Configuration;
use LumeWeb\Portal\Generated\Account\Model\LoginRequest;
use LumeWeb\Portal\Generated\Account\Model\LoginResponse;
use LumeWeb\Portal\Generated\Account\Model\OTPDisableRequest;
use LumeWeb\Portal\Generated\Account\Model\OTPValidateRequest;
use LumeWeb\Portal\Generated\Account\Model\OTPVerifyRequest;
use LumeWeb\Portal\Generated\Account\Model\RegisterRequest;
use LumeWeb\Portal\Generated\Account\ObjectSerializer;
use LumeWeb\Portal\Http\ErrorFactory;
use LumeWeb\Portal\Http\OpHandler;
use LumeWeb\Portal\Http\PortalException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Client
{
    /**
     * Default endpoint for the account service.
     */
    public const DEFAULT_ENDPOINT = 'https://account.pinner.xyz';

    /**
     * Operation IDs used to look up each operation's name and error map in the
     * shared OpHandler.
     */
    public const OP_LOGIN = 0;
    public const OP_OTP_VALIDATION = 1;
    public const OP_PING = 2;
    public const OP_OTP_GENERATION = 3;
    public const OP_OTP_VERIFICATION = 4;
    public const OP_OTP_DISABLE = 5;
    public const OP_API_KEY_LOGIN = 6;
    public const OP_REGISTRATION = 7;

    private Configuration $config;
    private DefaultApi $api;
    private ClientInterface $http;
    private OpHandler $handler;
    private ?string $jwt = null;

    /**
     * @param string $endpoint Full account API base URL (defaults to DEFAULT_ENDPOINT);
     *                         a bare host, e.g. "account.pinner.xyz", is normalized to https.
     * @param string|null $jwt Optional JWT auth token to send as Bearer on
     *                         authenticated calls (can be updated via setAuthToken).
     * @param ClientInterface|null $http Guzzle client to use (injected in tests,
     *                         defaults to a fresh Guzzle client).
     */
    public function __construct(string $endpoint = self::DEFAULT_ENDPOINT, ?string $jwt = null, ?ClientInterface $http = null)
    {
        $endpoint = \rtrim($endpoint, '/');
        if (!\preg_match('#^https?://#', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }

        $this->config = (new Configuration())->setHost($endpoint);
        $this->http = $http ?? new GuzzleHttpClient();
        $this->api = new DefaultApi($this->http, $this->config);
        $this->handler = new OpHandler();

        $this->registerOperations();

        if ($jwt !== null) {
            $this->setAuthToken($jwt);
        }
    }

    /**
     * Sets (or hot-swaps) the JWT sent as Authorization: Bearer on
     * authenticated calls.
     */
    public function setAuthToken(string $token): void
    {
        $this->jwt = $token;
    }

    /**
     * Authenticates with email/password.
     *
     * Without 2FA the account service responds with a 302 redirect carrying the
     * JWT as an auth_token cookie or an auth_token Location query parameter.
     * With 2FA it responds 200 with a (intermediate) JWT and otp=true.
     *
     * @return LoginResult Result with token, otpRequired and intermediateJwt.
     *
     * @throws PortalException on a failed/error response (mapped by OpHandler).
     */
    public function login(string $email, string $password): LoginResult
    {
        $request = $this->api->apiAuthLoginPostRequest(
            new LoginRequest(['email' => $email, 'password' => $password])
        );

        $response = $this->send($request, self::OP_LOGIN, [200, 302]);

        // Non-OTP login returns 302 with the JWT in the redirect.
        if ($response->getStatusCode() === 302) {
            $token = $this->extractTokenFromRedirect($response);

            return new LoginResult($token, false, $token);
        }

        $login = ObjectSerializer::deserialize(
            \json_decode($this->responseBody($response), true) ?: null,
            LoginResponse::class,
        );

        if (!$login instanceof LoginResponse || (string) $login->getToken() === '') {
            throw new PortalException('login response did not contain a token');
        }

        return new LoginResult(
            (string) $login->getToken(),
            $login->getOtp() === true,
            (string) $login->getToken(),
        );
    }

    /**
     * Authenticates using an API key and returns a JWT token.
     *
     * The API key is sent raw in the Authorization header (no "Bearer "
     * prefix).
     *
     * @throws PortalException on a failed/error response (mapped by OpHandler).
     */
    public function loginWithApiKey(string $apiKey): string
    {
        $request = $this->api->apiAuthKeyPostRequest($apiKey);

        $response = $this->send($request, self::OP_API_KEY_LOGIN, [200]);

        $login = ObjectSerializer::deserialize(
            \json_decode($this->responseBody($response), true) ?: null,
            LoginResponse::class,
        );

        if (!$login instanceof LoginResponse || (string) $login->getToken() === '') {
            throw new PortalException('API key login response did not contain a token');
        }

        return (string) $login->getToken();
    }

    /**
     * Completes 2FA login using an intermediate JWT and OTP code, returning the
     * final JWT.
     *
     * @throws PortalException on a failed/error response (mapped by OpHandler).
     */
    public function validateOtp(string $intermediateJwt, string $otp): string
    {
        $request = $this->api->apiAuthOtpValidatePostRequest(
            new OTPValidateRequest(['otp' => $otp])
        );
        $request = $request->withHeader('Authorization', 'Bearer ' . $intermediateJwt);

        $response = $this->send($request, self::OP_OTP_VALIDATION, [302]);

        return $this->extractTokenFromRedirect($response);
    }

    /**
     * Verifies the JWT token is valid. The current auth token is sent as
     * Authorization: Bearer when set.
     *
     * @throws PortalException on a failed/error response (mapped by OpHandler).
     */
    public function ping(): void
    {
        $this->send(
            $this->api->apiAuthPingPostRequest(),
            self::OP_PING,
            [200],
        );
    }

    /**
     * Generates a new OTP secret for 2FA setup.
     *
     * @throws PortalException on a failed/error response (mapped by OpHandler).
     */
    public function generateOtp(): string
    {
        $response = $this->send(
            $this->api->apiAuthOtpGeneratePostRequest(),
            self::OP_OTP_GENERATION,
            [200],
        );

        $otp = \json_decode($this->responseBody($response), true)['otp'] ?? '';

        if (!\is_string($otp) || $otp === '') {
            throw new PortalException('OTP generation response did not contain a secret');
        }

        return $otp;
    }

    /**
     * Verifies an OTP code and enables 2FA for the account.
     *
     * @throws PortalException on a failed/error response (mapped by OpHandler).
     */
    public function verifyOtp(string $otp): void
    {
        $this->send(
            $this->api->apiAuthOtpVerifyPostRequest(new OTPVerifyRequest(['otp' => $otp])),
            self::OP_OTP_VERIFICATION,
            [204],
        );
    }

    /**
     * Disables 2FA for the account.
     *
     * @throws PortalException on a failed/error response (mapped by OpHandler).
     */
    public function disableOtp(string $password): void
    {
        $this->send(
            $this->api->apiAuthOtpDisablePostRequest(new OTPDisableRequest(['password' => $password])),
            self::OP_OTP_DISABLE,
            [204],
        );
    }

    /**
     * Registers a new user account.
     *
     * @throws PortalException on a failed/error response (mapped by OpHandler).
     */
    public function register(string $email, string $firstName, string $lastName, string $password): void
    {
        $this->send(
            $this->api->apiAuthRegisterPostRequest(new RegisterRequest([
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password' => $password,
            ])),
            self::OP_REGISTRATION,
            [201, 200],
        );
    }

    /**
     * Sends a pre-built request with redirect-following disabled so 302
     * responses (token redirects) are observed, then delegates all status/error
     * handling to the OpHandler. Non-success responses throw the mapped typed
     * exception.
     *
     * @param RequestInterface $request     PSR-7 request built by the generated API.
     * @param int              $opId        Operation ID for error map / name lookups.
     * @param int[]            $successCodes Status codes that indicate success.
     */
    private function send(RequestInterface $request, int $opId, array $successCodes): ResponseInterface
    {
        if ($this->jwt !== null && !$request->hasHeader('Authorization')) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->jwt);
        }

        $response = $this->http->send($request, [
            'http_errors' => false,
            'allow_redirects' => false,
        ]);

        $exception = $this->handler->handleResponse(
            $response->getStatusCode(),
            $this->responseBody($response),
            $opId,
            $successCodes,
        );

        if ($exception !== null) {
            throw $exception;
        }

        return $response;
    }

    /**
     * Reads the (possibly already-consumed) response body as a string.
     */
    private function responseBody(ResponseInterface $response): string
    {
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return (string) $stream;
    }

    /**
     * Extracts the JWT from a 302 redirect response. Checks the auth_token
     * Set-Cookie header first, then the auth_token query parameter in the
     * Location header.
     */
    private function extractTokenFromRedirect(ResponseInterface $response): string
    {
        foreach ($response->getHeader('Set-Cookie') as $cookieHeader) {
            foreach (\explode(';', $cookieHeader) as $part) {
                $part = \trim($part);
                if (\str_starts_with($part, 'auth_token=')) {
                    $value = \substr($part, \strlen('auth_token='));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        $location = $response->getHeaderLine('Location');
        if ($location !== '') {
            $query = \parse_url($location, PHP_URL_QUERY);
            if (\is_string($query) && $query !== '') {
                foreach (\explode('&', $query) as $pair) {
                    $parts = \explode('=', $pair, 2);
                    if ($parts[0] === 'auth_token' && isset($parts[1]) && $parts[1] !== '') {
                        return \rawurldecode($parts[1]);
                    }
                }
            }
        }

        throw new PortalException('no auth token found in cookies or redirect location');
    }

    /**
     * Registers operation names and operation -> status error factories on the
     * OpHandler.
     */
    private function registerOperations(): void
    {
        $this->handler->setName(self::OP_LOGIN, 'login');
        $this->handler->setName(self::OP_OTP_VALIDATION, 'OTP validation');
        $this->handler->setName(self::OP_PING, 'ping');
        $this->handler->setName(self::OP_OTP_GENERATION, 'OTP generation');
        $this->handler->setName(self::OP_OTP_VERIFICATION, 'OTP verification');
        $this->handler->setName(self::OP_OTP_DISABLE, 'OTP disable');
        $this->handler->setName(self::OP_API_KEY_LOGIN, 'API key login');
        $this->handler->setName(self::OP_REGISTRATION, 'registration');

        $this->handler->addOperation(self::OP_LOGIN, [
            401 => ErrorFactory::authError('invalid login credentials'),
        ]);

        $this->handler->addOperation(self::OP_OTP_VALIDATION, [
            400 => ErrorFactory::plainError('invalid OTP code'),
            401 => ErrorFactory::authError('invalid or expired 2FA session'),
        ]);

        $this->handler->addOperation(self::OP_PING, [
            401 => ErrorFactory::authError('invalid JWT token'),
        ]);

        $this->handler->addOperation(self::OP_OTP_GENERATION, [
            401 => ErrorFactory::authError('authentication required'),
        ]);

        $this->handler->addOperation(self::OP_OTP_VERIFICATION, [
            401 => ErrorFactory::authError('authentication required'),
            400 => ErrorFactory::plainError('invalid OTP code'),
        ]);

        $this->handler->addOperation(self::OP_OTP_DISABLE, [
            401 => ErrorFactory::authError('authentication required or invalid password'),
        ]);

        $this->handler->addOperation(self::OP_API_KEY_LOGIN, [
            401 => ErrorFactory::authError('invalid API key'),
            403 => ErrorFactory::forbiddenError('account is pending deletion'),
        ]);

        $this->handler->addOperation(self::OP_REGISTRATION, [
            409 => ErrorFactory::plainError('user already exists with this email'),
        ]);
    }
}
