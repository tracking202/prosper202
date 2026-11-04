<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;

/**
 * A Google Ads click already recorded for a landing page, found by its gclid:
 * the tracking template (pt.php) records the click while the visitor goes
 * straight to the page, and the page's script then takes that click rather
 * than recording a second one, as paid 1.9.56 did.
 *
 * Only a click of the page's own account, on that page, from the same
 * stored address, in the last WINDOW seconds: the gclid is the visitor's to
 * send, and Google's background call comes from the visitor's own browser.
 */
final readonly class ParallelTrackingClick
{
    /** The template's hit and the page's script arrive seconds apart; a reload soon after is the same visit. */
    public const WINDOW = 600;

    /** Seconds a visit waits for the other request of its click to finish recording. */
    public const LOCK_WAIT = 3;

    private function __construct(
        public int $clickId,
        public string $clickIdPublic,
        public string $affCampaignId,
        public int $clickTime,
    ) {
    }

    /**
     * A failed read is logged and answers null: the page view records a click
     * of its own, and the visitor goes on.
     */
    public static function find(
        Connection $conn,
        int $userId,
        int $landingPageId,
        string $gclid,
        int $ipId,
        int $now
    ): ?self {
        $gclid = ClickRecord::storedGclid($gclid);
        if ($userId < 1 || $landingPageId < 1 || $gclid === '' || $ipId < 1) {
            return null;
        }

        try {
            $row = self::read($conn, $userId, $landingPageId, $gclid, $ipId, $now);
        } catch (\Throwable $e) {
            error_log('Prosper202 parallel tracking: the template click for landing page ' . $landingPageId
                . ' could not be read, so this view records its own: ' . $e->getMessage());

            return null;
        }
        if ($row === null) {
            return null;
        }

        return new self(
            (int) $row['click_id'],
            (string) $row['click_id_public'],
            (string) $row['aff_campaign_id'],
            (int) $row['click_time']
        );
    }

    /**
     * Holds the gclid's lock (GET_LOCK) until release(): the template's hit
     * and the page's script arrive together, and without it each could miss
     * the other's click and both record one. The name, or null when there is
     * nothing to hold or the lock was not had within LOCK_WAIT seconds: the
     * visit then records as it would without it.
     */
    public static function hold(Connection $conn, int $userId, int $landingPageId, string $gclid): ?string
    {
        $gclid = ClickRecord::storedGclid($gclid);
        if ($userId < 1 || $landingPageId < 1 || $gclid === '') {
            return null;
        }
        $name = self::lockName($userId, $landingPageId, $gclid);
        try {
            $stmt = $conn->prepareWrite('SELECT GET_LOCK(?, ?) AS got');
            $conn->bind($stmt, 'si', [$name, self::LOCK_WAIT]);
            $got = $conn->fetchOne($stmt);
        } catch (\Throwable $e) {
            error_log('Prosper202 parallel tracking: the lock for landing page ' . $landingPageId
                . ' could not be taken: ' . $e->getMessage());

            return null;
        }

        return $got !== null && (int) ($got['got'] ?? 0) === 1 ? $name : null;
    }

    /** Lets go of hold()'s lock; the connection's end releases it too. */
    public static function release(Connection $conn, ?string $name): void
    {
        if ($name === null) {
            return;
        }
        try {
            $stmt = $conn->prepareWrite('SELECT RELEASE_LOCK(?) AS released');
            $conn->bind($stmt, 's', [$name]);
            $conn->fetchOne($stmt);
        } catch (\Throwable $e) {
            error_log('Prosper202 parallel tracking: the lock ' . $name . ' could not be released: ' . $e->getMessage());
        }
    }

    /** GET_LOCK names are server-wide and at most 64 characters. */
    public static function lockName(int $userId, int $landingPageId, string $gclid): string
    {
        return 'p202pt:' . md5($userId . ':' . $landingPageId . ':' . ClickRecord::storedGclid($gclid));
    }

    /** @return array<string, mixed>|null */
    private static function read(
        Connection $conn,
        int $userId,
        int $landingPageId,
        string $gclid,
        int $ipId,
        int $now
    ): ?array {
        // The primary: the template's click was written moments ago.
        $stmt = $conn->prepareWrite(
            'SELECT c.click_id, cr.click_id_public, c.aff_campaign_id, c.click_time
               FROM 202_clicks AS c
               INNER JOIN 202_google AS g ON g.click_id = c.click_id
               INNER JOIN 202_clicks_record AS cr ON cr.click_id = c.click_id
               INNER JOIN 202_clicks_advance AS ca ON ca.click_id = c.click_id
              WHERE c.user_id = ? AND c.click_time >= ? AND c.landing_page_id = ? AND g.gclid = ?
                AND ca.ip_id = ? AND cr.click_id_public > 0
                AND ' . LatestVisit::isLatest('c') . '
              ORDER BY c.click_id DESC
              LIMIT 1'
        );
        $conn->bind($stmt, 'iiisi', [$userId, $now - self::WINDOW, $landingPageId, $gclid, $ipId]);

        return $conn->fetchOne($stmt);
    }
}
