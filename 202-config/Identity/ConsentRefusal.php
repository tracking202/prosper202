<?php

declare(strict_types=1);

namespace Prosper202\Identity;

use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * A request of a click that withheld consent (ClickIdentity::$withheld)
 * holds it back for the whole visit: 202_click_consent_refusals keeps, per
 * click, the time of the latest visit a request withheld it from. A
 * destination identifies no visitor whose click's latest visit is at or
 * before that time (standsAt()), whatever a later request of the same visit
 * carries: one that names no p202_consent does not give back what an earlier
 * one refused. A later visit (a re-click at a later time) that consents is
 * read on its own.
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
     * the click its tracking template made): the refusal covers every visit
     * recorded so far, since a web server's clock can stamp the visit that
     * withheld before one it follows (LatestVisit::refresh()).
     */
    public static function recordForStoredClick(Connection $conn, int $clickId, int $visitTime): void
    {
        self::record($conn, $clickId, max($visitTime, self::latestRecordedVisit($conn, $clickId)));
    }

    /**
     * Whether consent was withheld from the click's latest visit, the one
     * at $latestVisitTime. A failed read is thrown: no answer is not "no
     * refusal" (CLAUDE.md #11).
     */
    public static function standsAt(Connection $conn, int $clickId, int $latestVisitTime): bool
    {
        // The write handle: a refusal just stored is not on a replica yet.
        $stmt = $conn->prepareWrite(
            'SELECT 1 AS refused FROM `' . self::TABLE . '` WHERE click_id = ? AND refused_at >= ?'
        );
        $conn->bind($stmt, 'ii', [$clickId, $latestVisitTime]);

        return $conn->fetchOne($stmt) !== null;
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
