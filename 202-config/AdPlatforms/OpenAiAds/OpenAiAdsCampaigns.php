<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms\OpenAiAds;

use Prosper202\AdPlatforms\AdConnectionInputException;
use Prosper202\AdPlatforms\AdConnectionStore;

/**
 * A ChatGPT Ads connection's campaigns, ad groups and ads, read from and
 * changed at OpenAI with the connection's Advertiser API key: status, review
 * status, budget and serving issues; pause and activate at each level; the
 * tracking template (landing_page_configuration.query_string_template); the
 * conversion event settings a campaign counts; and spend by campaign.
 *
 * Every write can be previewed ($dryRun): the object is read and what would
 * change is answered, and nothing is sent. Attaching event settings is a
 * read, a merge and a write, because OpenAI's conversion_event_setting_ids
 * replaces the list: a setting someone else attached between the read and
 * the write is lost, which the answer cannot know.
 *
 * Not here (spec 7 G): creating campaigns, ads or creatives, budgets and
 * bids, audiences, feeds, bulk jobs.
 */
final class OpenAiAdsCampaigns
{
    /** Levels as the API path names them => OpenAI's path. */
    public const LEVELS = ['campaigns' => 'campaigns', 'ad-groups' => 'ad_groups', 'ads' => 'ads'];
    public const PAGE_LIMIT = 100;
    public const MAX_PAGE_LIMIT = 500;
    public const SPEND_MAX_DAYS = 365;
    private const SPEND_PAGE = 2000;
    private const SPEND_MAX_PAGES = 5;
    private const OBJECT_ID = '/\A[a-z]+_[A-Za-z0-9-]{1,64}\z/D';

    public function __construct(private readonly AdConnectionStore $store, private readonly AdvertiserApi $api)
    {
    }

    /**
     * One page of a level, newest first as OpenAI orders it, with serving issues. $parentId narrows ad groups to a
     * campaign and ads to an ad group. Null when the account has no such connection.
     *
     * @return array{data: list<array<string, mixed>>, has_more: bool, after: ?string}|null
     */
    public function list(int $userId, int $connectionId, string $level, ?string $parentId, int $limit, ?string $after): ?array
    {
        $path = self::path($level);
        $key = $this->key($userId, $connectionId);
        if ($key === null) {
            return null;
        }
        $query = ['include' => ['serving_issues'], 'limit' => $limit];
        if ($parentId !== null) {
            $query[$level === 'ad-groups' ? 'campaign_id' : 'ad_group_id'] = self::objectId($parentId, $level === 'ad-groups' ? 'campaign_id' : 'ad_group_id');
        }
        if ($after !== null) {
            $query['after'] = self::cursor($after);
        }
        $answer = $this->called($this->api->get($key, $path, $query), 'list the ' . str_replace('-', ' ', $level));
        $rows = $answer['data'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new AdConnectionInputException('connection', 'OpenAI\'s answer has no list of ' . str_replace('-', ' ', $level) . '; try again.');
        }
        $last = $answer['last_id'] ?? null;
        $more = ($answer['has_more'] ?? false) === true;

        return [
            'data' => array_map(fn (mixed $row): array => self::shape($level, is_array($row) ? $row : []), $rows),
            'has_more' => $more,
            'after' => $more && is_string($last) && $last !== '' ? $last : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function get(int $userId, int $connectionId, string $level, string $objectId): ?array
    {
        $key = $this->key($userId, $connectionId);
        if ($key === null) {
            return null;
        }

        return self::shape($level, $this->read($key, $level, $objectId));
    }

    /**
     * Pause or activate a campaign, ad group or ad. Null when the account has no such connection.
     *
     * @return array<string, mixed>|null
     */
    public function setStatus(int $userId, int $connectionId, string $level, string $objectId, string $action, bool $dryRun): ?array
    {
        if ($action !== 'pause' && $action !== 'activate') {
            throw new \LogicException('action is pause or activate');
        }
        $key = $this->key($userId, $connectionId);
        if ($key === null) {
            return null;
        }
        $objectId = self::objectId($objectId, 'id');
        $to = $action === 'pause' ? 'paused' : 'active';
        if ($dryRun) {
            $current = self::shape($level, $this->read($key, $level, $objectId));

            return self::preview($action, $level, $current, ['status' => ['from' => $current['status'], 'to' => $to]]);
        }
        $done = $this->called($this->api->post($key, self::path($level) . '/' . $objectId . '/' . $action, []), $action . ' ' . $objectId);

        return ['action' => $action, 'level' => $level, 'record' => self::shape($level, $done)];
    }

    /**
     * Set the tracking template OpenAI appends to the ad's destination URL, on a campaign, an ad group or an ad
     * (the lowest level set wins). Null when the account has no such connection.
     *
     * @return array<string, mixed>|null
     */
    public function setTemplate(int $userId, int $connectionId, string $level, string $objectId, ?string $template, bool $dryRun): ?array
    {
        $template = self::template($template ?? TrackingTemplate::default());
        $key = $this->key($userId, $connectionId);
        if ($key === null) {
            return null;
        }
        $objectId = self::objectId($objectId, 'id');
        if ($dryRun) {
            $current = self::shape($level, $this->read($key, $level, $objectId));

            return self::preview('set-template', $level, $current, ['query_string_template' => ['from' => $current['query_string_template'], 'to' => $template]]);
        }
        $done = $this->called(
            $this->api->post($key, self::path($level) . '/' . $objectId, ['landing_page_configuration' => ['query_string_template' => $template]]),
            'set the tracking template of ' . $objectId
        );

        return ['action' => 'set-template', 'level' => $level, 'record' => self::shape($level, $done)];
    }

    /**
     * Add conversion event settings to a campaign's (the ones setup made when $ids is empty), keeping the ones it
     * has. Null when the account has no such connection.
     *
     * @param list<string> $ids
     * @return array<string, mixed>|null
     */
    public function attachEventSettings(int $userId, int $connectionId, string $campaignId, array $ids, bool $dryRun): ?array
    {
        $connection = $this->store->forCalling($userId, $connectionId);
        if ($connection === null) {
            return null;
        }
        $key = $connection['credentials']['advertiser_api_key'] ?? '';
        $campaignId = self::objectId($campaignId, 'id');
        if ($ids === []) {
            foreach ((array) ($connection['setup']['event_settings'] ?? []) as $setting) {
                if (is_array($setting) && is_string($setting['id'] ?? null)) {
                    $ids[] = $setting['id'];
                }
            }
            if ($ids === []) {
                throw new AdConnectionInputException('event_setting_ids', 'Setup has made no event settings for this connection yet: run p202 ad-connection setup, or name the event settings (ces_…) to attach.');
            }
        }
        foreach ($ids as $id) {
            self::objectId($id, 'event_setting_ids');
        }
        $current = self::shape('campaigns', $this->read($key, 'campaigns', $campaignId));
        $had = $current['conversion_event_setting_ids'];
        $merged = array_values(array_unique([...$had, ...$ids]));
        $change = ['conversion_event_setting_ids' => ['from' => $had, 'to' => $merged]];
        if ($dryRun || $merged === $had) {
            return array_replace(self::preview('attach-event-settings', 'campaigns', $current, $change), $dryRun ? [] : ['dry_run' => false, 'unchanged' => true]);
        }
        $done = $this->called($this->api->post($key, 'campaigns/' . $campaignId, ['conversion_event_setting_ids' => $merged]), 'attach event settings to ' . $campaignId);

        return ['action' => 'attach-event-settings', 'level' => 'campaigns', 'record' => self::shape('campaigns', $done)];
    }

    /**
     * Spend, impressions and clicks by campaign over whole days in the ad account's own time zone ($since to $until,
     * both included; yesterday when both are null), as OpenAI reports them now. Null when the account has no such
     * connection.
     *
     * @return array<string, mixed>|null
     */
    public function spend(int $userId, int $connectionId, ?string $since, ?string $until, int $now): ?array
    {
        $connection = $this->store->get($userId, $connectionId);
        if ($connection === null) {
            return null;
        }
        $account = (array) $connection['account'];
        $timezone = (string) ($account['timezone'] ?? '');
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new AdConnectionInputException('connection', 'The ad account\'s time zone ' . $timezone . ' is not one this server knows, so its days cannot be named; p202 ad-connection check reads it again.');
        }
        [$since, $until] = self::days($since, $until, $zone, $now);
        $key = $this->key($userId, $connectionId) ?? throw new \RuntimeException('ad connection ' . $connectionId . ' was deleted while its spend was read');
        $range = json_encode(['type' => 'date_range', 'since' => $since, 'until' => $until], JSON_THROW_ON_ERROR);
        $campaigns = [];
        $after = null;
        for ($page = 0; $page < self::SPEND_MAX_PAGES; $page++) {
            $answer = $this->called($this->api->get($key, 'ad_account/insights', [
                'time_granularity' => 'none',
                'aggregation_level' => 'campaign',
                'fields' => ['campaign.id', 'campaign.name', 'campaign.impressions', 'campaign.clicks', 'campaign.spend'],
                'time_ranges' => [$range],
                'limit' => self::SPEND_PAGE,
            ] + ($after === null ? [] : ['after' => $after])), 'read spend');
            foreach ((array) ($answer['data'] ?? []) as $row) {
                if (!is_array($row) || !is_string($row['campaign_id'] ?? null)) {
                    continue;
                }
                $campaigns[] = [
                    'campaign_id' => $row['campaign_id'],
                    'name' => is_string($row['campaign_name'] ?? null) ? $row['campaign_name'] : '',
                    'impressions' => is_int($row['impressions'] ?? null) ? $row['impressions'] : 0,
                    'clicks' => is_int($row['clicks'] ?? null) ? $row['clicks'] : 0,
                    'spend' => is_int($row['spend'] ?? null) || is_float($row['spend'] ?? null) ? $row['spend'] : 0,
                ];
            }
            $last = $answer['last_id'] ?? null;
            if (($answer['has_more'] ?? false) !== true || !is_string($last) || $last === '' || $last === $after) {
                $after = null;
                break;
            }
            $after = $last;
        }
        if ($after !== null) {
            throw new AdConnectionInputException('since', 'The account has more than ' . (self::SPEND_PAGE * self::SPEND_MAX_PAGES) . ' campaigns with spend in that window; ask for fewer days.');
        }
        usort($campaigns, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return [
            'since' => $since,
            'until' => $until,
            'timezone' => $timezone,
            'currency' => (string) ($account['currency'] ?? ''),
            'currency_matches_account' => $connection['currency_matches_account'],
            'total' => [
                'impressions' => array_sum(array_column($campaigns, 'impressions')),
                'clicks' => array_sum(array_column($campaigns, 'clicks')),
                'spend' => round((float) array_sum(array_column($campaigns, 'spend')), 6),
            ],
            'campaigns' => $campaigns,
            'note' => 'As OpenAI reports it now: spend settles later than clicks, so the last days can still change.',
        ];
    }

    /** The OpenAI path of a level, or a refusal naming the levels. */
    public static function path(string $level): string
    {
        return self::LEVELS[$level] ?? throw new AdConnectionInputException('level', 'level must be one of: ' . implode(', ', array_keys(self::LEVELS)) . '.');
    }

    /** An OpenAI object id (cmpn_…, adgrp_…, ad_…, ces_…): it goes into a request path, so nothing else may. */
    public static function objectId(string $id, string $field): string
    {
        if (preg_match(self::OBJECT_ID, $id) !== 1) {
            throw new AdConnectionInputException($field, $field . ' must be an id as ChatGPT Ads shows it (cmpn_…, adgrp_…, ad_…, ces_…): letters, _, then letters, digits or -.');
        }

        return $id;
    }

    /** A tracking template OpenAI takes: at most 2048 characters, a query string with no spaces or control characters. */
    public static function template(string $template): string
    {
        $template = ltrim(trim($template), '?');
        if ($template === '' || strlen($template) > TrackingTemplate::MAX_LENGTH || preg_match('/[\x00-\x20\x7f#]/', $template) === 1) {
            throw new AdConnectionInputException('template', 'template must be a query string of at most ' . TrackingTemplate::MAX_LENGTH . ' characters with no spaces or #, such as ' . TrackingTemplate::default() . '.');
        }

        return $template;
    }

    /**
     * The days asked for, checked: YYYY-MM-DD, $since not after $until, at most SPEND_MAX_DAYS, none after today
     * in $zone. Both null is yesterday there.
     *
     * @return array{0: string, 1: string}
     */
    public static function days(?string $since, ?string $until, \DateTimeZone $zone, int $now): array
    {
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->format('Y-m-d');
        if ($since === null && $until === null) {
            $yesterday = \DateTimeImmutable::createFromFormat('!Y-m-d', $today, $zone);
            $day = $yesterday === false ? $today : $yesterday->modify('-1 day')->format('Y-m-d');

            return [$day, $day];
        }
        if ($since === null || $until === null) {
            throw new AdConnectionInputException($since === null ? 'since' : 'until', 'Give since and until together (YYYY-MM-DD, in the ad account\'s time zone), or neither for yesterday.');
        }
        $parsed = [];
        foreach (['since' => $since, 'until' => $until] as $field => $value) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
            if ($day === false || $day->format('Y-m-d') !== $value) {
                throw new AdConnectionInputException($field, $field . ' must be a date, YYYY-MM-DD.');
            }
            $parsed[$field] = $day;
        }
        if ($parsed['since'] > $parsed['until']) {
            throw new AdConnectionInputException('since', 'since is after until.');
        }
        if ($until > $today) {
            throw new AdConnectionInputException('until', 'until is after today (' . $today . ' in the ad account\'s time zone).');
        }
        if ((int) $parsed['since']->diff($parsed['until'])->days >= self::SPEND_MAX_DAYS) {
            throw new AdConnectionInputException('since', 'At most ' . self::SPEND_MAX_DAYS . ' days at a time.');
        }

        return [$since, $until];
    }

    /**
     * A campaign, ad group or ad as Prosper202 shows it: what decides whether it serves and what it costs.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function shape(string $level, array $row): array
    {
        $string = static fn (string $k): ?string => is_string($row[$k] ?? null) ? $row[$k] : null;
        $issues = [];
        foreach ((array) ($row['serving_issues'] ?? []) as $issue) {
            if (is_array($issue) && is_string($issue['code'] ?? null)) {
                $issues[] = $issue['code'];
            }
        }
        $landing = is_array($row['landing_page_configuration'] ?? null) ? $row['landing_page_configuration'] : [];
        $shaped = [
            'id' => $string('id'),
            'name' => $string('name'),
            'status' => $string('status'),
            'serving_issues' => $issues,
            'query_string_template' => is_string($landing['query_string_template'] ?? null) ? $landing['query_string_template'] : null,
        ];
        if ($level === 'campaigns') {
            $budget = is_array($row['budget'] ?? null) ? $row['budget'] : [];
            $micros = static fn (string $k): ?int => is_int($budget[$k] ?? null) ? $budget[$k] : null;
            $shaped += [
                'budget' => ['daily_spend_limit_micros' => $micros('daily_spend_limit_micros'), 'lifetime_spend_limit_micros' => $micros('lifetime_spend_limit_micros')],
                'bidding_type' => $string('bidding_type'),
                'start_time' => is_int($row['start_time'] ?? null) ? $row['start_time'] : null,
                'end_time' => is_int($row['end_time'] ?? null) ? $row['end_time'] : null,
                'conversion_event_setting_ids' => array_values(array_filter((array) ($row['conversion_event_setting_ids'] ?? []), 'is_string')),
            ];
        }
        if ($level === 'ads') {
            $review = is_array($row['review'] ?? null) ? $row['review'] : [];
            $shaped += [
                'review_status' => $string('review_status'),
                'review_reason' => is_string($review['reason'] ?? null) ? $review['reason'] : null,
            ];
        }

        return $shaped;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, array{from: mixed, to: mixed}> $change
     * @return array<string, mixed>
     */
    private static function preview(string $action, string $level, array $record, array $change): array
    {
        return ['dry_run' => true, 'action' => $action, 'level' => $level, 'record' => $record, 'change' => $change];
    }

    /** @return array<string, mixed> */
    private function read(#[\SensitiveParameter] string $key, string $level, string $objectId): array
    {
        $objectId = self::objectId($objectId, 'id');

        return $this->called($this->api->get($key, self::path($level) . '/' . $objectId, ['include' => ['serving_issues']]), 'read ' . $objectId);
    }

    /** The connection's Advertiser API key; null when the account has no such connection. */
    private function key(int $userId, int $connectionId): ?string
    {
        $connection = $this->store->forCalling($userId, $connectionId);

        return $connection === null ? null : ($connection['credentials']['advertiser_api_key'] ?? '');
    }

    /**
     * An answer's JSON, or the refusal that says why there is none.
     *
     * @return array<string, mixed>
     */
    private function called(AdvertiserAnswer $answer, string $what): array
    {
        if ($answer->isOk()) {
            return $answer->json;
        }
        $field = $answer->kind === AdvertiserAnswer::NOT_FOUND ? 'id' : 'connection';
        $why = match ($answer->kind) {
            AdvertiserAnswer::KEY_REFUSED => ' The Advertiser API key was refused: p202 ad-connection check, then update --api-key-file.',
            AdvertiserAnswer::NOT_FOUND => ' This ad account has no such object; list them first.',
            default => '',
        };

        throw new AdConnectionInputException($field, 'Could not ' . $what . ': ' . $answer->error . '.' . $why, $answer->kind);
    }

    private static function cursor(string $after): string
    {
        if (preg_match('/\A[A-Za-z0-9_:=-]{1,200}\z/D', $after) !== 1) {
            throw new AdConnectionInputException('after', 'after must be the cursor a previous page answered.');
        }

        return $after;
    }
}
