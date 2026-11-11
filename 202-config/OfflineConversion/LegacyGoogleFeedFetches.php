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
 * account, the table is bounded (noteFetch()), and a row is shown to the
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
    /** Schedules kept in all. */
    public const MAX_ROWS = 50;
    /** Schedules kept for one campaign, and for ac=--. */
    public const PER_CAMPAIGN = 5;
    /** A schedule not fetched for this long gives its place to a new one when there is no room. */
    public const IDLE_FOR = 2 * 86400;
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
     * Note a fetch of the retired feed. False when it was not noted: its
     * campaign is not one of this install's, or the table holds as many
     * schedules as it keeps (MAX_ROWS in all, PER_CAMPAIGN for one campaign
     * or for ac=--) and none of them has gone IDLE_FOR without a fetch. A
     * real schedule is fetched again and again, so it keeps its place; a
     * row nobody fetches again gives its place up.
     *
     * @param Schedule $schedule
     */
    public function noteFetch(array $schedule, int $now): bool
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
        if ($schedule['campaign_id'] > 0) {
            // A campaign nobody has is a schedule nobody would be shown.
            $known = $this->conn->prepareWrite('SELECT 1 AS known FROM 202_aff_campaigns WHERE aff_campaign_id = ? LIMIT 1');
            $this->conn->bind($known, 'i', [$schedule['campaign_id']]);
            if ($this->conn->fetchOne($known) === null) {
                return false;
            }
        }
        if (
            !$this->makeRoom(' WHERE aff_campaign_id = ?', 'i', [$schedule['campaign_id']], self::PER_CAMPAIGN, $now)
            || !$this->makeRoom('', '', [], self::MAX_ROWS, $now)
        ) {
            return false;
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
     * Whether the rows $where selects leave room for one more under $cap,
     * after the one fetched longest ago is removed if it has gone IDLE_FOR
     * without a fetch.
     *
     * @param list<int> $values
     */
    private function makeRoom(string $where, string $types, array $values, int $cap, int $now): bool
    {
        $count = $this->conn->prepareWrite('SELECT COUNT(*) AS n FROM ' . TableRegistry::LEGACY_GOOGLE_FEED_FETCHES . $where);
        if ($values !== []) {
            $this->conn->bind($count, $types, $values);
        }
        if (StoredRow::int($this->conn->fetchOne($count) ?? [], 'n') < $cap) {
            return true;
        }
        $idle = $this->conn->prepareWrite(
            'DELETE FROM ' . TableRegistry::LEGACY_GOOGLE_FEED_FETCHES . ($where === '' ? ' WHERE' : $where . ' AND')
            . ' last_fetched_at < ? ORDER BY last_fetched_at, fetch_id LIMIT 1'
        );
        $this->conn->bind($idle, $types . 'i', [...$values, $now - self::IDLE_FOR]);

        return $this->conn->executeUpdate($idle) === 1;
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
     * How many of the account's schedules still wait to be moved over: what
     * Home and Account Overview say, until each is moved or hidden. One
     * naming every campaign counts only for an account that already sends
     * conversions to Google.
     */
    public function waiting(int $userId, int $now): int
    {
        $fetches = $this->forAccount($userId, $now);
        if ($fetches === []) {
            return 0;
        }
        // Anyone can request the old address, and one naming every campaign is shown to every account. It
        // is news on Home only for an account that sends Google conversions: it has a Google name or feed.
        $names = (new ConversionNames($this->conn))->read($userId)[ConversionNames::GOOGLE];
        $sendsToGoogle = $names['default'] !== null || $names['campaigns'] !== [];
        foreach ((new FeedStore($this->conn))->list($userId) as $feed) {
            $sendsToGoogle = $sendsToGoogle || $feed->format->platform() === ConversionNames::GOOGLE;
        }

        return count(array_filter(
            $fetches,
            static fn (array $fetch): bool => $fetch['state'] === self::READY && ($fetch['campaign_id'] > 0 || $sendsToGoogle)
        ));
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
        // Made once: a second click that raced the first finds the first's feed.
        [$feed, $key] = (new FeedStore($this->conn))->create($userId, [
            'format' => self::FORMAT,
            'days' => self::DAYS,
            'conversion_name' => $fetch['conversion_name'],
            'ad_user_data' => null,
            'ad_personalization' => null,
        ], $now, true);

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
}
