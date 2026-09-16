<?php

declare(strict_types=1);

/*
 * Typed exception for HTTP 403 responses (and any error factory that wraps the
 * ErrForbidden sentinel).
 */

namespace LumeWeb\Portal\Http;

final class ForbiddenException extends PortalException
{
}
