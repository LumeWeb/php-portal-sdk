<?php

declare(strict_types=1);

/*
 * Typed exception for HTTP 400 responses (and any error factory that wraps the
 * ErrBadRequest sentinel).
 */

namespace LumeWeb\Portal\Http;

final class BadRequestException extends PortalException
{
}
