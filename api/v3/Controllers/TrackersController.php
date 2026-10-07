<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;
use Prosper202\Click\TrackingBaseUrl;
use Prosper202\Click\TrackingLinkVariables;

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

    /**
     * A tracker's link, as Get Links builds it: on this install's tracking
     * base (Prosper202\Click\TrackingBaseUrl — user 1's domain or this
     * server's, with the install's path), carrying its traffic source's
     * custom variables and the built-in tokens (TrackingLinkVariables).
     *
     * @param array<string, mixed> $params values for the built-in tokens
     *   (c1-c4, utm_*, t202ref, t202b, t202kw), as Get Links' boxes take them;
     *   anything else is refused by name
     * @param array<string, mixed>|null $server the request ($_SERVER)
     */
    public function getTrackingUrl(int $id, array $params = [], ?array $server = null): array
    {
        $values = self::tokenValues($params);
        $tracker = $this->get($id);
        $row = $tracker['data'];
        $publicId = (int)$row['tracker_id_public'];
        $baseUrl = TrackingBaseUrl::build($this->trackingDomain(), $server ?? $_SERVER, dirname(__DIR__, 3));
        $variables = TrackingLinkVariables::query($this->customVariables((int)($row['ppc_account_id'] ?? 0)), $values);

        // A landing-page tracker promotes the landing page's own URL, so resolve
        // it here; direct-link and rotator trackers don't need it.
        if ((int)($row['landing_page_id'] ?? 0) > 0) {
            $row['landing_page_url'] = $this->getLandingPageUrl((int)$row['landing_page_id']);
        }

        return [
            'data' => [
                'tracker_id'        => $id,
                'tracker_id_public' => $publicId,
                'direct_url'        => self::buildDirectUrl($baseUrl, $publicId, $row, $variables),
                'tracking_params'   => '?t202id=' . $publicId . $variables,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private static function tokenValues(array $params): array
    {
        $values = [];
        $errors = [];
        foreach ($params as $key => $value) {
            if (!in_array($key, TrackingLinkVariables::BUILT_IN, true)) {
                $errors[(string) $key] = 'Not a link token; the link takes: ' . implode(', ', TrackingLinkVariables::BUILT_IN);
                continue;
            }
            if (!is_scalar($value)) {
                $errors[$key] = 'Must be a single value';
                continue;
            }
            $problem = TrackingLinkVariables::problem(trim((string) $value));
            if ($problem !== null) {
                $errors[$key] = $problem;
                continue;
            }
            $values[$key] = trim((string) $value);
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid link token', $errors);
        }

        return $values;
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
    public static function buildDirectUrl(string $baseUrl, int $publicId, array $tracker, string $variables = ''): string
    {
        if ((int)($tracker['landing_page_id'] ?? 0) > 0) {
            // A tracker can reference a landing page that no longer resolves
            // (deleted, or not owned by this user — the table has no FK). When
            // the URL can't be built, fall through to the redirect handler
            // instead of emitting a broken "http://?t202id=..." link.
            $lpUrl = self::buildLandingPageUrl((string)($tracker['landing_page_url'] ?? ''), $publicId, $variables);
            if ($lpUrl !== '') {
                return $lpUrl;
            }
        }

        $handler = (int)($tracker['rotator_id'] ?? 0) > 0 ? 'rtr.php' : 'dl.php';
        return rtrim($baseUrl, '/') . '/tracking202/redirect/' . $handler . '?t202id=' . $publicId . $variables;
    }

    /**
     * Append t202id to a landing page URL, preserving any existing query string
     * and fragment. Matches the parse_url handling in generate_tracking_link.php.
     * Returns an empty string when the URL is missing or unparseable, so the
     * caller can fall back to a redirect-handler URL.
     */
    private static function buildLandingPageUrl(string $landingPageUrl, int $publicId, string $variables = ''): string
    {
        if (trim($landingPageUrl) === '') {
            return '';
        }

        $parsed = parse_url($landingPageUrl);
        if ($parsed === false || empty($parsed['host'])) {
            return '';
        }

        $host = $parsed['host'];
        if (!empty($parsed['port'])) {
            $host .= ':' . $parsed['port'];
        }
        $url = ($parsed['scheme'] ?? 'http') . '://' . $host . ($parsed['path'] ?? '') . '?';
        if (!empty($parsed['query'])) {
            $url .= $parsed['query'] . '&';
        }
        // The variables before the fragment: Get Links appends them after
        // it, where a browser never sends them.
        $url .= 't202id=' . $publicId . $variables;
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
        $result = $stmt->get_result();
        if ($result === false) {
            // Read as "no landing page", this would hand out a dl.php link
            // for a tracker whose traffic should land on the page.
            $stmt->close();
            throw new DatabaseException('Failed to query landing page URL');
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        return (string)($row['landing_page_url'] ?? '');
    }

    /** user 1's tracking domain, which getTrackingDomain() builds every UI link on; '' when unset. */
    private function trackingDomain(): string
    {
        $stmt = $this->prepare('SELECT user_tracking_domain FROM 202_users_pref WHERE user_id = 1 LIMIT 1');
        $this->execute($stmt, 'Failed to query tracking domain');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Failed to query tracking domain');
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        return (string) ($row['user_tracking_domain'] ?? '');
    }

    /**
     * The live custom variables of the traffic source this tracker's account
     * belongs to — this user's account only, as Get Links checks.
     *
     * @return list<array{parameter: string, placeholder: string}>
     */
    private function customVariables(int $ppcAccountId): array
    {
        if ($ppcAccountId <= 0) {
            return [];
        }
        $stmt = $this->prepare(
            'SELECT v.parameter, v.placeholder
             FROM 202_ppc_network_variables v
             INNER JOIN 202_ppc_accounts a ON a.ppc_network_id = v.ppc_network_id
             WHERE a.ppc_account_id = ? AND a.user_id = ? AND v.deleted = 0
             ORDER BY v.ppc_variable_id'
        );
        $this->bind($stmt, 'ii', $ppcAccountId, $this->userId);
        $this->execute($stmt, 'Failed to query traffic source variables');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Failed to query traffic source variables');
        }
        $out = [];
        while ($row = $result->fetch_assoc()) {
            $out[] = ['parameter' => (string) $row['parameter'], 'placeholder' => (string) $row['placeholder']];
        }
        $stmt->close();

        return $out;
    }
}
