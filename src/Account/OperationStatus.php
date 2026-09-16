<?php

declare(strict_types=1);

/*
 * Represents the status of an account operation.
 *
 * Mirrors the Go SDK's OperationStatus type: the string constants
 * (OperationStatusPending etc.), DefaultSettledStates and IsSettled().
 * The PHP port exposes the constants and helpers statically.
 */

namespace LumeWeb\Portal\Account;

final class OperationStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const DUPLICATE = 'duplicate';

    /**
     * The default operation statuses considered "settled" (finished).
     *
     * @return string[]
     */
    public static function defaultSettledStates(): array
    {
        return [self::COMPLETED, self::FAILED, self::DUPLICATE];
    }

    /**
     * Returns true if the given status is a settled state (finished, no longer
     * being processed).
     */
    public static function isSettled(string $status): bool
    {
        return \in_array($status, self::defaultSettledStates(), true);
    }
}
