<?php

declare(strict_types=1);

/*
 * Unit tests for the centralized HTTP response/error handler.
 *
 * Success codes pass through, operation -> status error factories map to typed
 * PHP exceptions, HTTP sentinels fall back to typed exceptions, and anything
 * else becomes a generic PortalException carrying the operation name, status
 * code and response body.
 */

namespace LumeWeb\Portal\Tests\Http;

use LumeWeb\Portal\Http\ConflictException;
use LumeWeb\Portal\Http\ErrorFactory;
use LumeWeb\Portal\Http\ForbiddenException;
use LumeWeb\Portal\Http\InternalServerException;
use LumeWeb\Portal\Http\NotFoundException;
use LumeWeb\Portal\Http\OpHandler;
use LumeWeb\Portal\Http\PortalException;
use LumeWeb\Portal\Http\UnauthorizedException;
use PHPUnit\Framework\TestCase;

final class OpHandlerTest extends TestCase
{
    private OpHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new OpHandler();
        $this->handler->setName(0, 'login');
        $this->handler->addOperation(0, [
            401 => ErrorFactory::authError('invalid login credentials'),
        ]);
        $this->handler->addOperation(1, [
            409 => ErrorFactory::plainError('user already exists with this email'),
        ]);
    }

    public function testReturnsNullForSuccessCode(): void
    {
        self::assertNull($this->handler->handleResponse(200, '{}', 0, [200]));
    }

    public function testReturnsNullWhenAnySuccessCodeMatches(): void
    {
        self::assertNull($this->handler->handleResponse(302, '', 9, [200, 302]));
    }

    public function testReturnsOperationSpecificAuthenticatedError(): void
    {
        $err = $this->handler->handleResponse(401, '{}', 0, [200]);

        self::assertInstanceOf(UnauthorizedException::class, $err);
        self::assertSame('invalid login credentials', $err->getMessage());
        self::assertSame(401, $err->statusCode);
    }

    public function testReturnsPlainErrorWithoutSentinelWrapping(): void
    {
        $err = $this->handler->handleResponse(409, '{}', 1, [200]);

        self::assertInstanceOf(PortalException::class, $err);
        self::assertNotInstanceOf(ConflictException::class, $err);
        self::assertSame('user already exists with this email', $err->getMessage());
    }

    public function testWrappedFactoryUsesSentinelType(): void
    {
        $this->handler->addOperation(2, [
            404 => ErrorFactory::notFoundError('user not found'),
        ]);

        $err = $this->handler->handleResponse(404, '', 2, [200]);

        self::assertInstanceOf(NotFoundException::class, $err);
        self::assertSame('user not found', $err->getMessage());
    }

    public function testSentinelFallbackForUnknownStatus(): void
    {
        $err = $this->handler->handleResponse(500, 'boom', 9, [200]);

        self::assertInstanceOf(InternalServerException::class, $err);
        self::assertSame('boom', $err->getMessage());
    }

    public function testSentinelFallbackCoversAllMappedStatuses(): void
    {
        $cases = [
            [400, \LumeWeb\Portal\Http\BadRequestException::class],
            [401, UnauthorizedException::class],
            [403, ForbiddenException::class],
            [404, NotFoundException::class],
            [409, ConflictException::class],
            [500, InternalServerException::class],
            [503, \LumeWeb\Portal\Http\UnavailableException::class],
        ];
        foreach ($cases as [$status, $class]) {
            $err = $this->handler->handleResponse($status, 'x', 9, [200]);
            self::assertInstanceOf($class, $err, "status {$status}");
        }
    }

    public function testGenericErrorUsesOperationNameStatusAndBody(): void
    {
        $err = $this->handler->handleResponse(502, 'bad gateway body', 0, [200, 302]);

        self::assertSame('login failed with status 502: bad gateway body', $err->getMessage());
        self::assertSame(502, $err->statusCode);
        self::assertSame('bad gateway body', $err->responseBody);
    }

    public function testGenericErrorFallsBackToDefaultOperationName(): void
    {
        $err = $this->handler->handleResponse(502, 'x', 99, [200]);

        self::assertSame('operation failed with status 502: x', $err->getMessage());
    }

    public function testValidateJson200ReturnsNullOnSuccess(): void
    {
        self::assertNull($this->handler->validateJson200(200, '{}', 0));
    }

    public function testValidateJson200MapsUnauthorizedToAuthenticationRequired(): void
    {
        $err = $this->handler->validateJson200(401, '{}', 0);

        self::assertInstanceOf(UnauthorizedException::class, $err);
        self::assertSame('authentication required', $err->getMessage());
    }

    public function testValidateJson200UsesSentinelForNotFound(): void
    {
        $err = $this->handler->validateJson200(404, 'nope', 9);

        self::assertInstanceOf(NotFoundException::class, $err);
    }

    public function testValidateJson200UsesExpectedStatusGenericMessage(): void
    {
        $err = $this->handler->validateJson200(422, 'invalid', 9);

        self::assertInstanceOf(PortalException::class, $err);
        self::assertSame('expected status 200, got 422: invalid', $err->getMessage());
    }

    public function testValidateJson201(): void
    {
        self::assertNull($this->handler->validateJson201(201, '{}', 9));

        $err = $this->handler->validateJson201(200, '{}', 9);
        self::assertSame('expected status 201, got 200: {}', $err->getMessage());
    }
}
