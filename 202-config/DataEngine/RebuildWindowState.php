<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

use Prosper202\Lifecycle\Moves;
use Prosper202\Lifecycle\State;
use Prosper202\Lifecycle\UnknownState;

/**
 * Where a report rebuild window (a 202_dataengine_job row) is. The table has
 * no state column: the state is its two flags, `processing` and `processed`,
 * read here and nowhere else. The values are the names the API has always
 * answered (GET /system/dataengine, `status`).
 *
 * A window is rebuilt by one run at a time: the run that claims it
 * (RebuildWindows::take()) finishes or releases it. Rolling its clicks up
 * again is harmless (the roll-up replaces), so a released window is simply
 * taken again.
 */
enum RebuildWindowState: string implements State
{
    use Moves;

    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';

    /** Each state as a WHERE condition and as a SET list, for RebuildWindows' statements. */
    public const IS_PENDING = 'processing = 0 AND processed = 0';
    public const IS_PROCESSING = 'processing <> 0 AND processed = 0';
    public const IS_COMPLETED = 'processed <> 0';
    public const SET_PENDING = 'processing = 0, processed = 0';
    public const SET_PROCESSING = 'processing = 1, processed = 0';
    public const SET_COMPLETED = 'processing = 0, processed = 1';

    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Processing],
            // Back to Pending when the roll-up failed, or its run ended without saying.
            self::Processing => [self::Completed, self::Pending],
            self::Completed => [],
        };
    }

    public static function initial(): array
    {
        return [self::Pending];
    }

    /**
     * A row's flags as its state. `processed` wins: a row with both set,
     * which no writer leaves, was read as done by every reader.
     *
     * @throws UnknownState for a flag that is not a whole number
     */
    public static function ofRow(mixed $processing, mixed $processed, string $where = '202_dataengine_job'): self
    {
        if (self::isSet($processed, $where . '.processed')) {
            return self::Completed;
        }

        return self::isSet($processing, $where . '.processing') ? self::Processing : self::Pending;
    }

    /** ofRow() as a WHERE condition (RebuildWindowsIntegrationTest holds the two together). */
    public function condition(): string
    {
        return match ($this) {
            self::Pending => self::IS_PENDING,
            self::Processing => self::IS_PROCESSING,
            self::Completed => self::IS_COMPLETED,
        };
    }

    /** The flags a row in this state is written with, as a SET list. */
    public function assignment(): string
    {
        return match ($this) {
            self::Pending => self::SET_PENDING,
            self::Processing => self::SET_PROCESSING,
            self::Completed => self::SET_COMPLETED,
        };
    }

    private static function isSet(mixed $flag, string $where): bool
    {
        if (is_int($flag) || (is_string($flag) && preg_match('/\A-?\d+\z/', $flag) === 1)) {
            return (int) $flag !== 0;
        }
        throw UnknownState::of(self::class, $flag, $where);
    }
}
