<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Apps\AppIdentity;
use Api\V3\Apps\Android\Integrity\IntegrityState;
use Api\V3\Apps\Android\InstallToken;
use Api\V3\Apps\Android\InstallTokenKey;
use Api\V3\Apps\Android\MatchState;
use Api\V3\HttpException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\MysqliStatements;
use Api\V3\Support\ResponseSanitizer;

/**
 * The operator's view of an Android registration's installs (plan §5.3,
 * §5.6), all reads:
 *
 *  - GET /apps/{id}/installs              the installs, newest first, with
 *    their MatchState, reason and trust, filterable;
 *  - GET /apps/{id}/installs/{uuid}       one install;
 *  - GET /apps/{id}/install-token?click_id=N   the install token for one of
 *    the caller's clicks and the store link that carries it — what the
 *    redirect writes into a `[[p202_install_token]]` link, for building a
 *    link by hand and for `p202 app install simulate`.
 *
 * Installs are written only by the public intake (POST /apps/installs);
 * the API never mutates them. The referrer fields and versions arrive from
 * an open endpoint, so every string is passed through ResponseSanitizer on
 * the way out, and the raw body is never served.
 */
final class AppInstallsController
{
    use MysqliStatements;

    private const COLUMNS = 'install_row_id, registration_id, install_uuid, store, click_id, conversion_id, match_state, match_reason, '
        . 'trusted, is_test, referrer_status, referrer_raw, referrer_truncated, utm_source, utm_medium, utm_campaign, utm_term, '
        . 'utm_content, gclid, referrer_click_at, install_begin_at, referrer_click_server_at, install_begin_server_at, '
        . 'install_version, google_play_instant, app_version, sdk_version, os_version, integrity_mode, integrity_state, integrity_reason, '
        . 'integrity_attempts, integrity_next_at, integrity_checked_at, integrity_verdict, first_open_at, '
        . 'received_at, settled_at, remote_ip';

    private const UNTRUSTED_FIELDS = [
        'referrer_raw', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid',
        'install_version', 'app_version', 'sdk_version', 'os_version', 'remote_ip',
        // Carries Google's own error text when a decode failed.
        'integrity_reason',
    ];

    private const INT_FIELDS = [
        'install_row_id', 'registration_id', 'click_id', 'conversion_id', 'trusted', 'referrer_click_at', 'install_begin_at',
        'referrer_click_server_at', 'install_begin_server_at', 'first_open_at', 'received_at', 'settled_at',
        'integrity_attempts', 'integrity_next_at', 'integrity_checked_at',
    ];

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /** @param array<string, mixed> $params */
    public function list(int $registrationId, array $params): array
    {
        $this->registration($registrationId);
        $allowed = ['match_state', 'integrity_state', 'trusted', 'test', 'click_id', 'time_from', 'time_to', 'limit', 'offset'];
        $unknown = array_diff(array_map('strval', array_keys($params)), $allowed);
        if ($unknown !== []) {
            throw new ValidationException('Unknown filter', array_fill_keys(array_values($unknown), 'is not a filter here (allowed: ' . implode(', ', $allowed) . ')'));
        }
        $limit = self::intParam($params, 'limit', 50, 1, 500);
        $offset = self::intParam($params, 'offset', 0, 0, PHP_INT_MAX);

        $where = ['user_id = ?', 'registration_id = ?'];
        $types = 'ii';
        $binds = [$this->userId, $registrationId];
        if (isset($params['match_state'])) {
            $state = MatchState::tryFrom((string) $params['match_state']);
            if ($state === null) {
                throw new ValidationException('Invalid match_state', ['match_state' => 'must be one of ' . implode(', ', MatchState::values())]);
            }
            $where[] = 'match_state = ?';
            $types .= 's';
            $binds[] = $state->value;
        }
        if (isset($params['integrity_state'])) {
            $integrity = IntegrityState::tryFrom((string) $params['integrity_state']);
            if ($integrity === null) {
                throw new ValidationException('Invalid integrity_state', ['integrity_state' => 'must be one of ' . implode(', ', IntegrityState::values())]);
            }
            $where[] = 'integrity_state = ?';
            $types .= 's';
            $binds[] = $integrity->value;
        }
        if (isset($params['trusted'])) {
            $where[] = match ((string) $params['trusted']) {
                '1', 'trusted' => 'trusted = 1',
                '0', 'refuted' => 'trusted = 0',
                'unvouched' => 'trusted IS NULL',
                default => throw new ValidationException('Invalid trusted', ['trusted' => 'must be trusted (1), refuted (0) or unvouched']),
            };
        }
        if (isset($params['test'])) {
            $test = (string) $params['test'];
            if ($test !== '0' && $test !== '1') {
                throw new ValidationException('Invalid test', ['test' => 'must be 0 or 1']);
            }
            $where[] = 'is_test = ' . (int) $test;
        }
        foreach (['click_id' => 'click_id = ?', 'time_from' => 'received_at >= ?', 'time_to' => 'received_at <= ?'] as $key => $clause) {
            if (isset($params[$key])) {
                $where[] = $clause;
                $types .= 'i';
                $binds[] = self::intParam($params, $key, 0, $key === 'click_id' ? 1 : 0, PHP_INT_MAX);
            }
        }
        $clause = implode(' AND ', $where);

        $stmt = $this->prepare('SELECT COUNT(*) AS total FROM 202_app_installs WHERE ' . $clause);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Install count failed');
        $count = $this->result($stmt)->fetch_assoc();
        $stmt->close();
        if (!is_array($count) || !isset($count['total'])) {
            throw new HttpException('Install count returned no row', 500);
        }

        $stmt = $this->prepare('SELECT ' . self::COLUMNS . ' FROM 202_app_installs WHERE ' . $clause
            . ' ORDER BY received_at DESC, install_row_id DESC LIMIT ? OFFSET ?');
        $this->bind($stmt, $types . 'ii', ...[...$binds, $limit, $offset]);
        $this->execute($stmt, 'Install list failed');
        $result = $this->result($stmt);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = self::present($row);
        }
        $stmt->close();

        return ['data' => $rows, 'pagination' => ['total' => (int) $count['total'], 'limit' => $limit, 'offset' => $offset]];
    }

    public function get(int $registrationId, string $installUuid): array
    {
        $this->registration($registrationId);
        $stmt = $this->prepare('SELECT ' . self::COLUMNS . ' FROM 202_app_installs WHERE user_id = ? AND registration_id = ? AND install_uuid = ? LIMIT 1');
        $this->bind($stmt, 'iis', $this->userId, $registrationId, $installUuid);
        $this->execute($stmt, 'Install lookup failed');
        $row = $this->result($stmt)->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            throw new NotFoundException('Install not found');
        }

        return ['data' => self::present($row)];
    }

    /** @param array<string, mixed> $params */
    public function installToken(int $registrationId, array $params): array
    {
        $registration = $this->registration($registrationId);
        if ($registration['platform'] !== AppIdentity::ANDROID) {
            throw new ValidationException('Install tokens are Android\'s', [
                'id' => 'registration ' . $registrationId . ' is an iOS app; install tokens ride Google Play store links',
            ]);
        }
        $unknown = array_diff(array_map('strval', array_keys($params)), ['click_id']);
        if ($unknown !== [] || !isset($params['click_id'])) {
            throw new ValidationException('Name one click', ['click_id' => 'is required: a click id from GET /clicks']);
        }
        $clickId = self::intParam($params, 'click_id', 0, 1, PHP_INT_MAX);

        $stmt = $this->prepare(
            'SELECT c.click_id, c.click_time, ac.app_registration_id FROM 202_clicks c
             LEFT JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id
             WHERE c.click_id = ? AND c.user_id = ? LIMIT 1'
        );
        $this->bind($stmt, 'ii', $clickId, $this->userId);
        $this->execute($stmt, 'Click lookup failed');
        $click = $this->result($stmt)->fetch_assoc();
        $stmt->close();
        if (!is_array($click)) {
            throw new NotFoundException('Click ' . $clickId . ' not found');
        }
        if ($click['app_registration_id'] !== null && (int) $click['app_registration_id'] !== $registrationId) {
            throw new ValidationException('The click\'s campaign is linked to another app', [
                'click_id' => 'click ' . $clickId . '\'s campaign is linked to app registration ' . (int) $click['app_registration_id']
                    . '; an install of this app on it would be foreign_click',
            ]);
        }

        try {
            $key = InstallTokenKey::load($this->db);
        } catch (\RuntimeException $e) {
            throw new HttpException('The install-token key cannot be read: ' . $e->getMessage(), 500);
        }
        if ($key === null) {
            throw new HttpException('This server has no install-token key; run the upgrade (upgrade.php), which mints it.', 503);
        }
        $token = InstallToken::forClick($clickId, $key);
        $referrer = 'p202=' . $token;

        return ['data' => [
            'registration_id' => $registrationId,
            'click_id' => $clickId,
            // What `p202 app install simulate` places Google's timestamps
            // after, so the simulated install is plausible.
            'click_time' => (int) $click['click_time'],
            'install_token' => $token,
            'referrer' => $referrer,
            'store_url' => 'https://play.google.com/store/apps/details?id=' . rawurlencode((string) $registration['app_key'])
                . '&referrer=' . rawurlencode($referrer),
        ]];
    }

    /** @return array{platform: string, app_key: string} */
    private function registration(int $registrationId): array
    {
        $stmt = $this->prepare('SELECT platform, app_key FROM 202_app_registrations WHERE registration_id = ? AND user_id = ? LIMIT 1');
        $this->bind($stmt, 'ii', $registrationId, $this->userId);
        $this->execute($stmt, 'Registration lookup failed');
        $row = $this->result($stmt)->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            throw new NotFoundException('App registration ' . $registrationId . ' not found');
        }

        return ['platform' => (string) $row['platform'], 'app_key' => (string) $row['app_key']];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $row = ResponseSanitizer::cleanRowFields($row, self::UNTRUSTED_FIELDS, 2100);
        foreach (self::INT_FIELDS as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['is_test'] = (int) $row['is_test'] === 1;
        $row['referrer_truncated'] = (int) $row['referrer_truncated'] === 1;
        $row['google_play_instant'] = $row['google_play_instant'] === null ? null : (int) $row['google_play_instant'] === 1;
        // The verdict summary the worker stored (the fields the policy read,
        // never the token), as an object; a value that does not parse is
        // shown as unreadable rather than dropped.
        $verdict = $row['integrity_verdict'];
        if ($verdict !== null) {
            $decoded = json_decode((string) $verdict, true);
            $row['integrity_verdict'] = is_array($decoded) ? ResponseSanitizer::cleanRowFields($decoded, array_keys(array_filter($decoded, 'is_string')), 200) : ['unreadable' => true];
        }

        return $row;
    }

    /** @param array<string, mixed> $params */
    private static function intParam(array $params, string $key, int $default, int $min, int $max): int
    {
        if (!array_key_exists($key, $params)) {
            return $default;
        }
        $raw = $params[$key];
        if (is_int($raw)) {
            $raw = (string) $raw;
        }
        if (!is_string($raw) || preg_match('/^(0|[1-9][0-9]{0,18})$/D', $raw) !== 1 || (string) (int) $raw !== $raw
            || (int) $raw < $min || (int) $raw > $max) {
            throw new ValidationException('Invalid ' . $key, [$key => 'must be a whole number from ' . $min . ($max === PHP_INT_MAX ? '' : ' to ' . $max)]);
        }

        return (int) $raw;
    }
}
