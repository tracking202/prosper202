<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * The schedules still fetching paid 1.9.56's retired Google Ads feed
 * (202_legacy_google_feed_fetches), so Setup › Offline Conversions can say
 * so and move each one over with the values its address carried: cn, the
 * conversion action, and ac, the campaign (-- for every campaign).
 *
 * The feed's address takes no credential this version reads, so a row is
 * what a request said, not what an account did (CLAUDE.md #16): it names no
 * account, the table holds at most MAX_ROWS, and a row is shown to the
 * account whose campaign it names (ac=-- to every account that may export)
 * as the old address's words. Nothing is changed until that account says so.
 *
 * @phpstan-type Schedule array{campaign_id: int, conversion_name: string}
 * @phpstan-type Fetch array{fetch_id: int, campaign_id: int, campaign_name: ?string, campaign_deleted: bool,
 *     conversion_name: string, first_fetched_at: int, last_fetched_at: int, fetches: int,
 *     stored_name: ?string, feed: ?Feed, state: string}
 */
final class LegacyGoogleFeedFetches
{
    /** Schedules kept; a new one past this is dropped until a stale one makes room. */
    public const MAX_ROWS = 50;
    /** A schedule not fetched for this long is no longer shown: Google Ads' longest schedule is monthly. */
    public const SHOWN_FOR = 35 * 86400;
    /** What the old schedule was: Google's Uploads template, every conversion Google still imports. */
    public const FORMAT = Format::GOOGLE_UPLOADS;
    public const DAYS = ExportOptions::MAX_DAYS;

    /** The replacement is made and named; only the schedule's address in Google Ads is left. */
    public const MOVED = 'moved';
    /** One action moves it. */
    public const READY = 'ready';
    /** The campaign it names was deleted, so nothing replaces it. */
    public const GONE = 'gone';

    public function __construct(private Connection $conn)
    {
    }

    /**
     * The schedule a retired feed's query names, or null when it names none
     * this version can move: cn must be a name ConversionNames stores, ac a
     * campaign id or --.
     *
     * @param array<array-key, mixed> $query
     * @return Schedule|null
     */
    public static function schedule(array $query): ?array
    {
        $name = $query['cn'] ?? null;
        $campaign = $query['ac'] ?? null;
        if (!is_string($name) || !is_string($campaign) || ConversionNames::problem($name) !== null) {
            return null;
        }
        if ($campaign === '--') {
            return ['campaign_id' => 0, 'conversion_name' => $name];
        }
        if (preg_match('/\A[1-9][0-9]{0,8}\z/D', $campaign) !== 1) {
            return null;
        }

        return ['campaign_id' => (int) $campaign, 'conversion_name' => $name];
    }

    /**
     * Note a fetch of the retired feed. False when the table is full of
     * schedules fetched lately, and this one is not among them.
     *
     * @param Schedule $schedule
     */
    public function record(array $schedule, int $now): bool
    {
        $again = $this->conn->prepareWrite(
            'UPDATE ' . TableRegistry::LEGACY_GOOGLE_FEED_FETCHES
            . ' SET last_fetched_at = GREATEST(last_fetched_at, ?), fetches = LEAST(fetches + 1, 4000000000)'
            . ' WHERE aff_campaign_id = ? AND conversion_name = ?'
        );
        $this->conn->bind($again, 'iis', [$now, $schedule['campaign_id'], $schedule['conversion_name']]);
        if ($this->conn->executeUpdate($again) > 0) {
            return true;
        }
        $rows = $this->count();
        if ($rows >= self::MAX_ROWS) {
            $stale = $this->conn->prepareWrite(
                'DELETE FROM ' . TableRegistry::LEGACY_GOOGLE_FEED_FETCHES . ' WHERE last_fetched_at < ?'
            );
            $this->conn->bind($stale, 'i', [$now - self::SHOWN_FOR]);
            if ($rows - $this->conn->executeUpdate($stale) >= self::MAX_ROWS) {
                return false;
            }
        }
        $first = $this->conn->prepareWrite(
            'INSERT INTO ' . TableRegistry::LEGACY_GOOGLE_FEED_FETCHES
            . ' (aff_campaign_id, conversion_name, first_fetched_at, last_fetched_at, fetches) VALUES (?, ?, ?, ?, 1)'
            . ' ON DUPLICATE KEY UPDATE last_fetched_at = GREATEST(last_fetched_at, VALUES(last_fetched_at)),'
            . ' fetches = LEAST(fetches + 1, 4000000000)'
        );
        $this->conn->bind($first, 'isii', [$schedule['campaign_id'], $schedule['conversion_name'], $now, $now]);
        $this->conn->executeUpdate($first);

        return true;
    }

    /**
     * The schedules fetched lately that are the account's to move: those
     * naming one of its campaigns, and those naming every campaign. Each
     * says what the account already has for it (its Google name for that
     * campaign, a fetch URL of that name) and so what is left to do.
     *
     * @return list<Fetch> the latest fetched first
     */
    public function forAccount(int $userId, int $now): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT f.fetch_id, f.aff_campaign_id, f.conversion_name, f.first_fetched_at, f.last_fetched_at, f.fetches,'
            . ' c.aff_campaign_name, c.aff_campaign_deleted'
            . ' FROM ' . TableRegistry::LEGACY_GOOGLE_FEED_FETCHES . ' AS f'
            . ' LEFT JOIN 202_aff_campaigns AS c ON c.aff_campaign_id = f.aff_campaign_id AND c.user_id = ?'
            . ' WHERE f.last_fetched_at >= ? AND (f.aff_campaign_id = 0 OR c.aff_campaign_id IS NOT NULL)'
            . ' ORDER BY f.last_fetched_at DESC, f.fetch_id DESC'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $now - self::SHOWN_FOR]);
        $rows = $this->conn->fetchAll($stmt);
        if ($rows === []) {
            return [];
        }
        $names = (new ConversionNames($this->conn))->read($userId)[ConversionNames::GOOGLE];
        $feeds = (new FeedStore($this->conn))->list($userId);
        $fetches = [];
        foreach ($rows as $row) {
            $campaignId = StoredRow::int($row, 'aff_campaign_id');
            $name = StoredRow::text($row, 'conversion_name');
            $deleted = $campaignId > 0 && StoredRow::int($row, 'aff_campaign_deleted') !== 0;
            $stored = $campaignId === 0 ? $names['default'] : ($names['campaigns'][$campaignId] ?? null);
            $feed = null;
            foreach ($feeds as $candidate) {
                if ($candidate->format === self::FORMAT && $candidate->conversionName === $name) {
                    $feed = $candidate;
                    break;
                }
            }
            $fetches[] = [
                'fetch_id' => StoredRow::int($row, 'fetch_id'),
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignId === 0 ? null : StoredRow::text($row, 'aff_campaign_name'),
                'campaign_deleted' => $deleted,
                'conversion_name' => $name,
                'first_fetched_at' => StoredRow::int($row, 'first_fetched_at'),
                'last_fetched_at' => StoredRow::int($row, 'last_fetched_at'),
                'fetches' => StoredRow::int($row, 'fetches'),
                'stored_name' => $stored,
                'feed' => $feed,
                'state' => $deleted ? self::GONE : ($stored === $name && $feed !== null ? self::MOVED : self::READY),
            ];
        }

        return $fetches;
    }

    /**
     * Move one of the account's schedules over: its conversion action
     * becomes the Google name of the campaign it named (the account's name
     * for every other campaign, for ac=--), and a fetch URL is made in the
     * old schedule's format for that name alone, unless the account has one.
     *
     * @return array{fetch: Fetch, feed: Feed, key: ?string}|null null when the schedule is not the
     *         account's to move, or its campaign was deleted; key is null when the fetch URL was already there
     * @throws \OverflowException when the account has FeedStore::MAX_FEEDS already
     */
    public function move(int $userId, int $fetchId, int $now): ?array
    {
        $fetch = $this->find($userId, $fetchId, $now);
        if ($fetch === null || $fetch['state'] === self::GONE) {
            return null;
        }
        if ($fetch['stored_name'] !== $fetch['conversion_name']) {
            (new ConversionNames($this->conn))->save(
                $userId,
                [ConversionNames::GOOGLE => [$fetch['campaign_id'] => $fetch['conversion_name']]],
                $now
            );
        }
        if ($fetch['feed'] !== null) {
            return ['fetch' => $fetch, 'feed' => $fetch['feed'], 'key' => null];
        }
        [$feed, $key] = (new FeedStore($this->conn))->create($userId, [
            'format' => self::FORMAT,
            'days' => self::DAYS,
            'conversion_name' => $fetch['conversion_name'],
            'ad_user_data' => null,
            'ad_personalization' => null,
        ], $now);

        return ['fetch' => $fetch, 'feed' => $feed, 'key' => $key];
    }

    /**
     * Stop showing one of the account's schedules. It is shown again when
     * the old address is next fetched. Whether there was one.
     */
    public function dismiss(int $userId, int $fetchId, int $now): bool
    {
        if ($this->find($userId, $fetchId, $now) === null) {
            return false;
        }
        $stmt = $this->conn->prepareWrite(
            'DELETE FROM ' . TableRegistry::LEGACY_GOOGLE_FEED_FETCHES . ' WHERE fetch_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$fetchId]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    /** @return Fetch|null */
    private function find(int $userId, int $fetchId, int $now): ?array
    {
        foreach ($this->forAccount($userId, $now) as $fetch) {
            if ($fetch['fetch_id'] === $fetchId) {
                return $fetch;
            }
        }

        return null;
    }

    private function count(): int
    {
        $stmt = $this->conn->prepareWrite('SELECT COUNT(*) AS n FROM ' . TableRegistry::LEGACY_GOOGLE_FEED_FETCHES);

        return StoredRow::int($this->conn->fetchOne($stmt) ?? [], 'n');
    }
}
