<?php

declare(strict_types=1);

/*
 * Typed exception for HTTP 404 responses (and any error factory that wraps the
 * ErrNotFound sentinel).
 */

namespace LumeWeb\Portal\Http;

final class NotFoundException extends PortalException
{
}
