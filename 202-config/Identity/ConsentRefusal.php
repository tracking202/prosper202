<?php

declare(strict_types=1);

namespace Prosper202\Identity;

use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * A request of a click whose visitor refused consent (ClickIdentity::$refused)
 * holds the click back for good: 202_click_consent_refusals keeps one row
 * per refused click. No destination or offline file is sent any conversion
 * of that click (standsSql(), DestinationQueue::deliver(),
 * OfflineExport::query()), whatever a later request of it carries: one that
 * names no p202_consent, or a later visit (a re-click) that consents, does
 * not give back what an earlier one refused. Only another click starts
 * fresh. A campaign's identity capture switched off is no refusal and
 * stores none.
 *
 * refused_at, the latest visit a request refused from, is kept for the
 * record; no reader decides by it.
 *
 * The row is written with the visit that withheld, in its transaction where
 * there is one, and only for such a visit: a consenting click costs no
 * statement. Nothing lowers or removes it but the click's deletion
 * (ClickRetention).
 *
 * The caller has shown the click is the account's.
 */
final class ConsentRefusal
{
    private const TABLE = TableRegistry::CLICK_CONSENT_REFUSALS;

    private function __construct()
    {
    }

    /** For a click being stored now: its only visit is the one at $visitTime. */
    public static function record(Connection $conn, int $clickId, int $visitTime): void
    {
        try {
            $stmt = $conn->prepareWrite(
                'INSERT INTO `' . self::TABLE . '` (click_id, refused_at) VALUES (?, ?)'
                    . ' ON DUPLICATE KEY UPDATE refused_at = GREATEST(refused_at, VALUES(refused_at))'
            );
            $conn->bind($stmt, 'ii', [$clickId, max(0, $visitTime)]);
            $conn->executeUpdate($stmt);
        } catch (\Throwable $e) {
            if (!self::tableMissing($e)) {
                throw $e;
            }
            // Code deployed before the 1.9.80 upgrade has made the table:
            // the visitor goes on. No destination exists until that upgrade.
            error_log('p202 consent: click ' . $clickId . ' withheld consent before the 1.9.80 upgrade created '
                . self::TABLE . ', so that was not stored with it. Run the upgrade.');
        }
    }

    /**
     * For a click already stored (a re-click, a landing page's request for
     * the click its tracking template made). refused_at is the latest visit
     * recorded so far, since a web server's clock can stamp the visit that
     * withheld before one it follows (LatestVisit::refresh()).
     */
    public static function recordForStoredClick(Connection $conn, int $clickId, int $visitTime): void
    {
        self::record($conn, $clickId, max($visitTime, self::latestRecordedVisit($conn, $clickId)));
    }

    /**
     * The SQL that is 1 when any request of click $click (an SQL
     * expression) refused, else 0: the one reading of the table, for every
     * reader that hands a conversion to an ad platform
     * (Ledger\ConsentWithheld::refusedSql()). A read that fails is the
     * statement's failure, never "no refusal" (CLAUDE.md #11).
     */
    public static function standsSql(string $click): string
    {
        // The table by its name, so the account-scope scan reads it
        // (AccountScopedJoinTest): it has no user_id, a row is keyed by the
        // account's own click, and the caller names that click.
        return "EXISTS (SELECT 1 FROM 202_click_consent_refusals AS cr WHERE cr.click_id = $click)";
    }

    /** The click's latest visit as recorded, read by its key; 0 for a click with one visit. */
    private static function latestRecordedVisit(Connection $conn, int $clickId): int
    {
        try {
            $stmt = $conn->prepareWrite(
                'SELECT click_time FROM `' . TableRegistry::CLICK_LATEST_VISITS . '` WHERE click_id = ?'
            );
            $conn->bind($stmt, 'i', [$clickId]);
            $row = $conn->fetchOne($stmt);
        } catch (\Throwable $e) {
            // Before the 1.9.79 upgrade made it, no visit is recorded there.
            if (!LatestVisit::tableMissing($e)) {
                throw $e;
            }

            return 0;
        }

        return $row === null ? 0 : (int) $row['click_time'];
    }

    /** Whether $e, or one it wraps, is the server's 1146 for the table. */
    private static function tableMissing(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (
                Connection::isMysqlError($current, 1146, '')
                && preg_match('/\b' . self::TABLE . '\b/', $current->getMessage()) === 1
            ) {
                return true;
            }
        }

        return false;
    }
}
