<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use Prosper202\Database\Connection;
use Prosper202\Identity\ConsentRefusal;

/**
 * A conversion whose own request withheld consent (p202_consent, read by
 * RequestSignals::consentGiven() alone): 202_conversion_logs.consent_withheld
 * is 1 on its row, written by the row's INSERT, so the row and the refusal
 * commit together or not at all. A later request of the same conversion
 * (a duplicate) that refuses sets it to 1; nothing sets it back to 0. Either
 * refuses the conversion's click too (ConsentRefusal). No
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
     * The SQL that is 1 when the visitor of the conversion read as `cl` (an
     * alias of 202_conversion_logs the caller gives it) refused consent, else
     * 0: the rule every reader that hands a conversion to an ad platform
     * applies (DestinationQueue::deliver(), OfflineExport::query()). Any of:
     * - its own request refused (consent_withheld; a NULL reads as 1, #25);
     * - it replaced a refused conversion and goes by that one's event id
     *   (DestinationEventId::of()): a sale refused once is not sent later
     *   under the name it was refused under;
     * - any request of its click refused (ConsentRefusal): a visit, the one
     *   it credits or any other, or the request of any conversion or goal
     *   event of the click: a later visit or conversion that consents does
     *   not give the click back. Only another click starts fresh.
     * The alias is written out, not passed in, so the account-scope scan
     * reads each tie (AccountScopedJoinTest).
     */
    public static function refusedSql(): string
    {
        return '(' . self::requestRefusedSql() . ' OR ' . self::replacedRefusedSql()
            . ' OR ' . self::visitRefusedSql() . ')';
    }

    /** The first of refusedSql()'s three, alone. */
    public static function requestRefusedSql(): string
    {
        return 'COALESCE(cl.consent_withheld, 1) <> 0';
    }

    /** The second of refusedSql()'s three, alone: the account's own rows of the click (#27). */
    public static function replacedRefusedSql(): string
    {
        // By the superseded_by key, named: a click can hold any number of rows,
        // and a table just filled has statistics that send the planner to the
        // click's or the account's key instead, a scan for every row read.
        return 'EXISTS (SELECT 1 FROM 202_conversion_logs AS rr FORCE INDEX (superseded_by)'
            . ' WHERE rr.superseded_by = cl.conv_id AND rr.click_id = cl.click_id AND rr.user_id = cl.user_id'
            . " AND rr.superseded_reason IN ('replace', 'batch') AND COALESCE(rr.consent_withheld, 1) <> 0)";
    }

    /** The third of refusedSql()'s three, alone. */
    public static function visitRefusedSql(): string
    {
        return ConsentRefusal::standsSql('cl.click_id');
    }

    /**
     * Whether the 1.9.80 upgrade has added the column to the ledger. Before
     * it, no destination exists to send to; a row that cannot carry its
     * refusal is not queued for one (MysqlConversionRepository::recordLocked()).
     */
    public static function columnPresent(Connection $conn): bool
    {
        return self::probe($conn, 'ledger', 'SELECT consent_withheld FROM 202_conversion_logs LIMIT 0');
    }

    /** Whether the 1.9.80 upgrade has added it to the goal events (GoalEngine::insertEvent()). */
    public static function goalEventsColumnPresent(Connection $conn): bool
    {
        return self::probe($conn, 'events', 'SELECT consent_withheld FROM 202_goal_events LIMIT 0');
    }

    private static function probe(Connection $conn, string $table, string $sql): bool
    {
        $handle = $conn->writeConnection();
        if (isset(self::$present[$handle][$table])) {
            return true;
        }
        try {
            $stmt = $conn->prepareWrite($sql);
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
