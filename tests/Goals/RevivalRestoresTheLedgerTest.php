<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalEngineException;

/**
 * A retired outcome that a later reconciliation returns to — the same goal,
 * version, n and event — is revived rather than written twice (the outcome's
 * UNIQUE key names the event). Revival has to restore its ledger row to what
 * it was before the engine retired it, whichever way it was retired: a
 * re-evaluation with a replacement SUPERSEDES the row, one with no
 * replacement SOFT-DELETES it. Reviving only the first left the second live
 * in the funnel and unpaid on the click.
 *
 * The scenario that found it is a funnel A → B: A matches, a re-evaluation
 * makes A stop matching (A and B retired with no replacement, so their rows
 * are deleted), and a second one makes A match again. A is written anew
 * under its new version; B, whose version did not change, is revived — and
 * must be paid again.
 *
 * What revival must not do is undo a deletion the engine did not make: an
 * operator's DELETE of the conversion stays, exactly as it would have
 * without the re-evaluations in between.
 *
 * @group integration
 */
final class RevivalRestoresTheLedgerTest extends TestCase
{
    use GoalDatabase;

    private const T = 1_650_000_000;

    private int $a;
    private int $b;

    /** @param array<string, mixed> $definition */
    private function edit(int $goal, array $definition): int
    {
        $this->clock += 100;

        return $this->goals->addVersion(1, $goal, GoalDefinition::parse($definition, $goal), $this->clock)['version'];
    }

    /** @return array<string, mixed> */
    private static function purchase(bool $big = false): array
    {
        $trigger = ['event' => 'purchase'];
        if ($big) {
            $trigger['where'] = [['prop' => 'amount', 'op' => 'gte', 'value' => 100]];
        }

        return ['name' => 'Purchase', 'trigger' => $trigger, 'value' => ['type' => 'fixed', 'amount' => 5]];
    }

    /** @param list<\Prosper202\Goals\GoalEvent> $more */
    private function funnel(string $mode = 'accumulate', array $more = []): void
    {
        $this->campaign(7, $mode);
        $this->click(100, 7);
        $this->a = $this->goal(7, self::purchase());
        $this->b = $this->goal(7, ['name' => 'Upsell', 'trigger' => ['event' => 'upsell'], 'after' => [$this->a],
            'value' => ['type' => 'fixed', 'amount' => 3]]);
        $this->ingest(100, [
            $this->event('p1', 'purchase', self::T + 1, ['amount' => 10]),
            $this->event('u1', 'upsell', self::T + 2),
            ...$more,
        ]);
    }

    /** @return array<string, array{deleted: int, reason: string|null, payout: string}> dedupe key => the row's state */
    private function goalRows(): array
    {
        $out = [];
        foreach ($this->ledger(100) as $r) {
            $out[(string) $r['dedupe_key']] = ['deleted' => (int) $r['deleted'], 'reason' => $r['superseded_reason'], 'payout' => (string) $r['click_payout']];
        }
        ksort($out);

        return $out;
    }

    private function assertAReplayAgrees(string $when): void
    {
        $replayed = $this->ingest(100, [$this->event('early' . $this->clock, 'noop', self::T - 1000)]);
        self::assertTrue($replayed['replayed']);
        self::assertSame([0, 0], [$replayed['outcomes_written'], $replayed['outcomes_retired']], $when . ': a replay disagrees with the stored outcomes');
    }

    public function testADependentRetiredWithItsPrerequisiteIsPaidAgainWhenThePrerequisiteMatchesAgain(): void
    {
        $this->funnel();
        self::assertSame([$this->a . ':1:1@p1=5.00000', $this->b . ':1:1@u1=3.00000'], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '8.00000'], $this->clickState(100));
        $bConv = (int) self::$db->query("SELECT conversion_id FROM 202_goal_outcomes WHERE goal_id = {$this->b}")->fetch_assoc()['conversion_id'];

        // Step two: A stops matching; A and B are retired with nothing in
        // their place, so both rows are deleted.
        $this->edit($this->a, self::purchase(true));
        $this->engine->reevaluate(1, $this->a, null, true);
        self::assertSame([], $this->liveOutcomes(100));
        self::assertSame(0, $this->clickState(100)['lead']);
        self::assertSame([], $this->counted(100));
        self::assertSame('0', (string) self::$db->query('SELECT leads FROM 202_dataengine WHERE click_id = 100')->fetch_assoc()['leads']);
        $seq = (int) self::$db->query("SELECT enqueue_seq FROM 202_attribution_pending WHERE conv_id = $bConv")->fetch_assoc()['enqueue_seq'];

        // Step three: A matches again under a third version. A's outcome is
        // new (a new version); B's is exactly the one retired at step two.
        $this->edit($this->a, self::purchase());
        $applied = $this->engine->reevaluate(1, $this->a, null, true);
        self::assertSame(2, $applied['totals']['write']);
        self::assertSame([$this->a . ':3:1@p1=5.00000', $this->b . ':1:1@u1=3.00000'], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '8.00000'], $this->clickState(100), 'B is live, so B is paid');
        self::assertSame(['goal:' . $this->b . ':1', 'goal:' . $this->a . ':3'], array_column($this->counted(100), 'source_ref'));

        // Revived, not duplicated: B still has one ledger row, the same one,
        // and one outcome row.
        self::assertSame(1, (int) self::$db->query("SELECT COUNT(*) AS n FROM 202_conversion_logs WHERE source_ref = 'goal:{$this->b}:1'")->fetch_assoc()['n']);
        self::assertSame(1, (int) self::$db->query("SELECT COUNT(*) AS n FROM 202_goal_outcomes WHERE goal_id = {$this->b}")->fetch_assoc()['n']);
        self::assertSame($bConv, (int) self::$db->query("SELECT conversion_id FROM 202_goal_outcomes WHERE goal_id = {$this->b}")->fetch_assoc()['conversion_id']);
        self::assertSame(3, count($this->ledger(100)), 'A v1, A v3 and B: three rows, never a fourth');
        $rows = $this->goalRows();
        self::assertSame(1, $rows['goal:' . $this->a . ':1:1:p1']['deleted'], 'A v1 stays retired');
        self::assertSame('reevaluation', $rows['goal:' . $this->a . ':1:1:p1']['reason']);
        self::assertSame(['deleted' => 0, 'reason' => null, 'payout' => '3.00000'], $rows['goal:' . $this->b . ':1:1:u1']);

        // The click's report row and the MTA outbox saw the change.
        $report = self::$db->query('SELECT leads, income FROM 202_dataengine WHERE click_id = 100')->fetch_assoc();
        self::assertSame(['1', '8.00000'], [(string) $report['leads'], (string) $report['income']]);
        self::assertSame($seq + 1, (int) self::$db->query("SELECT enqueue_seq FROM 202_attribution_pending WHERE conv_id = $bConv")->fetch_assoc()['enqueue_seq'],
            'the revived row is queued for MTA again');
        $this->assertAReplayAgrees('after the revival');

        // And retiring it a second time deletes it again.
        $this->edit($this->a, self::purchase(true));
        $this->engine->reevaluate(1, $this->a, null, true);
        self::assertSame(0, $this->clickState(100)['lead']);
        self::assertSame([], $this->counted(100));
    }

    /**
     * On a replace campaign the click shows its latest counted row, and a
     * revived row keeps its conv_id: after the third step the prerequisite's
     * new row is the latest, so it — not the revived dependent — is the
     * click's value (plan §5.7). The dependent's row counts as the ledger
     * decides, superseded by the latest as every older row is.
     */
    public function testOnAReplaceCampaignARevivedRowIsTheValueOnlyWhenItIsTheLatest(): void
    {
        $this->funnel('replace');
        self::assertSame(['lead' => 1, 'payout' => '3.00000'], $this->clickState(100), 'B was written last');
        $this->edit($this->a, self::purchase(true));
        $this->engine->reevaluate(1, $this->a, null, true);
        self::assertSame(0, $this->clickState(100)['lead']);

        $this->edit($this->a, self::purchase());
        $this->engine->reevaluate(1, $this->a, null, true);
        self::assertSame(['lead' => 1, 'payout' => '5.00000'], $this->clickState(100), 'A v3 is the newest row');
        $rows = $this->goalRows();
        self::assertSame(['deleted' => 0, 'reason' => 'replace', 'payout' => '3.00000'], $rows['goal:' . $this->b . ':1:1:u1']);
        self::assertSame(['deleted' => 0, 'reason' => null, 'payout' => '5.00000'], $rows['goal:' . $this->a . ':3:1:p1']);

        // Re-applying version 1 revives A v1 as well; A v3's row (the newest)
        // is superseded by the re-evaluation, so B is the latest counted row.
        $this->engine->reevaluate(1, $this->a, 1, true);
        self::assertSame(['lead' => 1, 'payout' => '3.00000'], $this->clickState(100));
    }

    public function testAnOutcomeRetiredWithNoReplacementIsPaidAgainWhenItsVersionIsReappliedToIt(): void
    {
        $this->funnel();
        $this->edit($this->a, self::purchase(true));
        $this->engine->reevaluate(1, $this->a, null, true);
        self::assertSame(0, $this->clickState(100)['lead']);

        // Re-apply version 1 itself: exactly A's (and B's) retired outcomes.
        $preview = $this->engine->reevaluate(1, $this->a, 1, false);
        self::assertSame(2, $preview['totals']['write']);
        $applied = $this->engine->reevaluate(1, $this->a, 1, true);
        self::assertSame(2, $applied['totals']['write']);
        self::assertSame([$this->a . ':1:1@p1=5.00000', $this->b . ':1:1@u1=3.00000'], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '8.00000'], $this->clickState(100));
        self::assertSame(2, count($this->ledger(100)), 'both rows revived in place');
        foreach ($this->goalRows() as $key => $row) {
            self::assertSame(0, $row['deleted'], $key);
            self::assertNull($row['reason'], $key);
        }
        $this->assertAReplayAgrees('after the revival');
    }

    public function testOneRevivalLiftsASupersessionAndAnotherADeletionInTheSameSubject(): void
    {
        $this->funnel('accumulate', [$this->event('p2', 'purchase', self::T + 3, ['amount' => 60])]);
        self::assertSame(['lead' => 1, 'payout' => '8.00000'], $this->clickState(100));

        // A v2 reaches A at p2, after the upsell: A v1's row is superseded
        // by A v2's, and B (reached before A now is) is deleted.
        $this->edit($this->a, ['name' => 'Purchase', 'trigger' => ['event' => 'purchase', 'where' => [['prop' => 'amount', 'op' => 'gte', 'value' => 50]]],
            'value' => ['type' => 'fixed', 'amount' => 7]]);
        $this->engine->reevaluate(1, $this->a, null, true);
        self::assertSame([$this->a . ':2:1@p2=7.00000'], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '7.00000'], $this->clickState(100));
        $rows = $this->goalRows();
        self::assertSame([0, 'reevaluation'], [$rows['goal:' . $this->a . ':1:1:p1']['deleted'], $rows['goal:' . $this->a . ':1:1:p1']['reason']]);
        self::assertSame([1, 'reevaluation'], [$rows['goal:' . $this->b . ':1:1:u1']['deleted'], $rows['goal:' . $this->b . ':1:1:u1']['reason']]);

        // Re-applying v1 revives both, each the way it was retired, and
        // supersedes A v2's row.
        $this->engine->reevaluate(1, $this->a, 1, true);
        self::assertSame([$this->a . ':1:1@p1=5.00000', $this->b . ':1:1@u1=3.00000'], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '8.00000'], $this->clickState(100));
        self::assertSame(3, count($this->ledger(100)));
        $rows = $this->goalRows();
        self::assertSame(['deleted' => 0, 'reason' => null, 'payout' => '5.00000'], $rows['goal:' . $this->a . ':1:1:p1']);
        self::assertSame(['deleted' => 0, 'reason' => null, 'payout' => '3.00000'], $rows['goal:' . $this->b . ':1:1:u1']);
        self::assertSame([0, 'reevaluation'], [$rows['goal:' . $this->a . ':2:1:p2']['deleted'], $rows['goal:' . $this->a . ':2:1:p2']['reason']]);
        $this->assertAReplayAgrees('after both revivals');
    }

    /**
     * An operator deleted B's conversion by hand. The outcomes that follow
     * are retired and revived by the engine, and the deletion stands: the
     * engine restores what it retired, never what someone else removed.
     */
    public function testRevivalLeavesADeletionTheEngineDidNotMake(): void
    {
        $this->funnel();
        $bConv = (int) self::$db->query("SELECT conversion_id FROM 202_goal_outcomes WHERE goal_id = {$this->b}")->fetch_assoc()['conversion_id'];
        (new MysqlConversionRepository($this->conn))->softDelete($bConv, 1);
        self::assertSame(['lead' => 1, 'payout' => '5.00000'], $this->clickState(100));

        $this->edit($this->a, self::purchase(true));
        $this->engine->reevaluate(1, $this->a, null, true);
        $this->edit($this->a, self::purchase());
        $this->engine->reevaluate(1, $this->a, null, true);

        self::assertSame([$this->a . ':3:1@p1=5.00000', $this->b . ':1:1@u1=3.00000'], $this->liveOutcomes(100));
        self::assertSame(['lead' => 1, 'payout' => '5.00000'], $this->clickState(100), 'the operator\'s deletion stands');
        $row = $this->goalRows()['goal:' . $this->b . ':1:1:u1'];
        self::assertSame(1, $row['deleted']);
        self::assertNull($row['reason'], 'the engine never marked a row it did not retire');
    }

    /**
     * An outcome whose conversion link no longer names its own ledger row
     * is refused, whole transaction and all: reviving a row that records a
     * different outcome could pay the click for something it never reached.
     */
    public function testAnOutcomeLinkedToAnotherLedgerRowIsRefused(): void
    {
        $this->funnel();
        $this->edit($this->a, self::purchase(true));
        $this->engine->reevaluate(1, $this->a, null, true);
        $aConv = (int) self::$db->query("SELECT conversion_id FROM 202_goal_outcomes WHERE goal_id = {$this->a}")->fetch_assoc()['conversion_id'];
        self::fixture("UPDATE 202_goal_outcomes SET conversion_id = $aConv WHERE goal_id = {$this->b}");
        $before = [$this->ledger(100), $this->clickState(100)];

        $this->edit($this->a, self::purchase());
        try {
            $this->engine->reevaluate(1, $this->a, null, true);
            self::fail('a revival through a broken link was applied');
        } catch (GoalEngineException $e) {
            self::assertSame(GoalEngineException::INTEGRITY, $e->reason);
            self::assertStringContainsString('conversion ' . $aConv, $e->getMessage());
        }
        self::assertSame($before, [$this->ledger(100), $this->clickState(100)], 'nothing of the refused subject was written');
        self::assertSame([], $this->liveOutcomes(100));
    }

    /**
     * A goal row on a click linked to a customer carries a revenue event.
     * Deleting it voids that event; reviving it posts the amount again; a
     * second deletion voids it again. Each step compensates exactly once, so
     * the customer's totals follow the row through every retirement.
     */
    public function testTheCustomersRevenueFollowsTheRowThroughEachRetirementAndRevival(): void
    {
        foreach (['202_customers', '202_revenue_events', '202_revenue_line_items', '202_clicks_tracking'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        self::fixture("INSERT INTO 202_customers SET customer_id = 9, user_id = 1, primary_ref = 'c9', first_seen_time = 1, last_activity_time = 1, created_at = 1, updated_at = 1");
        self::fixture('INSERT INTO 202_clicks_tracking SET click_id = 100, c1_id = 0, c2_id = 0, c3_id = 0, c4_id = 0, customer_id = 9');
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->a = $this->goal(7, self::purchase());
        $this->ingest(100, [$this->event('p1', 'purchase', self::T + 1, ['amount' => 10])]);

        $customer = static fn (): array => array_map('strval', self::$db->query('SELECT order_count, total_revenue FROM 202_customers WHERE customer_id = 9')->fetch_row());
        $ledgerSum = static fn (): string => (string) self::$db->query('SELECT COALESCE(SUM(amount), 0) FROM 202_revenue_events WHERE customer_id = 9')->fetch_row()[0];
        self::assertSame(['1', '5.00000'], $customer());

        $this->edit($this->a, self::purchase(true));
        $this->engine->reevaluate(1, $this->a, null, true);
        self::assertSame(['0', '0.00000'], $customer(), 'retired: voided');

        $this->engine->reevaluate(1, $this->a, 1, true);
        self::assertSame(['1', '5.00000'], $customer(), 'revived: posted again');
        self::assertSame('5.00000', $ledgerSum());

        $this->engine->reevaluate(1, $this->a, 2, true);
        self::assertSame(['0', '0.00000'], $customer(), 'retired again: voided again, not skipped as a repeat of the first void');
        self::assertSame('0.00000', $ledgerSum());

        $this->engine->reevaluate(1, $this->a, 1, true);
        self::assertSame(['1', '5.00000'], $customer());
        self::assertSame('5.00000', $ledgerSum());
        self::assertSame(5, (int) self::$db->query('SELECT COUNT(*) FROM 202_revenue_events WHERE customer_id = 9')->fetch_row()[0],
            'purchase, void, reinstatement, void, reinstatement');

        // The reconcile job's own definition of an order agrees with the cache.
        $orders = (int) self::$db->query("SELECT SUM(CASE WHEN event_type IN ('purchase','renewal','one_time') THEN 1
                WHEN event_type = 'adjustment' AND external_ref LIKE 'void:%' THEN -1 ELSE 0 END) FROM 202_revenue_events WHERE customer_id = 9")->fetch_row()[0];
        self::assertSame(1, $orders);
    }
}
