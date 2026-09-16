<?php

declare(strict_types=1);

/*
 * Typed exception for HTTP 500 responses (and any error factory that wraps the
 * ErrInternalServer sentinel).
 */

namespace LumeWeb\Portal\Http;

final class InternalServerException extends PortalException
{
}
