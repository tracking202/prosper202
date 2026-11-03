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
 * Only a click of the page's own account, on that page, from the last
 * WINDOW seconds: the gclid is the visitor's to send.
 */
final readonly class ParallelTrackingClick
{
    /** The template's hit and the page's script arrive seconds apart; a reload soon after is the same visit. */
    public const WINDOW = 600;

    private function __construct(
        public int $clickId,
        public string $clickIdPublic,
        public string $affCampaignId,
    ) {
    }

    public static function find(Connection $conn, int $userId, int $landingPageId, string $gclid, int $now): ?self
    {
        $gclid = ClickRecord::storedGclid($gclid);
        if ($userId < 1 || $landingPageId < 1 || $gclid === '') {
            return null;
        }

        // The primary: the template's click was written moments ago.
        $stmt = $conn->prepareWrite(
            'SELECT c.click_id, cr.click_id_public, c.aff_campaign_id
               FROM 202_clicks AS c
               INNER JOIN 202_google AS g ON g.click_id = c.click_id
               INNER JOIN 202_clicks_record AS cr ON cr.click_id = c.click_id
              WHERE c.user_id = ? AND c.click_time >= ? AND c.landing_page_id = ? AND g.gclid = ?
                AND cr.click_id_public > 0
              ORDER BY c.click_id DESC
              LIMIT 1'
        );
        $conn->bind($stmt, 'iiis', [$userId, $now - self::WINDOW, $landingPageId, $gclid]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        return new self((int) $row['click_id'], (string) $row['click_id_public'], (string) $row['aff_campaign_id']);
    }
}
