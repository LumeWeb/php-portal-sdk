<?php

declare(strict_types=1);

/*
 * Typed exception for HTTP 503 responses (and any error factory that maps an
 * unavailable service to a typed exception).
 */

namespace LumeWeb\Portal\Http;

final class UnavailableException extends PortalException
{
}
