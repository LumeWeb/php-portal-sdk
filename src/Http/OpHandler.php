<?php

declare(strict_types=1);

/*
 * Centralized HTTP response / error handling for Portal SDK operations.
 *
 * Mirrors the Go SDK's internal/http.OpHandler:
 *  1. a status code listed in $successCodes passes through (returns null);
 *  2. an operation -> status error factory maps to a typed exception;
 *  3. unhandled HTTP sentinel statuses fall back to typed exceptions carrying
 *     the response body;
 *  4. anything else becomes a generic PortalException with the operation name,
 *     status code and response body.
 *
 * Because all status/error checking lives here, individual client calls never
 * repeat the per-call status checks the Go SDK performs on every endpoint.
 */

namespace LumeWeb\Portal\Http;

final class OpHandler
{
    /**
     * Default operation name used in generic error messages.
     */
    public const DEFAULT_OPERATION_NAME = 'operation';

    /**
     * HTTP status -> typed exception sentinel map (Go's sentinelMap).
     *
     * @var array<int, class-string<PortalException>>
     */
    private const SENTINELS = [
        400 => BadRequestException::class,
        401 => UnauthorizedException::class,
        403 => ForbiddenException::class,
        404 => NotFoundException::class,
        409 => ConflictException::class,
        500 => InternalServerException::class,
        503 => UnavailableException::class,
    ];

    /**
     * @var array<int, array<int, ErrorFactory>>
     */
    private array $messages = [];

    /**
     * @var array<int, string>
     */
    private array $names = [];

    private string $default = self::DEFAULT_OPERATION_NAME;

    /**
     * Registers the operation -> status error factory map for an operation.
     *
     * @param array<int, ErrorFactory> $errorMap
     */
    public function addOperation(int $opId, array $errorMap): void
    {
        $this->messages[$opId] = $errorMap;
    }

    /**
     * Assigns a human-readable name to an operation ID (used in generic errors).
     */
    public function setName(int $opId, string $name): void
    {
        $this->names[$opId] = $name;
    }

    /**
     * Handles an HTTP response for an operation.
     *
     * @param int   $statusCode   HTTP status code of the response
     * @param string $body        Raw response body
     * @param int   $opId         Operation ID (for error map / name lookups)
     * @param int[] $successCodes Status codes that indicate success
     */
    public function handleResponse(int $statusCode, string $body, int $opId, array $successCodes): ?PortalException
    {
        foreach ($successCodes as $code) {
            if ($statusCode === $code) {
                return null;
            }
        }

        if (isset($this->messages[$opId][$statusCode])) {
            return $this->messages[$opId][$statusCode]->make($statusCode, $body);
        }

        $sentinelClass = self::SENTINELS[$statusCode] ?? null;
        if ($sentinelClass !== null) {
            return new $sentinelClass($body, $statusCode, $body);
        }

        $opName = $this->names[$opId] ?? $this->default;

        return new PortalException(
            \sprintf('%s failed with status %d: %s', $opName, $statusCode, $body),
            $statusCode,
            $body,
        );
    }

    /**
     * Validates a JSON 200 response (Go's ValidateJSON200).
     *
     * Returns null on success, or a typed exception otherwise. A 401 is always
     * mapped to "authentication required", then the operation map, then HTTP
     * sentinels, then a generic "expected status 200" message.
     */
    public function validateJson200(int $statusCode, string $body, int $opId): ?PortalException
    {
        return $this->validateJson($statusCode, $body, $opId, 200);
    }

    /**
     * Validates a JSON 201 response (Go's ValidateJSON201).
     */
    public function validateJson201(int $statusCode, string $body, int $opId): ?PortalException
    {
        return $this->validateJson($statusCode, $body, $opId, 201);
    }

    private function validateJson(int $statusCode, string $body, int $opId, int $expected): ?PortalException
    {
        if ($statusCode === 401) {
            return new UnauthorizedException('authentication required', 401, $body);
        }

        if ($statusCode !== $expected) {
            if (isset($this->messages[$opId][$statusCode])) {
                return $this->messages[$opId][$statusCode]->make($statusCode, $body);
            }

            $sentinelClass = self::SENTINELS[$statusCode] ?? null;
            if ($sentinelClass !== null) {
                return new $sentinelClass($body, $statusCode, $body);
            }

            return new PortalException(
                \sprintf('expected status %d, got %d: %s', $expected, $statusCode, $body),
                $statusCode,
                $body,
            );
        }

        return null;
    }
}
