<?php

declare(strict_types=1);

/*
 * Typed exception for HTTP 403 responses (and any error factory that maps a
 * forbidden request to a typed exception).
 */

namespace LumeWeb\Portal\Http;

final class ForbiddenException extends PortalException
{
}
