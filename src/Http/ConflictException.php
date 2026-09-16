<?php

declare(strict_types=1);

/*
 * Typed exception for HTTP 409 responses (and any error factory that wraps the
 * ErrConflict sentinel).
 */

namespace LumeWeb\Portal\Http;

final class ConflictException extends PortalException
{
}
