<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\NotFoundException;

class TrackersController extends Controller
{
    protected function tableName(): string { return '202_trackers'; }
    protected function primaryKey(): string { return 'tracker_id'; }

    protected function fields(): array
    {
        return [
            'aff_campaign_id'   => ['type' => 'i', 'required' => true],
            'ppc_account_id'    => ['type' => 'i', 'default' => 0],
            'text_ad_id'        => ['type' => 'i', 'default' => 0],
            'landing_page_id'   => ['type' => 'i', 'default' => 0],
            'rotator_id'        => ['type' => 'i', 'default' => 0],
            'click_cpc'         => ['type' => 'd'],
            'click_cpa'         => ['type' => 'd'],
            'click_cloaking'    => ['type' => 'i', 'default' => 0],
            'tracker_id_public' => ['type' => 'i'],
        ];
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        $publicId = isset($payload['tracker_id_public']) && (int)$payload['tracker_id_public'] > 0
            ? (int)$payload['tracker_id_public']
            : random_int(10_000_000, 999_999_999);

        return [
            'tracker_id_public' => ['type' => 'i', 'value' => $publicId],
            'tracker_time'      => ['type' => 'i', 'value' => time()],
        ];
    }

    public function getTrackingUrl(int $id): array
    {
        $tracker = $this->get($id);
        $row = $tracker['data'];
        $publicId = (int)$row['tracker_id_public'];
        $baseUrl = $this->getBaseUrl();

        // A landing-page tracker promotes the landing page's own URL, so resolve
        // it here; direct-link and rotator trackers don't need it.
        if ((int)($row['landing_page_id'] ?? 0) > 0) {
            $row['landing_page_url'] = $this->getLandingPageUrl((int)$row['landing_page_id']);
        }

        return [
            'data' => [
                'tracker_id'        => $id,
                'tracker_id_public' => $publicId,
                'direct_url'        => self::buildDirectUrl($baseUrl, $publicId, $row),
                'tracking_params'   => '?t202id=' . $publicId . '&t202kw={keyword}&c1={c1}&c2={c2}&c3={c3}&c4={c4}',
            ],
        ];
    }

    /**
     * Pick the promoted tracking URL for a tracker based on its type, mirroring
     * the UI link generator (tracking202/ajax/generate_tracking_link.php):
     *   - landing-page tracker -> the landing page's own URL + ?t202id=
     *   - rotator tracker       -> rtr.php
     *   - direct-link tracker   -> dl.php
     *
     * Landing page takes precedence over rotator, matching get_trackers.php which
     * checks landing_page_id first.
     *
     * @param array<string,mixed> $tracker Tracker row; needs rotator_id, landing_page_id,
     *                                      and landing_page_url when landing_page_id > 0.
     */
    public static function buildDirectUrl(string $baseUrl, int $publicId, array $tracker): string
    {
        if ((int)($tracker['landing_page_id'] ?? 0) > 0) {
            return self::buildLandingPageUrl((string)($tracker['landing_page_url'] ?? ''), $publicId);
        }

        $handler = (int)($tracker['rotator_id'] ?? 0) > 0 ? 'rtr.php' : 'dl.php';
        return rtrim($baseUrl, '/') . '/tracking202/redirect/' . $handler . '?t202id=' . $publicId;
    }

    /**
     * Append t202id to a landing page URL, preserving any existing query string
     * and fragment. Matches the parse_url handling in generate_tracking_link.php.
     */
    private static function buildLandingPageUrl(string $landingPageUrl, int $publicId): string
    {
        $parsed = parse_url($landingPageUrl);

        $host = ($parsed['host'] ?? '');
        if (!empty($parsed['port'])) {
            $host .= ':' . $parsed['port'];
        }
        $url = ($parsed['scheme'] ?? 'http') . '://' . $host . ($parsed['path'] ?? '') . '?';
        if (!empty($parsed['query'])) {
            $url .= $parsed['query'] . '&';
        }
        $url .= 't202id=' . $publicId;
        if (!empty($parsed['fragment'])) {
            $url .= '#' . $parsed['fragment'];
        }
        return $url;
    }

    private function getLandingPageUrl(int $landingPageId): string
    {
        $stmt = $this->prepare('SELECT landing_page_url FROM 202_landing_pages WHERE landing_page_id = ? AND user_id = ? LIMIT 1');
        $this->bind($stmt, 'ii', $landingPageId, $this->userId);
        $this->execute($stmt, 'Failed to query landing page URL');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (string)($row['landing_page_url'] ?? '');
    }

    private function getBaseUrl(): string
    {
        $stmt = $this->prepare('SELECT user_tracking_domain FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $this->bind($stmt, 'i', $this->userId);
        $this->execute($stmt, 'Failed to query tracking domain');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['user_tracking_domain'])) {
            throw new NotFoundException('Tracking domain not configured');
        }
        return rtrim($row['user_tracking_domain'], '/');
    }
}
