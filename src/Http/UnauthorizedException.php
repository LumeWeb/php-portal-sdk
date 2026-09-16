<?php

declare(strict_types=1);

/*
 * Typed exception for HTTP 401 responses (and any error factory that maps an
 * authentication failure). Callers can catch this typed exception instead of
 * parsing response messages.
 */

namespace LumeWeb\Portal\Http;

final class UnauthorizedException extends PortalException
{
}
