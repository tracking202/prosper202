<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use Prosper202\Database\Connection;

/**
 * A conversion whose own request withheld consent (p202_consent, read by
 * RequestSignals::consentGiven() alone): 202_conversion_logs.consent_withheld
 * is 1 on its row, written by the row's INSERT, so the row and the refusal
 * commit together or not at all. Its destinations are sent the conversion
 * with no user data: no address, no hashed email or phone, no customer id
 * (DestinationQueue::deliver()). A consenting conversion leaves the
 * column's 0.
 */
final class ConsentWithheld
{
    public const COLUMN = 'consent_withheld';

    /** @var \WeakMap<\mysqli, true>|null */
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
     * Whether the 1.9.80 upgrade has added the column. Before it, no
     * destination exists to send to; a row that cannot carry its refusal is
     * not queued for one (MysqlConversionRepository::recordLocked()).
     */
    public static function columnPresent(Connection $conn): bool
    {
        $handle = $conn->writeConnection();
        if (isset(self::$present[$handle])) {
            return true;
        }
        try {
            $stmt = $conn->prepareWrite('SELECT ' . self::COLUMN . ' FROM 202_conversion_logs LIMIT 0');
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
        self::$present[$handle] = true;

        return true;
    }
}
