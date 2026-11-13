<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use Prosper202\Database\Connection;

/**
 * A conversion whose own request withheld consent (p202_consent, read by
 * RequestSignals::consentGiven() alone): 202_conversion_logs.consent_withheld
 * is 1 on its row, written by the row's INSERT, so the row and the refusal
 * commit together or not at all. A later request of the same conversion
 * (a duplicate) that refuses sets it to 1; nothing sets it back to 0. No
 * destination is sent such a conversion (DestinationQueue::deliver()); the
 * install's own reports count it. A goal event keeps its request's refusal
 * in 202_goal_events.consent_withheld, which every ledger row its outcomes
 * write, first or again, carries. A consenting request leaves the 0.
 */
final class ConsentWithheld
{
    public const COLUMN = 'consent_withheld';

    /** @var \WeakMap<\mysqli, array<string, true>>|null handles whose table was seen to have the column */
    private static ?\WeakMap $present = null;

    private function __construct()
    {
    }

    /**
     * What a writer was handed: absent is consent given, as the parameter's
     * absence is; anything but a bool is refused (CLAUDE.md #4).
     *
     * @param array<string, mixed> $data
     */
    public static function from(array $data): bool
    {
        $value = $data[self::COLUMN] ?? false;
        if (!is_bool($value)) {
            throw new \RuntimeException(self::COLUMN . ' must be true or false');
        }

        return $value;
    }

    /**
     * Whether the 1.9.80 upgrade has added the column to $table
     * (202_conversion_logs or 202_goal_events). Before it, no destination
     * exists to send to; a ledger row that cannot carry its refusal is not
     * queued for one (MysqlConversionRepository::recordLocked()).
     */
    public static function columnPresent(Connection $conn, string $table = '202_conversion_logs'): bool
    {
        $handle = $conn->writeConnection();
        if (isset(self::$present[$handle][$table])) {
            return true;
        }
        try {
            $stmt = $conn->prepareWrite('SELECT ' . self::COLUMN . ' FROM `' . $table . '` LIMIT 0');
        } catch (\RuntimeException $e) {
            for ($current = $e; $current !== null; $current = $current->getPrevious()) {
                if (
                    Connection::isMysqlError($current, 1054, '')
                    && str_contains($current->getMessage(), "'" . self::COLUMN . "'")
                ) {
                    return false;
                }
            }
            throw $e;
        }
        if ($stmt instanceof \mysqli_stmt) {
            $stmt->close();
        }
        self::$present ??= new \WeakMap();
        self::$present[$handle] = [$table => true] + (self::$present[$handle] ?? []);

        return true;
    }
}
