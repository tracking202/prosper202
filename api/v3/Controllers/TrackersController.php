<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\TrackingBaseLookup;
use Prosper202\Click\TrackingLinkVariables;
use Prosper202\Setup\TrackerPublicId;

class TrackersController extends Controller
{
    use TrackingBaseLookup;

    protected function tableName(): string { return '202_trackers'; }
    protected function primaryKey(): string { return 'tracker_id'; }

    protected function fields(): array
    {
        return [
            'aff_campaign_id'   => ['type' => 'i', 'required' => true, 'range' => self::MEDIUMINT_UNSIGNED],
            'ppc_account_id'    => ['type' => 'i', 'default' => 0, 'range' => self::MEDIUMINT_UNSIGNED],
            'text_ad_id'        => ['type' => 'i', 'default' => 0, 'range' => self::MEDIUMINT_UNSIGNED],
            'landing_page_id'   => ['type' => 'i', 'default' => 0, 'range' => self::MEDIUMINT_UNSIGNED],
            'rotator_id'        => ['type' => 'i', 'default' => 0, 'range' => self::INT_UNSIGNED],
            'click_cpc'         => ['type' => 'd', 'nullable' => true, 'range' => [-99.99999, 99.99999]],
            'click_cpa'         => ['type' => 'd', 'nullable' => true, 'range' => [-99.99999, 99.99999]],
            // -1 is Get Links' default: the campaign decides. 0 turned
            // cloaking off for every API-made link, whatever the campaign said.
            'click_cloaking'    => ['type' => 'i', 'default' => -1, 'allowed' => [-1, 0, 1], 'range' => self::TINYINT],
            'tracker_id_public' => ['type' => 'i', 'range' => self::BIGINT_UNSIGNED],
        ];
    }

    /** decimal(7,5): the largest cost the columns hold. */
    private const MAX_COST = 99.99999;

    /**
     * Checked on the request as sent, before the base class casts it: '0.5'
     * cast to an integer is a valid cloaking value, and a cost the columns
     * cannot hold is a strict-mode error rather than a 422.
     */
    #[\Override]
    protected function validatePayload(array $payload, bool $requireRequired = false, ?array $current = null): array
    {
        $errors = [];
        $cloaking = $payload['click_cloaking'] ?? null;
        if ($cloaking !== null && !((is_int($cloaking) || is_string($cloaking)) && in_array((string) $cloaking, ['-1', '0', '1'], true))) {
            $errors['click_cloaking'] = "Must be -1 (the campaign's setting), 0 (off for this link) or 1 (on for this link)";
        }
        foreach (['click_cpc', 'click_cpa'] as $cost) {
            $value = $payload[$cost] ?? null;
            if ($value !== null && (!is_numeric($value) || (float) $value < 0 || (float) $value > self::MAX_COST)) {
                $errors[$cost] = 'Must be a cost from 0 to ' . self::MAX_COST;
            }
        }
        // dl.php charges a tracker with any click_cpa per action; one with
        // both would be charged per click and per action. Get Links writes
        // one and leaves the other NULL.
        if (($payload['click_cpc'] ?? null) !== null && ($payload['click_cpa'] ?? null) !== null) {
            $errors['click_cpa'] = 'A tracker costs per click (click_cpc) or per action (click_cpa), not both: send one';
        }
        if ($errors !== []) {
            throw new ValidationException('Validation failed', $errors);
        }

        return parent::validatePayload($payload, $requireRequired, $current);
    }

    /**
     * Setting one cost switches the tracker to it, as Get Links' cost type
     * does: the other column is cleared, or a CPC tracker given a CPA would
     * be charged both ways. Only a value switches: a null clears that one
     * cost (a GET body sent back carries the unused cost as null, and must
     * not clear the one in use).
     */
    #[\Override]
    protected function beforeUpdate(int|string $id, array $payload): array
    {
        if (array_key_exists('tracker_id_public', $payload)) {
            $this->assertPublicIdFree((int) $payload['tracker_id_public'], (int) $id);
        }
        if (($payload['click_cpa'] ?? null) !== null) {
            return ['click_cpc' => ['type' => 'd', 'value' => null]];
        }
        if (($payload['click_cpc'] ?? null) !== null) {
            return ['click_cpa' => ['type' => 'd', 'value' => null]];
        }

        return [];
    }

    /**
     * A sent public id is kept (p202 sync and import carry a tracker's link
     * to another server) unless another tracker, in any account, holds it:
     * the click endpoints look a tracker up by that id alone. Without one,
     * a free id is drawn (TrackerPublicId).
     */
    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        if (isset($payload['tracker_id_public']) && (int)$payload['tracker_id_public'] > 0) {
            $publicId = (int)$payload['tracker_id_public'];
            $this->assertPublicIdFree($publicId, null);
        } else {
            $publicId = $this->guardPublicIdLookup(fn (): int => TrackerPublicId::generate($this->db));
        }

        return [
            'tracker_id_public' => ['type' => 'i', 'value' => $publicId],
            'tracker_time'      => ['type' => 'i', 'value' => time()],
        ];
    }

    /**
     * A redirector's link and an advanced landing page's have no campaign:
     * Get Links (generate_tracking_link.php) asks for one only for a direct
     * link or a simple landing page (tracker_type 0) and stores 0 for the
     * other two. assertLinksOwned() refused that 0 for every tracker, so POST
     * /trackers could make neither. A link counts as a redirector's when it
     * names a redirector, and as an advanced page's when the landing page it
     * names is one of this account's live advanced (type 1) pages; anything
     * else still needs its campaign. The values are the body's, else the
     * record's.
     */
    #[\Override]
    protected function requiredLinkMayBeNone(string $field, array $clean, ?array $current): bool
    {
        if ($field !== 'aff_campaign_id') {
            return false;
        }
        $value = static fn (string $f): int => (int) (array_key_exists($f, $clean) ? $clean[$f] : ($current[$f] ?? 0));
        if ($value('rotator_id') > 0) {
            return true;
        }
        $landingPageId = $value('landing_page_id');

        return $landingPageId > 0 && $this->isAdvancedLandingPage($landingPageId);
    }

    /** Whether $landingPageId is a live advanced (type 1) landing page of this account's. A failed read throws. */
    private function isAdvancedLandingPage(int $landingPageId): bool
    {
        $stmt = $this->prepare(
            'SELECT landing_page_type FROM 202_landing_pages
             WHERE landing_page_id = ? AND user_id = ? AND COALESCE(landing_page_deleted, 0) = 0 LIMIT 1'
        );
        $this->bind($stmt, 'ii', $landingPageId, $this->userId);
        $this->execute($stmt, 'Landing page lookup failed');
        $row = $this->resultOf($stmt, 'Landing page lookup failed')->fetch_assoc();
        $stmt->close();

        return $row !== null && (int) $row['landing_page_type'] === 1;
    }

    private function assertPublicIdFree(int $publicId, ?int $trackerId): void
    {
        if ($publicId <= 0) {
            throw new ValidationException('Validation failed', [
                'tracker_id_public' => 'A positive whole number; leave it out and one is chosen',
            ]);
        }
        if ($this->guardPublicIdLookup(fn (): bool => TrackerPublicId::isTaken($this->db, $publicId, $trackerId))) {
            throw new ValidationException('Validation failed', [
                'tracker_id_public' => "$publicId is another tracker's public id (the t202id in its link); leave it out and a free one is chosen",
            ]);
        }
    }

    /**
     * @template T
     * @param callable(): T $lookup
     * @return T
     */
    private function guardPublicIdLookup(callable $lookup): mixed
    {
        try {
            return $lookup();
        } catch (\RuntimeException $e) {
            throw new DatabaseException('Tracker public id lookup failed', $e);
        }
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
        $baseUrl = $this->trackingBaseUrl($server);
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
     * caller can fall back to a redirect-handler URL. Get Links' list of
     * saved links (get_trackers.php) builds its landing page links here too.
     */
    public static function buildLandingPageUrl(string $landingPageUrl, int $publicId, string $variables = ''): string
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

    /**
     * The live custom variables of the traffic source this tracker's account
     * belongs to — this user's account, and a source of the same user's, as
     * Get Links reads them (get_trackers.php joins the source on
     * `pn.user_id` and takes the variables of the account's own sources).
     * The source was joined on its id alone, and a variable has no user_id
     * of its own (it is owned through its source, CLAUDE.md #27), so an
     * account row naming another account's source -- one written before
     * links were checked -- put that account's parameters and placeholders
     * into this account's link (measured live).
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
             INNER JOIN 202_ppc_networks n ON n.ppc_network_id = a.ppc_network_id AND n.user_id = a.user_id
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
