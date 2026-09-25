<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Apps\Android\PendingClickSettler;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalScope;
use Prosper202\Notifications\NotificationOutbox;

/**
 * The Android intake against a real server (plan §5.2–§5.5): the install
 * row, its MatchState, the built-in install goal's conversion on the click,
 * the MTA outbox, the traffic-source notification, replays, forgeries,
 * settling a pending click, and events through the goal engine.
 *
 * @group integration
 */
final class InstallIntakeIntegrationTest extends TestCase
{
    use AndroidDatabase;

    private const U1 = '00000000-0000-4000-8000-000000000001';
    private const U2 = '00000000-0000-4000-8000-000000000002';
    private const U3 = '00000000-0000-4000-8000-000000000003';

    public function testAnAttributedInstallIsTheInstallGoalsConversionOnItsClick(): void
    {
        $this->click(100);
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100) . '&utm_source=newsletter'));
        self::assertSame(200, $r['status'], json_encode($r));
        self::assertSame(['install_uuid' => self::U1, 'match' => 'attributed', 'reason' => 'Attributed to click 100.', 'trusted' => 1, 'test' => false,
            'integrity' => 'not_requested', 'duplicate' => false], $r['body']['data']);
        self::assertArrayNotHasKey('click_id', $r['body']['data'], 'the answer carries no click data the referrer did not hold');

        $row = self::installRow(self::U1);
        self::assertSame(['attributed', '1', '100', 'newsletter'], [$row['match_state'], (string) $row['trusted'], (string) $row['click_id'], $row['utm_source']]);
        $ledger = self::ledger(100);
        self::assertCount(1, $ledger);
        self::assertSame(['app_install', 'install', 'install', '4', '1', '2.50000', (string) (self::CLICK_TIME + 61)], [
            $ledger[0]['source'], $ledger[0]['dedupe_key'], $ledger[0]['event_name'], (string) $ledger[0]['pixel_type'],
            (string) $ledger[0]['payable'], $ledger[0]['click_payout'], (string) $ledger[0]['conv_time'],
        ], 'key install, pixel_type 4, at Google\'s install time, at the campaign default');
        self::assertSame('install:' . $row['install_row_id'], $ledger[0]['source_ref']);
        self::assertNull($ledger[0]['transaction_id'], 'no network sent an id');
        self::assertSame((string) $ledger[0]['conv_id'], (string) $row['conversion_id'], 'the install row names its conversion');
        self::assertSame(['lead' => 1, 'payout' => '2.50000'], self::clickValue(100));
        self::assertSame(1, self::rows('202_attribution_pending', 'conv_id = ' . (int) $ledger[0]['conv_id']), 'queued for MTA in the same transaction');

        $outcome = self::$db->query("SELECT o.*, g.builtin FROM 202_goal_outcomes o JOIN 202_goals g ON g.goal_id = o.goal_id")->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $outcome);
        self::assertSame(['install', 'install', '@install', '5', '1'], [$outcome[0]['builtin'], $outcome[0]['subject_type'], $outcome[0]['event_id'],
            (string) $outcome[0]['app_registration_id'], (string) $outcome[0]['payable']]);

        $out = self::outbox();
        self::assertCount(1, $out);
        self::assertSame(['reached', 'pending', '90', (string) $ledger[0]['conv_id']], [$out[0]['kind'], $out[0]['status'], (string) $out[0]['pixel_id'], (string) $out[0]['conv_id']]);
        self::assertSame('https://ts.example/pb?sub=100&goal=install&v=2.50&tx=install&p=2.50', $out[0]['url']);

        // The worker sends it once.
        $outbox = new NotificationOutbox(new Connection(self::$db), fn (): int => $this->clock, function (string $url): bool {
            $this->sent[] = $url;

            return true;
        });
        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 0], $outbox->sendDue(10));
        self::assertSame(['sent' => 0, 'failed' => 0, 'retrying' => 0], $outbox->sendDue(10));
        self::assertSame([$out[0]['url']], $this->sent);
    }

    public function testAReplayIsADuplicateAndAReusedIdWithOtherContentIsRefused(): void
    {
        $this->click(100);
        $body = self::body(self::U1, 'p202=' . self::tokenFor(100));
        self::assertSame('attributed', $this->install($body)['body']['data']['match']);
        $replay = $this->install($body);
        self::assertSame(200, $replay['status']);
        self::assertTrue($replay['body']['data']['duplicate']);
        self::assertSame('attributed', $replay['body']['data']['match']);
        // Whitespace and key order are not content.
        $reordered = $this->install((string) json_encode(array_reverse($body, true), JSON_PRETTY_PRINT));
        self::assertTrue($reordered['body']['data']['duplicate'] ?? false, json_encode($reordered));
        $changed = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100), ['app_version' => '3.3.0']));
        self::assertSame(409, $changed['status']);
        self::assertStringContainsString('different content', $changed['body']['message']);
        self::assertSame(1, self::rows('202_app_installs'));
        self::assertCount(1, self::ledger(100));
        self::assertCount(1, self::outbox());
    }

    public function testOnlyAnAcceptedEventExemptsAnUnvouchedInstallFromRetention(): void
    {
        self::assertSame('organic', $this->install(self::body(self::U1, 'utm_source=google-play&utm_medium=organic'))['body']['data']['match']);
        self::assertSame(0, (int) self::installRow(self::U1)['has_events']);

        // A batch the engine refuses stores nothing, so it must not keep the
        // install past retention either.
        $conflict = $this->events(self::U1, [
            ['event_id' => 'e1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 500],
            ['event_id' => 'e1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 501],
        ]);
        self::assertSame(409, $conflict['status'], json_encode($conflict));
        self::assertSame(0, self::rows('202_goal_events'));
        self::assertSame(0, (int) self::installRow(self::U1)['has_events'], 'a refused batch leaves the retention hint alone');

        self::assertSame(200, $this->events(self::U1, [['event_id' => 'e1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 500]])['status']);
        self::assertSame(1, self::rows('202_goal_events'));
        self::assertSame(1, (int) self::installRow(self::U1)['has_events'], 'a stored event keeps the install');

        // A later refused batch does not undo the hint for events already stored.
        self::assertSame(409, $this->events(self::U1, [['event_id' => 'e1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 999]])['status']);
        self::assertSame(1, (int) self::installRow(self::U1)['has_events']);
    }

    public function testASecondDeviceOnTheSameClickIsADuplicateClick(): void
    {
        $this->click(100);
        $referrer = 'p202=' . self::tokenFor(100);
        $this->install(self::body(self::U1, $referrer));
        $second = $this->install(self::body(self::U2, $referrer));
        self::assertSame('duplicate_click', $second['body']['data']['match']);
        self::assertNull($second['body']['data']['trusted']);
        self::assertCount(1, self::ledger(100), 'one install conversion per click');
        self::assertNull(self::installRow(self::U2)['conversion_id']);
    }

    public function testForgeriesAreRefutedAndReachNoGoal(): void
    {
        $this->click(100);
        $token = self::tokenFor(100);
        $mac = substr($token, 4);
        $tampered = '100.' . ($mac[0] === 'A' ? 'B' : 'A') . substr($mac, 1);
        $r = $this->install(self::body(self::U1, 'p202=' . $tampered));
        self::assertSame(['bad_token', 0], [$r['body']['data']['match'], $r['body']['data']['trusted']]);
        self::assertStringContainsString('signature does not verify', $r['body']['data']['reason']);
        // The sequential-id attack: a bare click id.
        self::assertSame('bad_token', $this->install(self::body(self::U2, 'p202=100'))['body']['data']['match']);
        self::assertSame([], self::ledger(100));
        self::assertSame(0, self::rows('202_goal_subjects'), 'a refuted install reaches no goal');
        self::assertSame(['lead' => 0, 'payout' => '2.50000'], self::clickValue(100));

        // Events for it are refused, terminally.
        $events = $this->events(self::U1, [['event_id' => 'e1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 500]]);
        self::assertSame(409, $events['status']);
        self::assertSame(0, self::rows('202_goal_events'));
    }

    public function testAnotherAppsTokenOrClickIsRefused(): void
    {
        $this->click(100);
        $body = self::body(self::U1, 'p202=' . self::tokenFor(100));
        $lifted = $this->install($body, self::OTHER_TOKEN);
        self::assertSame(422, $lifted['status'], 'a token lifted into another app is a visible mismatch');
        self::assertStringContainsString('com.other.app', $lifted['body']['message']);
        self::assertStringContainsString('com.example.summit', $lifted['body']['message']);
        self::assertSame(404, $this->install($body, str_repeat('c', 64))['status']);
        self::assertSame(400, $this->install($body, 'not-a-token')['status']);
        self::assertSame(400, $this->install($body, '')['status']);

        // Another account's click.
        $this->campaign(31, null, 'accumulate', '1.00', 2);
        $this->click(200, 31, 2);
        self::assertSame('foreign_click', $this->install(self::body(self::U2, 'p202=' . self::tokenFor(200)))['body']['data']['match']);
        // A campaign linked to another registration of the same account.
        self::fixture("INSERT INTO 202_app_registrations SET registration_id=7, user_id=1, platform='android', app_key='com.example.other',
            app_name='O', accept_test_signals=0, attribution_window_days=7, trust_client_revenue=0, app_token='" . str_repeat('d', 64) . "', created_at=1, updated_at=1");
        $this->campaign(32, 7);
        $this->click(300, 32);
        $foreign = $this->install(self::body(self::U3, 'p202=' . self::tokenFor(300)));
        self::assertSame('foreign_click', $foreign['body']['data']['match']);
        self::assertSame(0, $foreign['body']['data']['trusted']);
        self::assertSame(0, self::rows('202_conversion_logs'));
    }

    public function testDeletingARegistrationUnlinksItsCampaignsSoRegisteringTheAppAgainAttributesTheirClicks(): void
    {
        // Another account's campaign linked to its own app: not the delete's to touch.
        $this->campaign(31, 6, 'accumulate', '1.00', 2);
        $this->click(100);
        $apps = new \Api\V3\Controllers\AppRegistrationsController(self::$db, 1);
        $apps->delete(5);
        self::assertSame([['30', null], ['31', '6']], self::$db->query('SELECT aff_campaign_id, app_registration_id FROM 202_aff_campaigns ORDER BY aff_campaign_id')->fetch_all(),
            'the deleted registration\'s campaign is unlinked in the delete; nobody else\'s moves');

        $again = $apps->create(['app_key' => 'com.example.summit', 'app_name' => 'Summit again'])['data'];
        $registration = (int) $again['registration_id'];
        self::assertNotSame(5, $registration);
        $token = (new \Api\V3\Controllers\AppInstallsController(self::$db, 1))->installToken($registration, ['click_id' => '100']);
        self::assertSame(100, $token['data']['click_id'], 'the operator can mint a token for the campaign\'s click again');
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)), (string) $again['app_token']);
        self::assertSame(['attributed', 1], [$r['body']['data']['match'], $r['body']['data']['trusted']], json_encode($r));
    }

    public function testTimingAndTheWindow(): void
    {
        $this->click(100);
        $this->click(101);
        $injected = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100), [], [
            'referrer_click_timestamp_server_seconds' => self::CLICK_TIME + 100, 'install_begin_timestamp_server_seconds' => self::CLICK_TIME + 50,
        ]));
        self::assertSame('implausible', $injected['body']['data']['match']);
        $late = $this->install(self::body(self::U2, 'p202=' . self::tokenFor(101), [], [
            'referrer_click_timestamp_server_seconds' => self::CLICK_TIME + 10, 'install_begin_timestamp_server_seconds' => self::CLICK_TIME + 8 * 86400,
        ]));
        self::assertSame('outside_window', $late['body']['data']['match']);
        self::assertNull($late['body']['data']['trusted'], 'late, not forged');
        self::assertSame(0, self::rows('202_conversion_logs'));
    }

    public function testOrganicThirdPartyAndUnavailableInstallsAreStoredAndCountedOnlyInTheFunnel(): void
    {
        self::assertSame('organic', $this->install(self::body(self::U1, 'utm_source=google-play&utm_medium=organic'))['body']['data']['match']);
        self::assertSame('third_party', $this->install(self::body(self::U2, 'gclid=Cj0KCQ&utm_source=google'))['body']['data']['match']);
        $unavailable = self::body(self::U3, '');
        $unavailable['referrer'] = ['status' => 'feature_not_supported'];
        self::assertSame('unavailable', $this->install($unavailable)['body']['data']['match']);
        self::assertSame('Cj0KCQ', self::installRow(self::U2)['gclid']);
        self::assertSame(0, self::rows('202_conversion_logs'));
        self::assertSame(3, self::rows('202_goal_outcomes', "event_id = '@install' AND payable = 0 AND conversion_id IS NULL"),
            'every settled, unrefuted install reaches the install goal, for the funnel');
    }

    public function testAPendingClickSettlesWhenTheClickArrivesAndItsEventsWaitUntilThen(): void
    {
        $referrer = 'p202=' . self::tokenFor(500);
        $r = $this->install(self::body(self::U1, $referrer));
        self::assertSame(['pending_click', null], [$r['body']['data']['match'], $r['body']['data']['trusted']]);
        self::assertSame(0, self::rows('202_goal_subjects'), 'a pending install is evaluated when it settles');

        $waiting = $this->events(self::U1, [['event_id' => 'e1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 500]]);
        self::assertSame(503, $waiting['status']);
        self::assertSame('pending_click', $waiting['body']['match']);
        self::assertSame(['Retry-After' => '60'], $waiting['headers'] ?? null);

        $settler = new PendingClickSettler(self::$db, fn (): int => $this->clock);
        self::assertSame(['examined' => 1, 'settled' => [], 'still_pending' => 1, 'failed' => 0], $settler->run());

        $this->click(500);
        self::assertSame(['examined' => 1, 'settled' => ['attributed' => 1], 'still_pending' => 0, 'failed' => 0], $settler->run());
        $row = self::installRow(self::U1);
        self::assertSame('attributed', $row['match_state']);
        self::assertNotNull($row['settled_at']);
        self::assertSame((string) self::ledger(500)[0]['conv_id'], (string) $row['conversion_id']);
        self::assertCount(1, self::outbox());
        self::assertSame(['examined' => 0, 'settled' => [], 'still_pending' => 0, 'failed' => 0], $settler->run(), 'settled once');

        self::assertSame(200, $this->events(self::U1, [['event_id' => 'e1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 500]])['status']);
    }

    public function testAPendingClickWhoseRegistrationIsGoneNeverStarvesAnotherApp(): void
    {
        // An older pending click on registration 5, which then disappears.
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(500)));
        // Another owner's app waits on its own click after it.
        $this->campaign(31, 6, 'accumulate', '1.00', 2);
        $this->install(self::body(self::U2, 'p202=' . self::tokenFor(600), ['app_key' => 'com.other.app']), self::OTHER_TOKEN);
        self::assertSame(['pending_click', 'pending_click'], [self::installRow(self::U1)['match_state'], self::installRow(self::U2)['match_state']]);
        self::fixture('DELETE FROM 202_app_registrations WHERE registration_id = 5');
        self::assertSame(0, self::rows('202_app_registrations', 'registration_id = 5'), 'the orphaning landed');

        $this->click(600, 31, 2);
        $settler = new PendingClickSettler(self::$db, fn (): int => $this->clock);
        self::assertSame(['examined' => 1, 'settled' => ['attributed' => 1], 'still_pending' => 0, 'failed' => 0], $settler->run(1),
            'the one slot went to the install that can be settled, not the older orphan');
        self::assertSame('attributed', self::installRow(self::U2)['match_state']);
        self::assertSame(['bad_token', '0'], [self::installRow(self::U1)['match_state'], (string) self::installRow(self::U1)['trusted']],
            'the orphan is retired as the deadline would leave it (OrphanedPendingClicks), never left pending for good');
    }

    public function testAClickNeverRecordedSettlesAsABadTokenAfterADay(): void
    {
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(501)));
        $this->clock += 86400;
        $done = (new PendingClickSettler(self::$db, fn (): int => $this->clock))->run();
        self::assertSame(['bad_token' => 1], $done['settled']);
        $row = self::installRow(self::U1);
        self::assertSame(['bad_token', '0'], [$row['match_state'], (string) $row['trusted']]);
        self::assertStringContainsString('never recorded', $row['match_reason']);
    }

    public function testATestInstallCountsOnlyUnderAcceptTestSignals(): void
    {
        $this->click(100);
        $this->click(101);
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100), ['test' => true]));
        self::assertSame(['attributed', null, true], [$r['body']['data']['match'], $r['body']['data']['trusted'], $r['body']['data']['test']]);
        self::assertSame([], self::ledger(100), 'an untrusted test install pays nothing');
        self::fixture('UPDATE 202_app_registrations SET accept_test_signals = 1 WHERE registration_id = 5');
        $accepted = $this->install(self::body(self::U2, 'p202=' . self::tokenFor(101), ['test' => true]));
        self::assertSame(1, $accepted['body']['data']['trusted']);
        self::assertCount(1, self::ledger(101));
    }

    /**
     * accept_test_signals is a live policy: toggling it re-judges the test
     * installs already stored, and their outcomes move onto or off the
     * click — the conversions written, retired and revived, never
     * duplicated, and the traffic source told once.
     */
    public function testTogglingAcceptTestSignalsReJudgesTheTestInstallsAlreadyStored(): void
    {
        $this->click(100);
        $level = $this->campaignGoal(30, ['name' => 'Level up', 'trigger' => ['event' => 'level_reached'], 'value' => ['type' => 'fixed', 'amount' => 4]]);
        $installGoal = $this->goals->ensureBuiltinInstallGoal(1, 5, 1);
        $this->goals->attach(1, 30, $installGoal, null, true, 1);
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100), ['test' => true]));
        self::assertSame(['attributed', null], [$r['body']['data']['match'], $r['body']['data']['trusted']]);
        self::assertSame(200, $this->events(self::U1, [['event_id' => 'l1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 100]])['status']);
        // A non-test install on another click: the policy is not about it.
        $this->click(101);
        self::assertSame(1, $this->install(self::body(self::U2, 'p202=' . self::tokenFor(101)))['body']['data']['trusted']);
        $untouched = self::ledger(101);
        self::assertSame([], self::ledger(100));
        self::assertSame(0, self::rows('202_goal_outcomes', 'goal_id = ' . $level), 'the campaign\'s goals apply through the click only');
        $apps = new \Api\V3\Controllers\AppRegistrationsController(self::$db, 1);

        // On: the stored test install is credited as if it had arrived now.
        $apps->update(5, ['accept_test_signals' => 1]);
        $row = self::installRow(self::U1);
        self::assertSame('1', (string) $row['trusted']);
        $credited = self::ledger(100);
        self::assertSame([['app_install', '0'], ['goal', '0']], array_map(static fn (array $c): array => [$c['source'], (string) $c['deleted']], $credited));
        self::assertSame((string) $credited[0]['conv_id'], (string) $row['conversion_id']);
        self::assertSame(['lead' => 1, 'payout' => '6.50000'], self::clickValue(100));
        $reached = static fn (): array => array_values(array_filter(self::outbox(), static fn (array $n): bool => $n['kind'] === 'reached' && in_array((string) $n['conv_id'], array_map(static fn (array $c): string => (string) $c['conv_id'], $credited), true)));
        self::assertSame(['pending', 'pending'], array_column($reached(), 'status'));
        self::assertSame(1, self::rows('202_goal_outcomes', 'goal_id = ' . $level . ' AND superseded_at IS NULL AND conversion_id IS NOT NULL'));

        // Idempotent: the same policy again changes nothing.
        $apps->update(5, ['accept_test_signals' => 1]);
        self::assertSame($credited, self::ledger(100));

        // Off: withdrawn. The install notification had gone out, so it is
        // retracted; the goal's never had, so it is cancelled.
        self::$db->query("UPDATE 202_notification_pending SET status = 'sent', attempts = 1 WHERE conv_id = " . (int) $credited[0]['conv_id'] . " AND kind = 'reached'");
        $apps->update(5, ['accept_test_signals' => 0]);
        $row = self::installRow(self::U1);
        self::assertNull($row['trusted']);
        self::assertNull($row['conversion_id']);
        self::assertSame(['1', '1'], array_map(static fn (array $c): string => (string) $c['deleted'], self::ledger(100)));
        self::assertSame(0, self::clickValue(100)['lead']);
        self::assertSame(['sent', 'cancelled'], array_column($reached(), 'status'));
        self::assertSame(1, self::rows('202_notification_pending', "kind = 'retraction' AND conv_id = " . (int) $credited[0]['conv_id']));
        self::assertSame(1, self::rows('202_goal_outcomes', "event_id = '@install' AND superseded_at IS NULL AND conversion_id IS NULL AND payable = 0 AND campaign_id IS NULL"),
            'the install stays in the funnel, off its click');
        self::assertSame(0, self::rows('202_goal_outcomes', 'goal_id = ' . $level . ' AND superseded_at IS NULL'), 'the campaign goal is retired with its row');

        // On again: the same rows come back — revived, not written twice.
        $apps->update(5, ['accept_test_signals' => 1]);
        $row = self::installRow(self::U1);
        self::assertSame('1', (string) $row['trusted']);
        self::assertSame((string) $credited[0]['conv_id'], (string) $row['conversion_id']);
        self::assertSame(array_column($credited, 'conv_id'), array_column(self::ledger(100), 'conv_id'));
        self::assertSame(['0', '0'], array_map(static fn (array $c): string => (string) $c['deleted'], self::ledger(100)));
        self::assertSame(['lead' => 1, 'payout' => '6.50000'], self::clickValue(100));
        self::assertSame(['sent', 'pending'], array_column($reached(), 'status'), 'the cancelled one is queued again; the sent one is not re-sent');
        self::assertSame(1, self::rows('202_goal_outcomes', 'goal_id = ' . $level . ' AND superseded_at IS NULL AND conversion_id IS NOT NULL'));
        self::assertSame($untouched, self::ledger(101));
    }

    /**
     * An outcome retired while its install had one credit and revived under
     * the other (a re-evaluation returning to it) is bound the way the
     * install is now: no ledger row without the click, its row back on it.
     */
    public function testAnOutcomeRevivedAfterItsInstallsCreditChangedIsBoundTheWayTheInstallIsNow(): void
    {
        self::fixture('UPDATE 202_app_registrations SET accept_test_signals = 1 WHERE registration_id = 5');
        $engine = new \Prosper202\Goals\GoalEngine(new Connection(self::$db), $this->goals, null, fn (): int => $this->clock);
        $apps = new \Api\V3\Controllers\AppRegistrationsController(self::$db, 1);
        $goal = $this->goals->create(1, GoalScope::REGISTRATION, 5, GoalDefinition::parse(['name' => 'Level', 'trigger' => ['event' => 'level_reached']]), 1);
        $this->goals->attach(1, 30, $goal, \Prosper202\Conversion\Ledger\Amount::toUnits('3'), false, 1);
        $installGoal = $this->goals->ensureBuiltinInstallGoal(1, 5, 1);
        $this->goals->attach(1, 30, $installGoal, null, false, 1);
        $this->click(100);
        self::assertSame(1, $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100), ['test' => true]))['body']['data']['trusted']);
        self::assertSame(200, $this->events(self::U1, [['event_id' => 'l1', 'name' => 'level_reached', 'occurred_at' => self::CLICK_TIME + 100, 'properties' => ['level' => 1]]])['status']);
        $outcome = static fn (): array => self::$db->query('SELECT conversion_id, superseded_at, campaign_id FROM 202_goal_outcomes WHERE goal_id = ' . $goal . ' AND goal_version = 1')->fetch_assoc();
        $row = static fn (int $conv): array => self::$db->query('SELECT deleted FROM 202_conversion_logs WHERE conv_id = ' . $conv)->fetch_assoc();
        $conv = (int) $outcome()['conversion_id'];
        self::assertGreaterThan(0, $conv);
        $edit = function (int $level) use ($goal): void {
            $this->clock += 100;
            $this->goals->addVersion(1, $goal, GoalDefinition::parse(['name' => 'Level', 'trigger' => ['event' => 'level_reached', 'where' => [['prop' => 'level', 'op' => 'gte', 'value' => $level]]]], $goal), $this->clock);
        };

        // Retired with its row while credited; revived with no credit.
        $edit(2);
        $engine->reevaluate(1, $goal, null, true);
        self::assertNotNull($outcome()['superseded_at']);
        self::assertSame('1', (string) $row($conv)['deleted']);
        $apps->update(5, ['accept_test_signals' => 0]);
        $engine->reevaluate(1, $goal, 1, true);
        self::assertSame(['conversion_id' => null, 'superseded_at' => null, 'campaign_id' => null], $outcome(), 'revived off the click, it names no ledger row');
        self::assertSame('1', (string) $row($conv)['deleted']);
        // Credited again: it takes back its own row.
        $apps->update(5, ['accept_test_signals' => 1]);
        self::assertSame((string) $conv, (string) $outcome()['conversion_id']);
        self::assertSame('0', (string) $row($conv)['deleted']);

        // Retired with no row while uncredited; revived with credit.
        $apps->update(5, ['accept_test_signals' => 0]);
        self::assertNull($outcome()['conversion_id']);
        $engine->reevaluate(1, $goal, 2, true);
        self::assertNotNull($outcome()['superseded_at']);
        $apps->update(5, ['accept_test_signals' => 1]);
        $engine->reevaluate(1, $goal, 1, true);
        self::assertSame((string) $conv, (string) $outcome()['conversion_id'], 'revived on the click, it takes its row back');
        self::assertNull($outcome()['superseded_at']);
        self::assertSame('0', (string) $row($conv)['deleted']);
        self::assertSame(1, self::rows('202_conversion_logs', "click_id = 100 AND source = 'goal'"), 'never written twice');
    }

    public function testDeletingARegistrationSettlesItsPendingClicks(): void
    {
        self::assertSame('pending_click', $this->install(self::body(self::U1, 'p202=' . self::tokenFor(500)))['body']['data']['match']);
        (new \Api\V3\Controllers\AppRegistrationsController(self::$db, 1))->delete(5);
        $row = self::installRow(self::U1);
        self::assertSame(['bad_token', '0'], [$row['match_state'], (string) $row['trusted']]);
        self::assertNotNull($row['settled_at']);
        self::assertStringContainsString('registration was deleted', $row['match_reason']);
        self::assertNull($row['click_id']);

        // A registration gone some other way (deleted by hand): the settler
        // retires its pending clicks rather than skipping them for ever.
        $pending = $this->install(self::body(self::U2, 'p202=' . self::tokenFor(501), ['app_key' => 'com.other.app']), self::OTHER_TOKEN);
        self::assertSame('pending_click', $pending['body']['data']['match'], json_encode($pending));
        self::fixture('DELETE FROM 202_app_registrations WHERE registration_id = 6');
        $done = (new PendingClickSettler(self::$db, fn (): int => $this->clock))->run();
        self::assertSame(0, $done['examined'], 'an orphan never takes a slot in the batch');
        self::assertSame(['bad_token', '0'], [self::installRow(self::U2)['match_state'], (string) self::installRow(self::U2)['trusted']]);
        self::assertSame(0, self::rows('202_app_installs', "match_state = 'pending_click'"));
    }

    public function testAnInstallGoalMissingItsVersionIsRepairedBeforeItIsUsed(): void
    {
        // The goal row committed, its version did not (registration create
        // runs the two INSERTs in autocommit).
        $goal = $this->goals->ensureBuiltinInstallGoal(1, 5, 1);
        self::fixture('DELETE FROM 202_goal_versions WHERE goal_id = ' . $goal);
        $this->click(100);
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        self::assertSame(['attributed', 1], [$r['body']['data']['match'], $r['body']['data']['trusted']], json_encode($r));
        self::assertCount(1, self::ledger(100));
        self::assertSame(1, self::rows('202_goal_versions', 'goal_id = ' . $goal . ' AND version = 1'));
        self::assertSame($goal, $this->goals->ensureBuiltinInstallGoal(1, 5, 2), 'the same goal, repaired, not a second one');
    }

    public function testWhatAnInstallPaysIsTheCampaignsDecision(): void
    {
        // Listing another goal and not the install turns the install off.
        $this->click(100);
        $this->campaignGoal(30, ['name' => 'Level 3', 'trigger' => ['event' => 'level_reached'], 'value' => ['type' => 'fixed', 'amount' => 4]]);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        $ledger = self::ledger(100);
        self::assertSame(['0', 'install'], [(string) $ledger[0]['payable'], $ledger[0]['dedupe_key']], 'tracked on the click, not paid');
        self::assertSame(['lead' => 0, 'payout' => '2.50000'], self::clickValue(100));
        self::assertSame([], self::outbox(), 'an unpaid outcome notifies nobody');

        // Listing the install goal with a payout pays that.
        $installGoal = (int) self::$db->query("SELECT goal_id FROM 202_goals WHERE builtin = 'install'")->fetch_assoc()['goal_id'];
        $this->goals->attach(1, 30, $installGoal, \Prosper202\Conversion\Ledger\Amount::toUnits('3.00'), true, 1);
        $this->click(101);
        $this->install(self::body(self::U2, 'p202=' . self::tokenFor(101)));
        self::assertSame(['1', '3.00000'], [(string) self::ledger(101)[0]['payable'], self::ledger(101)[0]['click_payout']]);
        self::assertSame(['lead' => 1, 'payout' => '3.00000'], self::clickValue(101));
    }

    public function testEventsReachGoalsPayAndNotifyOnceWhateverTheOrder(): void
    {
        $this->click(100);
        $level3 = $this->campaignGoal(30, ['name' => 'Level 3', 'trigger' => ['event' => 'level_reached', 'where' => [['prop' => 'level', 'op' => 'gte', 'value' => 3]]],
            'value' => ['type' => 'fixed', 'amount' => 4]]);
        $second = $this->campaignGoal(30, ['name' => 'Second purchase', 'trigger' => ['event' => 'purchase'], 'threshold' => ['count' => 2],
            'value' => ['type' => 'from_property', 'prop' => '$revenue']]);
        // The campaign lists goals, so it lists the install goal too (at the
        // campaign's default payout) to keep paying for installs.
        $installGoal = $this->goals->ensureBuiltinInstallGoal(1, 5, 1);
        $this->goals->attach(1, 30, $installGoal, null, true, 1);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        self::assertSame(['1', '2.50000'], [(string) self::ledger(100)[0]['payable'], self::ledger(100)[0]['click_payout']]);

        // Before the test clock, so device time orders the events.
        $t = self::CLICK_TIME + 100;
        foreach ([1, 2, 3] as $level) {
            self::assertSame(200, $this->events(self::U1, [['event_id' => 'l' . $level, 'name' => 'level_reached', 'occurred_at' => $t + $level, 'properties' => ['level' => $level]]])['status']);
        }
        $replay = $this->events(self::U1, [['event_id' => 'l3', 'name' => 'level_reached', 'occurred_at' => $t + 3, 'properties' => ['level' => 3]]]);
        self::assertSame(['accepted' => [], 'duplicates' => ['l3']], array_intersect_key($replay['body']['data'], ['accepted' => 1, 'duplicates' => 1]));
        $goalRows = array_values(array_filter(self::ledger(100), static fn (array $r): bool => $r['source'] === 'goal'));
        self::assertCount(1, $goalRows);
        self::assertSame(['goal:' . $level3 . ':1', '4.00000', '1'], [$goalRows[0]['source_ref'], $goalRows[0]['click_payout'], (string) $goalRows[0]['payable']]);
        self::assertSame(['lead' => 1, 'payout' => '6.50000'], self::clickValue(100), 'install 2.50 + level 3 4.00, accumulated');
        $reached = array_values(array_filter(self::outbox(), static fn (array $r): bool => $r['kind'] === 'reached'));
        self::assertCount(2, $reached);
        self::assertStringContainsString('goal=Level%203&v=4.00', $reached[1]['url']);

        // Revenue from the device: stored, not paid, until the registration trusts it.
        $this->events(self::U1, [['event_id' => 'p1', 'name' => 'purchase', 'occurred_at' => $t + 10, 'revenue' => 5]]);
        $this->events(self::U1, [['event_id' => 'p2', 'name' => 'purchase', 'occurred_at' => $t + 20, 'revenue' => 10]]);
        $purchase = array_values(array_filter(self::ledger(100), static fn (array $r): bool => $r['source_ref'] === 'goal:' . $second . ':1'));
        self::assertSame(['0', '10.00000'], [(string) $purchase[0]['payable'], $purchase[0]['click_payout']], 'untrusted revenue is kept, not credited');

        // A late, earlier purchase moves "the second purchase": the old row is
        // superseded, the new one written, and the traffic source — told
        // nothing about the unpaid row — hears nothing now either.
        $this->events(self::U1, [['event_id' => 'p0', 'name' => 'purchase', 'occurred_at' => $t + 5, 'revenue' => 1]]);
        $purchase = array_values(array_filter(self::ledger(100), static fn (array $r): bool => $r['source_ref'] === 'goal:' . $second . ':1'));
        self::assertCount(2, $purchase);
        self::assertSame('replay', $purchase[0]['superseded_reason']);
        self::assertStringEndsWith(':p1', $purchase[1]['dedupe_key'], 'p0, p1: the second purchase is now p1');
        self::assertCount(2, array_filter(self::outbox(), static fn (array $r): bool => $r['kind'] === 'reached'));
    }

    public function testAReplacedOutcomeIsAnnouncedOnce(): void
    {
        self::fixture('UPDATE 202_app_registrations SET trust_client_revenue = 1 WHERE registration_id = 5');
        $this->click(100);
        $second = $this->campaignGoal(30, ['name' => 'Second purchase', 'trigger' => ['event' => 'purchase'], 'threshold' => ['count' => 2],
            'value' => ['type' => 'from_property', 'prop' => '$revenue']]);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        // Before the test clock, so device time orders the events.
        $t = self::CLICK_TIME + 100;
        $this->events(self::U1, [['event_id' => 'p1', 'name' => 'purchase', 'occurred_at' => $t + 10, 'revenue' => 5]]);
        $this->events(self::U1, [['event_id' => 'p2', 'name' => 'purchase', 'occurred_at' => $t + 20, 'revenue' => 10]]);
        $pending = array_values(array_filter(self::outbox(), static fn (array $r): bool => str_contains($r['url'], 'Second')));
        self::assertCount(1, $pending);
        self::assertSame('pending', $pending[0]['status']);

        // Still pending when the replay moves it: cancelled, and the
        // replacement's own reached goes out instead.
        $this->events(self::U1, [['event_id' => 'p0', 'name' => 'purchase', 'occurred_at' => $t + 5, 'revenue' => 1]]);
        $rows = array_values(array_filter(self::outbox(), static fn (array $r): bool => str_contains($r['url'], 'Second') || $r['kind'] !== 'reached'));
        self::assertSame(['cancelled', 'pending'], array_column($rows, 'status'));
        self::assertStringContainsString('v=5.00', $rows[1]['url'], 'the corrected value is the first the network hears');

        // Sent, then moved again: nothing new goes out; the correction is
        // recorded as suppressed (no correction URL).
        (new NotificationOutbox(new Connection(self::$db), fn (): int => $this->clock, fn (string $u): bool => true))->sendDue(10);
        $this->events(self::U1, [['event_id' => 'pm', 'name' => 'purchase', 'occurred_at' => $t + 1, 'revenue' => 2]]);
        $kinds = array_map(static fn (array $r): string => $r['kind'] . ':' . $r['status'], array_values(array_filter(
            self::outbox(),
            static fn (array $r): bool => str_contains($r['url'], 'Second') || $r['kind'] !== 'reached'
        )));
        self::assertSame(['reached:cancelled', 'reached:sent', 'reached:cancelled', 'correction:suppressed'], $kinds);
        self::assertSame('goal:' . $second . ':1', (string) self::$db->query("SELECT source_ref FROM 202_conversion_logs WHERE superseded_reason IS NULL AND source_ref LIKE 'goal:%' ORDER BY conv_id DESC LIMIT 1")->fetch_assoc()['source_ref']);

        // Moved once more: the row being replaced never sent its own reached
        // (its predecessor had), and its correction says so — so the network,
        // which heard the outcome two rows ago, still hears nothing new.
        $this->events(self::U1, [['event_id' => 'pz', 'name' => 'purchase', 'occurred_at' => $t, 'revenue' => 3]]);
        $kinds = array_map(static fn (array $r): string => $r['kind'] . ':' . $r['status'], array_values(array_filter(
            self::outbox(),
            static fn (array $r): bool => str_contains($r['url'], 'Second') || $r['kind'] !== 'reached'
        )));
        self::assertSame(['reached:cancelled', 'reached:sent', 'reached:cancelled', 'correction:suppressed', 'reached:cancelled', 'correction:suppressed'], $kinds);
        self::assertSame(['sent' => 0, 'failed' => 0, 'retrying' => 0], (new NotificationOutbox(new Connection(self::$db), fn (): int => $this->clock, fn (string $u): bool => true))->sendDue(10));
    }
}
