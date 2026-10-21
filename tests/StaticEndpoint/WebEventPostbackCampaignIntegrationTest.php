<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;

/**
 * pb.php's campaign check for a postback that carries an event
 * (p202RecordWebEvent()'s `campaign_id`), on a real server: as for a plain
 * conversion (LegacyPostbackCampaignIntegrationTest), the click converts
 * for a campaign one of its visits was on, a campaign of the click's own
 * account, and the event is evaluated, and its conversion credited, by the
 * latest visit's campaign.
 *
 * The visitor bought offer A (campaign 11), a redirector re-click sent them
 * to offer B (campaign 12, whose goal pays for `purchase`), and A's network
 * posts back with event=purchase: it was refused, as the check read the
 * latest visit only.
 *
 * @group integration
 */
final class WebEventPostbackCampaignIntegrationTest extends TestCase
{
    use \Tests\Goals\GoalDatabase {
        setUp as private goalSetUp;
    }

    private const CLICK = 100;
    private const FIRST = 1_690_000_000;
    private const LATEST = self::FIRST + 3600;
    private const THEIRS = 14;

    protected function setUp(): void
    {
        $this->goalSetUp();
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
        $tables = ['202_clicks_rotator', '202_click_latest_visits', '202_identity_observations',
            '202_identity_signals'];
        foreach ($tables as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        foreach ([11, 12, 13] as $campaign) {
            $this->campaign($campaign);
        }
        self::fixture('INSERT INTO 202_aff_campaigns SET aff_campaign_id=' . self::THEIRS . ', user_id=2,'
            . " aff_network_id=1,"
            . " aff_campaign_name='theirs', aff_campaign_url='http://x', aff_campaign_payout=0, aff_campaign_time=1,"
            . " aff_campaign_foreign_payout=0, payout_mode='accumulate'");
        $this->goal(12, ['name' => 'Purchase', 'trigger' => ['event' => 'purchase'],
            'value' => ['type' => 'fixed', 'amount' => '2']]);
    }

    /** The click's first visit on $first and its re-click on $latest, as the writer stores them. */
    private function reClicked(int $first, int $latest): void
    {
        foreach ([[$first, self::FIRST, 0], [$latest, self::LATEST, 5]] as [$campaign, $time, $rotator]) {
            $columns = 'click_id=' . self::CLICK . ", user_id=1, aff_campaign_id=$campaign,"
                . ' click_payout=0, click_cpc=0,'
                . " click_lead=0, click_time=$time";
            $route = $rotator > 0 ? 9 : 0;
            self::fixture("INSERT INTO 202_clicks SET $columns, rotator_id=$rotator, rule_id=$route");
            self::fixture("INSERT INTO 202_clicks_spy SET $columns");
        }
        self::fixture('INSERT INTO 202_clicks_rotator SET click_id=' . self::CLICK
            . ', rotator_id=5, rule_id=3, rule_redirect_id=9');
        LatestVisit::refresh($this->conn, self::CLICK);
    }

    /** @return array<string, mixed>|null */
    private static function postback(int $campaign, string $eventId): ?array
    {
        return p202RecordWebEvent(
            self::$db,
            self::CLICK,
            ['event' => 'purchase', 'event_id' => $eventId],
            ['campaign_id' => $campaign, 'browser' => false]
        );
    }

    /** @return list<string> the campaign of each conversion recorded on the click */
    private static function convertedCampaigns(): array
    {
        $rows = self::$db->query('SELECT campaign_id FROM 202_conversion_logs WHERE click_id = ' . self::CLICK
            . ' ORDER BY conv_id')->fetch_all(MYSQLI_NUM);

        return array_column($rows, 0);
    }

    public function testAnEventForAnEarlierVisitsCampaignIsEvaluatedByTheLatestVisitsGoals(): void
    {
        $this->reClicked(11, 12);

        $outcome = self::postback(11, 'bought-on-a');

        self::assertSame('recorded', $outcome['status'] ?? null, (string) json_encode($outcome));
        self::assertSame(['12'], self::convertedCampaigns(), 'the latest visit\'s campaign');
    }

    public function testAnEventForTheLatestVisitsCampaignIsRecorded(): void
    {
        $this->reClicked(11, 12);

        self::assertSame('recorded', self::postback(12, 'bought-on-b')['status'] ?? null);
        self::assertSame(['12'], self::convertedCampaigns());
    }

    public function testAnEventForACampaignNoVisitWasOnIsRefused(): void
    {
        $this->reClicked(11, 12);

        self::assertSame(['status' => 'not_found', 'reason' => 'campaign_mismatch'], self::postback(13, 'bought-on-c'));
        self::assertSame([], self::convertedCampaigns());
    }

    public function testAVisitNamingAnotherAccountsCampaignConvertsNothingForIt(): void
    {
        $this->reClicked(self::THEIRS, 12);

        self::assertSame(
            ['status' => 'not_found', 'reason' => 'campaign_mismatch'],
            self::postback(self::THEIRS, 'bought-on-theirs')
        );
        self::assertSame([], self::convertedCampaigns());
    }
}
