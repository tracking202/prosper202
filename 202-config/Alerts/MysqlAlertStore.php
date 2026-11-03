<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

use Prosper202\Database\Connection;

/**
 * The alerts' settings per account (202_alert_settings, no row is the
 * defaults) and the incident each alert was last sent for per campaign
 * (202_alert_state).
 */
final class MysqlAlertStore
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public function settings(int $userId): AlertSettings
    {
        $stmt = $this->conn->prepareRead(
            'SELECT user_id, enabled, notify_slack, notify_email, notify_webhook, gap_multiple,'
            . ' min_conversions, daily_cost_cap FROM 202_alert_settings WHERE user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? AlertSettings::defaults() : self::settingsOf($row);
    }

    public function saveSettings(int $userId, AlertSettings $settings): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_alert_settings'
            . ' (user_id, enabled, notify_slack, notify_email, notify_webhook, gap_multiple, min_conversions,'
            . ' daily_cost_cap, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), notify_slack = VALUES(notify_slack),'
            . ' notify_email = VALUES(notify_email), notify_webhook = VALUES(notify_webhook),'
            . ' gap_multiple = VALUES(gap_multiple), min_conversions = VALUES(min_conversions),'
            . ' daily_cost_cap = VALUES(daily_cost_cap), updated_at = VALUES(updated_at)'
        );
        $this->conn->bind($stmt, 'iiiiiiisi', [
            $userId,
            $settings->enabled ? 1 : 0,
            $settings->notifySlack ? 1 : 0,
            $settings->notifyEmail ? 1 : 0,
            $settings->notifyWebhook ? 1 : 0,
            $settings->gapMultiple,
            $settings->minConversions,
            $settings->dailyCostCap,
            time(),
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * The accounts with alerts on, by id, with their settings. A row that
     * cannot be read is named in $unreadable rather than read as off.
     *
     * @param array<int, string> $unreadable user id => why, filled in
     * @return array<int, AlertSettings>
     */
    public function enabledAccounts(array &$unreadable = []): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT s.user_id, s.enabled, s.notify_slack, s.notify_email, s.notify_webhook, s.gap_multiple,'
            . ' s.min_conversions, s.daily_cost_cap'
            . ' FROM 202_alert_settings s INNER JOIN 202_users u ON u.user_id = s.user_id'
            . ' WHERE s.enabled <> 0 AND u.user_active = 1 AND u.user_deleted = 0 ORDER BY s.user_id'
        );
        $accounts = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $userId = (int) $row['user_id'];
            try {
                $accounts[$userId] = self::settingsOf($row);
            } catch (\UnexpectedValueException $e) {
                $unreadable[$userId] = $e->getMessage();
            }
        }

        return $accounts;
    }

    /**
     * The incident each alert was last sent for: "kind:campaign" => key.
     *
     * @return array<string, int>
     */
    public function sentIncidents(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT aff_campaign_id, alert_kind, incident_key FROM 202_alert_state WHERE user_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $sent = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $sent[$row['alert_kind'] . ':' . (int) $row['aff_campaign_id']] = (int) $row['incident_key'];
        }

        return $sent;
    }

    public static function incidentId(Alert $alert): string
    {
        return $alert->kind->stateKey() . ':' . $alert->campaignId;
    }

    public function recordSent(Alert $alert, int $now): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_alert_state (user_id, aff_campaign_id, alert_kind, incident_key, alerted_at)'
            . ' VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE incident_key = VALUES(incident_key), alerted_at = VALUES(alerted_at)'
        );
        $this->conn->bind($stmt, 'iisii', [
            $alert->userId,
            $alert->campaignId,
            $alert->kind->stateKey(),
            $alert->incidentKey,
            $now,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /** @param array<string, mixed> $row */
    private static function settingsOf(array $row): AlertSettings
    {
        $userId = (int) ($row['user_id'] ?? 0);
        $cap = $row['daily_cost_cap'] ?? null;
        if (is_float($cap) || is_int($cap)) {
            $cap = number_format((float) $cap, 2, '.', '');
        }
        try {
            return new AlertSettings(
                self::switch($row, 'enabled', $userId),
                self::switch($row, 'notify_slack', $userId),
                self::switch($row, 'notify_email', $userId),
                self::switch($row, 'notify_webhook', $userId),
                self::int($row, 'gap_multiple', $userId),
                self::int($row, 'min_conversions', $userId),
                is_string($cap) ? $cap : '',
            );
        } catch (\InvalidArgumentException $e) {
            throw new \UnexpectedValueException(
                "Account $userId's alert settings are stored out of range: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function switch(array $row, string $column, int $userId): bool
    {
        $value = self::int($row, $column, $userId);
        if ($value !== 0 && $value !== 1) {
            throw new \UnexpectedValueException(
                "Account $userId's alert setting $column is stored as $value, not 0 or 1"
            );
        }

        return $value === 1;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $column, int $userId): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException(
            "Account $userId's alert setting $column could not be read: " . var_export($value, true)
        );
    }
}
