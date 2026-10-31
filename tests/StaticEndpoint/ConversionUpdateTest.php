<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * Tests for p202ApplyConversionClickSide() in static-endpoint-helpers.php:
 * the part of a conversion that touches the click but is not its value.
 *
 * Ported from the tests of p202ApplyConversionUpdate(), which also set
 * click_lead and click_payout. Those two are now derived from the click's
 * ledger rows by MysqlConversionLedger::recompute(), so the assertions that
 * this function set them are inverted: it must not (ClickValueWritersTest
 * holds the same line for the whole tree).
 */
final class ConversionUpdateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine {
                public function setDirtyHour($click_id) {}
                public function getSummary($s,$e,$p,$u=1,$up=false,$n=false) { return ""; }
            }');
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
    }

    public function testUpdatesClicksAndSpyTables(): void
    {
        $db = new FakeMysqliConnection();

        self::assertTrue(p202ApplyConversionClickSide($db, 100, '25.00'));

        self::assertCount(1, $db->statementsContaining('UPDATE 202_clicks SET'));
        self::assertCount(1, $db->statementsContaining('UPDATE 202_clicks_spy SET'));
    }

    public function testNeverWritesTheLeadFlagOrThePayout(): void
    {
        $db = new FakeMysqliConnection();

        p202ApplyConversionClickSide($db, 100, '25.00');

        foreach ($db->statements as $stmt) {
            self::assertStringNotContainsString('click_lead', $stmt->sql);
            self::assertStringNotContainsString('click_payout', $stmt->sql);
        }
    }

    public function testSetsCpaValueInClickCpcBoundNotInterpolated(): void
    {
        $db = new FakeMysqliConnection();

        p202ApplyConversionClickSide($db, 100, '25.50');

        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            $stmts = $db->statementsContaining('UPDATE ' . $table . ' SET click_cpc = ?, click_filtered = 0 WHERE click_id = ?');
            self::assertCount(1, $stmts, $table);
            self::assertSame('si', $stmts[0]->boundTypes);
            self::assertSame(['25.50', 100], $stmts[0]->boundValues);
        }
    }

    public function testEmptyCpaOnlyClearsTheFilteredFlag(): void
    {
        $db = new FakeMysqliConnection();

        p202ApplyConversionClickSide($db, 100, '');

        self::assertCount(0, $db->statementsContaining('click_cpc'));
        $stmts = $db->statementsContaining('UPDATE 202_clicks SET click_filtered = 0 WHERE click_id = ?');
        self::assertCount(1, $stmts);
        self::assertSame([100], $stmts[0]->boundValues);
    }

    public function testANonNumericCpaIsNotWrittenAsCost(): void
    {
        $db = new FakeMysqliConnection();

        p202ApplyConversionClickSide($db, 100, "25'; DROP TABLE 202_clicks; --");

        self::assertCount(0, $db->statementsContaining('click_cpc'), 'a CPA that is not a number is not a cost');
    }

    public function testAFailedClicksUpdateIsReported(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsExecuteReturns('UPDATE 202_clicks SET', false);

        self::assertFalse(
            p202ApplyConversionClickSide($db, 100, '10.00'),
            'the caller (p202RecordConversion) rolls the conversion back on false'
        );
    }

    public function testAFailedSpyUpdateIsNotFatal(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsExecuteReturns('UPDATE 202_clicks_spy SET', false);

        self::assertTrue(p202ApplyConversionClickSide($db, 100, '10.00'), 'the spy copy is best-effort');
    }
}
