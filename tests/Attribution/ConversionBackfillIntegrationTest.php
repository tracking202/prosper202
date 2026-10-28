<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\AttributionWorker;
use Prosper202\Attribution\ConversionBackfill;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * Conversions recorded before the upgrade reach multi-touch attribution
 * (Codex P1 on PR #157): the upgrade marks the clicks it found and the
 * worker brings each pre-upgrade lead click in as the legacy baseline the
 * ledger would carry in on its first write, queued `backfill`, then credits
 * it like any conversion. Before, nothing queued them, and MTA reported
 * nothing for any range before the upgrade.
 *
 * The pre-upgrade state is what _upgrade_conversion_ledger() leaves: lead
 * clicks with their cached payout and pre_ledger rows (or none at all).
 *
 * @group integration
 */
final class ConversionBackfillIntegrationTest extends TestCase
{
    use AttributionDatabase;

    private const T = 1_700_000_000;

    /** A click as the pre-ledger code left it: a lead with a cached value, and its old rows marked pre_ledger. */
    private function preLedgerClick(int $id, string $value, int $rows, bool $lead = true): void
    {
        $this->click($id, 7, self::T + $id);
        foreach (['202_clicks', '202_clicks_spy'] as $t) {
            self::fixture("UPDATE $t SET click_lead = " . ($lead ? 1 : 0) . ", click_payout = $value WHERE click_id = $id");
        }
        for ($i = 1; $i <= $rows; $i++) {
            self::fixture("INSERT INTO 202_conversion_logs SET click_id=$id, transaction_id='OLD-$id-$i', campaign_id=7, click_payout=$value,
                user_id=1, click_time=" . (self::T + $id) . ', conv_time=' . (self::T + $id + 60) . ", time_difference='', ip='', pixel_type=2,
                user_agent='', deleted=0, source='postback', payable=1, superseded_reason='pre_ledger', dedupe_key='tx:OLD-$id-$i'");
        }
        $this->visit($id, self::T + $id, self::cookie('v' . $id));
    }

    /** @return list<array{conv_id: int, click_id: int, source: string}> */
    private static function baselines(): array
    {
        $rows = self::all("SELECT conv_id, click_id, source FROM 202_conversion_logs WHERE source = 'legacy_baseline' ORDER BY click_id");

        return array_map(
            static fn (array $r): array => ['conv_id' => (int) $r['conv_id'], 'click_id' => (int) $r['click_id'], 'source' => (string) $r['source']],
            $rows
        );
    }

    protected function tearDown(): void
    {
        self::$db?->query('TRUNCATE TABLE 202_attribution_backfill');
    }

    public function testTheWorkerBringsEveryPreUpgradeLeadIntoAttributionOnce(): void
    {
        self::$db->query('TRUNCATE TABLE 202_attribution_backfill');
        $this->campaign(7);
        $this->preLedgerClick(100, '12.50000', 1);   // a lead with its old row
        $this->preLedgerClick(101, '3.00000', 0);    // a lead whose writes left no row
        $this->preLedgerClick(102, '0.00000', 1, false); // cleared before the upgrade: nothing to credit
        $this->preLedgerClick(103, '5.00000', 1);    // converted again after the upgrade (below)
        $this->preLedgerClick(12_345, '2.00000', 1); // a few chunks further on

        // The upgrade: the marker only.
        self::assertSame(true, self::$db->query(ConversionBackfill::MARK_SQL));
        self::assertSame(true, self::$db->query(ConversionBackfill::MARK_SQL), 'a re-run of the step');
        $backfill = new ConversionBackfill($this->conn);
        self::assertSame(['next_click_id' => 0, 'through_click_id' => 12_345], array_intersect_key((array) $backfill->state(), ['next_click_id' => 1, 'through_click_id' => 1]));
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_pending'), 'the upgrade itself queues nothing');

        // After the upgrade: one conversion by the new code on 103 (which
        // carries 103's baseline in itself and queues it), and a click newer
        // than the marker, which the walk never reaches.
        $this->convert(103, '1.00', 'NEW-1');
        $this->preLedgerClick(20_000, '9.00000', 1);

        $progress = (new AttributionReports($this->conn))->queue(1)['backfill'];
        self::assertSame([true, 0], [$progress['in_progress'], $progress['percent']], 'the Attribution page and API say it is under way');

        $report = AttributionWorker::runExclusive($this->conn, 30);
        self::assertNotNull($report);
        $did = array_intersect_key((array) $report->backfill, ['clicks' => 1, 'baselines' => 1, 'finished' => 1]);
        self::assertSame(
            ['clicks' => 4, 'baselines' => 3, 'finished' => true],
            $did,
            '100, 101, 103 and 12345 are pre-upgrade leads; 103 was already carried in by its new conversion'
        );
        self::assertStringContainsString('pre-upgrade backfill: 4 lead click(s) examined, 3 queued, finished', $report->summary());

        self::assertSame([100, 101, 103, 12_345], array_column(self::baselines(), 'click_id'), 'one baseline per pre-upgrade lead, none for the cleared click or the newer one');
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_pending'), 'and the same run credited every one');
        $model = $this->defaultModelId();
        foreach (self::baselines() as $b) {
            // 103's baseline was replaced by its new sale (campaign 7 is replace mode): the sale is credited instead.
            $credited = $b['click_id'] === 103 ? (int) self::scalar("SELECT conv_id FROM 202_conversion_logs WHERE transaction_id = 'NEW-1'") : $b['conv_id'];
            self::assertNotSame([], self::credits($credited, $model), 'click ' . $b['click_id'] . ' has credits');
        }
        self::assertSame('18.50000', self::revenueUnder($model), '12.50 + 3 + 1 (103\'s new sale replaced its old value) + 2');

        // Done: nothing more, and the reports stop saying so.
        self::assertNull((new ConversionBackfill($this->conn))->progress());
        self::assertNull((new AttributionReports($this->conn))->queue(1)['backfill']);
        self::assertNull(AttributionWorker::runExclusive($this->conn, 30)?->backfill);

        // A walk interrupted after its work and before its cursor moved runs
        // the same chunks again and adds nothing.
        self::$db->query('UPDATE 202_attribution_backfill SET next_click_id = 0, finished_at = NULL');
        $again = AttributionWorker::runExclusive($this->conn, 30);
        self::assertSame(0, $again?->backfill['baselines']);
        self::assertCount(4, self::baselines());
        self::assertSame('18.50000', self::revenueUnder($model));
    }

    public function testAFreshInstallHasNothingToBackfill(): void
    {
        self::$db->query('TRUNCATE TABLE 202_attribution_backfill');
        $this->campaign(7);
        $this->click(100, 7, self::T);
        $this->convert(100, '4.00', 'A-1');
        self::assertNull((new ConversionBackfill($this->conn))->state());
        self::assertNull(AttributionWorker::runExclusive($this->conn, 30)?->backfill);
        self::assertNull((new AttributionReports($this->conn))->queue(1)['backfill']);
    }
}
