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
