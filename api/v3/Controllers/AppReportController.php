<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Apps\Android\InstallReport;
use Api\V3\Exception\ValidationException;

/**
 * GET /apps/report: the cross-platform app report (plan §5.6).
 *
 * Two signal sources answer it, each in its own terms:
 *
 *  - iOS: Apple's SKAdNetwork and AdAttributionKit postbacks, decoded
 *    through the SKAN encodings (AppPostbacksController::report()). Apple's
 *    numbers are delayed, aggregate and privacy-thresholded.
 *  - Android: the installs the SDK reported and the goals they reached
 *    (InstallReport). These are per install and immediate.
 *
 * `platform` picks `ios`, `android` or `all` (both). Left out it is `ios`:
 * the report was Apple's alone before Android installs existed, and every
 * client and saved query that asks without a platform keeps reading what
 * it always read — each answer names its `platform`, so the scope is never
 * silent. The shared groupings — day, registration, platform — work on
 * any; a grouping or a filter only one platform has (ad-network, source,
 * country, version, protocol, conversion-type and every postback filter
 * for iOS; campaign, match-state, integrity-state, goal and the install
 * filters for Android) needs that platform, and asked of another one the
 * request is a 422 that says which platform to ask for.
 *
 * Every group row carries `platform` and the metrics both platforms share
 * — `installs`, the trust-class counts, `goals_reached`, `revenue`,
 * `events` — beside its own. Totals are per platform; with both platforms
 * they are `{ios, android, combined}`, the combined figure labelled as
 * such and made only of metrics that mean the same on both.
 */
final class AppReportController
{
    public const PLATFORMS = ['ios', 'android'];

    /** Groupings both platforms answer. */
    public const SHARED_GROUPINGS = ['day', 'registration', 'platform'];

    /** Groupings only Apple's postbacks carry. */
    public const IOS_GROUPINGS = ['ad-network', 'source', 'country', 'version', 'protocol', 'conversion-type'];

    /** Groupings only Android installs carry. */
    public const ANDROID_GROUPINGS = ['campaign', 'match-state', 'integrity-state', 'goal'];

    /** Parameters every platform reads. */
    private const SHARED_PARAMS = ['time_from', 'time_to', 'registration_id', 'registration_ids', 'limit'];

    /** The postback filters (the postback list's), iOS only. */
    public const IOS_FILTERS = [
        'signature', 'protocol', 'conversion_type', 'ad_interaction_type', 'app_id', 'ad_network_id', 'version',
        'transaction_id', 'country_code', 'source_identifier', 'campaign_id', 'fidelity_type',
        'postback_sequence_index', 'did_win', 'redownload', 'coarse_conversion_value',
    ];

    /** The metrics the combined totals add up: the ones that mean the same on both platforms. */
    private const COMBINED = ['installs', 'trusted_count', 'refuted_count', 'unvouched_count', 'test_count', 'goals_reached'];

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /** @param array<string, mixed> $params */
    public function report(array $params): array
    {
        $platform = self::platformOf($params);
        $groupBy = (string)($params['group_by'] ?? 'day');
        self::assertGroupingFits($groupBy, $platform);
        self::assertFiltersFit($params, $platform);

        $ios = null;
        $android = null;
        if ($platform !== 'android') {
            $ios = $this->iosReport($params, $groupBy);
        }
        if ($platform !== 'ios') {
            $android = (new InstallReport($this->db, $this->userId))->report(self::only($params, [...self::SHARED_PARAMS, ...InstallReport::FILTERS]) + ['group_by' => $groupBy]);
        }

        // The platform grouping is one row per platform asked for, the
        // platform's whole window. A platform with nothing in the window
        // still gets its row — of zeros, from its totals — because an
        // absent row reads as "not measured" where the answer is "none".
        if ($groupBy === 'platform') {
            if ($ios !== null && $ios['groups'] === []) {
                $ios['groups'] = [$ios['totals']];
            }
            if ($android !== null && $android['groups'] === []) {
                $android['groups'] = [$android['totals']];
            }
        }

        if ($platform === 'ios') {
            return [
                'data' => ['group_by' => $groupBy, 'platform' => 'ios', 'groups' => $ios['groups'], 'totals' => $ios['totals']],
                'meta' => $ios['meta'] + ['platform' => 'ios'],
            ];
        }
        if ($platform === 'android') {
            return [
                'data' => ['group_by' => $groupBy, 'platform' => 'android', 'groups' => $android['groups'], 'totals' => $android['totals']],
                'meta' => [
                    'timezone' => 'UTC',
                    'platform' => 'android',
                    'trusted' => $android['trusted'],
                    'groups_truncated' => $android['truncated'],
                    'notes' => self::androidNotes(),
                ],
            ];
        }

        $groups = [...$ios['groups'], ...$android['groups']];
        if ($groupBy === 'day') {
            // One timeline: by day, iOS before Android within a day.
            usort($groups, static fn (array $a, array $b): int
                => [(string)$a['date'], (string)$a['platform']] <=> [(string)$b['date'], (string)$b['platform']]);
        }

        return [
            'data' => [
                'group_by' => $groupBy,
                'platform' => 'all',
                'groups' => $groups,
                'totals' => [
                    'ios' => $ios['totals'],
                    'android' => $android['totals'],
                    'combined' => self::combine($ios['totals'], $android['totals']),
                ],
            ],
            'meta' => [
                'timezone' => 'UTC',
                'platform' => 'all',
                'trusted' => 'trusted-only',
                'groups_truncated' => $ios['meta']['groups_truncated'] || $android['truncated'],
                'limit_applies' => 'per platform: each platform returns at most `limit` groups',
                'notes' => 'iOS: ' . (string)$ios['meta']['notes'] . '. Android: ' . self::androidNotes()
                    . '. combined adds only metrics that mean the same on both platforms (' . implode(', ', self::COMBINED) . ', revenue)',
            ],
        ];
    }

    /**
     * The iOS report, with the shared columns every row carries and the
     * decode totals beside the counters.
     *
     * The postback report's own totals are counters only (its decode is per
     * group); the whole window's decode is the report grouped by the
     * constant `platform`, which is one group — asked for separately unless
     * that is already the grouping.
     *
     * @param array<string, mixed> $params
     * @return array{groups: list<array<string, mixed>>, totals: array<string, mixed>, meta: array<string, mixed>}
     */
    private function iosReport(array $params, string $groupBy): array
    {
        $postbacks = new AppPostbacksController($this->db, $this->userId);
        $iosParams = self::only($params, [...self::SHARED_PARAMS, ...self::IOS_FILTERS]);
        $answer = $postbacks->report($iosParams + ['group_by' => $groupBy]);

        $groups = [];
        foreach ($answer['data']['groups'] as $group) {
            $groups[] = self::iosShared($group);
        }

        if ($groupBy === 'platform') {
            $whole = $answer['data']['groups'][0] ?? null;
        } else {
            $whole = $postbacks->report(['group_by' => 'platform'] + $iosParams)['data']['groups'][0] ?? null;
        }
        $totals = ['platform' => 'ios'] + $answer['data']['totals'];
        foreach (['measurable', 'decoded', 'undecoded', 'ambiguous_encoding', 'null_conversion_values'] as $key) {
            $totals[$key] = (int)($whole[$key] ?? 0);
        }
        $totals['decoded_revenue'] = (float)($whole['decoded_revenue'] ?? 0.0);
        $totals['revenue'] = $totals['decoded_revenue'];
        $totals['goals_reached'] = $totals['decoded'];
        $totals['events'] = $whole['events'] ?? (object)[];

        return ['groups' => $groups, 'totals' => $totals, 'meta' => $answer['meta']];
    }

    /**
     * An iOS group with the shared names: `goals_reached` is the decoded
     * postbacks (each decodes to one goal) and `revenue` their decoded
     * revenue.
     *
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private static function iosShared(array $group): array
    {
        $group['platform'] = 'ios';
        $group['goals_reached'] = (int)($group['decoded'] ?? 0);
        $group['revenue'] = (float)($group['decoded_revenue'] ?? 0.0);

        return $group;
    }

    /**
     * @param array<string, mixed> $ios
     * @param array<string, mixed> $android
     * @return array<string, mixed>
     */
    private static function combine(array $ios, array $android): array
    {
        $out = [];
        foreach (self::COMBINED as $key) {
            $out[$key] = (int)($ios[$key] ?? 0) + (int)($android[$key] ?? 0);
        }
        $out['revenue'] = round((float)($ios['revenue'] ?? 0) + (float)($android['revenue'] ?? 0), 5);

        return $out;
    }

    /** @param array<string, mixed> $params */
    private static function platformOf(array $params): string
    {
        $raw = $params['platform'] ?? '';
        if (!is_string($raw)) {
            throw new ValidationException('Invalid platform', ['platform' => 'Must be ios, android or all']);
        }
        $platform = strtolower(trim($raw));
        if ($platform === '') {
            // The report's meaning before Android existed (class docblock).
            return 'ios';
        }
        if ($platform === 'all') {
            return 'all';
        }
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new ValidationException('Invalid platform', ['platform' => 'Must be ios (the default), android or all']);
        }

        return $platform;
    }

    private static function assertGroupingFits(string $groupBy, string $platform): void
    {
        $all = [...self::SHARED_GROUPINGS, ...self::IOS_GROUPINGS, ...self::ANDROID_GROUPINGS];
        if (!in_array($groupBy, $all, true)) {
            throw new ValidationException('Invalid group_by', ['group_by' => 'Must be one of: ' . implode(', ', $all)]);
        }
        if (in_array($groupBy, self::IOS_GROUPINGS, true) && $platform !== 'ios') {
            throw new ValidationException('That grouping is iOS only', [
                'group_by' => $groupBy . ' is a dimension of Apple\'s postbacks: ask for platform=ios, the default (Android installs have no ' . $groupBy . '). Groupings for both platforms: ' . implode(', ', self::SHARED_GROUPINGS),
            ]);
        }
        if (in_array($groupBy, self::ANDROID_GROUPINGS, true) && $platform !== 'android') {
            throw new ValidationException('That grouping is Android only', [
                'group_by' => $groupBy . ' is a dimension of Android installs: ask for platform=android (Apple\'s postbacks carry no ' . $groupBy . '). Groupings for both platforms: ' . implode(', ', self::SHARED_GROUPINGS),
            ]);
        }
    }

    /** @param array<string, mixed> $params */
    private static function assertFiltersFit(array $params, string $platform): void
    {
        $errors = [];
        foreach (self::IOS_FILTERS as $filter) {
            if (self::given($params, $filter) && $platform !== 'ios') {
                $errors[$filter] = 'filters Apple\'s postbacks only: ask for platform=ios, the default';
            }
        }
        foreach (InstallReport::FILTERS as $filter) {
            if (self::given($params, $filter) && $platform !== 'android') {
                $errors[$filter] = 'filters Android installs only: ask for platform=android';
            }
        }
        if ($errors !== []) {
            throw new ValidationException('A filter names one platform', $errors);
        }
    }

    /** @param array<string, mixed> $params */
    private static function given(array $params, string $key): bool
    {
        return isset($params[$key]) && $params[$key] !== '' && $params[$key] !== [];
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function only(array $params, array $keys): array
    {
        return array_intersect_key($params, array_flip($keys));
    }

    private static function androidNotes(): string
    {
        return 'installs are distinct trusted (attributed) installs; organic, refuted_count, unvouched_count, test_count, pending and the match_states / integrity_states breakdowns count every install beside them'
            . '; figures are by install (a cohort): a goal an install reached counts in the group of its install, however much later it was reached'
            . '; events, goals_reached and revenue count the goals trusted installs reached (trusted= recomputes installs and these over that class)'
            . '; revenue is the value of payable outcomes, what the campaigns were credited'
            . '; group_by=goal counts distinct installs per goal (installs, and each trust class) and orders busiest first — sort by after for a funnel';
    }
}
