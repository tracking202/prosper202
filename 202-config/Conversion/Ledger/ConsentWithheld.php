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
     * The SQL that is 1 when the visitor of conversion $c (an alias of
     * 202_conversion_logs) refused consent, else 0: the rule every reader
     * that hands a conversion to an ad platform applies
     * (DestinationQueue::deliver(), OfflineExport::query()). Any of:
     * - its own request refused (consent_withheld; a NULL reads as 1, #25);
     * - it replaced a refused conversion and goes by that one's event id
     *   (DestinationEventId::of()): a sale refused once is not sent later
     *   under the name it was refused under;
     * - a request of the visit it credits (its click_time, read under the
     *   click's lock when it was recorded), or of a later visit, refused
     *   (ConsentRefusal). A later visit that consents, with no refusal at or
     *   after it, credits its own conversions.
     */
    public static function refusedSql(string $c): string
    {
        return '(' . self::requestRefusedSql($c) . ' OR ' . self::replacedRefusedSql($c)
            . ' OR ' . self::visitRefusedSql($c) . ')';
    }

    /** The first of refusedSql()'s three, alone. */
    public static function requestRefusedSql(string $c): string
    {
        return "COALESCE($c.consent_withheld, 1) <> 0";
    }

    /** The second of refusedSql()'s three, alone: the account's own rows of the click (#27). */
    public static function replacedRefusedSql(string $c): string
    {
        // By the superseded_by key, named: a click can hold any number of rows,
        // and a table just filled has statistics that send the planner to the
        // click's or the account's key instead, a scan for every row read.
        return "EXISTS (SELECT 1 FROM 202_conversion_logs AS rr FORCE INDEX (superseded_by)"
            . " WHERE rr.superseded_by = $c.conv_id"
            . " AND rr.click_id = $c.click_id AND rr.user_id = $c.user_id"
            . " AND rr.superseded_reason IN ('replace', 'batch') AND COALESCE(rr.consent_withheld, 1) <> 0)";
    }

    /** The third of refusedSql()'s three, alone. */
    public static function visitRefusedSql(string $c): string
    {
        return ConsentRefusal::standsAtSql("$c.click_id", "COALESCE($c.click_time, 0)");
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
