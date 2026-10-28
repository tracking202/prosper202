<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Goals\GoalEvent;
use Prosper202\Goals\WebEvents;
use Prosper202\Identity\IdentityGraph;
use Prosper202\Identity\IdentityKeys;
use Prosper202\Identity\IdentitySignal;
use Prosper202\Identity\SignalType;

/**
 * A redirector re-click on a real server, from the goals' side: click 600's
 * first visit is on campaign 11, its latest (the re-click, stored second, as
 * the writer stores it) on campaign 12. The click's goals, payouts, event
 * anchor and the page that names it are its latest visit's.
 *
 * @group integration
 */
final class ReClickedClickGoalsIntegrationTest extends TestCase
{
    use GoalDatabase {
        setUp as private goalSetUp;
    }

    private const FIRST = 1_700_000_000;
    private const LATEST = 1_700_000_600;

    protected function setUp(): void
    {
        $this->goalSetUp();
        $tables = ['202_clicks_rotator', '202_landing_pages', '202_identity_observations', '202_identity_signals',
            '202_identity_keys', '202_click_latest_visits'];
        foreach ($tables as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $this->clock = self::LATEST + 100;
        $this->campaign(11, 'replace', '3.00');
        $this->campaign(12, 'replace', '7.00');
        $this->campaign(13, 'replace', '9.00');
    }

    /**
     * A click's visits, in the order given, each at its own time; the last on
     * a route, and the latest recorded, as a re-click is.
     */
    private function visits(int $clickId, array $campaignsAt): void
    {
        $last = array_key_last($campaignsAt);
        foreach ($campaignsAt as $time => $campaign) {
            $route = $time === $last ? ', rotator_id=5, rule_id=9' : '';
            $columns = "click_id=$clickId, user_id=1, aff_campaign_id=$campaign, click_payout=0, click_cpc=0,"
                . " click_lead=0, click_time=$time";
            self::fixture("INSERT INTO 202_clicks SET $columns" . $route);
            self::fixture("INSERT INTO 202_clicks_spy SET $columns");
        }
        self::fixture("INSERT INTO 202_clicks_rotator SET click_id=$clickId, rotator_id=5, rule_id=3,"
            . ' rule_redirect_id=9');
        if (count($campaignsAt) > 1) {
            LatestVisit::refresh($this->conn, $clickId);
        }
    }

    private function reClicked(int $clickId = 600): void
    {
        $this->visits($clickId, [self::FIRST => 11, self::LATEST => 12]);
    }

    public function testTheClickSubjectIsItsLatestVisit(): void
    {
        $this->reClicked();

        $subject = $this->engine->clickSubject(1, 600);
        self::assertSame([12, self::LATEST], [$subject->campaignId, $subject->clickAt]);
        self::assertSame(
            ['user_id' => 1, 'campaign_id' => 12, 'click_time' => self::LATEST],
            (new WebEvents($this->conn))->click(600),
            'a pixel\'s event is the latest visit\'s'
        );
    }

    public function testAnEventIsEvaluatedAndPaidUnderTheLatestVisitsCampaign(): void
    {
        $this->reClicked();
        $sale = static fn (string $name, string $amount): array => [
            'name' => $name, 'trigger' => ['event' => 'sale'], 'value' => ['type' => 'fixed', 'amount' => $amount],
        ];
        $this->goal(11, $sale('Sale on the first', '1.00'));
        $this->goal(12, $sale('Sale on the latest', '4.00'));

        $this->ingest(600, [$this->event('s1', 'sale', self::LATEST + 10)]);

        $rows = self::$db->query('SELECT campaign_id, click_payout, click_time FROM 202_conversion_logs'
            . ' WHERE click_id = 600 AND deleted = 0')->fetch_all(MYSQLI_ASSOC);
        self::assertSame(
            [['campaign_id' => '12', 'click_payout' => '4.00000', 'click_time' => (string) self::LATEST]],
            $rows
        );
    }

    /**
     * A subject read before a re-click committed: the engine reads it again
     * from the click's lock, so the outcome, its goal and payout, and the
     * ledger row it writes all take the visit the ledger credits.
     */
    public function testAnEventOnASubjectReadBeforeAReClickTakesTheReClicksVisit(): void
    {
        $this->visits(600, [self::FIRST => 11]);
        $sale = static fn (string $name, string $amount): array => [
            'name' => $name, 'trigger' => ['event' => 'sale'], 'value' => ['type' => 'fixed', 'amount' => $amount],
        ];
        $this->goal(11, $sale('Sale on the first', '1.00'));
        $this->goal(12, $sale('Sale on the latest', '4.00'));
        $subject = $this->engine->clickSubject(1, 600);
        self::assertSame(11, $subject->campaignId);

        self::fixture('DELETE FROM 202_clicks_rotator WHERE click_id = 600');
        self::fixture('INSERT INTO 202_clicks SET click_id=600, user_id=1, aff_campaign_id=12, click_payout=0,'
            . ' click_cpc=0, click_lead=0, click_time=' . self::LATEST . ', rotator_id=5, rule_id=9');
        self::fixture('INSERT INTO 202_clicks_rotator SET click_id=600, rotator_id=5, rule_id=3, rule_redirect_id=9');
        LatestVisit::refresh($this->conn, 600);
        $this->clock += 10;
        $e = $this->event('s1', 'sale', self::LATEST + 10);
        $stamped = new GoalEvent(
            $e->eventId,
            $e->name,
            $e->occurredAt,
            $this->clock,
            $e->properties,
            $e->revenue,
            $e->revenueTrusted,
            $e->transactionId
        );
        $this->engine->ingest(1, $subject, [$stamped]);

        $outcomes = self::$db->query('SELECT campaign_id, value FROM 202_goal_outcomes'
            . " WHERE subject_type = 'click' AND subject_id = 600")->fetch_all(MYSQLI_ASSOC);
        self::assertSame([['campaign_id' => '12', 'value' => '4.00000']], $outcomes);
        $rows = self::$db->query('SELECT campaign_id, click_payout, click_time FROM 202_conversion_logs'
            . ' WHERE click_id = 600 AND deleted = 0')->fetch_all(MYSQLI_ASSOC);
        self::assertSame(
            [['campaign_id' => '12', 'click_payout' => '4.00000', 'click_time' => (string) self::LATEST]],
            $rows
        );
    }

    /**
     * The re-clicked click 600 (campaign 11, then 12) and click 601, made
     * before it on campaign 11, both observed carrying one visitor id; a
     * page of each campaign. Returns the visitor id.
     */
    private function visitorOfTwoClicks(): string
    {
        $this->reClicked();
        $this->visits(601, [self::FIRST - 100 => 11]);
        foreach ([111 => 11, 122 => 12] as $page => $campaign) {
            self::fixture("INSERT INTO 202_landing_pages SET landing_page_id=$page, user_id=1,"
                . " landing_page_id_public=$page, aff_campaign_id=$campaign, landing_page_nickname='lp',"
                . " landing_page_url='http://lp', landing_page_deleted=0, landing_page_time=1, landing_page_type=0");
        }
        $lpid = str_repeat('ab', 16);
        $hash = IdentityGraph::hash(
            (new IdentityKeys($this->conn))->forUser(1)['hash'],
            new IdentitySignal(SignalType::LANDING_PAGE, $lpid)
        );
        foreach ([600, 601] as $c) {
            self::fixture("INSERT INTO 202_identity_observations SET click_id=$c, signal_type='lpid',"
                . " signal_hash='$hash', observed_at=1");
        }

        return $lpid;
    }

    /**
     * Deployed before the 1.9.79 upgrade has made 202_click_latest_visits,
     * p202.track()'s lookup failed on the missing table and the event was
     * lost (a 500 from event.php). It reads every visit as latest then, as
     * isLatest() reads a click with no record, and says so in the log.
     */
    public function testAVisitorIdNamesAClickBeforeTheUpgradeHasMadeTheRecordTable(): void
    {
        $lpid = $this->visitorOfTwoClicks();
        $events = new WebEvents($this->conn);
        $log = tempnam(sys_get_temp_dir(), 'p202-web-event-log');
        self::assertIsString($log);
        $was = ini_set('error_log', $log);
        self::$db->query('RENAME TABLE 202_click_latest_visits TO 202_click_latest_visits_away');
        try {
            $first = $events->clickForVisitor('111', $lpid, $this->clock);
            $latest = $events->clickForVisitor('122', $lpid, $this->clock);
        } finally {
            self::$db->query('RENAME TABLE 202_click_latest_visits_away TO 202_click_latest_visits');
            ini_set('error_log', (string) $was);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertSame(['user_id' => 1, 'click_id' => 600, 'campaign_id' => 11], $first, 'its first visit counts too');
        self::assertSame(['user_id' => 1, 'click_id' => 600, 'campaign_id' => 12], $latest);
        self::assertStringContainsString('until the 1.9.79 upgrade has run', $logged);
    }

    public function testAVisitorIdNamesAClickByItsLatestVisitsCampaign(): void
    {
        $lpid = $this->visitorOfTwoClicks();
        $events = new WebEvents($this->conn);

        self::assertSame(
            ['user_id' => 1, 'click_id' => 601, 'campaign_id' => 11],
            $events->clickForVisitor('111', $lpid, $this->clock),
            'the re-clicked click is campaign 12\'s now, so the first campaign\'s page names its own click'
        );
        self::assertSame(
            ['user_id' => 1, 'click_id' => 600, 'campaign_id' => 12],
            $events->clickForVisitor('122', $lpid, $this->clock)
        );
    }

    public function testAReevaluationSelectsEachClickOnceByItsLatestVisitsCampaign(): void
    {
        $this->reClicked();
        // Its first visit on campaign 12, its latest on 13: not a click of the goal's campaigns.
        $this->visits(602, [self::FIRST => 12, self::LATEST => 13]);
        $goal = $this->goal(
            12,
            ['name' => 'Lead', 'trigger' => ['event' => 'lead'], 'value' => ['type' => 'fixed', 'amount' => '2.00']]
        );
        $this->goals->attach(1, 11, $goal, null, true, $this->clock);
        foreach ([600, 602] as $click) {
            $this->ingest($click, [$this->event('e' . $click, 'lead', self::LATEST + 10)]);
        }

        $plan = $this->engine->reevaluate(1, $goal, null, false);

        self::assertSame([600], array_column($plan['subjects'], 'subject_id'), (string) json_encode($plan['subjects']));
    }
}
