<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Controllers\AppInstallsController;
use Api\V3\Controllers\AppRegistrationsController;
use Api\V3\Controllers\GoalsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;

/**
 * An install on a re-clicked click, on a real server: click 120's first
 * visit is on campaign 34, linked to another of the account's apps
 * (registration 7), through traffic source 70; its latest, the re-click
 * (stored second and recorded as latest, as the writer stores it, with the
 * link's source 71), is on campaign 30, linked to this app. The install is
 * attributed to the latest visit, paid at its campaign's terms, timed from
 * it, announced through the first visit's traffic source, and its campaign
 * goals are the latest visit's campaign's.
 *
 * @group integration
 */
final class ReClickedInstallIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as private androidSetUp;
    }

    private const U1 = '00000000-0000-4000-8000-0000000000c1';
    private const U2 = '00000000-0000-4000-8000-0000000000c2';
    private const FIRST = self::CLICK_TIME - 600;

    protected function setUp(): void
    {
        $this->androidSetUp();
        self::$db->query('TRUNCATE TABLE 202_clicks_rotator');
        self::fixture("INSERT INTO 202_app_registrations SET registration_id=7, user_id=1, platform='android',
            app_key='com.example.seven', app_name='Seven', accept_test_signals=0, attribution_window_days=7,
            trust_client_revenue=0, app_token='" . str_repeat('7', 64) . "', created_at=1, updated_at=1");
        $this->campaign(34, 7, 'accumulate', '9.00');
        self::fixture("REPLACE INTO 202_ppc_accounts SET ppc_account_id=71, user_id=1, ppc_network_id=1,
            ppc_account_name='link', ppc_account_time=1");
        self::fixture("INSERT INTO 202_ppc_account_pixels SET pixel_id=93, ppc_account_id=71, pixel_type_id=4,
            pixel_code='https://link.example/pb?sub=[[subid]]'");
        $visits = [[34, 70, self::FIRST, 0, 0], [30, 71, self::CLICK_TIME, 5, 9]];
        foreach ($visits as [$campaign, $ppc, $time, $rotator, $redirect]) {
            $columns = "click_id=120, user_id=1, aff_campaign_id=$campaign, ppc_account_id=$ppc, click_payout=0,"
                . " click_cpc=0, click_lead=0, click_time=$time";
            self::fixture("INSERT INTO 202_clicks SET $columns, rotator_id=$rotator, rule_id=$redirect");
            self::fixture("INSERT INTO 202_clicks_spy SET $columns");
        }
        self::fixture('INSERT INTO 202_clicks_rotator SET click_id=120, rotator_id=5, rule_id=3, rule_redirect_id=9');
        LatestVisit::refresh($this->conn, 120);
    }

    /** @return array<string, mixed> */
    private static function signup(string $name, string $amount): array
    {
        return ['name' => $name, 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => $amount]];
    }

    public function testTheTokenIsMintedForTheAppTheLatestVisitsCampaignLinks(): void
    {
        try {
            $token = (new AppInstallsController(self::$db, 1))->installToken(5, ['click_id' => '120']);
        } catch (ValidationException $e) {
            self::fail('refused: ' . (string) json_encode($e->getFieldErrors()));
        }

        self::assertSame(120, $token['data']['click_id']);
    }

    public function testTheInstallIsTheLatestVisitsAndTheFirstVisitsSourceIsTold(): void
    {
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(120)));

        self::assertSame('attributed', $r['body']['data']['match'] ?? null, (string) json_encode($r));
        $row = self::installRow(self::U1);
        self::assertNotNull($row);
        self::assertSame('61', (string) $row['ctit_seconds'], 'timed from the latest visit');
        $ledger = self::$db->query('SELECT campaign_id, click_payout, click_time FROM 202_conversion_logs'
            . ' WHERE click_id = 120')->fetch_all(MYSQLI_ASSOC);
        self::assertSame(
            [['campaign_id' => '30', 'click_payout' => '2.50000', 'click_time' => (string) self::CLICK_TIME]],
            $ledger,
            'at the latest visit\'s campaign default'
        );
        self::assertSame(
            ['90'],
            array_map(static fn (array $o): string => (string) $o['pixel_id'], self::outbox()),
            'the first visit\'s traffic source'
        );
    }

    public function testTheInstallsEventsAreEvaluatedUnderTheLatestVisitsCampaign(): void
    {
        $signup = static fn (string $name, string $amount): array => [
            'name' => $name, 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => $amount],
        ];
        $this->campaignGoal(30, $signup('Signup here', '1.25'));
        $this->campaignGoal(34, $signup('Signup there', '6.00'));
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(120)));

        $r = $this->events(
            self::U1,
            [['event_id' => 's1', 'name' => 'signup', 'occurred_at' => self::CLICK_TIME + 900]]
        );

        self::assertSame(200, $r['status'], (string) json_encode($r));
        $paid = self::$db->query('SELECT campaign_id, click_payout FROM 202_conversion_logs'
            . " WHERE click_id = 120 AND source = 'goal'")->fetch_all(MYSQLI_ASSOC);
        self::assertSame([['campaign_id' => '30', 'click_payout' => '1.25000']], $paid);
    }

    /**
     * A test install's credit withdrawn (accept_test_signals turned off):
     * the goals it reached only through its click's campaign are the latest
     * visit's campaign's (GoalEngine::clickCampaignGoals()), so those are
     * the outcomes retired with their rows.
     */
    public function testWithdrawnCreditRetiresTheGoalsOfTheLatestVisitsCampaign(): void
    {
        self::fixture('UPDATE 202_app_registrations SET accept_test_signals = 1 WHERE registration_id = 5');
        $here = $this->campaignGoal(30, self::signup('Signup here', '1.25'));
        $there = $this->campaignGoal(34, self::signup('Signup there', '6.00'));
        $r = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(120), ['test' => true]));
        self::assertSame(['attributed', 1], [$r['body']['data']['match'] ?? null, $r['body']['data']['trusted'] ?? null], (string) json_encode($r));
        $e = $this->events(self::U1, [['event_id' => 's1', 'name' => 'signup', 'occurred_at' => self::CLICK_TIME + 900]]);
        self::assertSame(200, $e['status'], (string) json_encode($e));
        $live = static fn (int $goal): array => self::$db->query('SELECT o.campaign_id, l.deleted FROM 202_goal_outcomes o'
            . ' LEFT JOIN 202_conversion_logs l ON l.conv_id = o.conversion_id'
            . ' WHERE o.goal_id = ' . $goal . ' AND o.superseded_at IS NULL')->fetch_all(MYSQLI_ASSOC);
        self::assertSame([['campaign_id' => '30', 'deleted' => '0']], $live($here), 'credited under the latest visit\'s campaign');
        self::assertSame([], $live($there));

        (new AppRegistrationsController(self::$db, 1))->update(5, ['accept_test_signals' => 0]);

        self::assertNull(self::installRow(self::U1)['trusted']);
        self::assertSame([], $live($here), 'the latest visit\'s campaign goal is retired');
        $goalRows = self::$db->query("SELECT campaign_id, deleted FROM 202_conversion_logs WHERE click_id = 120 AND source = 'goal'")
            ->fetch_all(MYSQLI_ASSOC);
        self::assertSame([['campaign_id' => '30', 'deleted' => '1']], $goalRows, 'with its row');
    }

    /**
     * A re-evaluation of a campaign goal over installs selects an install by
     * its click's latest visit: install A (click 120) is campaign 30's, not
     * its first visit's 34; install B, on registration 7 through click 121's
     * one visit on 34, is 34's.
     */
    public function testAReevaluationSelectsAnInstallByItsClicksLatestVisitsCampaign(): void
    {
        $here = $this->campaignGoal(30, self::signup('Signup here', '1.25'));
        $there = $this->campaignGoal(34, self::signup('Signup there', '6.00'));
        $a = $this->install(self::body(self::U1, 'p202=' . self::tokenFor(120)));
        $this->click(121, 34);
        $b = $this->install(
            self::body(self::U2, 'p202=' . self::tokenFor(121), ['app_key' => 'com.example.seven']),
            str_repeat('7', 64)
        );
        foreach ([$a, $b] as $r) {
            self::assertSame('attributed', $r['body']['data']['match'] ?? null, (string) json_encode($r));
        }
        $rowA = (int) self::installRow(self::U1)['install_row_id'];
        $rowB = (int) self::installRow(self::U2)['install_row_id'];
        self::assertSame(2, self::rows('202_goal_subjects', "subject_type = 'install' AND subject_id IN ($rowA, $rowB)"));
        $api = new GoalsController(self::$db, 1);
        $selected = static function (int $goal) use ($api): array {
            $plan = $api->reevaluationPreview($goal, ['subject_type' => 'install'])['data'];

            return [array_column($plan['subjects'], 'subject_id'), $plan['totals']['subjects']];
        };

        self::assertSame([[$rowB], 1], $selected($there), 'install A\'s click is campaign 30\'s now');
        self::assertSame([[$rowA], 1], $selected($here));
    }
}
