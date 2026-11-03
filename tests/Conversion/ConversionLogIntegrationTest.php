<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\ConversionLog;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;

/**
 * The conversion log's reads against a real MySQL/MariaDB: one account's
 * rows only, each filter, and the pages.
 *
 * Skips unless P202_TEST_DB_HOST (and friends) name a scratch database.
 *
 * @group integration
 */
final class ConversionLogIntegrationTest extends TestCase
{
    private const MINE = 1;
    private const OTHER = 2;
    private const DAY = 1767225600; // 2026-01-01 00:00 UTC

    private static ?\mysqli $db = null;
    private ConversionLog $log;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) '
                . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
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
        foreach (['202_conversion_logs', '202_aff_campaigns'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $this->log = new ConversionLog(new Connection(self::$db));

        $this->campaign(10, self::MINE, 'Spring sale store');
        $this->campaign(11, self::MINE, 'CRM free trial');
        $this->campaign(12, self::MINE, 'Old quote form', 1);
        $this->campaign(20, self::OTHER, 'Other account demo requests');

        // conv, click, user, campaign, source, pixel_type, conv and click offsets from DAY,
        // deleted, txid, payout, reverses
        $this->conversion(1, 500, self::MINE, 10, 'postback', 2, 3600, 600, 0, 'ORD-1001', '49.00');
        $this->conversion(2, 500, self::MINE, 10, 'postback', 2, 7200, 600, 0, 'ORD-1002', '19.00');
        $this->conversion(3, 501, self::MINE, 11, 'universal_pixel', 3, 86400 * 2, 86400 + 60, 1, null, '5.00');
        $this->conversion(4, 502, self::MINE, 11, 'api', 4, 86400 * 3, 86400 * 3 - 30, 0, 'ORD-1001', '-49.00', 1);
        // Another account's rows, one of them on the same click id and campaign id space.
        $this->conversion(5, 500, self::OTHER, 20, 'postback', 2, 3600, 600, 0, 'ORD-9', '75.00');
        $this->conversion(6, 600, self::OTHER, 20, 'pixel', 1, 86400 * 2, 600, 1, null, '12.00');
        // Mine, naming another account's campaign: counted, but its name is not read.
        $this->conversion(7, 503, self::MINE, 20, 'pixel', 1, 86400 * 4, 86400 * 4 - 5, 0, null, '3.00');
    }

    private static function exec(string $sql): void
    {
        if (self::$db->query($sql) !== true) {
            throw new \RuntimeException('fixture failed: ' . self::$db->error);
        }
    }

    private function campaign(int $id, int $user, string $name, int $deleted = 0): void
    {
        self::exec(sprintf(
            "INSERT INTO 202_aff_campaigns SET aff_campaign_id=%d, user_id=%d, aff_network_id=1, aff_campaign_name='%s',
             aff_campaign_url='https://shop.example.com/', aff_campaign_payout=10, aff_campaign_time=1,
             aff_campaign_deleted=%d",
            $id,
            $user,
            self::$db->real_escape_string($name),
            $deleted
        ));
    }

    private function conversion(
        int $convId,
        int $clickId,
        int $user,
        int $campaign,
        string $source,
        int $pixelType,
        int $convOffset,
        int $clickOffset,
        int $deleted,
        ?string $txid,
        string $payout,
        ?int $reverses = null
    ): void {
        $ip = ['192.0.2.' . $convId, '198.51.100.' . $convId, '203.0.113.' . $convId][$convId % 3];
        self::exec(sprintf(
            "INSERT INTO 202_conversion_logs SET conv_id=%d, click_id=%d, user_id=%d, campaign_id=%d,
             source='%s', pixel_type=%d,
             conv_time=%d, click_time=%d, deleted=%d, transaction_id=%s, click_payout=%s, reverses_conv_id=%s,
             time_difference='', ip='%s', user_agent='Mozilla/5.0 (demo)', dedupe_key='row:%d',
             match_method='click_id'",
            $convId,
            $clickId,
            $user,
            $campaign,
            $source,
            $pixelType,
            self::DAY + $convOffset,
            self::DAY + $clickOffset,
            $deleted,
            $txid === null ? 'NULL' : "'" . $txid . "'",
            $payout,
            $reverses === null ? 'NULL' : (string) $reverses,
            $ip,
            $convId
        ));
    }

    /** @param array<string, mixed> $filters */
    private function ids(int $user, array $filters = [], int $page = 0): array
    {
        $result = $this->log->page($user, $filters + [
            'campaign_id' => null, 'click_id' => null, 'from' => null, 'to' => null,
            'time_basis' => 'conversion', 'type' => null, 'deleted' => null,
        ], $page);

        return array_column($result['rows'], 'conv_id');
    }

    public function testOnlyTheAccountsOwnRowsNewestFirstDeletedIncluded(): void
    {
        self::assertSame([7, 4, 3, 2, 1], $this->ids(self::MINE));
        self::assertSame([6, 5], $this->ids(self::OTHER));
    }

    public function testAnotherAccountsCampaignNameIsNotRead(): void
    {
        $rows = $this->log->page(self::MINE, ['campaign_id' => null, 'click_id' => null, 'from' => null, 'to' => null,
            'time_basis' => 'conversion', 'type' => null, 'deleted' => null], 0)['rows'];
        $byId = array_column($rows, null, 'conv_id');

        self::assertNull($byId[7]['campaign_name']);
        self::assertSame('Spring sale store', $byId[1]['campaign_name']);
    }

    public function testRowsCarryTheLogColumns(): void
    {
        $rows = $this->log->page(self::MINE, ['campaign_id' => null, 'click_id' => 502, 'from' => null, 'to' => null,
            'time_basis' => 'conversion', 'type' => null, 'deleted' => null], 0)['rows'];

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(502, $row['click_id']);
        self::assertSame(self::DAY + 86400 * 3 - 30, $row['click_time']);
        self::assertSame(self::DAY + 86400 * 3, $row['conv_time']);
        self::assertSame('30s', $row['elapsed']);
        self::assertSame('198.51.100.4', $row['ip']);
        self::assertSame('API', $row['type']);
        self::assertSame('ORD-1001', $row['transaction_id']);
        self::assertSame(-49.0, (float) $row['amount']);
        self::assertSame(1, $row['reverses_conv_id']);
        self::assertFalse($row['deleted']);
        self::assertSame('Mozilla/5.0 (demo)', $row['user_agent']);
    }

    public function testAClickWithSeveralConversionsListsEach(): void
    {
        self::assertSame([2, 1], $this->ids(self::MINE, ['click_id' => 500]));
        // The other account's conversion on the same click id is not among them.
        self::assertSame([5], $this->ids(self::OTHER, ['click_id' => 500]));
    }

    public function testCampaignFilterIsScopedToTheAccount(): void
    {
        self::assertSame([4, 3], $this->ids(self::MINE, ['campaign_id' => 11]));
        // Naming another account's campaign lists only this account's rows on that id.
        self::assertSame([7], $this->ids(self::MINE, ['campaign_id' => 20]));
        self::assertSame([], $this->ids(self::OTHER, ['campaign_id' => 10]));
    }

    public function testTypeAndDeletedFilters(): void
    {
        self::assertSame([2, 1], $this->ids(self::MINE, ['type' => 'postback']));
        self::assertSame([3], $this->ids(self::MINE, ['deleted' => 'yes']));
        self::assertSame([7, 4, 2, 1], $this->ids(self::MINE, ['deleted' => 'no']));
    }

    public function testDateRangeAppliesToConversionOrClickTime(): void
    {
        // Day 2 (UTC): conversion 3 converted then, from a click on day 1.
        $day2 = ['from' => self::DAY + 86400 * 2, 'to' => self::DAY + 86400 * 3 - 1];
        self::assertSame([3], $this->ids(self::MINE, $day2));
        self::assertSame([4], $this->ids(self::MINE, $day2 + ['time_basis' => 'click']));
        $day1 = ['from' => self::DAY + 86400, 'to' => self::DAY + 86400 * 2 - 1];
        self::assertSame([], $this->ids(self::MINE, $day1));
        self::assertSame([3], $this->ids(self::MINE, $day1 + ['time_basis' => 'click']));
    }

    public function testPagesAndTotal(): void
    {
        self::exec('DELETE FROM 202_conversion_logs');
        for ($i = 1; $i <= ConversionLog::PER_PAGE + 3; $i++) {
            $this->conversion($i, 700 + $i, self::MINE, 10, 'postback', 2, $i * 60, 0, 0, 'ORD-' . $i, '1.00');
        }
        $this->conversion(900, 999, self::OTHER, 20, 'postback', 2, 60, 0, 0, 'ORD-X', '1.00');
        $filters = ['campaign_id' => null, 'click_id' => null, 'from' => null, 'to' => null,
            'time_basis' => 'conversion', 'type' => null, 'deleted' => null];

        $first = $this->log->page(self::MINE, $filters, 0);
        $second = $this->log->page(self::MINE, $filters, 1);

        self::assertSame(ConversionLog::PER_PAGE + 3, $first['total']);
        self::assertCount(ConversionLog::PER_PAGE, $first['rows']);
        self::assertSame([3, 2, 1], array_column($second['rows'], 'conv_id'));
        self::assertSame(ConversionLog::PER_PAGE + 3, $second['total']);
    }

    public function testTheCampaignListIsTheAccountsLiveCampaigns(): void
    {
        self::assertSame([11 => 'CRM free trial', 10 => 'Spring sale store'], $this->log->campaigns(self::MINE));
        self::assertSame([20 => 'Other account demo requests'], $this->log->campaigns(self::OTHER));
    }

    public function testNoAccountIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ids(0);
    }
}
