<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\PartitionInstaller;
use Prosper202\DataEngine\ClickRollupSql;

/**
 * The rollup of one click, run on a real server: the server accepts the
 * click_id key hint, the row lands, and 202_clicks is read through the key.
 *
 * On MariaDB 10.11 a small, partitioned 202_clicks (a new install's: the web
 * installer partitions it by week) was scanned (EXPLAIN type ALL), and the
 * scan's shared locks deadlocked this rollup with a concurrent click's
 * insert, which was rolled back and lost (errno 1213). Unpartitioned, or on
 * MySQL 8.0, the key was used unasked, so on CI's server the plan assertion
 * holds with or without the hint; run it on MariaDB to see it fail without one.
 *
 * @group integration
 */
final class SingleClickRollupIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 990091;
    private const CLICK = 99009101;
    private const NEXT_CLICK = 99009102;

    /** Whether the installer partitions on this server (MariaDB does; MySQL 8 lists no partition plugin). */
    private static bool $partitions = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        // The partitions the web installer cuts; SchemaInstaller alone does not.
        $installer = new PartitionInstaller(self::$db);
        $installer->install();
        self::$partitions = $installer->isPartitioningSupported();
        self::q(self::$strict);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        self::user(self::USER);
        foreach ([self::CLICK, self::NEXT_CLICK] as $click) {
            self::row('202_clicks', [
                'click_id' => $click, 'user_id' => self::USER, 'aff_campaign_id' => 0, 'ppc_account_id' => 0,
                'landing_page_id' => 0, 'click_cpc' => 0.25, 'click_payout' => 0, 'click_time' => time() - 3600,
            ]);
            self::row('202_clicks_record', ['click_id' => $click, 'click_id_public' => $click]);
        }
    }

    private static function cleanUp(): void
    {
        $clicks = self::CLICK . ', ' . self::NEXT_CLICK;
        foreach (['202_dataengine', '202_clicks', '202_clicks_record', '202_click_latest_visits'] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
    }

    public function testTheRollupOfOneClickWritesItsRowAndOnlyIts(): void
    {
        foreach (ClickRollupSql::forClick('202_dataengine', self::CLICK) as $sql) {
            self::q($sql);
        }

        $result = self::$db->query(
            'SELECT click_id, user_id, clicks, cost FROM 202_dataengine WHERE click_id IN ('
            . self::CLICK . ', ' . self::NEXT_CLICK . ')'
        );
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $rows, 'one report row, for the click rolled up');
        self::assertSame((string) self::CLICK, (string) $rows[0]['click_id']);
        self::assertSame((string) self::USER, (string) $rows[0]['user_id']);
        self::assertSame('1', (string) $rows[0]['clicks']);
        self::assertEqualsWithDelta(0.25, (float) $rows[0]['cost'], 0.00001);
    }

    public function testTheServerReadsTheClickThroughItsKey(): void
    {
        $partitions = self::$db->query(
            "SELECT COUNT(*) FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = '202_clicks' AND PARTITION_NAME IS NOT NULL"
        );
        self::assertInstanceOf(\mysqli_result::class, $partitions, self::$db->error);
        $count = (int) $partitions->fetch_row()[0];
        if (self::$partitions) {
            self::assertGreaterThan(1, $count, '202_clicks is partitioned, as the installer leaves it here');
        } else {
            self::assertSame(0, $count, '202_clicks is not partitioned, as the installer leaves it here');
        }

        // A recorded latest visit, so the removal's plan reaches 202_clicks:
        // with none, it reads no row of it.
        self::q('INSERT INTO 202_click_latest_visits (click_id, click_time) SELECT click_id, click_time'
            . ' FROM 202_clicks WHERE click_id = ' . self::CLICK);
        foreach (ClickRollupSql::forClick('202_dataengine', self::CLICK) as $step => $sql) {
            $result = self::$db->query('EXPLAIN ' . $sql);
            self::assertInstanceOf(\mysqli_result::class, $result, $step . ': ' . self::$db->error);
            $clicks = array_values(array_filter(
                $result->fetch_all(MYSQLI_ASSOC),
                static fn (array $row): bool => ($row['table'] ?? null) === '2c'
            ));

            self::assertCount(1, $clicks, $step . ': the plan has one row for 202_clicks');
            $plan = $step . ': the plan for 202_clicks: ' . json_encode($clicks[0]);
            self::assertContains($clicks[0]['type'], ['ref', 'const', 'eq_ref'], $plan);
            self::assertSame('click_id', $clicks[0]['key'], $plan);
        }
    }
}
