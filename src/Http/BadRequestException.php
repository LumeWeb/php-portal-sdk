<?php

declare(strict_types=1);

/*
 * Typed exception for HTTP 400 responses (and any error factory that maps a
 * bad request to a typed exception).
 */

namespace LumeWeb\Portal\Http;

final class BadRequestException extends PortalException
{
}
