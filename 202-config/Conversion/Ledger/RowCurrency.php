<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use Api\V3\Controllers\UsersController;
use Prosper202\Database\Connection;

/**
 * The currency a ledger row's amount is in (202_conversion_logs.currency).
 *
 * Every row is stamped when it is written, so a later change of the
 * account's currency does not relabel it. The one "none" is '' (UNKNOWN):
 * a row written while the 1.9.80 upgrade had not added the column, or one
 * whose account had no preferences row to read when the upgrade backfilled
 * it. Every reader takes a stored value through read(), which answers a
 * three-letter code or UNKNOWN and nothing else.
 */
final class RowCurrency
{
    public const UNKNOWN = '';

    /** The ledger's currency columns, as a SELECT list names them. */
    public const COLUMNS = 'currency, stated_amount, stated_currency';

    /** @var \WeakMap<\mysqli, true>|null handles whose table was seen to have the columns */
    private static ?\WeakMap $present = null;

    private function __construct()
    {
    }

    /** A stored currency: its code, or UNKNOWN for anything that is not one. */
    public static function read(mixed $stored): string
    {
        $code = is_string($stored) ? strtoupper(trim($stored)) : '';

        return preg_match('/\A[A-Z]{3}\z/', $code) === 1 ? $code : self::UNKNOWN;
    }

    /** The same for an API answer: the code, or null when unknown. */
    public static function forAnswer(mixed $stored): ?string
    {
        $code = self::read($stored);

        return $code === self::UNKNOWN ? null : $code;
    }

    /**
     * The account's currency now, as every page and report reads it
     * (UsersController::normalizeCurrency()). Read on the write connection:
     * a ledger writer calls it inside its transaction, after the click's lock.
     */
    public static function ofAccount(Connection $conn, int $userId): string
    {
        $stmt = $conn->prepareWrite('SELECT user_account_currency FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);

        return UsersController::normalizeCurrency($row['user_account_currency'] ?? null);
    }

    /** How many of the account's ledger rows have no currency recorded. */
    public static function unrecorded(Connection $conn, int $userId): int
    {
        $stmt = $conn->prepareWrite("SELECT COUNT(*) AS n FROM 202_conversion_logs WHERE user_id = ? AND currency = ''");
        $conn->bind($stmt, 'i', [$userId]);

        return (int) ($conn->fetchOne($stmt)['n'] ?? 0);
    }

    /**
     * Record $currency on the account's rows that have none, on the owner's
     * word: the upgrade leaves a row unrecorded when it cannot tell which
     * currency the account counted in when the row was written, and only
     * the owner knows. In batches, so no write to a conversion waits long;
     * returns how many rows were stamped and how many are still to do
     * (above 0 only when $maxBatches ran out: ask again).
     *
     * @return array{stamped: int, left: int}
     */
    public static function recordForUnrecorded(Connection $conn, int $userId, string $currency, int $maxBatches = 200): array
    {
        if (self::read($currency) !== $currency || $currency === self::UNKNOWN) {
            throw new \InvalidArgumentException('"' . $currency . '" is not a currency code');
        }
        $stamped = 0;
        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $stmt = $conn->prepareWrite("UPDATE 202_conversion_logs SET currency = ? WHERE user_id = ? AND currency = '' LIMIT 20000");
            $conn->bind($stmt, 'si', [$currency, $userId]);
            $changed = $conn->executeUpdate($stmt);
            $stamped += $changed;
            if ($changed < 20000) {
                break;
            }
        }

        return ['stamped' => $stamped, 'left' => self::unrecorded($conn, $userId)];
    }

    /**
     * Whether 202_conversion_logs has the currency columns yet. The pixels
     * and postbacks do not wait for the upgrade, so a writer can run before
     * it: then the row is written as before 1.9.80, without a currency, and
     * the upgrade's backfill stamps it. Asked by preparing a statement that
     * names the columns and is never executed, so it reads nothing (and
     * takes no snapshot). Yes is remembered for the handle; no is asked
     * again, since the upgrade can run at any moment. Any failure but the
     * server's "unknown column" is the caller's.
     */
    public static function columnsPresent(Connection $conn): bool
    {
        $handle = $conn->writeConnection();
        if (isset(self::$present[$handle])) {
            return true;
        }
        try {
            $stmt = $conn->prepareWrite('SELECT ' . self::COLUMNS . ' FROM 202_conversion_logs LIMIT 0');
        } catch (\RuntimeException $e) {
            if (!self::columnMissing($e)) {
                throw $e;
            }
            error_log('p202 conversions: the 1.9.80 upgrade has not added the ledger\'s currency columns yet, so'
                . ' conversions are written and read without their currency until it does, and it records them then.'
                . ' Run the upgrade.');

            return false;
        }
        if ($stmt instanceof \mysqli_stmt) {
            $stmt->close();
        }
        self::$present ??= new \WeakMap();
        self::$present[$handle] = true;

        return true;
    }

    /** Whether $e, or one it wraps, is the server's 1054 for one of the currency columns. */
    public static function columnMissing(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (!Connection::isMysqlError($current, 1054, '')) {
                continue;
            }
            foreach (["'currency'", "'stated_amount'", "'stated_currency'"] as $column) {
                if (str_contains($current->getMessage(), $column)) {
                    return true;
                }
            }
        }

        return false;
    }
}
