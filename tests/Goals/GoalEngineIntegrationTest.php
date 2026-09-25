<?php

declare(strict_types=1);

namespace Tests\Goals;

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
        ]);
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
        $reversal = $repo->record(1, ['click_id' => 100, 'transaction_id' => 'ORD-1', 'reversal' => true, 'source' => 'postback']);
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
        // An install subject with no click (an organic install, PR 5) is
        // visible only in 202_goal_outcomes: the ledger is click-bound.
        $this->campaign(7);
        $this->goal(7, ['name' => 'Level', 'trigger' => ['event' => 'level'], 'value' => ['type' => 'fixed', 'amount' => 1]]);
        $subject = new \Prosper202\Goals\GoalSubject('install', 55, null, self::T, [], null, 7);
        $this->clock += 10;
        $result = $this->engine->ingest(1, $subject, [new \Prosper202\Goals\GoalEvent('l', 'level', self::T + 5, $this->clock, [], null, false, null)]);
        self::assertSame(1, $result['outcomes_written']);
        self::assertSame('0', self::$db->query('SELECT COUNT(*) AS n FROM 202_conversion_logs')->fetch_assoc()['n']);
        $row = self::$db->query('SELECT subject_type, subject_id, conversion_id, payable FROM 202_goal_outcomes')->fetch_assoc();
        self::assertSame(['install', '55', null, '1'], [$row['subject_type'], $row['subject_id'], $row['conversion_id'], $row['payable']]);
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
