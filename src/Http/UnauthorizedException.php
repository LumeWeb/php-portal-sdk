<?php

declare(strict_types=1);

/*
 * Typed exception for HTTP 401 responses (and any error factory that wraps the
 * ErrUnauthorized sentinel). Mirrors the Go SDK's sentinel-based error checks,
 * so PHP callers can catch a typed exception instead of parsing messages.
 */

namespace LumeWeb\Portal\Http;

final class UnauthorizedException extends PortalException
{
}
