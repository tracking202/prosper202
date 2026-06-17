<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * End-to-end verification of the conversion-recording guarantees against a REAL
 * MySQL/MariaDB database (the unit tests use fakes and cannot exercise the
 * insert_id success path, the UNIQUE (click_id, transaction_id) backstop, or
 * MySQL's "multiple NULLs do not collide" behaviour).
 *
 * Skips automatically unless a test database is configured via env:
 *   P202_TEST_DB_HOST, P202_TEST_DB_PORT, P202_TEST_DB_USER,
 *   P202_TEST_DB_PASS, P202_TEST_DB_NAME
 *
 * @group integration
 */
final class ConversionIdempotencyIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return; // no DB configured; individual tests will skip
        }

        // SchemaInstaller and the static helpers expect a global _mysqli_query()
        // and a DataEngine class to exist. Provide minimal stand-ins so we can
        // drive the real production code paths without bootstrapping connect2.php.
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
        }

        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';

        // Match production error mode (connect2.php) so behaviour is realistic.
        mysqli_report(MYSQLI_REPORT_STRICT);

        $port = (int) (getenv('P202_TEST_DB_PORT') ?: 3306);
        $db = @mysqli_connect(
            $host,
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            $port
        );
        if (!$db) {
            return; // connection failed; tests will skip
        }

        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db) {
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        // Clean slate for each test.
        self::$db->query('TRUNCATE TABLE 202_conversion_logs');
        self::$db->query('TRUNCATE TABLE 202_clicks');
        self::$db->query('TRUNCATE TABLE 202_clicks_spy');
    }

    private function insertClick(int $clickId, float $payout = 10.0, int $campaignId = 7): void
    {
        $db = self::$db;
        $db->query("INSERT INTO 202_clicks SET click_id=$clickId, user_id=1, aff_campaign_id=$campaignId, click_payout=$payout, click_cpc=0, click_lead=0, click_time=1700000000");
        $db->query("INSERT INTO 202_clicks_spy SET click_id=$clickId, user_id=1, aff_campaign_id=$campaignId, click_payout=$payout, click_cpc=0, click_lead=0, click_time=1700000000");
    }

    private function log(int $clickId, float $payout = 10.0): array
    {
        return [
            'click_id'        => $clickId,
            'campaign_id'     => '7',
            'user_id'         => '1',
            'click_time'      => 1700000000,
            'conv_time'       => 1700000100,
            'time_difference' => '0 days, 0 hours, 1 min and 40 sec',
            'ip'              => '203.0.113.9',
            'pixel_type'      => 3,
            'user_agent'      => 'IntegrationTest/1.0',
            'click_payout'    => (string) $payout,
        ];
    }

    private function conversionCount(int $clickId): int
    {
        $res = self::$db->query("SELECT COUNT(*) AS c FROM 202_conversion_logs WHERE click_id=$clickId");
        return (int) $res->fetch_assoc()['c'];
    }

    public function testSuccessfulConversionReturnsRealConvIdAndFlagsClick(): void
    {
        $this->insertClick(1000);

        $result = p202RecordConversion(self::$db, $this->log(1000), '', true, '10.0', 'ORD-1');

        self::assertFalse($result['duplicate']);
        self::assertGreaterThan(0, $result['conv_id'], 'A real conv_id must be returned from insert_id');
        self::assertSame(1, $this->conversionCount(1000));

        $click = self::$db->query('SELECT click_lead FROM 202_clicks WHERE click_id=1000')->fetch_assoc();
        self::assertSame('1', (string) $click['click_lead'], 'The source click must be flagged converted');
    }

    public function testReplayWithSameTransactionIdDoesNotDoubleCount(): void
    {
        $this->insertClick(1000);

        $first = p202RecordConversion(self::$db, $this->log(1000), '', true, '10.0', 'ORD-DUP');
        $second = p202RecordConversion(self::$db, $this->log(1000), '', true, '10.0', 'ORD-DUP');

        self::assertFalse($first['duplicate']);
        self::assertTrue($second['duplicate'], 'A retried postback with the same order id is a duplicate');
        self::assertSame($first['conv_id'], $second['conv_id'], 'The existing conversion is returned, not a new one');
        self::assertSame(1, $this->conversionCount(1000), 'Only one conversion row may exist for a repeated order id');
    }

    public function testEmptyTransactionIdAllowsRepeatConversions(): void
    {
        $this->insertClick(1001);

        $first = p202RecordConversion(self::$db, $this->log(1001), '', true, '10.0', '');
        $second = p202RecordConversion(self::$db, $this->log(1001), '', true, '10.0', '');

        self::assertFalse($first['duplicate']);
        self::assertFalse($second['duplicate']);
        self::assertNotSame($first['conv_id'], $second['conv_id']);
        self::assertSame(2, $this->conversionCount(1001), 'No order id means no dedup; both conversions are recorded');

        $res = self::$db->query("SELECT COUNT(*) AS c FROM 202_conversion_logs WHERE click_id=1001 AND transaction_id IS NULL");
        self::assertSame(2, (int) $res->fetch_assoc()['c'], 'Empty transaction ids must be stored as NULL');
    }

    public function testUniqueKeyRejectsDuplicateTransactionIdAtDbLevel(): void
    {
        $this->insertClick(1002);
        $db = self::$db;

        $ok = $db->query("INSERT INTO 202_conversion_logs SET click_id=1002, transaction_id='X1', campaign_id=7, click_payout=1, user_id=1, click_time=1, conv_time=1, time_difference='', ip='', pixel_type=1, user_agent='', deleted=0");
        self::assertTrue($ok);

        // Second insert with the same (click_id, transaction_id) must violate the
        // UNIQUE backstop even if application-level dedup were bypassed. Depending
        // on the mysqli_report mode this surfaces either as a false return or a
        // thrown exception — both must carry ER_DUP_ENTRY (1062).
        try {
            $dup = $db->query("INSERT INTO 202_conversion_logs SET click_id=1002, transaction_id='X1', campaign_id=7, click_payout=1, user_id=1, click_time=1, conv_time=1, time_difference='', ip='', pixel_type=1, user_agent='', deleted=0");
            self::assertFalse($dup, 'The UNIQUE (click_id, transaction_id) key must reject a duplicate');
            self::assertSame(1062, $db->errno, 'MySQL ER_DUP_ENTRY expected');
        } catch (\mysqli_sql_exception $e) {
            self::assertSame(1062, $e->getCode(), 'MySQL ER_DUP_ENTRY expected');
        }
    }

    public function testMissingSourceClickWritesNoOrphanConversion(): void
    {
        // No click inserted for 999999.
        $result = p202RecordConversion(self::$db, $this->log(999999), '', true, '10.0', 'ORD-X');

        self::assertSame(0, $result['conv_id']);
        self::assertFalse($result['duplicate']);
        self::assertSame(0, $this->conversionCount(999999), 'No conversion may be recorded for a non-existent click');
    }
}
