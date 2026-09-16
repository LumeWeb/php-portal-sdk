<?php

declare(strict_types=1);

/*
 * Unit tests for the account value objects returned by the client.
 */

namespace LumeWeb\Portal\Tests\Account;

use LumeWeb\Portal\Account\ApiKey;
use LumeWeb\Portal\Account\LoginResult;
use PHPUnit\Framework\TestCase;

final class AccountValueObjectsTest extends TestCase
{
    public function testLoginResultCarriesAllFields(): void
    {
        $result = new LoginResult('token-1', true, 'token-1');

        self::assertSame('token-1', $result->token);
        self::assertTrue($result->otpRequired);
        self::assertSame('token-1', $result->intermediateJwt);
    }

    public function testApiKeyCarriesCreatedAndLastUsed(): void
    {
        $key = new ApiKey('my key', 'tok', 'uuid-1', '2025-01-01', '2025-02-01');

        self::assertSame('my key', $key->name);
        self::assertSame('tok', $key->token);
        self::assertSame('uuid-1', $key->uuid);
        self::assertSame('2025-01-01', $key->createdAt);
        self::assertSame('2025-02-01', $key->lastUsedAt);
    }
}
