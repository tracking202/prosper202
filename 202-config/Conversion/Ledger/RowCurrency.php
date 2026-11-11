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
 *
 * currency_basis says how the currency is known: written with the row
 * (BASIS_WRITTEN), labelled by the 1.9.80 upgrade with the account's
 * currency at the time (BASIS_UPGRADE), or said by the owner
 * (BASIS_OWNER). Only rows that are not BASIS_WRITTEN, and unrecorded ones,
 * are the owner's to correct (recordBefore()).
 */
final class RowCurrency
{
    public const UNKNOWN = '';

    public const BASIS_WRITTEN = 0;
    public const BASIS_UPGRADE = 1;
    public const BASIS_OWNER = 2;

    /** The ledger's currency columns, as a SELECT list names them. */
    public const COLUMNS = 'currency, stated_amount, stated_currency, currency_basis';

    /** Rows one statement of recordBefore() changes. */
    private const BATCH = 20000;

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
     * Record, on the owner's word, that the account's conversions recorded
     * before $before (and from $since, when given) were in $currency.
     *
     * Only rows whose currency nothing wrote with them change: those the
     * upgrade labelled with the account's currency at the time, those it
     * could not label, and those the owner said before (so a mistake can be
     * said again). A row written with its currency is never touched, though
     * its conversion time is in the range: an import of old conversions
     * says its own. Each changed row is marked BASIS_OWNER.
     *
     * A reversal is in its sale's currency, so it is never chosen by its own
     * time: the reversals of every row the owner has said follow it,
     * whenever they were recorded.
     *
     * In batches, each leaving the selection (the rows it changed now hold
     * $currency on the owner's word), so no write to a conversion waits long;
     * returns how many rows were changed and how many are still to do (above
     * 0 only when $maxBatches ran out: ask again).
     *
     * @return array{stamped: int, left: int}
     */
    public static function recordBefore(
        Connection $conn,
        int $userId,
        string $currency,
        int $before,
        ?int $since = null,
        int $maxBatches = 200
    ): array {
        if (self::read($currency) !== $currency || $currency === self::UNKNOWN) {
            throw new \InvalidArgumentException('"' . $currency . '" is not a currency code');
        }
        if ($since !== null && $since >= $before) {
            throw new \InvalidArgumentException('the range is empty: it starts at or after its end');
        }
        // A reversal is never chosen by its own time: it follows its sale, below.
        $where = ' WHERE user_id = ? AND reverses_conv_id IS NULL AND conv_time < ?' . ($since !== null ? ' AND conv_time >= ?' : '')
            . " AND (currency = '' OR currency_basis <> " . self::BASIS_WRITTEN . ')'
            . ' AND NOT (currency = ? AND currency_basis = ' . self::BASIS_OWNER . ')';
        $types = 'ii' . ($since !== null ? 'i' : '') . 's';
        $values = [$userId, $before, ...($since !== null ? [$since] : []), $currency];

        $stamped = 0;
        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $stmt = $conn->prepareWrite('UPDATE 202_conversion_logs SET currency = ?, currency_basis = '
                . self::BASIS_OWNER . $where . ' LIMIT ' . self::BATCH);
            $conn->bind($stmt, 's' . $types, [$currency, ...$values]);
            $changed = $conn->executeUpdate($stmt);
            $stamped += $changed;
            if ($changed < self::BATCH) {
                break;
            }
        }

        $follow = $conn->prepareWrite(
            'UPDATE 202_conversion_logs r JOIN 202_conversion_logs s'
            . ' ON s.conv_id = r.reverses_conv_id AND s.user_id = r.user_id'
            . ' SET r.currency = s.currency'
            . ' WHERE r.user_id = ? AND s.currency_basis = ' . self::BASIS_OWNER . ' AND r.currency <> s.currency'
        );
        $conn->bind($follow, 'i', [$userId]);
        $stamped += $conn->executeUpdate($follow);

        $left = $conn->prepareWrite('SELECT COUNT(*) AS n FROM 202_conversion_logs' . $where);
        $conn->bind($left, $types, $values);

        return ['stamped' => $stamped, 'left' => (int) ($conn->fetchOne($left)['n'] ?? 0)];
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
            foreach (["'currency'", "'stated_amount'", "'stated_currency'", "'currency_basis'"] as $column) {
                if (str_contains($current->getMessage(), $column)) {
                    return true;
                }
            }
        }

        return false;
    }
}
