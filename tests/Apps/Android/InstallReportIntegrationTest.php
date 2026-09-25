<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Controllers\AppReportController;
use Api\V3\Exception\ValidationException;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalScope;
use Tests\TestCase;

/**
 * GET /apps/report with Android in it (plan §5.6, PR 11), against a real
 * database: the install figures, the goals those installs reached and what
 * they were credited, each breakdown, the funnel's goal rows, the trust
 * default, and the cross-platform rules (a one-platform grouping or filter
 * without its platform is a 422; totals are per platform plus a labelled
 * combined figure).
 *
 * The fixture, through the real intake: two attributed installs on
 * campaign 30's clicks (each the install goal at 2.50), one organic, one
 * forged (a tampered token, refuted); the first attributed install then
 * reaches the campaign's Level 3 ($4) and the app's own Tutorial goal
 * (after the install goal), and so does the organic install.
 *
 * @group integration
 */
final class InstallReportIntegrationTest extends TestCase
{
    use AndroidDatabase;

    private const U1 = '00000000-0000-4000-8000-0000000000a1';
    private const U2 = '00000000-0000-4000-8000-0000000000a2';
    private const U3 = '00000000-0000-4000-8000-0000000000a3';
    private const U4 = '00000000-0000-4000-8000-0000000000a4';

    private int $installGoal = 0;
    private int $level3 = 0;
    private int $tutorial = 0;

    private function seed(): void
    {
        $this->click(100);
        $this->click(101);
        $this->level3 = $this->campaignGoal(30, ['name' => 'Level 3', 'trigger' => ['event' => 'level_reached', 'where' => [['prop' => 'level', 'op' => 'gte', 'value' => 3]]],
            'value' => ['type' => 'fixed', 'amount' => 4]]);
        $this->installGoal = $this->goals->ensureBuiltinInstallGoal(1, 5, 1);
        $this->goals->attach(1, 30, $this->installGoal, null, true, 1);
        $this->tutorial = $this->goals->create(1, GoalScope::REGISTRATION, 5, GoalDefinition::parse([
            'name' => 'Tutorial', 'trigger' => ['event' => 'tutorial_done'], 'after' => [$this->installGoal],
            'within' => ['days' => 7, 'from' => 'install'],
        ]), 1);

        self::assertSame('attributed', $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)))['body']['data']['match']);
        self::assertSame('attributed', $this->install(self::body(self::U2, 'p202=' . self::tokenFor(101)))['body']['data']['match']);
        self::assertSame('organic', $this->install(self::body(self::U3, 'utm_source=google-play&utm_medium=organic'))['body']['data']['match']);
        $forged = substr(self::tokenFor(100), 0, -2) . 'xx';
        self::assertSame('bad_token', $this->install(self::body(self::U4, 'p202=' . $forged))['body']['data']['match']);

        $t = self::CLICK_TIME + 100;
        foreach ([self::U1, self::U3] as $uuid) {
            self::assertSame(200, $this->events($uuid, [
                ['event_id' => 'l3', 'name' => 'level_reached', 'occurred_at' => $t + 1, 'properties' => ['level' => 3]],
                ['event_id' => 't1', 'name' => 'tutorial_done', 'occurred_at' => $t + 2],
            ])['status']);
        }
    }

    /** @param array<string, mixed> $params */
    private function report(array $params): array
    {
        return (new AppReportController(self::$db, 1))->report($params);
    }

    /** @return array<string, mixed> */
    private static function jsonRound(array $value): array
    {
        return json_decode((string) json_encode($value), true);
    }

    public function testTheAndroidFiguresCountTrustedInstallsWithEveryOtherClassBesideThem(): void
    {
        $this->seed();
        $r = self::jsonRound($this->report(['platform' => 'android', 'group_by' => 'registration']));

        self::assertSame(['registration', 'android'], [$r['data']['group_by'], $r['data']['platform']]);
        self::assertSame('trusted-only', $r['meta']['trusted']);
        self::assertCount(1, $r['data']['groups'], 'user 1 has one Android app; user 2\'s is not theirs to see');
        $g = $r['data']['groups'][0];
        self::assertSame([5, 'Summit', 'com.example.summit', 'android'], [$g['registration_id'], $g['app_name'], $g['app_key'], $g['platform']]);
        self::assertSame(
            ['received' => 4, 'installs' => 2, 'organic' => 1, 'pending' => 0, 'trusted_count' => 2, 'refuted_count' => 1, 'unvouched_count' => 1, 'test_count' => 0],
            array_intersect_key($g, array_flip(['received', 'installs', 'organic', 'pending', 'trusted_count', 'refuted_count', 'unvouched_count', 'test_count']))
        );
        self::assertEqualsCanonicalizing(['attributed' => 2, 'organic' => 1, 'bad_token' => 1], $g['match_states']);
        self::assertSame(['not_requested' => 4], $g['integrity_states']);

        // The goals trusted installs reached: two installs (2.50 each, paid
        // on campaign 30), U1's Level 3 ($4, paid) and U1's Tutorial (tracked,
        // unpaid). The organic install's goals are not headline figures.
        self::assertSame(4, $g['goals_reached']);
        self::assertEqualsWithDelta(9.0, $g['revenue'], 0.00001);
        self::assertEqualsCanonicalizing([
            'install' => ['count' => 2, 'revenue' => 5],
            'Level 3' => ['count' => 1, 'revenue' => 4],
            'Tutorial' => ['count' => 1, 'revenue' => 0],
        ], $g['events']);

        // Totals are the ungrouped query, and agree here because nothing was cut.
        $t = $r['data']['totals'];
        self::assertSame(['android', 4, 2, 4], [$t['platform'], $t['received'], $t['installs'], $t['goals_reached']]);
        self::assertEqualsWithDelta(9.0, $t['revenue'], 0.00001);
        self::assertFalse($r['meta']['groups_truncated']);
    }

    public function testEachBreakdownGroupsTheSameInstalls(): void
    {
        $this->seed();

        $byCampaign = self::jsonRound($this->report(['platform' => 'android', 'group_by' => 'campaign']))['data']['groups'];
        $campaigns = [];
        foreach ($byCampaign as $g) {
            $campaigns[(string) ($g['aff_campaign_id'] ?? 'none')] = [$g['received'], $g['installs'], $g['aff_campaign_name']];
        }
        self::assertSame([2, 2, 'c30'], $campaigns['30'], 'the two attributed installs, by their clicks\' campaign');
        self::assertSame([2, 0, null], $campaigns['none'], 'the organic and the forged install have no campaign of the user\'s');

        $byState = self::jsonRound($this->report(['platform' => 'android', 'group_by' => 'match-state']))['data']['groups'];
        $states = array_column($byState, 'received', 'match_state');
        ksort($states);
        self::assertSame(['attributed' => 2, 'bad_token' => 1, 'organic' => 1], $states);

        $byIntegrity = self::jsonRound($this->report(['platform' => 'android', 'group_by' => 'integrity-state']))['data']['groups'];
        self::assertSame([['not_requested', 4]], array_map(static fn (array $g): array => [$g['integrity_state'], $g['received']], $byIntegrity));

        $byDay = self::jsonRound($this->report(['platform' => 'android', 'group_by' => 'day']))['data']['groups'];
        self::assertSame([gmdate('Y-m-d', $this->clock)], array_column($byDay, 'date'));
        self::assertSame(2, $byDay[0]['installs']);
    }

    public function testTheGoalRowsAreTheFunnel(): void
    {
        $this->seed();
        $rows = self::jsonRound($this->report(['platform' => 'android', 'group_by' => 'goal', 'registration_id' => '5']))['data']['groups'];
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['goal_name']] = $row;
        }
        self::assertSame(['Level 3', 'Tutorial', 'install'], array_keys(array_sort_keys($byName)));
        self::assertSame([2, 1, 0, 'install'], [$byName['install']['installs'], $byName['install']['unvouched_count'], $byName['install']['refuted_count'], $byName['install']['builtin']],
            'two trusted installs and the organic one reached the install goal; the forged one reached nothing');
        self::assertSame([1, 1, [$this->installGoal], 'registration', 5], [$byName['Tutorial']['installs'], $byName['Tutorial']['unvouched_count'],
            $byName['Tutorial']['after'], $byName['Tutorial']['goal_scope'], $byName['Tutorial']['goal_scope_id']], 'the step says what it comes after');
        self::assertSame([1, 4.0], [$byName['Level 3']['goals_reached'], (float) $byName['Level 3']['revenue']]);
    }

    public function testAnExplicitTrustClassRecomputesTheGoalsOverIt(): void
    {
        $this->seed();
        $r = self::jsonRound($this->report(['platform' => 'android', 'group_by' => 'platform', 'trusted' => 'unvouched']));
        self::assertSame('as-filtered', $r['meta']['trusted']);
        $g = $r['data']['groups'][0];
        self::assertSame([1, 0, 1, 2], [$g['received'], $g['installs'], $g['unvouched_count'], $g['goals_reached']],
            'the organic install: its install goal and Tutorial (Level 3 is campaign 30\'s, and an organic install has no campaign)');
        self::assertEqualsWithDelta(0.0, $g['revenue'], 0.00001, 'nothing an organic install reaches is paid');
    }

    public function testBothPlatformsTogether(): void
    {
        $this->seed();
        $r = self::jsonRound($this->report(['group_by' => 'platform']));
        self::assertSame('all', $r['data']['platform']);
        self::assertSame(['ios', 'android'], array_column($r['data']['groups'], 'platform'));
        self::assertSame(0, $r['data']['groups'][0]['installs'], 'no postbacks: the iOS row is zeros, not missing');
        self::assertSame(['ios', 'android', 'combined'], array_keys($r['data']['totals']));
        self::assertSame(2, $r['data']['totals']['combined']['installs']);
        self::assertSame(4, $r['data']['totals']['combined']['goals_reached']);
        self::assertEqualsWithDelta(9.0, $r['data']['totals']['combined']['revenue'], 0.00001);
        foreach (['installs', 'revenue', 'goals_reached', 'trusted_count', 'events'] as $shared) {
            self::assertArrayHasKey($shared, $r['data']['groups'][0], "every row carries $shared, iOS rows included");
        }
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string, 2: string}> */
    public static function oneSidedRequests(): iterable
    {
        yield 'an iOS grouping with both platforms' => [['group_by' => 'ad-network'], 'group_by', 'platform=ios'];
        yield 'an iOS grouping on Android' => [['group_by' => 'country', 'platform' => 'android'], 'group_by', 'platform=ios'];
        yield 'an Android grouping with both platforms' => [['group_by' => 'goal'], 'group_by', 'platform=android'];
        yield 'an Android grouping on iOS' => [['group_by' => 'match-state', 'platform' => 'ios'], 'group_by', 'platform=android'];
        yield 'a postback filter with both platforms' => [['signature' => 'valid'], 'signature', 'platform=ios'];
        yield 'an install filter on iOS' => [['match_state' => 'organic', 'platform' => 'ios'], 'match_state', 'platform=android'];
        yield 'a platform nobody has' => [['platform' => 'windows'], 'platform', 'ios, android or all'];
        yield 'an unknown state' => [['platform' => 'android', 'match_state' => 'maybe'], 'match_state', 'attributed'];
        yield 'a cast trust class' => [['platform' => 'android', 'trusted' => 'yes'], 'trusted', 'unvouched'];
    }

    /**
     * @dataProvider oneSidedRequests
     * @param array<string, string> $params
     */
    public function testAOneSidedGroupingOrFilterNeedsItsPlatform(array $params, string $field, string $says): void
    {
        try {
            $this->report($params);
            self::fail('a report that would silently leave one platform out was answered');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFieldErrors());
            self::assertStringContainsString($says, $e->getFieldErrors()[$field]);
        }
    }
}

/**
 * @param array<string, mixed> $a
 * @return array<string, mixed>
 */
function array_sort_keys(array $a): array
{
    ksort($a);

    return $a;
}
