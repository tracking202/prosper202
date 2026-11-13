<?php

declare(strict_types=1);

namespace Tests\Goals;

use Prosper202\Goals\FastGoalPolicy;
use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Goals\GoalEngineException;

/**
 * Goal conversions through the real ledger (plan §2.1, §5.5): the engine
 * writes outcome rows and, for a subject with a click, ledger rows through
 * MysqlConversionRepository's own writer — in one transaction with the
 * events — and the click's value is the ledger's recompute, in both payout
 * modes.
 *
 * @group integration
 */
final class GoalEngineIntegrationTest extends TestCase
{
    use GoalDatabase;

    private const T = 1_650_000_000;

    public function testPurLinkOffersKeepTheirGoalEventsAndLedgerRowsSeparate(): void
    {
        $this->campaign(7);
        $this->campaign(8);
        $this->click(100, 7);
        $this->click(101, 7);
        $goal = $this->goal(7, ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $this->goals->attach(1, 8, $goal, null, true, $this->clock);
        $page = 777001;
        self::fixture("DELETE FROM 202_landing_pages WHERE landing_page_id=$page");
        self::fixture("INSERT INTO 202_landing_pages SET landing_page_id=$page, user_id=1, aff_campaign_id=0, landing_page_type=1, landing_page_nickname='goal streams', landing_page_url='https://example.test/', landing_page_time=1");
        self::fixture("UPDATE 202_clicks SET landing_page_id=$page WHERE click_id IN (100,101)");
        $subjects = new \Prosper202\Goals\PurLinkGoalSubjects($this->conn);
        $write = function (int $campaign, \Prosper202\Goals\GoalEvent $event) use ($subjects, $page): array {
            return $this->conn->transaction(function () use ($subjects, $page, $campaign, $event): array {
                $subject = $subjects->forRouteInTransaction(1, 100, $page, $campaign);
                self::assertNotNull($subject);
                return $this->engine->ingestPurLinkInTransaction(1, $subject, [$event], \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID);
            });
        };
        $first = $write(7, $this->event('same-id', 'signup', self::T + 10));
        $second = $write(8, $this->event('same-id', 'signup', self::T + 10));
        self::assertSame(['same-id'], $this->engine->finishPurLinkIngest(1, $first)['accepted']);
        self::assertSame(['same-id'], $this->engine->finishPurLinkIngest(1, $second)['accepted']);
        $rows = self::$db->query('SELECT campaign_id,click_payout,dedupe_key FROM 202_conversion_logs WHERE click_id=100 ORDER BY conv_id')->fetch_all(MYSQLI_ASSOC);
        self::assertCount(2, $rows);
        self::assertSame(['7', '8'], array_column($rows, 'campaign_id'));
        self::assertSame(['3.00000', '3.00000'], array_column($rows, 'click_payout'));
        self::assertNotSame($rows[0]['dedupe_key'], $rows[1]['dedupe_key']);
        self::assertCount(2, self::$db->query('SELECT subject_id FROM 202_purlink_goal_subjects WHERE click_id=100')->fetch_all(MYSQLI_ASSOC));
        $retry = $write(8, $this->event('same-id', 'signup', self::T + 10));
        self::assertSame(['same-id'], $this->engine->finishPurLinkIngest(1, $retry)['duplicates']);
        $late = $write(7, $this->event('late-view', 'view', self::T));
        self::assertTrue($this->engine->finishPurLinkIngest(1, $late)['replayed']);
        self::assertSame('2', self::$db->query('SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id=100')->fetch_row()[0]);
        self::assertSame('1', self::$db->query("SELECT COUNT(*) FROM 202_goal_events e JOIN 202_purlink_goal_subjects s ON s.subject_id=e.subject_id WHERE e.subject_type='purlink' AND s.campaign_id=8")->fetch_row()[0]);

        $error = null;
        try {
            $this->conn->transaction(function () use ($subjects, $page): void {
                $subject = $subjects->forRouteInTransaction(1, 101, $page, 8);
                self::assertNotNull($subject);
                $this->engine->ingestPurLinkInTransaction(1, $subject, [$this->event('rollback', 'signup', self::T)], \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $caught) {
            $error = $caught;
        }
        self::assertNotNull($error, 'the transaction was not rolled back');
        self::assertSame('rollback', $error->getMessage());
        self::assertSame('0', self::$db->query('SELECT COUNT(*) FROM 202_purlink_goal_subjects WHERE click_id=101')->fetch_row()[0]);
        self::assertCount(0, $this->ledger(101));
    }

    public function testPurLinkGoalReevaluationIsPaginatedAndKeepsOfferAttribution(): void
    {
        $this->campaign(7);
        $this->campaign(8);
        $this->click(100, 7);
        $goal = $this->goal(7, ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $this->goals->attach(1, 8, $goal, null, true, $this->clock);
        $page = 777003;
        self::fixture("DELETE FROM 202_landing_pages WHERE landing_page_id=$page");
        self::fixture("INSERT INTO 202_landing_pages SET landing_page_id=$page,user_id=1,aff_campaign_id=0,landing_page_type=1,landing_page_nickname='reevaluate',landing_page_url='https://example.test/',landing_page_time=1");
        self::fixture("UPDATE 202_clicks SET landing_page_id=$page WHERE click_id=100");
        $subjects = new \Prosper202\Goals\PurLinkGoalSubjects($this->conn);
        foreach ([7, 8] as $campaign) {
            $done = $this->conn->transaction(function () use ($subjects, $page, $campaign): array {
                $subject = $subjects->forRouteInTransaction(1, 100, $page, $campaign);
                self::assertNotNull($subject);
                return $this->engine->ingestPurLinkInTransaction(1, $subject,
                    [$this->event('same-id', 'signup', self::T + 1)], \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID);
            });
            $this->engine->finishPurLinkIngest(1, $done);
        }
        $this->clock += 100;
        $this->goals->addVersion(1, $goal, \Prosper202\Goals\GoalDefinition::parse(
            ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 5]], $goal
        ), $this->clock);
        $before = $this->ledger(100);
        $preview = $this->engine->reevaluate(1, $goal, null, false, 1, 0, \Prosper202\Goals\GoalSubject::PURLINK);
        self::assertSame('purlink', $preview['subject_type']);
        self::assertCount(1, $preview['subjects']);
        self::assertNotNull($preview['next_after']);
        self::assertSame(1, $preview['totals']['retire']);
        self::assertSame($before, $this->ledger(100), 'preview does not change the ledger');
        $first = $this->engine->reevaluate(1, $goal, null, true, 1, 0, \Prosper202\Goals\GoalSubject::PURLINK);
        self::assertSame($preview['next_after'], $first['next_after']);
        self::assertSame('8.00000', $this->clickState(100)['payout']);
        $last = $this->engine->reevaluate(1, $goal, null, true, 1, $first['next_after'], \Prosper202\Goals\GoalSubject::PURLINK);
        self::assertNull($last['next_after']);
        self::assertSame('10.00000', $this->clickState(100)['payout']);
        $rows = self::$db->query("SELECT campaign_id,click_payout FROM 202_conversion_logs WHERE click_id=100 AND superseded_by IS NULL AND deleted=0 ORDER BY campaign_id")->fetch_all(MYSQLI_ASSOC);
        self::assertSame([['campaign_id'=>'7','click_payout'=>'5.00000'], ['campaign_id'=>'8','click_payout'=>'5.00000']], $rows);
        $again = $this->engine->reevaluate(1, $goal, null, true, 10, 0, \Prosper202\Goals\GoalSubject::PURLINK);
        self::assertSame(0, $again['totals']['retire'] + $again['totals']['write']);
        // A late event replays only the first offer and keeps its rebase.
        $late = $this->conn->transaction(function () use ($subjects, $page): array {
            $subject = $subjects->forRouteInTransaction(1, 100, $page, 7);
            self::assertNotNull($subject);
            return $this->engine->ingestPurLinkInTransaction(1, $subject,
                [$this->event('late-view', 'view', self::T)], \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID);
        });
        self::assertTrue($this->engine->finishPurLinkIngest(1, $late)['replayed']);
        self::assertSame('10.00000', $this->clickState(100)['payout']);
        $live = self::$db->query("SELECT campaign_id,goal_version FROM 202_goal_outcomes WHERE subject_type='purlink' AND superseded_at IS NULL ORDER BY campaign_id")->fetch_all(MYSQLI_ASSOC);
        self::assertSame([['campaign_id'=>'7','goal_version'=>'2'], ['campaign_id'=>'8','goal_version'=>'2']], $live);
        // Retained historical outcomes can still be listed after click data
        // retention, but cannot be re-evaluated without their original click.
        self::fixture('DELETE FROM 202_clicks WHERE click_id=100');
        $withoutClick = $this->engine->reevaluate(1, $goal, null, false, 10, 0, \Prosper202\Goals\GoalSubject::PURLINK);
        self::assertSame([], $withoutClick['subjects']);
    }

    public function testPurLinkGoalSubjectsEnforceOwnerAndSimplePageCampaign(): void
    {
        $this->campaign(7);
        $this->campaign(8);
        $this->click(100, 7);
        $page = 777002;
        self::fixture("DELETE FROM 202_landing_pages WHERE landing_page_id=$page");
        self::fixture("INSERT INTO 202_landing_pages SET landing_page_id=$page, user_id=1, aff_campaign_id=7, landing_page_type=0, landing_page_nickname='simple scope', landing_page_url='https://example.test/', landing_page_time=1");
        self::fixture("UPDATE 202_clicks SET landing_page_id=$page WHERE click_id=100");
        $subjects = new \Prosper202\Goals\PurLinkGoalSubjects($this->conn);
        self::assertNull($this->conn->transaction(fn () => $subjects->forRouteInTransaction(1, 100, $page, 8)));
        self::assertNull($this->conn->transaction(fn () => $subjects->forRouteInTransaction(2, 100, $page, 7)));
        self::assertNull($this->conn->transaction(fn () => $subjects->forRouteInTransaction(1, 100, $page + 1, 7)));
        self::assertSame('0', self::$db->query('SELECT COUNT(*) FROM 202_purlink_goal_subjects')->fetch_row()[0]);
        $subject = $this->conn->transaction(fn () => $subjects->forRouteInTransaction(1, 100, $page, 7));
        self::assertNotNull($subject);
        $again = $this->conn->transaction(fn () => $subjects->forRouteInTransaction(1, 100, $page, 7));
        self::assertSame($subject->id, $again->id);
        $forged = new \Prosper202\Goals\GoalSubject(\Prosper202\Goals\GoalSubject::PURLINK, $subject->id, $subject->clickAt, null, [], 100, 8);
        try {
            $this->conn->transaction(fn () => $this->engine->ingestPurLinkInTransaction(1, $forged,
                [$this->event('forged', 'signup', self::T)], \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID));
            self::fail('a subject naming a different campaign was accepted');
        } catch (GoalEngineException $error) {
            self::assertSame(GoalEngineException::NOT_FOUND, $error->reason);
        }
        self::assertSame('0', self::$db->query('SELECT COUNT(*) FROM 202_goal_events')->fetch_row()[0]);
    }

    public function testCampaignScopedEventChecksTheCurrentClickUnderLock(): void
    {
        $this->campaign(7);
        $this->campaign(8);
        $this->click(100, 7);
        $this->goal(7, ['name' => 'Signup', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $subject = $this->engine->clickSubject(1, 100);
        $event = $this->event('pur-link-event', 'signup', self::T + 1);

        // A route changes after the endpoint reads its subject but before
        // the engine locks it. The old link must not store an event at all.
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::fixture('UPDATE ' . $table . ' SET aff_campaign_id=8 WHERE click_id=100');
        }
        try {
            $this->engine->ingest(1, $subject, [$event], \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, expectedCampaignId: 7);
            self::fail('a stale campaign was accepted');
        } catch (GoalEngineException $error) {
            self::assertSame(GoalEngineException::NOT_FOUND, $error->reason);
        }
        self::assertSame('0', self::$db->query('SELECT COUNT(*) AS n FROM 202_goal_events')->fetch_assoc()['n']);
        self::assertCount(0, $this->ledger(100));

        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::fixture('UPDATE ' . $table . ' SET aff_campaign_id=7 WHERE click_id=100');
        }
        $result = $this->engine->ingest(1, $subject, [$event], \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, expectedCampaignId: 7);
        self::assertSame(['pur-link-event'], $result['accepted']);
        self::assertSame(['lead' => 1, 'payout' => '3.00000'], $this->clickState(100));
        $again = $this->engine->ingest(1, $subject, [$event], \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, expectedCampaignId: 7);
        self::assertSame(['pur-link-event'], $again['duplicates']);
        self::assertCount(1, $this->ledger(100));
    }

    public function testAWebFunnelReachesTwoGoalsWithTwoLedgerRowsAndARolledUpValue(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $optin = $this->goal(7, ['name' => 'Opt-in', 'trigger' => ['event' => 'optin'], 'value' => ['type' => 'fixed', 'amount' => '1.50']]);
        $sale = $this->goal(7, ['name' => 'Sale', 'trigger' => ['event' => 'sale'], 'after' => [], 'value' => ['type' => 'fixed', 'amount' => 20]]);

        $first = $this->ingest(100, [$this->event('o1', 'optin', self::T + 1)]);
        self::assertSame(['o1'], $first['accepted']);
        self::assertSame(1, $first['outcomes_written']);
        $this->ingest(100, [$this->event('s1', 'sale', self::T + 2, [], null, false, 'NET-77')]);

        self::assertSame(['lead' => 1, 'payout' => '21.50000'], $this->clickState(100));
        $rows = $this->ledger(100);
        self::assertCount(2, $rows);
        self::assertSame(['goal', 'goal'], array_column($rows, 'source'));
        self::assertSame(['goal:' . $optin . ':1', 'goal:' . $sale . ':1'], array_column($rows, 'source_ref'));
        self::assertSame(['optin', 'sale'], array_column($rows, 'event_name'));
        self::assertSame('goal:' . $sale . ':1:1:s1', $rows[1]['dedupe_key']);
        self::assertSame('NET-77', $rows[1]['transaction_id'], 'the network id the event carried is kept on the row');
        self::assertNull($rows[0]['transaction_id']);

        // Both are queued for MTA, in the same transaction as the rows.
        $queued = (int) self::$db->query('SELECT COUNT(*) AS n FROM 202_attribution_pending WHERE conv_id IN ('
            . (int) $rows[0]['conv_id'] . ',' . (int) $rows[1]['conv_id'] . ')')->fetch_assoc()['n'];
        self::assertSame(2, $queued);

        // Each outcome links its ledger row.
        $links = self::$db->query('SELECT conversion_id FROM 202_goal_outcomes ORDER BY outcome_id')->fetch_all(MYSQLI_ASSOC);
        self::assertSame(array_column($rows, 'conv_id'), array_column($links, 'conversion_id'));
    }

    public function testReplaceModeShowsTheLatestGoalRowAndMarksTheOtherSuperseded(): void
    {
        $this->campaign(7, 'replace');
        $this->click(100, 7);
        $this->goal(7, ['name' => 'Install', 'trigger' => ['event' => 'first_open'], 'value' => ['type' => 'fixed', 'amount' => 1]]);
        $this->goal(7, ['name' => 'Level 3', 'trigger' => ['event' => 'level', 'where' => [['prop' => 'level', 'op' => 'gte', 'value' => 3]]],
            'value' => ['type' => 'fixed', 'amount' => 4]]);

        $this->ingest(100, [$this->event('f', 'first_open', self::T + 1)]);
        foreach ([1, 2, 3] as $level) {
            $this->ingest(100, [$this->event('l' . $level, 'level', self::T + 1 + $level, ['level' => $level])]);
        }
        $this->ingest(100, [$this->event('l3', 'level', self::T + 4, ['level' => 3])]); // a replay of level 3

        self::assertSame(['lead' => 1, 'payout' => '4.00000'], $this->clickState(100));
        $rows = $this->ledger(100);
        self::assertCount(2, $rows, 'exactly two conversions: the replayed event is a duplicate');
        self::assertSame('replace', $rows[0]['superseded_reason']);

        // The same rows under accumulate: the recompute, not a running total.
        $this->setMode(7, 'accumulate');
        (new MysqlConversionRepository($this->conn))->record(1, [
            'click_id' => 100, 'transaction_id' => 'unrelated', 'payout' => '0', 'payable' => false, 'source' => 'postback',
        ], \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID);
        self::assertSame(['lead' => 1, 'payout' => '5.00000'], $this->clickState(100));
    }

    public function testARetriedEventIsADuplicateAndAReusedIdWithOtherContentIsRefused(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->goal(7, ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'repeat' => ['mode' => 'each'], 'value' => ['type' => 'fixed', 'amount' => 3]]);

        $this->ingest(100, [$this->event('b1', 'buy', self::T + 1, ['sku' => 'A'])]);
        $retry = $this->ingest(100, [$this->event('b1', 'buy', self::T + 1, ['sku' => 'A'])]);
        self::assertSame([], $retry['accepted']);
        self::assertSame(['b1'], $retry['duplicates']);
        self::assertSame(0, $retry['outcomes_written']);

        try {
            $this->ingest(100, [$this->event('b2', 'buy', self::T + 2), $this->event('b1', 'buy', self::T + 1, ['sku' => 'B'])]);
            self::fail('a reused event id with different content was accepted');
        } catch (GoalEngineException $e) {
            self::assertSame(GoalEngineException::EVENT_CONFLICT, $e->reason);
            self::assertStringContainsString('"b1"', $e->getMessage());
        }
        // The whole batch rolled back: b2 was not stored either.
        self::assertSame('1', self::$db->query('SELECT COUNT(*) AS n FROM 202_goal_events')->fetch_assoc()['n']);
        self::assertSame(['lead' => 1, 'payout' => '3.00000'], $this->clickState(100));
        self::assertCount(1, $this->ledger(100));
    }

    /**
     * A refusing event whose batch is refused (an event id reused with other
     * content) records nothing, but the click's refusal is stored on its own:
     * the visitor's answer stands whatever the sender got wrong. A consenting
     * batch refused the same way stores none.
     */
    public function testARefusingEventInARefusedBatchStillRefusesItsClick(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->click(101, 7);
        self::$db->query('DELETE FROM 202_click_consent_refusals WHERE click_id IN (100, 101)');
        $this->goal(7, ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'repeat' => ['mode' => 'each'],
            'value' => ['type' => 'fixed', 'amount' => 3]]);
        $byId = \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID;
        $refusing = function (int $click, bool $refuses) use ($byId): array {
            $event = new \Prosper202\Goals\GoalEvent(
                eventId: 'b1',
                name: 'buy',
                occurredAt: self::T + 1,
                receivedAt: self::T + 50,
                properties: ['sku' => 'B'],
                revenue: null,
                revenueTrusted: false,
                transactionId: null,
                matchMethod: $byId,
                consentWithheld: $refuses,
            );

            return $this->engine->ingest(1, $this->engine->clickSubject(1, $click), [$event], $byId);
        };
        foreach ([100 => true, 101 => false] as $click => $refuses) {
            $this->ingest($click, [$this->event('b1', 'buy', self::T + 1, ['sku' => 'A'])]);
            try {
                $refusing($click, $refuses);
                self::fail('a reused event id with different content was accepted');
            } catch (GoalEngineException $e) {
                self::assertSame(GoalEngineException::EVENT_CONFLICT, $e->reason);
            }
            self::assertCount(1, $this->ledger($click), "click $click: nothing more recorded");
        }
        $refused = self::$db->query('SELECT click_id FROM 202_click_consent_refusals WHERE click_id IN (100, 101)')
            ->fetch_all(MYSQLI_NUM);
        self::assertSame([['100']], $refused, 'only the refusing request refused its click');
    }

    public function testAGoalTheCampaignDoesNotPayForIsTrackedNotPaid(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->goal(7, ['name' => 'Tutorial', 'trigger' => ['event' => 'tutorial'], 'value' => ['type' => 'fixed', 'amount' => 9]], false);
        $this->goal(7, ['name' => 'Seen', 'trigger' => ['event' => 'view'], 'value' => ['type' => 'none']]);

        $this->ingest(100, [$this->event('t', 'tutorial', self::T + 1), $this->event('v', 'view', self::T + 2)]);

        self::assertSame(0, $this->clickState(100)['lead'], 'no payable row: the click is not a lead');
        $rows = $this->ledger(100);
        self::assertSame(['0', '0'], array_column($rows, 'payable'));
        self::assertSame(['9.00000', '0.00000'], array_column($rows, 'click_payout'), 'reported at the goal\'s value, not paid');
        $reasons = self::$db->query('SELECT value_note FROM 202_goal_outcomes ORDER BY outcome_id')->fetch_all(MYSQLI_ASSOC);
        self::assertSame(['not_payable_on_campaign', null], array_column($reasons, 'value_note'));
    }

    public function testARevenueValueIsPaidOnlyFromATrustedPathAndACampaignPayoutOverridesIt(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->click(101, 7);
        $this->click(102, 7);
        $purchase = $this->goal(7, ['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'value' => ['type' => 'from_property']]);

        $this->ingest(100, [$this->event('p', 'purchase', self::T + 1, [], 12.5, false)]);
        self::assertSame(0, $this->clickState(100)['lead'], 'an untrusted revenue is not credited');
        self::assertSame(['0', '12.50000'], [$this->ledger(100)[0]['payable'], $this->ledger(100)[0]['click_payout']],
            'it is stored and reported on the row, unpaid');

        $this->ingest(101, [$this->event('p', 'purchase', self::T + 1, [], 12.5, true)]);
        self::assertSame(['lead' => 1, 'payout' => '12.50000'], $this->clickState(101));

        $this->goals->attach(1, 7, $purchase, 250000, true, $this->clock); // 2.50
        $this->ingest(102, [$this->event('p', 'purchase', self::T + 1, [], 12.5, false)]);
        self::assertSame(['lead' => 1, 'payout' => '2.50000'], $this->clickState(102), 'the campaign payout is the server\'s, trusted');
    }

    public function testAGoalRowCanBeReversedByItsTransactionIdAndDeletedLikeAnyRow(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->goal(7, ['name' => 'Sale', 'trigger' => ['event' => 'sale'], 'repeat' => ['mode' => 'each'], 'value' => ['type' => 'fixed', 'amount' => 10]]);
        $this->ingest(100, [$this->event('s1', 'sale', self::T + 1, [], null, false, 'ORD-1'), $this->event('s2', 'sale', self::T + 2)]);
        self::assertSame('20.00000', $this->clickState(100)['payout']);

        $repo = new MysqlConversionRepository($this->conn);
        $reversal = $repo->record(1, ['click_id' => 100, 'transaction_id' => 'ORD-1', 'reversal' => true, 'source' => 'postback'], \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID);
        self::assertNotNull($reversal['reversesConvId']);
        self::assertSame(['lead' => 1, 'payout' => '10.00000'], $this->clickState(100), 'the reversal nets against the goal row it names');

        $second = $this->ledger(100)[1];
        $repo->softDelete((int) $second['conv_id'], 1);
        self::assertSame(['lead' => 1, 'payout' => '0.00000'], $this->clickState(100));

        // A later event still evaluates; the deleted row stays deleted and
        // its outcome stays live (the ledger decides what counts).
        $this->ingest(100, [$this->event('s3', 'sale', self::T + 3)]);
        self::assertSame(['lead' => 1, 'payout' => '10.00000'], $this->clickState(100));
        self::assertCount(3, $this->goals->liveOutcomes(1, ['subject_type' => 'click', 'subject_id' => 100]));
    }

    public function testASubjectHoldsAtMostTheCapOfEvents(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->ingest(100, [$this->event('e1', 'x', self::T + 1)]);
        self::$db->query("UPDATE 202_goal_subjects SET event_count = 10000 WHERE subject_id = 100");

        try {
            $this->ingest(100, [$this->event('e2', 'x', self::T + 2)]);
            self::fail('the 10,001st event was accepted');
        } catch (GoalEngineException $e) {
            self::assertSame(GoalEngineException::EVENT_CAP, $e->reason);
            self::assertStringContainsString('10000', $e->getMessage());
        }
    }

    public function testAClickOfAnotherUserIsNotASubject(): void
    {
        $this->campaign(7);
        self::fixture('INSERT INTO 202_clicks SET click_id=300, user_id=2, aff_campaign_id=7, click_payout=0, click_cpc=0, click_lead=0, click_time=1');
        $this->expectException(GoalEngineException::class);
        $this->engine->clickSubject(1, 300);
    }

    public function testAnOutcomeWithoutAClickWritesNoLedgerRow(): void
    {
        // An install subject with no click (an organic install) evaluates its
        // registration's goals and is visible only in 202_goal_outcomes: the
        // ledger is click-bound, and with no click there is no campaign to
        // pay, so the outcome is tracked, not payable.
        $this->goals->create(1, \Prosper202\Goals\GoalScope::REGISTRATION, 9, \Prosper202\Goals\GoalDefinition::parse(
            ['name' => 'Level', 'trigger' => ['event' => 'level'], 'value' => ['type' => 'fixed', 'amount' => 1]]
        ), $this->clock);
        // The organic install itself: the engine reads an install subject's
        // credit from its row under the row's lock (GoalEngine::current()).
        self::$db->query('DELETE FROM 202_app_installs WHERE install_row_id = 55');
        self::fixture("INSERT INTO 202_app_installs SET install_row_id = 55, user_id = 1, registration_id = 9,
            install_uuid = '55555555-5555-4555-8555-555555555555', body_hash = '" . str_repeat('0', 64) . "', store = 'google_play',
            match_state = 'organic', match_reason = 'no p202 token', referrer_status = 'ok', install_begin_server_at = " . self::T . ',
            received_at = ' . self::T);
        $subject = new \Prosper202\Goals\GoalSubject('install', 55, null, self::T, [], null, null, 9);
        $this->clock += 10;
        $result = $this->engine->ingest(1, $subject, [new \Prosper202\Goals\GoalEvent('l', 'level', self::T + 5, $this->clock, [], null, false, null)], \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID);
        self::assertSame(1, $result['outcomes_written']);
        self::assertSame('0', self::$db->query('SELECT COUNT(*) AS n FROM 202_conversion_logs')->fetch_assoc()['n']);
        $row = self::$db->query('SELECT subject_type, subject_id, conversion_id, payable, value_note, app_registration_id FROM 202_goal_outcomes')->fetch_assoc();
        // Registration 9 has no row here, so its fast-goal policy is the
        // unreadable one (hold, under an hour): the level, 5 s after the
        // install, is held, and the note says so rather than
        // not_payable_on_campaign — the reason that stands if a click ever
        // credits the install (FastGoalPolicy).
        self::assertSame(['install', '55', null, '0', FastGoalPolicy::HELD_NOTE, '9'], [
            $row['subject_type'], $row['subject_id'], $row['conversion_id'], $row['payable'], $row['value_note'], $row['app_registration_id'],
        ]);
    }

    /**
     * A subject whose live outcomes pass what one read holds is refused by
     * name, never reconciled against a truncated read (which would take the
     * unread outcomes for missing and write them a second time).
     */
    public function testASubjectWithMoreLiveOutcomesThanOneReadHoldsIsRefusedNotTruncated(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $goal = $this->goal(7, ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'repeat' => ['mode' => 'each']]);
        $this->ingest(100, [$this->event('b1', 'buy', self::T + 1)]);
        $digits = '(SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 '
            . 'UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        // 100,000 more live outcomes of goal version 99 beside the real one.
        self::fixture("INSERT INTO 202_goal_outcomes (user_id, subject_type, subject_id, goal_id, goal_version, n, event_id, reached_at,
                value_source, payable, created_at)
            SELECT 1, 'click', 100, $goal, 99, 1 + a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d, 'x', 1, 'none', 0, 1
            FROM $digits a, $digits b, $digits c, $digits e, $digits f");
        self::assertSame((string) (\Prosper202\Goals\GoalEngine::MAX_LIVE_OUTCOMES_PER_SUBJECT + 1),
            self::$db->query('SELECT COUNT(*) AS n FROM 202_goal_outcomes WHERE superseded_at IS NULL')->fetch_assoc()['n']);

        foreach (['an in-order event' => self::T + 2, 'a replay' => self::T] as $what => $at) {
            try {
                $this->ingest(100, [$this->event('b' . $at, 'buy', $at)]);
                self::fail($what . ' was evaluated against a truncated read');
            } catch (GoalEngineException $e) {
                self::assertSame(GoalEngineException::INTEGRITY, $e->reason, $what);
                self::assertStringContainsString('more than 100000 live goal outcomes', $e->getMessage(), $what);
            }
        }
        self::assertSame('1', self::$db->query('SELECT COUNT(*) AS n FROM 202_goal_events WHERE subject_id = 100')->fetch_assoc()['n'],
            'the refused events rolled back');
    }
}
