<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

use Api\V3\Controllers\UsersController;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Database\Connection;
use Prosper202\Report\AccountZone;

/**
 * An account's campaigns over the last AlertSettings::LOOKBACK_DAYS, in
 * three reads bounded by time: its campaigns, its conversions by arrival
 * (202_conversion_logs' user_conv_time) and its report rows by click time
 * (202_dataengine's user_id, click_time). Only the account's own campaigns
 * are read (a row naming another account's campaign id is not counted), and
 * "today" is the account's day in its own zone.
 */
final class AccountActivity
{
    /**
     * Sources that record a batch at once, not conversions as they happen:
     * left out of the rhythm (CampaignActivity), still conversions today.
     */
    public const BATCH_SOURCES = [ConversionSource::SUBID_UPLOAD, ConversionSource::REVENUE_UPLOAD];

    /** Milliseconds a week's read may take on MySQL (MariaDB ignores the hint). */
    public const READ_MS = 20000;

    /**
     * @param array<int, CampaignActivity> $campaigns by campaign id
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $now,
        public readonly string $timezone,
        public readonly int $dayStart,
        public readonly string $currency,
        public readonly array $campaigns,
    ) {
    }

    public static function windowStart(int $now): int
    {
        return $now - AlertSettings::LOOKBACK_DAYS * 86400;
    }

    /** The start of the day $now falls on in $timezone. */
    public static function dayStart(int $now, string $timezone): int
    {
        return (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone($timezone))
            ->setTime(0, 0)
            ->getTimestamp();
    }

    public static function read(Connection $conn, int $userId, int $now): self
    {
        $timezone = AccountZone::read($conn, $userId);
        $dayStart = self::dayStart($now, $timezone);
        $from = self::windowStart($now);

        $stmt = $conn->prepareRead('SELECT user_account_currency FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $conn->bind($stmt, 'i', [$userId]);
        $pref = $conn->fetchOne($stmt);
        $currency = UsersController::normalizeCurrency($pref['user_account_currency'] ?? null);

        $stmt = $conn->prepareRead(
            'SELECT aff_campaign_id, aff_campaign_name FROM 202_aff_campaigns'
            . ' WHERE user_id = ? AND aff_campaign_deleted = 0'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $names = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $names[(int) $row['aff_campaign_id']] = (string) $row['aff_campaign_name'];
        }
        if ($names === []) {
            return new self($userId, $now, $timezone, $dayStart, $currency, []);
        }

        // A reversal is not a conversion arriving, nor is a pre-ledger
        // value's baseline row; every other row is, payable or not.
        $batch = array_map(static fn (ConversionSource $s): string => $s->value, self::BATCH_SOURCES);
        $live = 'source NOT IN (' . implode(', ', array_fill(0, count($batch), '?')) . ')';
        $stmt = $conn->prepareRead(
            'SELECT /*+ MAX_EXECUTION_TIME(' . self::READ_MS . ') */ campaign_id,'
            . " SUM($live) AS conversions,"
            . " COUNT(DISTINCT CASE WHEN $live THEN conv_time DIV 3600 END) AS hours,"
            . " MIN(CASE WHEN $live THEN conv_time END) AS first_at,"
            . " MAX(CASE WHEN $live THEN conv_time END) AS last_at,"
            . ' SUM(conv_time >= ?) AS today'
            . ' FROM 202_conversion_logs'
            . ' WHERE user_id = ? AND conv_time >= ? AND conv_time <= ? AND deleted = 0 AND reverses_conv_id IS NULL'
            . ' AND source <> ?'
            . ' GROUP BY campaign_id'
        );
        $conn->bind($stmt, str_repeat('s', 4 * count($batch)) . 'iiiis', [
            ...$batch, ...$batch, ...$batch, ...$batch,
            $dayStart, $userId, $from, $now, ConversionSource::LEGACY_BASELINE->value,
        ]);
        $conversions = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $conversions[(int) $row['campaign_id']] = $row;
        }

        $stmt = $conn->prepareRead(
            'SELECT /*+ MAX_EXECUTION_TIME(' . self::READ_MS . ') */ aff_campaign_id,'
            . ' SUM(CASE WHEN click_filtered = 0 THEN clicks ELSE 0 END) AS real_clicks,'
            . ' MAX(CASE WHEN click_filtered = 0 AND clicks > 0 THEN click_time END) AS last_real_click,'
            . ' SUM(CASE WHEN click_time >= ? THEN clicks ELSE 0 END) AS clicks_today,'
            . ' SUM(CASE WHEN click_time >= ? THEN cost ELSE 0 END) AS cost_today'
            . ' FROM 202_dataengine'
            . ' WHERE user_id = ? AND click_time >= ? AND click_time <= ?'
            . ' GROUP BY aff_campaign_id'
        );
        $conn->bind($stmt, 'iiiii', [$dayStart, $dayStart, $userId, $from, $now]);
        $clicks = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $clicks[(int) $row['aff_campaign_id']] = $row;
        }

        $campaigns = [];
        foreach ($names as $id => $name) {
            $c = $conversions[$id] ?? null;
            $k = $clicks[$id] ?? null;
            if ($c === null && $k === null) {
                continue;
            }
            $campaigns[$id] = new CampaignActivity(
                $id,
                $name,
                (int) ($c['conversions'] ?? 0),
                (int) ($c['hours'] ?? 0),
                isset($c['first_at']) ? (int) $c['first_at'] : null,
                isset($c['last_at']) ? (int) $c['last_at'] : null,
                (int) ($c['today'] ?? 0),
                (int) ($k['real_clicks'] ?? 0),
                isset($k['last_real_click']) ? (int) $k['last_real_click'] : null,
                (int) ($k['clicks_today'] ?? 0),
                (float) ($k['cost_today'] ?? 0),
            );
        }

        return new self($userId, $now, $timezone, $dayStart, $currency, $campaigns);
    }

    /** Real clicks on a campaign after $after, up to now. */
    public function realClicksAfter(Connection $conn, int $campaignId, int $after): int
    {
        $stmt = $conn->prepareRead(
            'SELECT COALESCE(SUM(clicks), 0) AS n FROM 202_dataengine'
            . ' WHERE user_id = ? AND click_time > ? AND click_time <= ? AND aff_campaign_id = ? AND click_filtered = 0'
        );
        $conn->bind($stmt, 'iiii', [$this->userId, $after, $this->now, $campaignId]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            throw new \UnexpectedValueException('The count of real clicks answered no row');
        }

        return (int) $row['n'];
    }
}
