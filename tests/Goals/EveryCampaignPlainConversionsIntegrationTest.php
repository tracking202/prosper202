<?php

declare(strict_types=1);

namespace Tests\Goals;

use Api\V3\Controllers\GoalsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalScope;
use Prosper202\Goals\WebEvents;

/**
 * A campaign that never chose goals keeps recording `event=` hits as plain
 * conversions when an account goal counts on every campaign, except for the
 * events that goal takes; a campaign that chose goals evaluates every event.
 * Through the postback entry point (p202RecordWebEvent), which answers
 * `no_goals` when the hit is left to the plain conversion.
 *
 * @group integration
 */
final class EveryCampaignPlainConversionsIntegrationTest extends TestCase
{
    use GoalDatabase {
        setUp as private goalSetUp;
    }

    private const T = 1_650_000_000;

    protected function setUp(): void
    {
        $this->goalSetUp();
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
        foreach (['202_clicks_rotator', '202_click_latest_visits'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $this->clock = time();
        foreach ([314, 315] as $campaign) {
            $this->campaign($campaign);
        }
        $this->click(2001, 314);
        $this->click(2002, 315);
        foreach ([2001, 2002] as $click) {
            LatestVisit::refresh($this->conn, $click);
        }
    }

    /** @return array<string, mixed>|null */
    private static function postback(int $click, string $name, string $eventId, array $extra = []): ?array
    {
        return p202RecordWebEvent(
            self::$db,
            $click,
            ['event' => $name, 'event_id' => $eventId] + $extra,
            \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID,
            ['browser' => false]
        );
    }

    private function accountGoalOnEveryCampaign(string $event = 'purchase'): int
    {
        $id = $this->goals->create(1, GoalScope::ACCOUNT, 0,
            GoalDefinition::parse(['name' => 'Purchase', 'trigger' => ['event' => $event], 'value' => ['type' => 'fixed', 'amount' => '10']]), $this->clock);
        (new GoalsController(self::$db, 1))->setEveryCampaign($id, []);

        return $id;
    }

    public function testAGoalLessCampaignKeepsItsPlainConversionForAnEventNoGoalTakes(): void
    {
        $this->accountGoalOnEveryCampaign();

        self::assertSame('no_goals', self::postback(2001, 'lead', 'l1')['status'] ?? null);
    }

    public function testAnEventTheEveryCampaignGoalTakesIsEvaluatedOnAGoalLessCampaign(): void
    {
        $this->accountGoalOnEveryCampaign();

        self::assertSame('recorded', self::postback(2001, 'purchase', 'p1')['status'] ?? null);
    }

    public function testAnEventTheEveryCampaignGoalsPredicateRefusesStaysAPlainConversion(): void
    {
        $id = $this->goals->create(1, GoalScope::ACCOUNT, 0,
            GoalDefinition::parse(['name' => 'Big purchase', 'trigger' => ['event' => 'purchase', 'where' => [['prop' => 'plan', 'op' => 'eq', 'value' => 'pro']]],
                'value' => ['type' => 'fixed', 'amount' => '10']]), $this->clock);
        (new GoalsController(self::$db, 1))->setEveryCampaign($id, []);

        $props = static fn (string $plan): array => ['event_props' => (string) json_encode(['plan' => $plan])];
        self::assertSame('no_goals', self::postback(2001, 'purchase', 'p1', $props('free'))['status'] ?? null, 'the predicate refuses it');
        self::assertSame('no_goals', self::postback(2001, 'purchase', 'p2')['status'] ?? null, 'no property at all');
        self::assertSame('recorded', self::postback(2001, 'purchase', 'p3', $props('pro'))['status'] ?? null, 'the predicate holds');
    }

    public function testATakenEventThatReachesNoOutcomeStaysAPlainConversion(): void
    {
        $api = new GoalsController(self::$db, 1);
        $count = $this->goals->create(1, GoalScope::ACCOUNT, 0,
            GoalDefinition::parse(['name' => 'Third purchase', 'trigger' => ['event' => 'purchase'], 'threshold' => ['count' => 3],
                'value' => ['type' => 'fixed', 'amount' => '10']]), $this->clock);
        $api->setEveryCampaign($count, []);

        self::assertSame('no_goals', self::postback(2001, 'purchase', 'c1')['status'] ?? null, 'hit 1 of 3');
        self::assertSame('no_goals', self::postback(2001, 'purchase', 'c2')['status'] ?? null, 'hit 2 of 3');
        self::assertSame('recorded', self::postback(2001, 'purchase', 'c3')['status'] ?? null, 'hit 3 reaches the goal');
        self::assertCount(1, $this->liveOutcomes(2001));
        self::assertSame('recorded', self::postback(2001, 'purchase', 'c3')['status'] ?? null, 'a retry of an event already taken is not a second conversion');
    }

    public function testAnUnmetPrerequisiteAndAnExpiredWindowLeaveThePlainConversion(): void
    {
        $api = new GoalsController(self::$db, 1);
        $lead = $this->goals->create(1, GoalScope::ACCOUNT, 0,
            GoalDefinition::parse(['name' => 'Lead', 'trigger' => ['event' => 'lead']]), $this->clock);
        $purchase = $this->goals->create(1, GoalScope::ACCOUNT, 0,
            GoalDefinition::parse(['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'after' => [$lead], 'value' => ['type' => 'fixed', 'amount' => '10']]), $this->clock);
        $api->setEveryCampaign($purchase, []);
        $fast = $this->goals->create(1, GoalScope::ACCOUNT, 0,
            GoalDefinition::parse(['name' => 'Fast signup', 'trigger' => ['event' => 'signup'], 'within' => ['days' => 1, 'from' => 'click'],
                'value' => ['type' => 'fixed', 'amount' => '1']]), $this->clock);
        $api->setEveryCampaign($fast, []);

        self::assertSame('no_goals', self::postback(2001, 'purchase', 'u1')['status'] ?? null, 'no lead yet');
        self::assertSame('no_goals', self::postback(2001, 'signup', 'u2')['status'] ?? null, 'the click is older than the window');
        self::assertSame('recorded', self::postback(2001, 'lead', 'u3')['status'] ?? null, 'the lead is a goal of its own');
        self::assertSame('recorded', self::postback(2001, 'purchase', 'u4')['status'] ?? null, 'now the purchase reaches its goal');
    }

    public function testASignedPurLinkEventThatReachesNoOutcomeKeepsItsPlainConversionToo(): void
    {
        $count = $this->goals->create(1, GoalScope::ACCOUNT, 0,
            GoalDefinition::parse(['name' => 'Third purchase', 'trigger' => ['event' => 'purchase'], 'threshold' => ['count' => 2],
                'value' => ['type' => 'fixed', 'amount' => '10']]), $this->clock);
        $signup = $this->goals->create(1, GoalScope::ACCOUNT, 0,
            GoalDefinition::parse(['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => '1']]), $this->clock);
        (new GoalsController(self::$db, 1))->setEveryCampaign($count, []);
        (new GoalsController(self::$db, 1))->setEveryCampaign($signup, []);
        self::fixture('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11101 WHERE aff_campaign_id = 314');
        self::$db->query('INSERT INTO 202_landing_pages SET user_id = 1, aff_campaign_id = 0, landing_page_type = 1,'
            . " landing_page_nickname = 'advanced', landing_page_url = 'https://example.test/', landing_page_time = 1");
        $page = (int) self::$db->insert_id;
        self::assertGreaterThan(0, $page);
        self::fixture("UPDATE 202_clicks SET landing_page_id = $page WHERE click_id = 2001");
        \Prosper202\Click\RecordedClicks::note(2001);
        $key = str_repeat('x', 32);
        $now = time();
        $proof = \Prosper202\Click\PurLinkProof::issue(\Prosper202\Click\PurLinkProof::EXIT, 2001, $page, 1, $now, 60, $key);
        $subid = (new \Prosper202\Click\PurLinkExit($this->conn))->subid($proof, '11101', $now, $key);
        self::assertIsString($subid);

        $hit = static fn (string $id): ?array => p202RecordPurLinkWebEvent(self::$db, $subid, $key, ['event' => 'purchase', 'event_id' => $id]);
        self::assertSame('no_goals', $hit('s1')['status'] ?? null, 'a taken event that reaches no outcome');
        self::assertSame('1', (string) self::$db->query('SELECT COUNT(*) FROM 202_goal_events')->fetch_row()[0], 'its progress is kept');
        self::assertSame('recorded', $hit('s3')['status'] ?? null, 'the second purchase reaches the count of 2');
        self::assertSame('recorded', p202RecordPurLinkWebEvent(self::$db, $subid, $key, ['event' => 'signup', 'event_id' => 's2'])['status'] ?? null, 'one that does');
    }

    public function testAnEventAPrerequisiteOfTheEveryCampaignGoalTakesIsEvaluated(): void
    {
        $lead = $this->goals->create(1, GoalScope::ACCOUNT, 0,
            GoalDefinition::parse(['name' => 'Lead', 'trigger' => ['event' => 'lead']]), $this->clock);
        $purchase = $this->goals->create(1, GoalScope::ACCOUNT, 0,
            GoalDefinition::parse(['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'after' => [$lead]]), $this->clock);
        (new GoalsController(self::$db, 1))->setEveryCampaign($purchase, []);

        self::assertSame('recorded', self::postback(2001, 'lead', 'l1')['status'] ?? null);
    }

    public function testANameNoGoalTakesIsLeftToThePlainConversionEvenWhenItIsInvalid(): void
    {
        $this->accountGoalOnEveryCampaign();

        self::assertSame('no_goals', self::postback(2001, 'not valid!', 'x1')['status'] ?? null);
    }

    public function testACampaignThatChoseGoalsEvaluatesEveryEventAsItDidBefore(): void
    {
        $this->accountGoalOnEveryCampaign();
        $this->goal(315, ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => '2']]);

        self::assertSame('recorded', self::postback(2002, 'lead', 'l2')['status'] ?? null, 'its own goal: an event no goal takes is not a plain conversion');
        self::assertSame([], $this->ledger(2002));
    }

    public function testACampaignWithATrackedShareOnlyKeepsItsPlainConversions(): void
    {
        $goal = $this->goals->create(1, GoalScope::ACCOUNT, 0,
            GoalDefinition::parse(['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'value' => ['type' => 'fixed', 'amount' => '10']]), $this->clock);
        (new GoalsController(self::$db, 1))->attachCampaign($goal, 314, ['payable' => false]);

        self::assertSame('no_goals', self::postback(2001, 'lead', 'l3')['status'] ?? null);
        self::assertSame('recorded', self::postback(2001, 'purchase', 'p3')['status'] ?? null);
    }

    public function testACampaignMadeAfterEveryCampaignWasTurnedOnKeepsItsPlainConversions(): void
    {
        $this->accountGoalOnEveryCampaign();
        $this->campaign(316);
        $this->click(2003, 316);
        LatestVisit::refresh($this->conn, 2003);

        self::assertSame('no_goals', self::postback(2003, 'lead', 'l4')['status'] ?? null);
    }

    public function testTheEventNameIsTheOneThePostbackCarries(): void
    {
        self::assertNull(WebEvents::nameFrom(['event' => 'not valid!']));
        self::assertSame('lead', WebEvents::nameFrom(['event' => 'lead']));
    }
}
