<?php

declare(strict_types=1);

/*
 * Base exception for all Portal SDK operations.
 *
 * Status/error checking is centralized in OpHandler: every non-success HTTP
 * response surfaces as a PortalException (or a typed subclass) carrying the
 * HTTP status code and the raw response body, so callers can branch on the
 * exception type, the message, or the underlying response.
 */

namespace LumeWeb\Portal\Http;

class PortalException extends \RuntimeException
{
    /**
     * @param string $message      Human-readable error message
     * @param int    $statusCode   HTTP status code that triggered the error
     * @param string $responseBody Raw response body as returned by the API
     */
    public function __construct(
        string $message = '',
        public int $statusCode = 0,
        public string $responseBody = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
