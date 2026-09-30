<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Apps\Android\InstallEventsIntake;
use Api\V3\Apps\Android\InstallIntake;
use Api\V3\Apps\Android\PendingClickSettler;
use Api\V3\Controllers\AppRegistrationsController;
use Api\V3\Controllers\AppReportController;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\QuotaStore;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\GoalSubject;
use Prosper202\Goals\OutcomeNotifier;

/**
 * The abuse limits of plan §7.1 against a real server, driven through the
 * intakes' own receive() — the entry the route hands a request to — with
 * the real ServerStateStore under a per-test directory:
 *
 *  - click-to-install time recorded on the install row, on the intake's and
 *    the pending-click settler's path, flagged against the registration's
 *    thresholds, and reported (counts, the ctit-flag grouping and filter);
 *  - the registration's install cap: 429 with Retry-After past it, nothing
 *    stored or counted, a replay still answered, another app untouched, and
 *    a 503 naming the column when the cap or its store cannot be read;
 *  - an install's event cap, counted per event, nothing stored past it;
 *  - goals reached implausibly fast: flagged under `count` and still paid,
 *    held (unpaid, unsent) under `hold`, and counted and filterable in the
 *    report;
 *  - the registration API refusing a malformed limit by name, and checking
 *    the CTIT pair under the registration's row lock;
 *  - a request admitted and then not recorded refunded, events charged only
 *    when newly stored, and a customer-only body capped;
 *  - a fast-goal decision standing through a policy switch, a re-evaluation,
 *    a revival and a change of the install's credit.
 *
 * @group integration
 */
final class AbuseLimitsIntegrationTest extends TestCase
{
    use AndroidDatabase;

    private const U1 = '00000000-0000-4000-8000-00000000a001';
    private const U2 = '00000000-0000-4000-8000-00000000a002';
    private const U3 = '00000000-0000-4000-8000-00000000a003';
    private const U4 = '00000000-0000-4000-8000-00000000a004';

    private static function limits(string $set, int $registration = 5): void
    {
        self::fixture("UPDATE 202_app_registrations SET $set WHERE registration_id = $registration");
    }

    /** @return array<string, mixed> */
    private function report(array $params): array
    {
        return json_decode((string) json_encode((new AppReportController(self::$db, 1))->report($params + ['platform' => 'android'])), true);
    }

    public function testClickToInstallTimeIsRecordedAndFlaggedAgainstTheRegistrationsThresholds(): void
    {
        // Google's install-begin is click + 61 s in the fixture body.
        $this->click(100);
        $this->click(101);
        $this->click(102);
        self::assertSame(200, $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)))['status']);
        $row = self::installRow(self::U1);
        self::assertSame(['61', 'ok'], [(string) $row['ctit_seconds'], $row['ctit_flag']]);

        self::limits('ctit_min_seconds = 100');
        $this->install(self::body(self::U2, 'p202=' . self::tokenFor(101)));
        self::assertSame(['61', 'short'], [(string) self::installRow(self::U2)['ctit_seconds'], self::installRow(self::U2)['ctit_flag']]);
        self::assertSame('attributed', self::installRow(self::U2)['match_state'], 'a flag marks; it does not refuse');

        self::limits('ctit_min_seconds = 10, ctit_max_seconds = 60');
        $this->install(self::body(self::U3, 'p202=' . self::tokenFor(102)));
        self::assertSame('long', self::installRow(self::U3)['ctit_flag']);

        // An organic install has no click to measure from.
        $this->install(self::body(self::U4, ''));
        self::assertSame([null, null], [self::installRow(self::U4)['ctit_seconds'], self::installRow(self::U4)['ctit_flag']]);

        $totals = $this->report(['group_by' => 'ctit-flag'])['data']['totals'];
        self::assertSame([3, 1, 1], [$totals['ctit_measured'], $totals['ctit_short'], $totals['ctit_long']]);
        $groups = [];
        foreach ($this->report(['group_by' => 'ctit-flag'])['data']['groups'] as $g) {
            $groups[$g['ctit_flag']] = $g['received'];
        }
        ksort($groups);
        self::assertSame(['long' => 1, 'ok' => 1, 'short' => 1, 'unmeasured' => 1], $groups);
        self::assertSame(1, $this->report(['ctit_flag' => 'short'])['data']['totals']['received']);
        self::assertSame(1, $this->report(['ctit_flag' => 'unmeasured'])['data']['totals']['received']);
        try {
            $this->report(['ctit_flag' => 'fast']);
            self::fail('an unknown ctit_flag is refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ctit_flag', $e->getFieldErrors());
        }
    }

    public function testThePendingClickSettlerMeasuresTheInstallWhenItsClickArrives(): void
    {
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(500)));
        self::assertSame(['pending_click', null], [self::installRow(self::U1)['match_state'], self::installRow(self::U1)['ctit_seconds']]);
        $this->click(500);
        // Thresholds that are not the defaults, so the settler is shown to
        // read the registration's own (through LockedInstall).
        self::limits('ctit_min_seconds = 100');
        (new PendingClickSettler(self::$db, fn (): int => $this->clock))->run();
        self::assertSame(['attributed', '61', 'short'], [self::installRow(self::U1)['match_state'], (string) self::installRow(self::U1)['ctit_seconds'], self::installRow(self::U1)['ctit_flag']]);
    }

    public function testTheRegistrationsInstallCapRefusesWithRetryAfterAndStoresNothing(): void
    {
        self::limits('install_cap_per_minute = 2');
        foreach ([100, 101, 102] as $click) {
            $this->click($click);
        }
        self::assertSame(200, $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)))['status']);
        self::assertSame(200, $this->install(self::body(self::U2, 'p202=' . self::tokenFor(101)))['status']);
        $over = $this->install(self::body(self::U3, 'p202=' . self::tokenFor(102)));
        self::assertSame(429, $over['status'], json_encode($over));
        self::assertStringContainsString('install_cap_per_minute = 2', $over['body']['message']);
        $wait = (int) ($over['headers']['Retry-After'] ?? 0);
        self::assertGreaterThanOrEqual(1, $wait);
        self::assertLessThanOrEqual(60, $wait);
        self::assertSame($wait, $over['body']['retry_after_seconds']);
        self::assertNull(self::installRow(self::U3), 'nothing stored past the cap');
        self::assertSame([], self::ledger(102), 'nothing counted past the cap');
        self::assertSame(2, self::rows('202_app_installs'));

        // A replay of a stored install stores nothing new: answered, not capped.
        $replay = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        self::assertSame([200, true], [$replay['status'], $replay['body']['data']['duplicate']]);

        // The cap is the registration's own: another app's budget is untouched.
        $this->click(900, 30, 2);
        $other = $this->install(self::body(self::U3, '', ['app_key' => 'com.other.app']), self::OTHER_TOKEN);
        self::assertSame(200, $other['status'], json_encode($other));
    }

    public function testAnUnreadableCapOrStoreFailsClosedWithA503NamingIt(): void
    {
        // 0 is outside the column's range: a value no write stores.
        self::limits('install_cap_per_minute = 0');
        $this->click(100);
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        self::assertSame(503, $r['status']);
        self::assertStringContainsString('install_cap_per_minute', $r['body']['message']);
        self::assertNull(self::installRow(self::U1));

        // A store that cannot be written: the state directory is a file.
        self::limits('install_cap_per_minute = 300');
        $file = tempnam(sys_get_temp_dir(), 'p202-not-a-dir');
        putenv('P202_SERVER_STATE_DIR=' . $file);
        try {
            $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        } finally {
            putenv('P202_SERVER_STATE_DIR=' . self::$androidStateDir);
            unlink($file);
        }
        self::assertSame([503, '60'], [$r['status'], $r['headers']['Retry-After'] ?? null], json_encode($r));
        self::assertNull(self::installRow(self::U1));
    }

    public function testAnInstallsEventCapIsCountedPerEventAndStoresNothingPastIt(): void
    {
        self::limits('event_cap_per_minute = 100');
        $this->click(100);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        $batch = static fn (string $prefix, int $n): array => array_map(
            static fn (int $i): array => ['event_id' => $prefix . $i, 'name' => 'tick', 'occurred_at' => self::CLICK_TIME + 200 + $i],
            range(1, $n)
        );
        self::assertSame(200, $this->events(self::U1, $batch('a', 60))['status']);
        $over = $this->events(self::U1, $batch('b', 60));
        self::assertSame(429, $over['status'], json_encode($over));
        self::assertStringContainsString('event_cap_per_minute = 100', $over['body']['message']);
        self::assertArrayHasKey('Retry-After', $over['headers']);
        self::assertSame(60, self::rows('202_goal_events'), 'the refused batch stored nothing');
        self::assertSame(200, $this->events(self::U1, $batch('c', 40))['status'], 'the rest of the budget is still there');
        self::assertSame(100, self::rows('202_goal_events'));

        // Another install on the same app has its own budget.
        $this->click(101);
        $this->install(self::body(self::U2, 'p202=' . self::tokenFor(101)));
        self::assertSame(200, $this->events(self::U2, $batch('d', 100))['status']);
    }

    public function testAGoalReachedTooFastIsFlaggedAndStillPaysUnderCount(): void
    {
        $this->click(100);
        $signup = $this->campaignGoal(30, ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $installGoal = $this->goals->ensureBuiltinInstallGoal(1, 5, 1);
        $this->goals->attach(1, 30, $installGoal, null, true, 1);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        // Install-begin is click + 61; the signup 2 s after it.
        self::assertSame(200, $this->events(self::U1, [['event_id' => 's1', 'name' => 'signup', 'occurred_at' => self::CLICK_TIME + 63]])['status']);

        $outcome = self::$db->query("SELECT too_fast, payable FROM 202_goal_outcomes WHERE goal_id = $signup AND superseded_at IS NULL")->fetch_assoc();
        self::assertSame(['1', '1'], [(string) $outcome['too_fast'], (string) $outcome['payable']], 'flagged, and paid under count');
        $install = self::$db->query("SELECT too_fast FROM 202_goal_outcomes WHERE goal_id = $installGoal")->fetch_assoc();
        self::assertSame('0', (string) $install['too_fast'], 'the install is never too fast after itself');
        self::assertSame(2, count(array_filter(self::outbox(), static fn (array $r): bool => $r['kind'] === 'reached')));

        $totals = $this->report([])['data']['totals'];
        self::assertSame([2, 1], [$totals['goals_reached'], $totals['fast_goals']]);
        self::assertSame(1, $this->report(['fast_goals' => '1'])['data']['totals']['received']);
        self::assertSame(0, $this->report(['fast_goals' => '0'])['data']['totals']['received']);
        $goalRows = $this->report(['group_by' => 'goal'])['data']['groups'];
        $byName = array_column($goalRows, 'fast_goals', 'goal_name');
        self::assertSame(1, $byName['Signup']);
    }

    public function testUnderHoldAGoalReachedTooFastIsRecordedFlaggedAndNeitherPaidNorSent(): void
    {
        self::limits("fast_goal_policy = 'hold', fast_goal_seconds = 30");
        $this->click(100);
        $signup = $this->campaignGoal(30, ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $level = $this->campaignGoal(30, ['name' => 'Level', 'trigger' => ['event' => 'level'], 'value' => ['type' => 'fixed', 'amount' => 1]]);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        $this->events(self::U1, [
            ['event_id' => 's1', 'name' => 'signup', 'occurred_at' => self::CLICK_TIME + 71],   // 10 s after the install: held
            ['event_id' => 'l1', 'name' => 'level', 'occurred_at' => self::CLICK_TIME + 161],   // 100 s after: paid
        ]);
        $rows = self::$db->query("SELECT goal_id, too_fast, payable FROM 202_goal_outcomes WHERE goal_id IN ($signup, $level) ORDER BY goal_id")->fetch_all(MYSQLI_ASSOC);
        self::assertSame([[(string) $signup, '1', '0'], [(string) $level, '0', '1']], array_map(static fn (array $r): array => [(string) $r['goal_id'], (string) $r['too_fast'], (string) $r['payable']], $rows));
        $held = array_values(array_filter(self::ledger(100), static fn (array $r): bool => $r['source_ref'] === 'goal:' . $signup . ':1'));
        self::assertSame('0', (string) $held[0]['payable'], 'its ledger row is tracked, not paid');
        $reached = array_values(array_filter(self::outbox(), static fn (array $r): bool => $r['kind'] === 'reached'));
        self::assertCount(1, array_filter($reached, static fn (array $r): bool => str_contains((string) $r['url'], 'goal=Level')));
        self::assertCount(0, array_filter($reached, static fn (array $r): bool => str_contains((string) $r['url'], 'goal=Signup')), 'a held goal is not sent');
        $totals = $this->report([])['data']['totals'];
        self::assertSame(1.0, (float) $totals['events']['Level']['revenue']);
        self::assertSame(0.0, (float) $totals['events']['Signup']['revenue'], 'a held goal carries no revenue');
        self::assertSame(1, $totals['fast_goals']);
    }

    public function testAnUnreadableFastGoalPolicyHoldsAndFlagsUnderTheCeiling(): void
    {
        // Both columns hold what no write stores; each is read as the value
        // that trusts least: the ceiling (3600 s) and hold.
        self::limits("fast_goal_policy = 'maybe', fast_goal_seconds = 9999");
        $this->click(100);
        $signup = $this->campaignGoal(30, ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        $this->events(self::U1, [['event_id' => 's1', 'name' => 'signup', 'occurred_at' => self::CLICK_TIME + 161]]); // 100 s: under the 3600 s ceiling
        $row = self::$db->query("SELECT too_fast, payable FROM 202_goal_outcomes WHERE goal_id = $signup")->fetch_assoc();
        self::assertSame(['1', '0'], [(string) $row['too_fast'], (string) $row['payable']], 'unreadable is the trusting-least reading, never "count"');
    }

    public function testTheRegistrationApiRefusesAMalformedLimitByName(): void
    {
        $apps = new AppRegistrationsController(self::$db, 1);
        $cases = [
            ['install_cap_per_minute' => 0],
            ['install_cap_per_minute' => '1e3'],
            ['install_cap_per_minute' => 1.5],
            ['event_cap_per_minute' => 99],
            ['ctit_min_seconds' => '07'],
            ['ctit_max_seconds' => 59],
            ['fast_goal_seconds' => 3601],
            ['fast_goal_policy' => 'HOLD'],
            ['fast_goal_seconds' => null],
            ['ctit_min_seconds' => 100, 'ctit_max_seconds' => 100],
        ];
        foreach ($cases as $payload) {
            try {
                $apps->update(5, $payload);
                self::fail('refused: ' . json_encode($payload));
            } catch (ValidationException $e) {
                self::assertNotSame([], array_intersect(array_keys($payload), array_keys($e->getFieldErrors())), json_encode($e->getFieldErrors()));
            }
        }
        // A pair valid on its own but not with the stored other half.
        $apps->update(5, ['ctit_max_seconds' => 600]);
        try {
            $apps->update(5, ['ctit_min_seconds' => 600]);
            self::fail('min at the stored max is refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ctit_min_seconds', $e->getFieldErrors());
        }
        $saved = (array) $apps->update(5, ['install_cap_per_minute' => '1000', 'fast_goal_policy' => 'hold', 'ctit_min_seconds' => 5])['data'];
        self::assertSame([1000, 'hold', 5, 600], [(int) $saved['install_cap_per_minute'], $saved['fast_goal_policy'], (int) $saved['ctit_min_seconds'], (int) $saved['ctit_max_seconds']]);

        // Android only.
        self::fixture("INSERT INTO 202_app_registrations SET registration_id=7, user_id=1, platform='ios', app_key='1234567890',
            app_name='iOS', accept_test_signals=0, attribution_window_days=7, trust_client_revenue=0, app_token='" . str_repeat('c', 64) . "', created_at=1, updated_at=1");
        try {
            $apps->update(7, ['install_cap_per_minute' => 10]);
            self::fail('an iOS registration takes no Android limit');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('install_cap_per_minute', $e->getFieldErrors());
        }
    }

    /** Both intakes ask the store they are given, with the registration's caps and injective buckets. */
    public function testAPlantedStoreIsTheOneAsked(): void
    {
        $this->click(100);
        $quota = new class () implements QuotaStore {
            /** @var list<array{0: string, 1: int, 2: int, 3: int}> */
            public array $asked = [];

            public function reserveQuotaWindow(string $bucket, int $limit, int $windowSeconds, int $cost = 1): array
            {
                $this->asked[] = [$bucket, $limit, $windowSeconds, $cost];

                return ['retry_after' => null, 'window_start' => 1];
            }

            public function refundQuota(string $bucket, int $windowSeconds, int $cost, int $windowStart): bool
            {
                throw new \LogicException('nothing failed, so nothing is refunded');
            }
        };
        $this->clock += 5;
        (new InstallIntake(self::$db, fn (): int => $this->clock, null, null, $quota))
            ->receive(self::TOKEN, (string) json_encode(self::body(self::U1, 'p202=' . self::tokenFor(100))), '203.0.113.9');
        $this->clock += 5;
        (new InstallEventsIntake(self::$db, fn (): int => $this->clock, null, $quota))
            ->receive(self::TOKEN, self::U1, (string) json_encode(['events' => [['event_id' => 'e1', 'name' => 'x', 'occurred_at' => self::CLICK_TIME + 500], ['event_id' => 'e2', 'name' => 'x', 'occurred_at' => self::CLICK_TIME + 501]]]));
        $row = self::installRow(self::U1);
        self::assertSame([
            ['app-install-cap:r5', 300, 60, 1],
            ['app-install-event-cap:r5:i' . $row['install_row_id'], 200, 60, 2],
        ], $quota->asked);
    }

    /** A trigger that fails every insert into $table, as a failure after admission would. */
    private static function failInserts(string $table): void
    {
        self::fixture("CREATE TRIGGER p202_planted_failure BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted failure'");
    }

    private static function stopFailing(): void
    {
        self::fixture('DROP TRIGGER IF EXISTS p202_planted_failure');
    }

    /** @param list<array<string, mixed>> $events */
    private static function batch(string $prefix, int $n, int $from = 200): array
    {
        return array_map(
            static fn (int $i): array => ['event_id' => $prefix . $i, 'name' => 'tick', 'occurred_at' => self::CLICK_TIME + $from + $i],
            range(1, $n)
        );
    }

    /** @param array<string, mixed> $body */
    private function eventsBody(string $uuid, array $body): array
    {
        $this->clock += 5;

        return (new InstallEventsIntake(self::$db, fn (): int => $this->clock))->receive(self::TOKEN, $uuid, (string) json_encode($body));
    }

    public function testAnInstallAdmittedAndThenNotRecordedIsRefunded(): void
    {
        self::limits('install_cap_per_minute = 1');
        $this->click(100);
        $this->click(101);
        self::failInserts('202_app_installs');
        try {
            $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
            self::fail('the planted failure reaches the caller');
        } catch (\Throwable $e) {
            self::assertStringContainsString('planted failure', $e->getMessage() . ($e->getPrevious()?->getMessage() ?? ''));
        } finally {
            self::stopFailing();
        }
        self::assertNull(self::installRow(self::U1));

        // The failed attempt's unit came back: the SDK's retry is admitted.
        $retry = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        self::assertSame(200, $retry['status'], json_encode($retry));
        $over = $this->install(self::body(self::U2, 'p202=' . self::tokenFor(101)));
        self::assertSame(429, $over['status'], 'and the cap still holds for what was recorded');
    }

    /** A refund that cannot be made is logged, and the caller still sees the failure it is answering for. */
    public function testAFailedRefundNeverReplacesTheOriginalError(): void
    {
        $this->click(100);
        $quota = new class () implements QuotaStore {
            public int $refunds = 0;

            public function reserveQuotaWindow(string $bucket, int $limit, int $windowSeconds, int $cost = 1): array
            {
                return ['retry_after' => null, 'window_start' => 1];
            }

            public function refundQuota(string $bucket, int $windowSeconds, int $cost, int $windowStart): bool
            {
                ++$this->refunds;
                throw new \RuntimeException('the refund store is down');
            }
        };
        self::failInserts('202_app_installs');
        try {
            (new InstallIntake(self::$db, fn (): int => $this->clock, null, null, $quota))
                ->receive(self::TOKEN, (string) json_encode(self::body(self::U1, 'p202=' . self::tokenFor(100))), '203.0.113.9');
            self::fail('the planted failure reaches the caller');
        } catch (\Throwable $e) {
            self::assertStringContainsString('planted failure', $e->getMessage() . ($e->getPrevious()?->getMessage() ?? ''));
            self::assertStringNotContainsString('refund store', $e->getMessage());
        } finally {
            self::stopFailing();
        }
        self::assertSame(1, $quota->refunds, 'the refund was attempted');
    }

    public function testAnEventsBatchIsChargedOnlyForWhatItNewlyStores(): void
    {
        self::limits('event_cap_per_minute = 100');
        $this->click(100);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));

        // A replay of a stored batch costs nothing: 60 + the replayed 60 + 40.
        self::assertSame(200, $this->events(self::U1, self::batch('a', 60))['status']);
        $replay = $this->events(self::U1, self::batch('a', 60));
        self::assertSame(200, $replay['status'], json_encode($replay));
        self::assertSame([0, 60], [count($replay['body']['data']['accepted']), count($replay['body']['data']['duplicates'])]);
        self::assertSame(200, $this->events(self::U1, self::batch('b', 40))['status'], 'the replay spent none of the budget');

        // At the cap, a replay is still answered — its events are recorded —
        // and anything new is refused.
        $atCap = $this->events(self::U1, self::batch('a', 60));
        self::assertSame(200, $atCap['status'], json_encode($atCap));
        self::assertSame(60, count($atCap['body']['data']['duplicates']));
        self::assertSame(429, $this->events(self::U1, self::batch('c', 1))['status']);
        // Partly new: charged for the new one only, which does not fit.
        self::assertSame(429, $this->events(self::U1, [...self::batch('a', 5), ...self::batch('c', 1)])['status']);
        self::assertSame(100, self::rows('202_goal_events'));
    }

    public function testAnEventsBatchTheEngineRefusesOrThatFailsIsRefunded(): void
    {
        self::limits('event_cap_per_minute = 100');
        $this->click(100);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        self::assertSame(200, $this->events(self::U1, self::batch('a', 1))['status']);

        // 50 new events and one stored id with different content: 409, the
        // batch stored nothing, and the 50 go back.
        $conflict = $this->events(self::U1, [...self::batch('x', 50), ['event_id' => 'a1', 'name' => 'other', 'occurred_at' => self::CLICK_TIME + 999]]);
        self::assertSame(409, $conflict['status'], json_encode($conflict));

        // A batch that fails in the store: the exception reaches the caller
        // and the 48 go back.
        self::failInserts('202_goal_events');
        try {
            $this->events(self::U1, self::batch('y', 48));
            self::fail('the planted failure reaches the caller');
        } catch (\Throwable $e) {
            self::assertStringContainsString('planted failure', $e->getMessage() . ($e->getPrevious()?->getMessage() ?? ''));
        } finally {
            self::stopFailing();
        }
        self::assertSame(1, self::rows('202_goal_events'));

        // All 99 remaining units are there.
        self::assertSame(200, $this->events(self::U1, self::batch('z', 99))['status']);
        self::assertSame(429, $this->events(self::U1, self::batch('w', 1))['status']);
    }

    public function testACustomerOnlyBodyIsUnderTheInstallsEventCap(): void
    {
        self::limits('event_cap_per_minute = 100');
        $this->click(100);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        $customer = ['customer' => ['id' => 'u-829', 'type' => 'custom', 'signature' => str_repeat('a', 64)]];
        self::assertSame(200, $this->eventsBody(self::U1, $customer)['status'], 'one unit of the budget');
        self::assertSame(200, $this->events(self::U1, self::batch('a', 99))['status']);
        $over = $this->eventsBody(self::U1, $customer);
        self::assertSame(429, $over['status'], json_encode($over));
        self::assertStringContainsString('event_cap_per_minute = 100', $over['body']['message']);

        // And, like the events, fails closed on an unreadable cap.
        self::limits('event_cap_per_minute = 5');
        self::assertSame(503, $this->eventsBody(self::U1, $customer)['status']);
    }

    /**
     * Two writes that each move one CTIT bound, the first still open when
     * the second is checked: the second is checked against the row the
     * first leaves, because both take the registration's row lock.
     */
    public function testTheCtitPairIsCheckedUnderTheRegistrationsRowLock(): void
    {
        $env = [];
        foreach (['P202_TEST_DB_HOST', 'P202_TEST_DB_USER', 'P202_TEST_DB_PASS', 'P202_TEST_DB_NAME', 'P202_TEST_DB_PORT'] as $name) {
            $env[$name] = (string) getenv($name);
        }
        $child = <<<'PHP'
            $db = mysqli_connect(getenv('P202_TEST_DB_HOST'), getenv('P202_TEST_DB_USER') ?: 'root', getenv('P202_TEST_DB_PASS') ?: '',
                getenv('P202_TEST_DB_NAME'), (int) (getenv('P202_TEST_DB_PORT') ?: 3306));
            $db->begin_transaction();
            $db->query('UPDATE 202_app_registrations SET ctit_max_seconds = 100 WHERE registration_id = 5');
            fwrite(STDOUT, "locked\n");
            fflush(STDOUT);
            usleep(1500000);
            $db->commit();
            PHP;
        $proc = proc_open([PHP_BINARY, '-r', $child], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource($proc);
        try {
            // Not stream_get_contents($pipes[2]) as a failure message: it
            // would be evaluated first, and wait for the child to exit.
            self::assertSame("locked\n", fgets($pipes[1]));
            try {
                (new AppRegistrationsController(self::$db, 1))->update(5, ['ctit_min_seconds' => 500]);
                self::fail('checked against the committed max (100), the min is refused');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('ctit_min_seconds', $e->getFieldErrors());
            }
        } finally {
            fclose($pipes[1]);
            $childErrors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
        }
        self::assertSame([0, ''], [$exit, $childErrors], 'the child held the lock and committed');
        $row = self::$db->query('SELECT ctit_min_seconds, ctit_max_seconds FROM 202_app_registrations WHERE registration_id = 5')->fetch_assoc();
        self::assertSame(['10', '100'], [(string) $row['ctit_min_seconds'], (string) $row['ctit_max_seconds']], 'never min >= max');
    }

    /** An engine whose notifier records every notice it is handed. */
    private function recordingEngine(array &$notices): GoalEngine
    {
        $notifier = new class ($notices) implements OutcomeNotifier {
            /** @param list<array<string, mixed>> $notices */
            public function __construct(private array &$notices)
            {
            }

            public function notify(int $userId, GoalSubject $subject, array $notices): array
            {
                array_push($this->notices, ...$notices);

                return [];
            }
        };

        return new GoalEngine($this->conn, $this->goals, null, fn (): int => $this->clock, $notifier);
    }

    /** @return array<string, string|null> the live outcome of $goal */
    private static function liveOutcome(int $goal): array
    {
        $rows = self::$db->query("SELECT goal_version, too_fast, payable, value_note, conversion_id FROM 202_goal_outcomes WHERE goal_id = $goal AND superseded_at IS NULL")->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $rows);

        return array_map(static fn ($v): ?string => $v === null ? null : (string) $v, $rows[0]);
    }

    private function signupV2(int $goal, string $amount): int
    {
        $this->clock += 100;

        return (int) $this->goals->addVersion(1, $goal, GoalDefinition::parse(
            ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => $amount]]
        ), $this->clock)['version'];
    }

    public function testAHeldGoalStaysHeldWhenThePolicyTurnsToCountAndItIsReEvaluatedOrRevived(): void
    {
        self::limits("fast_goal_policy = 'hold', fast_goal_seconds = 30");
        $this->click(100);
        $signup = $this->campaignGoal(30, ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        $this->events(self::U1, [['event_id' => 's1', 'name' => 'signup', 'occurred_at' => self::CLICK_TIME + 71]]); // 10 s after the install
        self::assertSame(['1', '1', '0', 'held_too_fast'], array_values(array_intersect_key(self::liveOutcome($signup), array_flip(['goal_version', 'too_fast', 'payable', 'value_note']))));

        // The operator switches to count, then re-evaluates onto a new version.
        self::limits("fast_goal_policy = 'count'");
        $v2 = $this->signupV2($signup, '4');
        $notices = [];
        $engine = $this->recordingEngine($notices);
        $engine->reevaluate(1, $signup, $v2, true, 100, 0, 'install');
        $live = self::liveOutcome($signup);
        self::assertSame([(string) $v2, '1', '0', 'held_too_fast'], [$live['goal_version'], $live['too_fast'], $live['payable'], $live['value_note']], 'the replacement keeps the hold');
        self::assertSame([], array_filter(self::ledger(100), static fn (array $r): bool => $r['source'] === 'goal' && (int) $r['deleted'] === 0 && (int) $r['payable'] === 1), 'no paid ledger row');
        self::assertSame([], array_filter(self::outbox(), static fn (array $r): bool => $r['kind'] === 'reached' && str_contains((string) $r['url'], 'goal=Signup')), 'nothing sent');
        self::assertSame([false], array_values(array_unique(array_column(array_filter($notices, static fn (array $n): bool => $n['goal_id'] === $signup), 'payable'))));

        // Back onto version 1: the retired row is revived, its ledger row
        // with it, and the answer reports it as stored — unpaid.
        $notices = [];
        $engine->reevaluate(1, $signup, 1, true, 100, 0, 'install');
        $live = self::liveOutcome($signup);
        self::assertSame(['1', '1', '0', 'held_too_fast'], [$live['goal_version'], $live['too_fast'], $live['payable'], $live['value_note']]);
        $revived = array_values(array_filter($notices, static fn (array $n): bool => $n['goal_id'] === $signup && $n['version'] === 1));
        self::assertCount(1, $revived);
        self::assertFalse($revived[0]['payable'], 'the revived outcome is reported as the held row it is');
        self::assertSame('none', $revived[0]['kind']);
        self::assertSame(0.0, (float) $this->report([])['data']['totals']['events']['Signup']['revenue']);
    }

    /**
     * A revived outcome whose row no restatement rewrites is answered as the
     * row is stored, not as a fresh valuation would put it: here the goal
     * has left the campaign since (a fresh valuation says unpaid), and the
     * revived row and its ledger row are the paid ones they were.
     */
    public function testARevivedOutcomeIsReportedAsItIsStored(): void
    {
        $this->click(100);
        $signup = $this->registrationGoal(['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        $this->events(self::U1, [['event_id' => 's1', 'name' => 'signup', 'occurred_at' => self::CLICK_TIME + 161]]);
        self::assertSame(['0', '1'], [self::liveOutcome($signup)['too_fast'], self::liveOutcome($signup)['payable']]);
        $v2 = $this->signupV2($signup, '4');
        $notices = [];
        $engine = $this->recordingEngine($notices);
        $engine->reevaluate(1, $signup, $v2, true, 100, 0, 'install');
        $this->goals->detach(1, 30, $signup);

        $notices = [];
        $engine->reevaluate(1, $signup, 1, true, 100, 0, 'install');
        $live = self::liveOutcome($signup);
        self::assertSame(['1', '1'], [$live['goal_version'], $live['payable']], 'revived as stored');
        $revived = array_values(array_filter($notices, static fn (array $n): bool => $n['goal_id'] === $signup && $n['version'] === 1));
        self::assertCount(1, $revived);
        self::assertTrue($revived[0]['payable'], 'answered as the paid row it is');
        self::assertNotSame('reached', $revived[0]['kind'], 'and never announced again');
    }

    public function testAGoalPaidUnderCountIsNotHeldByALaterSwitchToHold(): void
    {
        self::limits("fast_goal_policy = 'count', fast_goal_seconds = 30");
        $this->click(100);
        $signup = $this->campaignGoal(30, ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        $this->events(self::U1, [['event_id' => 's1', 'name' => 'signup', 'occurred_at' => self::CLICK_TIME + 71]]);
        self::assertSame(['1', '1', null], [self::liveOutcome($signup)['too_fast'], self::liveOutcome($signup)['payable'], self::liveOutcome($signup)['value_note']]);

        self::limits("fast_goal_policy = 'hold'");
        $v2 = $this->signupV2($signup, '4');
        $notices = [];
        $this->recordingEngine($notices)->reevaluate(1, $signup, $v2, true, 100, 0, 'install');
        $live = self::liveOutcome($signup);
        self::assertSame([(string) $v2, '1', '1', null], [$live['goal_version'], $live['too_fast'], $live['payable'], $live['value_note']], 'decided at write time: still paid');
    }

    /**
     * A change of the install's credit gives its outcomes their ledger rows
     * (recreditInstallInTransaction()): a held outcome gets an unpaid one
     * whatever the policy says by then, and a flagged outcome written under
     * count — unpaid only for want of a click — gets a paid one.
     */
    /** @param array<string, mixed> $definition */
    private function registrationGoal(array $definition): int
    {
        $id = $this->goals->create(1, \Prosper202\Goals\GoalScope::REGISTRATION, 5, GoalDefinition::parse($definition), 1);
        $this->goals->attach(1, 30, $id, null, true, 1);

        return $id;
    }

    public function testACreditChangeKeepsEachOutcomesFastGoalDecision(): void
    {
        self::limits("fast_goal_policy = 'hold', fast_goal_seconds = 30");
        $this->click(100);
        // Registration goals the campaign pays for: an uncredited install
        // reaches them for the funnel, with no click to pay on.
        $signup = $this->registrationGoal(['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $level = $this->registrationGoal(['name' => 'Level', 'trigger' => ['event' => 'level'], 'value' => ['type' => 'fixed', 'amount' => 1]]);
        // A test install the registration does not accept: attributed, not credited.
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100), ['test' => true]));
        self::assertSame('attributed', $r['body']['data']['match']);
        $this->events(self::U1, [['event_id' => 's1', 'name' => 'signup', 'occurred_at' => self::CLICK_TIME + 71]]);
        self::limits("fast_goal_policy = 'count'");
        $this->events(self::U1, [['event_id' => 'l1', 'name' => 'level', 'occurred_at' => self::CLICK_TIME + 75]]);
        self::assertSame(['1', '0', 'held_too_fast', null], [self::liveOutcome($signup)['too_fast'], self::liveOutcome($signup)['payable'], self::liveOutcome($signup)['value_note'], self::liveOutcome($signup)['conversion_id']]);
        self::assertSame(['1', null], [self::liveOutcome($level)['too_fast'], self::liveOutcome($level)['conversion_id']]);

        (new AppRegistrationsController(self::$db, 1))->update(5, ['accept_test_signals' => 1]);

        $s = self::liveOutcome($signup);
        self::assertNotNull($s['conversion_id'], 'the held outcome gains its row');
        self::assertSame(['0', 'held_too_fast'], [$s['payable'], $s['value_note']], 'and stays held under count');
        $l = self::liveOutcome($level);
        self::assertSame(['1', '1'], [$l['too_fast'], $l['payable']], 'the one written under count pays once credited');
        $rows = array_column(array_filter(self::ledger(100), static fn (array $r): bool => $r['source'] === 'goal' && (int) $r['deleted'] === 0), 'payable', 'source_ref');
        self::assertSame('0', (string) $rows['goal:' . $signup . ':1']);
        self::assertSame('1', (string) $rows['goal:' . $level . ':1']);
    }
}
