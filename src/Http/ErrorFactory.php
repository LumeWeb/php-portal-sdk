<?php

declare(strict_types=1);

/*
 * Factory for operation -> status error mappings.
 *
 * Produces the typed exception for a given operation/status pair: auth errors
 * (UnauthorizedException), plain errors (base PortalException), not-found,
 * forbidden, bad-request and conflict. A factory is stored in an OpHandler
 * operation map and, on make(), produces a typed PortalException subclass
 * carrying the HTTP status and raw response body.
 *
 * plainError() intentionally returns the base PortalException so callers can
 * distinguish a plain error (message only) from a status-typed error.
 */

namespace LumeWeb\Portal\Http;

final class ErrorFactory
{
    /**
     * @param class-string<PortalException> $class
     */
    private function __construct(
        private readonly string $class,
        private readonly string $message,
    ) {
    }

    public static function authError(string $message): self
    {
        return new self(UnauthorizedException::class, $message);
    }

    public static function plainError(string $message): self
    {
        return new self(PortalException::class, $message);
    }

    public static function notFoundError(string $message): self
    {
        return new self(NotFoundException::class, $message);
    }

    public static function forbiddenError(string $message): self
    {
        return new self(ForbiddenException::class, $message);
    }

    public static function badRequestError(string $message): self
    {
        return new self(BadRequestException::class, $message);
    }

    public static function conflictError(string $message): self
    {
        return new self(ConflictException::class, $message);
    }

    /**
     * Instantiates the configured typed exception for the given HTTP response.
     */
    public function make(int $statusCode, string $responseBody = ''): PortalException
    {
        $class = $this->class;

        return new $class($this->message, $statusCode, $responseBody);
    }
}
