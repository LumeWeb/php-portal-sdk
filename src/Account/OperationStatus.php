<?php

declare(strict_types=1);

/*
 * Represents the status of an account operation.
 *
 * Exposes the operation status string constants (see the class constants),
 * the default set of settled states via defaultSettledStates() and an
 * isSettled() helper for deciding whether an operation has finished.
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
