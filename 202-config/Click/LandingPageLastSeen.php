<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;

/**
 * When each of an account's landing pages last had a click
 * (202_landing_page_last_seen), which the landing page list answers as
 * `last_seen_at`. MysqlClickRepository writes it once a click has
 * committed; a failure is logged there and never the click's.
 *
 * A page's time moves at most once every WINDOW seconds, so a busy page
 * writes its row about once a minute rather than once a click, and the time
 * read can be up to WINDOW seconds behind its latest click. It never moves
 * back: a click recorded late (an outage replay) does not undo a later one.
 * A page another account owns is never written for the click's account.
 */
final class LandingPageLastSeen
{
    /** The seconds a page's stored time may lag its latest click. */
    public const int WINDOW = 60;

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * Notes $clickTime as $landingPageId's latest click when it is the
     * account's page and the stored time is WINDOW or more seconds older.
     *
     * @return bool whether the row was written
     */
    public function stamp(int $userId, int $landingPageId, int $clickTime): bool
    {
        if ($landingPageId < 1 || $userId < 1 || $clickTime < 1) {
            return false;
        }
        // On the write connection: a page made a moment ago may not be on a replica yet.
        $stmt = $this->conn->prepareWrite(
            'SELECT s.last_click_at FROM 202_landing_pages AS p'
                . ' LEFT JOIN 202_landing_page_last_seen AS s'
                . ' ON s.landing_page_id = p.landing_page_id AND s.user_id = p.user_id'
                . ' WHERE p.landing_page_id = ? AND p.user_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$landingPageId, $userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return false;
        }
        if ($row['last_click_at'] !== null && (int) $row['last_click_at'] > $clickTime - self::WINDOW) {
            return false;
        }
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_landing_page_last_seen (landing_page_id, user_id, last_click_at)'
                // A row left from another owner of the page is the account's now, with this time.
                . ' VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE'
                . ' last_click_at = IF(user_id = ?, GREATEST(last_click_at, ?), ?), user_id = ?'
        );
        $this->conn->bind(
            $stmt,
            'iiiiiii',
            [$landingPageId, $userId, $clickTime, $userId, $clickTime, $clickTime, $userId]
        );
        $this->conn->executeUpdate($stmt);

        return true;
    }
}
