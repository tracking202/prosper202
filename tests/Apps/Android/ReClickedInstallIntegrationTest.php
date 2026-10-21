<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Controllers\AppInstallsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * An install on a re-clicked click, on a real server: click 120's first
 * visit is on campaign 34, linked to another of the account's apps
 * (registration 7), through traffic source 70; its latest, the re-click
 * (stored second, as the writer stores it, with the link's source 71), is on
 * campaign 30, linked to this app. The install is attributed to the latest
 * visit, paid at its campaign's terms, timed from it, and announced through
 * the first visit's traffic source.
 *
 * @group integration
 */
final class ReClickedInstallIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as private androidSetUp;
    }

    private const U1 = '00000000-0000-4000-8000-0000000000c1';
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
}
