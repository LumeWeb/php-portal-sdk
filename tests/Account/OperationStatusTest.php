<?php

declare(strict_types=1);

/*
 * Unit tests for the operation status helpers: the status constants,
 * defaultSettledStates() and isSettled().
 */

namespace LumeWeb\Portal\Tests\Account;

use LumeWeb\Portal\Account\OperationStatus;
use PHPUnit\Framework\TestCase;

final class OperationStatusTest extends TestCase
{
    public function testDefaultSettledStates(): void
    {
        self::assertSame(
            [OperationStatus::COMPLETED, OperationStatus::FAILED, OperationStatus::DUPLICATE],
            OperationStatus::defaultSettledStates(),
        );
    }

    public function testIsSettled(): void
    {
        self::assertTrue(OperationStatus::isSettled(OperationStatus::COMPLETED));
        self::assertTrue(OperationStatus::isSettled(OperationStatus::FAILED));
        self::assertTrue(OperationStatus::isSettled(OperationStatus::DUPLICATE));

        self::assertFalse(OperationStatus::isSettled(OperationStatus::PENDING));
        self::assertFalse(OperationStatus::isSettled(OperationStatus::PROCESSING));
    }
}
