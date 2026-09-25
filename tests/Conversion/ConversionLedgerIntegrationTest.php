<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\ReversalException;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Conversion\RevenueUploadImporter;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;

/**
 * The conversion ledger against a real MySQL/MariaDB: every path that sets
 * a click's value, with the value read back from 202_clicks and explained
 * by the rows. The calculator's rules are unit-tested in
 * ClickValueCalculatorTest; this is the proof that the writer applies them
 * to the real tables, under the real keys, in one transaction.
 *
 * Skips unless P202_TEST_DB_HOST (and friends) name a scratch database.
 *
 * @group integration
 */
final class ConversionLedgerIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;
    private MysqlConversionRepository $repo;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        // The ledger runs under strict mode in production; so does this.
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        foreach (['202_conversion_logs', '202_clicks', '202_clicks_spy', '202_aff_campaigns', '202_attribution_pending', '202_conversion_uploads'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $this->repo = new MysqlConversionRepository(new Connection(self::$db));
    }

    /** Fixture rows omit columns the tracking code fills; only the writer runs strict. */
    private static function fixture(string $sql): void
    {
        self::$db->query("SET SESSION sql_mode=''");
        try {
            if (self::$db->query($sql) !== true) {
                throw new \RuntimeException('fixture failed: ' . self::$db->error);
            }
        } finally {
            self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        }
    }

    private function campaign(int $id, string $mode = 'replace', string $payout = '10.00'): void
    {
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=$id, user_id=1, aff_network_id=1, aff_campaign_name='c$id',
            aff_campaign_url='http://x', aff_campaign_payout=$payout, aff_campaign_time=1, aff_campaign_foreign_payout=$payout, payout_mode='$mode'");
    }

    private function click(int $id, int $campaign, string $payout = '10.00', int $lead = 0): void
    {
        foreach (['202_clicks', '202_clicks_spy'] as $t) {
            self::fixture("INSERT INTO $t SET click_id=$id, user_id=1, aff_campaign_id=$campaign, click_payout=$payout, click_cpc=0, click_lead=$lead, click_time=1700000000");
        }
    }

    /** @return array{lead: int, payout: string, spy_payout: string} */
    private function clickState(int $id): array
    {
        $c = self::$db->query("SELECT click_lead, click_payout FROM 202_clicks WHERE click_id=$id")->fetch_assoc();
        $s = self::$db->query("SELECT click_payout FROM 202_clicks_spy WHERE click_id=$id")->fetch_assoc();

        return ['lead' => (int) $c['click_lead'], 'payout' => (string) $c['click_payout'], 'spy_payout' => (string) $s['click_payout']];
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $clickId): array
    {
        $r = self::$db->query("SELECT conv_id, click_payout, source, dedupe_key, superseded_by, superseded_reason, deleted, reverses_conv_id, source_ref
            FROM 202_conversion_logs WHERE click_id=$clickId ORDER BY conv_id");

        return $r->fetch_all(MYSQLI_ASSOC);
    }

    private function record(int $clickId, array $data): array
    {
        return $this->repo->record(1, ['click_id' => $clickId, 'source' => 'postback'] + $data);
    }

    public function testReplaceKeepsTheLatestAndSaysWhatItReplaced(): void
    {
        $this->campaign(7);
        $this->click(100, 7);

        $a = $this->record(100, ['payout' => '5', 'transaction_id' => 'A']);
        $b = $this->record(100, ['payout' => '10', 'transaction_id' => 'B']);

        self::assertSame(['lead' => 1, 'payout' => '10.00000', 'spy_payout' => '10.00'], $this->clickState(100));
        $rows = $this->rows(100);
        self::assertSame((string) $b['convId'], (string) $rows[0]['superseded_by']);
        self::assertSame('replace', $rows[0]['superseded_reason']);
        self::assertNull($rows[1]['superseded_reason']);
        self::assertSame(['tx:A', 'tx:B'], array_column($rows, 'dedupe_key'));

        // Deleting the winner restores the one it replaced.
        $this->repo->softDelete((int) $b['convId'], 1);
        self::assertSame('5.00000', $this->clickState(100)['payout']);
        self::assertNull($this->rows(100)[0]['superseded_reason'], 'no longer superseded');

        // Deleting the last one leaves a click that is no longer a lead.
        $this->repo->softDelete((int) $a['convId'], 1);
        self::assertSame(0, $this->clickState(100)['lead']);
    }

    public function testAReplayIsADuplicateAndChangesNothing(): void
    {
        $this->campaign(7);
        $this->click(100, 7);

        $first = $this->record(100, ['payout' => '5', 'transaction_id' => 'A']);
        $again = $this->record(100, ['payout' => '99', 'transaction_id' => 'A']);

        self::assertTrue($again['duplicate']);
        self::assertSame($first['convId'], $again['convId']);
        self::assertCount(1, $this->rows(100));
        self::assertSame('5.00000', $this->clickState(100)['payout']);
    }

    public function testAccumulateAddsAndAPlainConversionHappensOnce(): void
    {
        $this->campaign(8, 'accumulate', '4.00');
        $this->click(200, 8, '4.00');

        $this->record(200, ['payout' => '5', 'transaction_id' => 'A']);
        $this->record(200, ['payout' => '3', 'transaction_id' => 'B']);
        self::assertSame('8.00000', $this->clickState(200)['payout']);

        // No id of its own: the campaign's one plain conversion, worth the
        // campaign's payout (the click's cached value is a running total).
        $plain = $this->record(200, []);
        self::assertFalse($plain['duplicate']);
        self::assertSame('12.00000', $this->clickState(200)['payout']);
        $retry = $this->record(200, []);
        self::assertTrue($retry['duplicate'], 'an id-less retry must not double the money');
        self::assertSame('12.00000', $this->clickState(200)['payout']);
        self::assertSame('conversion', $this->rows(200)[2]['dedupe_key']);
    }

    public function testAReversalNetsItsSaleOnceAndSaysWhichSale(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $sale = $this->record(100, ['payout' => '3', 'transaction_id' => 'S-1']);

        $rev = $this->record(100, ['transaction_id' => 'S-1', 'reversal' => true]);
        self::assertFalse($rev['duplicate']);
        self::assertSame(['lead' => 1, 'payout' => '0.00000', 'spy_payout' => '0.00'], $this->clickState(100));
        $row = $this->rows(100)[1];
        self::assertSame('-3.00000', $row['click_payout']);
        self::assertSame((string) $sale['convId'], (string) $row['reverses_conv_id']);
        self::assertSame('conv:' . $sale['convId'], $row['source_ref']);
        self::assertSame('rev:' . $sale['convId'] . ':1', $row['dedupe_key']);

        self::assertTrue($this->record(100, ['transaction_id' => 'S-1', 'reversal' => true])['duplicate'], 'a replayed reversal');

        try {
            $this->record(100, ['transaction_id' => 'S-1', 'reversal' => true, 'reversal_ref' => 'R-2']);
            self::fail('a second, different reversal of one sale was accepted');
        } catch (ReversalException $e) {
            self::assertSame(ReversalException::CONFLICT, $e->kind);
            self::assertStringContainsString('already reversed by conversion ' . $rev['convId'], $e->getMessage());
        }

        try {
            $this->record(100, ['transaction_id' => 'NOPE', 'reversal' => true]);
            self::fail('a reversal of an unknown sale was accepted');
        } catch (ReversalException $e) {
            self::assertSame(ReversalException::NO_TARGET, $e->kind);
            self::assertStringContainsString('"NOPE"', $e->getMessage());
        }
        self::assertCount(2, $this->rows(100), 'refused reversals write nothing');
    }

    public function testANegativeAmountForAKnownSaleIsAPartialReversal(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->record(100, ['payout' => '10', 'transaction_id' => 'S-1']);
        $this->record(100, ['payout' => '-4', 'transaction_id' => 'S-1']);

        self::assertSame('6.00000', $this->clickState(100)['payout']);
    }

    public function testAPreLedgerClickKeepsItsValueAsABaselineRow(): void
    {
        $this->campaign(7);
        $this->click(100, 7, '20.00', 1); // converted before the upgrade: $20 cached
        self::fixture("INSERT INTO 202_conversion_logs SET click_id=100, transaction_id=NULL, campaign_id=7, click_payout=12, user_id=1,
            click_time=1, conv_time=1, time_difference='', ip='', pixel_type=2, user_agent='', deleted=0,
            source='postback', dedupe_key='row:1', superseded_reason='pre_ledger'");

        // Replace: a new conversion replaces the cached $20, as it always did.
        $this->record(100, ['payout' => '5', 'transaction_id' => 'N']);
        self::assertSame('5.00000', $this->clickState(100)['payout']);
        $rows = $this->rows(100);
        self::assertSame('legacy_baseline', $rows[1]['source']);
        self::assertSame('20.00000', $rows[1]['click_payout']);
        self::assertSame((string) $rows[1]['conv_id'], (string) $rows[0]['superseded_by'], 'the old row points at the value that stands for it');

        // And the baseline is inserted once.
        $this->record(100, ['payout' => '6', 'transaction_id' => 'M']);
        self::assertCount(1, array_filter($this->rows(100), static fn (array $r): bool => $r['source'] === 'legacy_baseline'));
    }

    public function testReversingAPreLedgerSaleNetsTheCarriedValue(): void
    {
        $this->campaign(7);
        $this->click(100, 7, '12.00', 1);
        self::fixture("INSERT INTO 202_conversion_logs SET click_id=100, transaction_id='OLD-1', campaign_id=7, click_payout=12, user_id=1,
            click_time=1, conv_time=1, time_difference='', ip='', pixel_type=2, user_agent='', deleted=0,
            source='postback', dedupe_key='tx:OLD-1', superseded_reason='pre_ledger'");

        $this->record(100, ['transaction_id' => 'OLD-1', 'reversal' => true]);
        self::assertSame(['lead' => 1, 'payout' => '0.00000', 'spy_payout' => '0.00'], $this->clickState(100));
    }

    public function testAPreLedgerClickInAnAccumulatingCampaignAddsToItsOldValue(): void
    {
        $this->campaign(8, 'accumulate');
        $this->click(200, 8, '20.00', 1);
        $this->record(200, ['payout' => '5', 'transaction_id' => 'N']);
        self::assertSame('25.00000', $this->clickState(200)['payout']);
    }

    public function testAConversionClearedBeforeTheUpgradeDoesNotComeBack(): void
    {
        $this->campaign(7);
        $this->click(100, 7, '10.00', 0); // cleared: not a lead, old row still there
        self::fixture("INSERT INTO 202_conversion_logs SET click_id=100, campaign_id=7, click_payout=50, user_id=1,
            click_time=1, conv_time=1, time_difference='', ip='', pixel_type=2, user_agent='', deleted=0,
            source='postback', dedupe_key='row:1', superseded_reason='pre_ledger'");

        $this->record(100, ['payout' => '5', 'transaction_id' => 'N']);
        self::assertSame('5.00000', $this->clickState(100)['payout'], 'the $50 cleared before the upgrade stays cleared');
        self::assertCount(0, array_filter($this->rows(100), static fn (array $r): bool => $r['source'] === 'legacy_baseline'));
    }

    public function testAnUploadIsSummedPerFileAndTheNextFileReplacesIt(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->click(101, 7);
        $this->record(100, ['payout' => '9', 'transaction_id' => 'P']); // a postback before the upload

        $importer = new RevenueUploadImporter(new Connection(self::$db), $this->repo);
        $csv = static function (string $text) {
            $h = fopen('php://memory', 'r+');
            fwrite($h, $text);
            rewind($h);
            return $h;
        };

        $first = $importer->import(1, 'a.csv', $csv("subid,amount\n100,$1.00\n100,2.00\n101,4\n999,5\n101,oops\n"), 0, 1);
        self::assertSame(3, $first['recorded']);
        self::assertSame(2, $first['skipped']);
        self::assertSame(['100' => '3.00000', '101' => '4.00000'], array_map('strval', $first['totals']));
        self::assertSame('3.00000', $this->clickState(100)['payout'], 'the file replaces the postback, summed within the file');
        self::assertSame('4.00000', $this->clickState(101)['payout']);
        $reasons = array_column(array_filter($first['lines'], static fn ($l) => $l['status'] === 'skipped'), 'reason', 'line');
        self::assertSame([5 => 'no click with this subid in your account', 6 => 'the commission is not a number'], $reasons);

        $second = $importer->import(1, 'b.csv', $csv("subid,amount\n100,5\n"), 0, 1);
        self::assertSame(1, $second['recorded']);
        self::assertSame('5.00000', $this->clickState(100)['payout'], 'the newer file replaces the older one');
        self::assertSame('4.00000', $this->clickState(101)['payout'], 'a click the newer file does not name keeps its value');
        $batchRows = array_filter($this->rows(100), static fn (array $r): bool => $r['source'] === 'revenue_upload');
        self::assertSame(['batch', 'batch', null], array_column(array_values($batchRows), 'superseded_reason'));

        // Re-applying the same file is a duplicate of every line.
        $again = $importer->import(1, 'b.csv', $csv("subid,amount\n100,5\n"), 0, 1);
        self::assertSame('5.00000', $this->clickState(100)['payout']);
        self::assertSame(1, $again['recorded'], 'a new batch: its own lines, its own keys');
    }

    public function testClearingASubidDeletesItsRowsAndItsLead(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->click(101, 7, '20.00', 1); // a pre-ledger lead with no rows
        $this->record(100, ['payout' => '5', 'transaction_id' => 'A']);

        self::assertSame(2, $this->repo->clearClicks(1, [100, 101, 999]));

        self::assertSame(0, $this->clickState(100)['lead']);
        self::assertSame(0, $this->clickState(101)['lead']);
        self::assertSame(['1'], array_values(array_unique(array_column($this->rows(100), 'deleted'))));
        self::assertSame('legacy_baseline', $this->rows(101)[0]['source'], 'the pre-ledger value is recorded, then cleared');
        self::assertSame('1', (string) $this->rows(101)[0]['deleted']);
    }

    public function testEveryCountedChangeIsQueuedForAttributionInTheSameTransaction(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $a = $this->record(100, ['payout' => '5', 'transaction_id' => 'A']);
        $b = $this->record(100, ['payout' => '10', 'transaction_id' => 'B']);

        $pending = self::$db->query('SELECT conv_id, reason, enqueue_seq FROM 202_attribution_pending ORDER BY conv_id')->fetch_all(MYSQLI_ASSOC);
        self::assertSame([(string) $a['convId'], (string) $b['convId']], array_column($pending, 'conv_id'));
        self::assertSame('counted_state', $pending[0]['reason'], 'A was queued again when B superseded it');
        self::assertSame('2', (string) $pending[0]['enqueue_seq']);
    }

    public function testAFailedInsertLeavesNeitherARowNorAChangedClick(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        try {
            // Strict mode refuses an event name longer than its column; the
            // whole transaction (row, click, outbox) must roll back.
            $this->record(100, ['payout' => '5', 'transaction_id' => 'A', 'event_name' => str_repeat('e', 300)]);
            self::fail('an over-long event name was stored');
        } catch (\Throwable) {
            self::addToAssertionCount(1);
        }
        self::assertSame([], $this->rows(100));
        self::assertSame(0, $this->clickState(100)['lead']);
        self::assertSame('0', (string) self::$db->query('SELECT COUNT(*) AS n FROM 202_attribution_pending')->fetch_assoc()['n']);
    }
}
