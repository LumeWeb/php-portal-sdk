<?php

declare(strict_types=1);

/*
 * Typed exception for HTTP 500 responses (and any error factory that maps an
 * internal server error to a typed exception).
 */

namespace LumeWeb\Portal\Http;

final class InternalServerException extends PortalException
{
}
