<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms\OpenAiAds;

use Prosper202\AdPlatforms\AdConnectionInputException;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\AdPlatforms\AdPlatformUnavailable;

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
 * OpenAI not reached, its rate limit and an answer that is not what its API
 * documents throw AdPlatformUnavailable: nothing the caller sent is wrong. A
 * refused key is an AdConnectionInputException naming api_key; an object the
 * ad account does not have, one naming id with $platform NOT_FOUND; OpenAI
 * refusing the request itself (a 400 or 409), $platform REFUSED.
 *
 * A write is done only when OpenAI's answer, or a read of the object after
 * it, shows the change on the object asked for. A write whose answer could be
 * a landed write (no answer, a 5xx, a 2xx that does not show the change) is
 * never answered as failed-try-again: the object is read back, and when that
 * does not settle it the answer is UNCONFIRMED, saying it may have been
 * applied (CLAUDE.md #13).
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
            throw new AdPlatformUnavailable(AdPlatformUnavailable::UNREADABLE, 'OpenAI\'s answer has no list of ' . str_replace('-', ' ', $level) . '.');
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
        // Read first: the preview, what was there before, and what cannot be changed.
        $current = self::shape($level, $this->read($key, $level, $objectId));
        if ($current['status'] === 'archived') {
            throw new AdConnectionInputException('id', $objectId . ' is archived at OpenAI: an archived object is neither paused nor activated, and nothing was sent.');
        }
        $ended = $level === 'campaigns' && is_int($current['end_time'] ?? null) && $current['end_time'] <= time();
        if ($action === 'activate' && $ended) {
            throw new AdConnectionInputException('id', $objectId . '\'s end time has passed at OpenAI, so it would not serve: change its schedule in ChatGPT Ads Manager first. Nothing was sent.');
        }
        $change = ['status' => ['from' => $current['status'], 'to' => $to]];
        if ($dryRun) {
            return self::preview($action, $level, $current, $change) + ($current['status'] === $to ? ['unchanged' => true] : []);
        }
        if ($current['status'] === $to) {
            return ['dry_run' => false, 'unchanged' => true, 'action' => $action, 'level' => $level, 'record' => $current, 'previous' => ['status' => $current['status']]];
        }
        $done = $this->written(
            $this->api->post($key, self::path($level) . '/' . $objectId . '/' . $action, []),
            $key,
            $level,
            $objectId,
            $action . ' ' . $objectId,
            static fn (array $record): bool => $record['status'] === $to
        );

        return ['action' => $action, 'level' => $level, 'record' => $done, 'previous' => ['status' => $current['status']]];
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
        $current = self::shape($level, $this->read($key, $level, $objectId));
        $previous = ['query_string_template' => $current['query_string_template']];
        if ($dryRun) {
            return self::preview('set-template', $level, $current, ['query_string_template' => ['from' => $current['query_string_template'], 'to' => $template]])
                + ['previous' => $previous] + ($current['query_string_template'] === $template ? ['unchanged' => true] : []);
        }
        if ($current['query_string_template'] === $template) {
            return ['dry_run' => false, 'unchanged' => true, 'action' => 'set-template', 'level' => $level, 'record' => $current, 'previous' => $previous];
        }
        $done = $this->written(
            $this->api->post($key, self::path($level) . '/' . $objectId, ['landing_page_configuration' => ['query_string_template' => $template]]),
            $key,
            $level,
            $objectId,
            'set the tracking template of ' . $objectId,
            static fn (array $record): bool => $record['query_string_template'] === $template
        );

        return ['action' => 'set-template', 'level' => $level, 'record' => $done, 'previous' => $previous];
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
        // OpenAI replaces the list: only a list it answered means what the campaign has. A missing or unreadable
        // one is not "none", or the write would remove the campaign's own (CLAUDE.md #4).
        if (!is_array($had)) {
            throw new AdPlatformUnavailable(AdPlatformUnavailable::UNREADABLE, 'OpenAI\'s answer for ' . $campaignId . ' has no readable list of its conversion event settings, so they cannot be added to: nothing was sent.');
        }
        $merged = array_values(array_unique([...$had, ...$ids]));
        $change = ['conversion_event_setting_ids' => ['from' => $had, 'to' => $merged]];
        if ($dryRun || $merged === $had) {
            return array_replace(self::preview('attach-event-settings', 'campaigns', $current, $change), $dryRun ? [] : ['dry_run' => false, 'unchanged' => true]);
        }
        $sorted = $merged;
        sort($sorted);
        $done = $this->written(
            $this->api->post($key, 'campaigns/' . $campaignId, ['conversion_event_setting_ids' => $merged]),
            $key,
            'campaigns',
            $campaignId,
            'attach event settings to ' . $campaignId,
            static function (array $record) use ($sorted): bool {
                $now = $record['conversion_event_setting_ids'];
                if (!is_array($now)) {
                    return false;
                }
                sort($now);

                return $now === $sorted;
            }
        );

        return ['action' => 'attach-event-settings', 'level' => 'campaigns', 'record' => $done, 'previous' => ['conversion_event_setting_ids' => $had]];
    }

    /**
     * Spend (major units of the ad account's currency, as OpenAI's Reporting reference says of spend, CPC and CPM;
     * budgets, unlike it, are micros), impressions and clicks by campaign over whole days in the ad account's own time zone ($since to $until,
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
        $cursors = [];
        for ($page = 0; $page < self::SPEND_MAX_PAGES; $page++) {
            $answer = $this->called($this->api->get($key, 'ad_account/insights', [
                'time_granularity' => 'none',
                'aggregation_level' => 'campaign',
                'fields' => ['campaign.id', 'campaign.name', 'campaign.impressions', 'campaign.clicks', 'campaign.spend'],
                'time_ranges' => [$range],
                'limit' => self::SPEND_PAGE,
            ] + ($after === null ? [] : ['after' => $after])), 'read spend');
            $rows = $answer['data'] ?? null;
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new AdPlatformUnavailable(AdPlatformUnavailable::UNREADABLE, 'OpenAI\'s spend answer has no list of campaigns.');
            }
            foreach ($rows as $row) {
                $campaigns[] = self::spendRow($row, $campaigns);
            }
            if (($answer['has_more'] ?? false) !== true) {
                $after = null;
                break;
            }
            // A next page needs a cursor that moves on: one seen before would read a page twice and count it twice.
            $last = $answer['last_id'] ?? null;
            if (!is_string($last) || $last === '' || isset($cursors[$last])) {
                throw new AdPlatformUnavailable(AdPlatformUnavailable::UNREADABLE, 'OpenAI\'s spend answer says more follow but gives no new cursor, so the rest cannot be read without counting a page twice.');
            }
            $cursors[$last] = true;
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
            'note' => 'As OpenAI reports it now, in the ad account\'s currency: spend settles later than clicks, so the last days can still change.',
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
                // null when OpenAI's answer has no list of ids: not the same as none (attachEventSettings).
                'conversion_event_setting_ids' => self::idList($row['conversion_event_setting_ids'] ?? null),
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
     * @throws AdPlatformUnavailable when OpenAI was not reached or is limiting requests: nothing about the request
     * @return array<string, mixed>
     */
    private function called(AdvertiserAnswer $answer, string $what): array
    {
        if ($answer->isOk()) {
            return $answer->json;
        }
        $said = 'Could not ' . $what . ': ' . $answer->error . '.';
        if ($answer->kind === AdvertiserAnswer::RATE_LIMITED || $answer->kind === AdvertiserAnswer::UNAVAILABLE) {
            $reason = $answer->kind === AdvertiserAnswer::RATE_LIMITED ? AdPlatformUnavailable::RATE_LIMITED : AdPlatformUnavailable::UNREACHABLE;

            throw new AdPlatformUnavailable($reason, $said);
        }
        [$field, $why] = match ($answer->kind) {
            AdvertiserAnswer::KEY_REFUSED => ['api_key', ' OpenAI refused the stored Advertiser API key: replace it with p202 ad-connection update --api-key-file.'],
            AdvertiserAnswer::NOT_FOUND => ['id', ' This ad account has no such object; list them first.'],
            AdvertiserAnswer::REFUSED => ['connection', ' That is OpenAI refusing it, not a field to change here: sending it again unchanged will not change the answer.'],
            default => ['connection', ''],
        };

        throw new AdConnectionInputException($field, $said . $why, $answer->kind);
    }

    /** @return list<string>|null */
    private static function idList(mixed $ids): ?array
    {
        if (!is_array($ids) || !array_is_list($ids)) {
            return null;
        }
        foreach ($ids as $id) {
            if (!is_string($id)) {
                return null;
            }
        }

        return $ids;
    }

    /**
     * One campaign's row of a spend answer, read strictly: a missing campaign id or a figure that is not a number
     * (null is OpenAI's "no figure", not zero) refuses the answer rather than counting it as 0 (CLAUDE.md #4).
     *
     * @param list<array<string, mixed>> $read the rows read before it
     * @return array{campaign_id: string, name: string, impressions: int, clicks: int, spend: int|float}
     */
    private static function spendRow(mixed $row, array $read): array
    {
        $id = is_array($row) ? ($row['campaign_id'] ?? null) : null;
        if (!is_string($id) || $id === '') {
            throw new AdPlatformUnavailable(AdPlatformUnavailable::UNREADABLE, 'OpenAI\'s spend answer has a row that names no campaign.');
        }
        if (in_array($id, array_column($read, 'campaign_id'), true)) {
            throw new AdPlatformUnavailable(AdPlatformUnavailable::UNREADABLE, 'OpenAI\'s spend answer names campaign ' . $id . ' twice.');
        }
        foreach (['impressions', 'clicks', 'spend'] as $field) {
            $value = $row[$field] ?? null;
            if (!is_int($value) && !($field === 'spend' && is_float($value))) {
                throw new AdPlatformUnavailable(AdPlatformUnavailable::UNREADABLE, 'OpenAI\'s spend answer gives campaign ' . $id . '\'s ' . $field . ' as ' . get_debug_type($value) . ', not a number, so the total cannot be told.');
            }
        }

        return [
            'campaign_id' => $id,
            'name' => is_string($row['campaign_name'] ?? null) ? $row['campaign_name'] : '',
            'impressions' => $row['impressions'],
            'clicks' => $row['clicks'],
            'spend' => $row['spend'],
        ];
    }

    /**
     * What a write did: the object as OpenAI shows it once $applied says the change is on it. An answer that could
     * be a landed write (no answer, a 5xx, a 2xx that does not show the change) is never answered "try again": the
     * object is read back, and when that does not show the change the write is UNCONFIRMED (CLAUDE.md #13).
     *
     * @param callable(array<string, mixed>): bool $applied
     * @return array<string, mixed>
     */
    private function written(AdvertiserAnswer $answer, #[\SensitiveParameter] string $key, string $level, string $objectId, string $what, callable $applied): array
    {
        $check = 'Read it (p202 ad-campaign get, or the campaigns page) before sending it again.';
        if ($answer->isOk()) {
            $record = self::shape($level, $answer->json);
            if ($record['id'] === $objectId && $applied($record)) {
                return $record;
            }
        } elseif ($answer->kind !== AdvertiserAnswer::UNAVAILABLE || str_starts_with((string) $answer->error, 'not sent')) {
            // Refused, not found, the key, the rate limit, or never sent: nothing was applied.
            $this->called($answer, $what);
        } elseif ($answer->status < 200 || $answer->status >= 300) {
            throw new AdPlatformUnavailable(AdPlatformUnavailable::UNREACHABLE, 'Could not ' . $what . ': ' . $answer->error . '. It may have been applied all the same. ' . $check);
        }
        try {
            $now = self::shape($level, $this->read($key, $level, $objectId));
        } catch (AdPlatformUnavailable | AdConnectionInputException $e) {
            throw new AdPlatformUnavailable(AdPlatformUnavailable::UNCONFIRMED, 'OpenAI took the request to ' . $what . ', but its answer does not show the change, and reading '
                . $objectId . ' back failed (' . $e->getMessage() . '): it may have been applied. ' . $check);
        }
        if ($now['id'] === $objectId && $applied($now)) {
            return $now;
        }

        throw new AdPlatformUnavailable(
            AdPlatformUnavailable::UNCONFIRMED,
            'OpenAI answered the request to ' . $what . ', but ' . $objectId . ' does not show it at OpenAI now (status '
                . ($now['status'] ?? 'unknown') . ', tracking template ' . ($now['query_string_template'] ?? 'not set') . '): it is not done. ' . $check,
            ['record' => $now]
        );
    }

    private static function cursor(string $after): string
    {
        if (preg_match('/\A[A-Za-z0-9_:=-]{1,200}\z/D', $after) !== 1) {
            throw new AdConnectionInputException('after', 'after must be the cursor a previous page answered.');
        }

        return $after;
    }
}
