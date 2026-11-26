<?php

declare(strict_types=1);

namespace Tests\Goals;

use Api\V3\Controllers\GoalsController;
use Api\V3\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalScope;

/**
 * One goal counting across campaigns: a funnel's goal defined once, on
 * campaign 316, and shared with 314 and 315 (paid on one, tracked on the
 * other), and an account goal counting on every campaign. Through the API's
 * writes and the engine's real ingest and ledger.
 *
 * @group integration
 */
final class SharedGoalsIntegrationTest extends TestCase
{
    use GoalDatabase;

    private const T = 1_650_000_000;

    private function api(): GoalsController
    {
        return new GoalsController(self::$db, 1);
    }

    /** @return array{payable: string, click_payout: string}|null the click's one ledger row */
    private function row(int $clickId): ?array
    {
        $rows = $this->ledger($clickId);
        self::assertLessThanOrEqual(1, count($rows));

        return $rows === [] ? null : ['payable' => (string) $rows[0]['payable'], 'click_payout' => (string) $rows[0]['click_payout']];
    }

    public function testAFunnelGoalDefinedOnceFiresOnAClickFromEachCampaignItIsSharedWith(): void
    {
        // The API writes at the wall clock; events arrive after it.
        $this->clock = time();
        foreach ([314, 315, 316, 317] as $c) {
            $this->campaign($c);
        }
        // The funnel's goal, defined on 316 and paid there at its value.
        $signup = $this->api()->create(['scope' => 'campaign', 'scope_id' => 316,
            'definition' => ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => '2.00']]])['data']['goal_id'];
        $this->api()->attachCampaign($signup, 314, ['payable' => false]);
        $this->api()->attachCampaign($signup, 315, ['payout' => '4.00']);

        $clicks = [314 => 1001, 315 => 1002, 316 => 1003, 317 => 1004];
        foreach ($clicks as $campaign => $click) {
            $this->click($click, $campaign);
            $this->ingest($click, [$this->event('s-' . $click, 'signup', self::T + 1)]);
        }

        self::assertSame([$signup . ':1:1@s-1001=2.00000 unpaid'], $this->liveOutcomes(1001), 'ChatGPT Ads (314): reached, tracked');
        self::assertSame(['payable' => '0', 'click_payout' => '2.00000'], $this->row(1001));
        self::assertSame(0, $this->clickState(1001)['lead']);
        self::assertSame(['lead' => 1, 'payout' => '4.00000'], $this->clickState(1002), '315 pays its own payout');
        self::assertSame(['lead' => 1, 'payout' => '2.00000'], $this->clickState(1003), '316, the owner, pays the goal\'s value');
        self::assertSame([], $this->liveOutcomes(1004), 'a campaign it is not shared with does not count it');
        self::assertNull($this->row(1004));

        // The shares read back from both sides.
        $terms = array_column($this->api()->campaigns($signup)['data'], 'payable', 'campaign_id');
        ksort($terms);
        self::assertSame([314 => false, 315 => true, 316 => true], $terms, "316 pays for its own goal by the row create wrote");
    }

    public function testATrackedShareIsNoTermAndSwitchingKeepsTheTimeItStartedCounting(): void
    {
        $this->clock = time();
        $this->campaign(314);
        $this->campaign(316);
        $signup = $this->api()->create(['scope' => 'campaign', 'scope_id' => 316,
            'definition' => ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 2]]])['data']['goal_id'];
        $started = $this->api()->attachCampaign($signup, 314, ['payable' => false])['data']['created_at'];

        self::assertSame([], $this->goals->campaignTerms(314, 1), 'a tracked share is no term: the install still pays the campaign default');
        self::assertSame([$signup], $this->goals->sharedGoalIds(1, 314));

        self::$db->query('UPDATE 202_campaign_goals SET created_at = created_at - 100 WHERE goal_id = ' . (int) $signup);
        $paid = $this->api()->attachCampaign($signup, 314, ['payout' => '1.00'])['data'];
        self::assertTrue($paid['payable']);
        self::assertSame($started - 100, $paid['created_at'], 'paid from tracked is an update of the row, not a new start');
        self::assertArrayHasKey($signup, $this->goals->campaignTerms(314, 1));

        $back = $this->api()->attachCampaign($signup, 314, ['payable' => false])['data'];
        self::assertSame([false, null, $started - 100], [$back['payable'], $back['payout'], $back['created_at']]);

        $preview = $this->api()->detachCampaignPreview($signup, 314)['data'];
        self::assertStringContainsString('stops counting on campaign 314', $preview['effect'], 'another campaign\'s goal leaves the campaign');
        $this->api()->detachCampaign($signup, 314);
        self::assertSame([], $this->goals->sharedGoalIds(1, 314));
    }

    public function testAnAccountGoalCountsOnEveryCampaignFromTheTimeItWasTurnedOn(): void
    {
        foreach ([314, 315, 999] as $c) {
            $this->campaign($c);
        }
        // Effective from the test clock, well before the wall clock that
        // turns it on, so an event in between is refused by the share alone.
        $purchase = $this->goals->create(1, GoalScope::ACCOUNT, 0,
            GoalDefinition::parse(['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'value' => ['type' => 'fixed', 'amount' => 10]]), $this->clock);
        $this->click(2001, 314);
        $this->click(2002, 315);
        $this->click(2003, 999);

        // Not yet on: an account goal on no campaign counts on no click.
        $this->ingest(2001, [$this->event('early', 'purchase', self::T)]);
        self::assertSame([], $this->liveOutcomes(2001));

        $on = $this->api()->setEveryCampaign($purchase, [])['data'];
        self::assertTrue($on['every_campaign']);
        $since = $on['every_campaign_since'];
        self::assertIsInt($since);
        self::assertSame($on['every_campaign_since'], $this->api()->setEveryCampaign($purchase, [])['data']['every_campaign_since'], 'on again keeps its start');
        $this->api()->attachCampaign($purchase, 315, ['payout' => '6.00']);

        // Received after it was turned on: counted on every campaign, paid
        // only where a campaign pays for it. The event the click sent
        // before stays where it was.
        $this->clock = $since;
        $this->ingest(2001, [$this->event('p1', 'purchase', self::T + 1)]);
        $this->ingest(2002, [$this->event('p2', 'purchase', self::T + 1)]);
        $this->ingest(2003, [$this->event('p3', 'purchase', self::T + 1)]);
        self::assertSame([$purchase . ':1:1@p1=10.00000 unpaid'], $this->liveOutcomes(2001));
        self::assertSame(['lead' => 1, 'payout' => '6.00000'], $this->clickState(2002));
        self::assertSame([$purchase . ':1:1@p3=10.00000 unpaid'], $this->liveOutcomes(2003), 'a campaign nobody set it on');

        // A campaign made after it was turned on counts it too.
        $this->campaign(1000);
        $this->click(2004, 1000);
        $this->ingest(2004, [$this->event('p4', 'purchase', self::T + 1)]);
        self::assertCount(1, $this->liveOutcomes(2004));

        // Its re-evaluation chooses clicks on every campaign.
        $plan = $this->engine->reevaluate(1, $purchase, null, false, 100, 0, 'click');
        self::assertSame([2001, 2002, 2003, 2004], array_map(static fn (array $s): int => (int) $s['subject_id'], $plan['subjects']));

        // Off: it keeps what it counted and the campaign that pays for it,
        // and stops counting everywhere else.
        $preview = $this->api()->clearEveryCampaignPreview($purchase)['data'];
        self::assertSame('delete', $preview['action']);
        $this->api()->clearEveryCampaign($purchase);
        self::assertFalse($this->api()->get($purchase)['data']['every_campaign']);
        $this->ingest(2003, [$this->event('p3b', 'purchase', self::T + 2)]);
        self::assertSame([$purchase . ':1:1@p3=10.00000 unpaid'], $this->liveOutcomes(2003));
        self::assertSame([$purchase], $this->goals->sharedGoalIds(1, 315));
        self::assertSame([], $this->goals->sharedGoalIds(1, 314));

        $this->expectException(NotFoundException::class);
        $this->api()->clearEveryCampaign($purchase);
    }

    public function testTheCampaignsPageListsWhatIsSharedWithItPaidOrTracked(): void
    {
        require_once dirname(__DIR__, 2) . '/tracking202/setup/_includes/campaign_goals.php';
        $this->clock = time();
        foreach ([314, 315, 316] as $c) {
            $this->campaign($c);
        }
        $signup = $this->api()->create(['scope' => 'campaign', 'scope_id' => 316,
            'definition' => ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 2]]])['data']['goal_id'];
        $this->api()->attachCampaign($signup, 314, ['payable' => false]);
        $this->api()->attachCampaign($signup, 315, ['payout' => '4.00']);
        $everywhere = $this->api()->create(['scope' => 'account', 'scope_id' => 0,
            'definition' => ['name' => 'Purchase', 'trigger' => ['event' => 'purchase']]])['data']['goal_id'];
        $this->api()->setEveryCampaign($everywhere, []);
        $gone = $this->api()->create(['scope' => 'account', 'scope_id' => 0,
            'definition' => ['name' => 'Old', 'trigger' => ['event' => 'old']]])['data']['goal_id'];
        $this->api()->setEveryCampaign($gone, []);
        $this->goals->archive(1, $gone, time());

        $listed = static function (int $campaign): array {
            $out = [];
            foreach (p202_goal_list(self::$db, 1, $campaign)['attached'] as $g) {
                $sum = p202_goal_summary($g, $campaign);
                $out[(int) $g['goal_id']] = p202_goal_shared_from($g, $campaign) . ($sum['paid'] ? ' paid ' . $sum['payout'] : ' tracked');
            }
            ksort($out);

            return $out;
        };
        self::assertSame([$signup => "campaign 316's goal tracked", $everywhere => 'account goal on every campaign tracked'], $listed(314),
            'an archived goal that counted on every campaign is not listed on each of them');
        self::assertSame([$signup => "campaign 316's goal paid $4.00", $everywhere => 'account goal on every campaign tracked'], $listed(315));
        self::assertSame([$everywhere => 'account goal on every campaign tracked'], $listed(316), 'its own goal is listed as its own');
        self::assertSame([$signup], array_column(p202_goal_list(self::$db, 1, 316)['own'], 'goal_id'));
    }

    public function testOnlyALiveAccountGoalOfItsOwnCountsOnEveryCampaign(): void
    {
        $this->campaign(314);
        $registration = $this->goals->create(1, GoalScope::REGISTRATION, 3, GoalDefinition::parse(['name' => 'R', 'trigger' => ['event' => 'r']]), $this->clock);
        $theirs = $this->goals->create(2, GoalScope::ACCOUNT, 0, GoalDefinition::parse(['name' => 'T', 'trigger' => ['event' => 't']]), $this->clock);
        $archived = $this->goals->create(1, GoalScope::ACCOUNT, 0, GoalDefinition::parse(['name' => 'A', 'trigger' => ['event' => 'a']]), $this->clock);
        $this->goals->archive(1, $archived, $this->clock);

        try {
            $this->api()->setEveryCampaign($registration, []);
            self::fail('a registration goal');
        } catch (\Api\V3\Exception\ValidationException $e) {
            self::assertArrayHasKey('scope', $e->getFieldErrors());
        }
        try {
            $this->api()->setEveryCampaign($theirs, []);
            self::fail('another account\'s goal');
        } catch (NotFoundException) {
        }
        try {
            $this->api()->setEveryCampaign($archived, []);
            self::fail('an archived goal');
        } catch (\Api\V3\Exception\ConflictException) {
        }
        try {
            $this->api()->setEveryCampaign($archived, ['since' => 1]);
            self::fail('a key it does not take');
        } catch (\Api\V3\Exception\ValidationException $e) {
            self::assertArrayHasKey('since', $e->getFieldErrors());
        }
        self::assertSame([], $this->goals->sharedGoalIds(1, 314));
        self::assertNull($this->goals->find(2, $theirs)['every_campaign_since']);
    }
}
