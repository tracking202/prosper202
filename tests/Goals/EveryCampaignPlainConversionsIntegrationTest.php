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
    private static function postback(int $click, string $name, string $eventId): ?array
    {
        return p202RecordWebEvent(
            self::$db,
            $click,
            ['event' => $name, 'event_id' => $eventId],
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
