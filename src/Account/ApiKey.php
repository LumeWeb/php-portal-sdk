<?php

declare(strict_types=1);

/*
 * An API key returned by the account service.
 *
 * Carries the key's name, token, uuid, createdAt and lastUsedAt fields.
 */

namespace LumeWeb\Portal\Account;

final class ApiKey
{
    public function __construct(
        public string $name,
        public string $token,
        public string $uuid,
        public string $createdAt,
        public string $lastUsedAt,
    ) {
    }
}
