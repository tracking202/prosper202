<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\LtvController;
use Api\V3\Controllers\RotatorsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * `truncated` on the paged v3 answers, run as SQL on a real server: the
 * LIMIT is bound, and only the server says how many rows a page of it holds
 * (CLAUDE.md #9). PagedAnswersSayWhetherRowsRemainTest holds every route
 * taking offset to PageRows; this runs one of each family through it -- the
 * CRUD base's list (GET /campaigns and every Setup list), GET /ltv/breakdown
 * (grouped rows a repository reads), GET /ltv/customers and GET /ltv/products
 * (rows beside a count, rows with none), GET /rotators (a list of its own) --
 * each with three rows: a page with one after it, a page of exactly the rows
 * that remain, and the last page.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 8701 and 8702's rows.
 *
 * @group integration
 */
final class PagedAnswersIntegrationTest extends TestCase
{
    private const USER = 8701;
    private const OTHER = 8702;

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
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
        // The rows leave the columns the reads never name to their zero.
        self::seed();
        $db->query("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
    }

    private static function q(string $sql): void
    {
        if (self::$db->query($sql) === false) {
            throw new \RuntimeException(self::$db->error . ' in ' . $sql);
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            foreach (['202_aff_campaigns', '202_clicks', '202_customers', '202_products', '202_rotators', '202_users'] as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = $u");
            }
        }
    }

    private static function seed(): void
    {
        self::cleanUp();
        foreach ([self::USER, self::OTHER] as $u) {
            self::q("INSERT INTO 202_users SET user_id = $u, user_name = 'pa$u', user_email = 'pa$u@example.com',"
                . " user_dash_email = '', user_pass = 'x', user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = ''");
        }
        $now = time() - 3600;
        // Three of USER's own of each; OTHER's rows are never on USER's pages.
        foreach ([self::USER => 870100, self::OTHER => 870200] as $u => $base) {
            for ($i = 1; $i <= 3; $i++) {
                $campaign = $base + $i;
                self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $campaign, user_id = $u, aff_network_id = 0,"
                    . " aff_campaign_name = 'pa campaign $i', aff_campaign_url = 'https://example.com/', aff_campaign_payout = 1,"
                    . " aff_campaign_time = $now, aff_campaign_foreign_payout = 0");
                self::q("INSERT INTO 202_clicks SET click_id = $campaign, user_id = $u, aff_campaign_id = $campaign, click_time = $now");
                self::q("INSERT INTO 202_customers SET user_id = $u, primary_ref = 'pa-$campaign', first_click_id = $campaign,"
                    . " total_revenue = " . (10 * $i) . ", order_count = 1, first_seen_time = $now, last_activity_time = $now,"
                    . " created_at = $now, updated_at = $now");
                self::q("INSERT INTO 202_products SET user_id = $u, external_product_id = 'pa-$campaign', name = 'p$i',"
                    . " created_at = $now, updated_at = $now");
                self::q("INSERT INTO 202_rotators SET public_id = $campaign, user_id = $u, name = 'pa rotator $i'");
            }
        }
    }

    /**
     * Each family's read, as the route calls it, answering [rows, truncated].
     *
     * @return iterable<string, array{0: callable(array<string, string>): array{0: list<array<string, mixed>>, 1: mixed}}>
     */
    public static function families(): iterable
    {
        $answer = static fn (array $a): array => [$a['data'], $a['truncated'] ?? 'absent'];
        yield 'GET /campaigns (the CRUD base)' => [static fn (array $p): array => $answer((new CampaignsController(self::$db, self::USER))->list($p))];
        yield 'GET /ltv/breakdown' => [static fn (array $p): array => $answer((new LtvController(self::$db, self::USER))->breakdown($p + ['by' => 'campaign']))];
        yield 'GET /ltv/customers' => [static fn (array $p): array => $answer((new LtvController(self::$db, self::USER))->customers($p))];
        yield 'GET /ltv/products' => [static fn (array $p): array => $answer((new LtvController(self::$db, self::USER))->products($p))];
        yield 'GET /rotators' => [static fn (array $p): array => $answer((new RotatorsController(self::$db, self::USER))->list($p))];
    }

    /** @dataProvider families */
    public function testTruncatedSaysWhetherRowsRemainAfterThePage(callable $read): void
    {
        [$rows, $truncated] = $read(['limit' => '2']);
        self::assertCount(2, $rows, 'the page holds the limit, not the row read past it');
        self::assertTrue($truncated, 'a third row remains');

        [$rest, $truncated] = $read(['limit' => '2', 'offset' => '2']);
        self::assertCount(1, $rest);
        self::assertFalse($truncated, 'the last page');

        [$all, $truncated] = $read(['limit' => '3']);
        self::assertCount(3, $all);
        self::assertFalse($truncated, 'a page of exactly the rows that remain');

        // The pages are the whole of the rows, each once, and only USER's.
        $key = static fn (array $r): string => json_encode($r, JSON_THROW_ON_ERROR);
        self::assertSame(array_map($key, $all), array_map($key, [...$rows, ...$rest]));
    }

    /** The CRUD base hands out a cursor exactly when rows remain. */
    public function testTheListCursorFollowsTruncated(): void
    {
        $list = new CampaignsController(self::$db, self::USER);
        $first = $list->list(['limit' => '2']);
        self::assertTrue($first['truncated']);
        self::assertIsString($first['pagination']['cursor']);
        self::assertSame(3, $first['pagination']['total']);
        $next = $list->list(['cursor' => $first['pagination']['cursor']]);
        self::assertFalse($next['truncated']);
        self::assertNull($next['pagination']['cursor']);
        self::assertCount(1, $next['data']);
    }

    /** The answers that had no paging keys now name the page they answer. */
    public function testTheLtvGroupedAnswersNameTheirPage(): void
    {
        $answer = (new LtvController(self::$db, self::USER))->breakdown(['by' => 'campaign', 'limit' => '1', 'offset' => '1']);
        self::assertSame([true, 1, 1], [$answer['truncated'], $answer['limit'], $answer['offset']]);
        self::assertSame('pa campaign 2', $answer['data'][0]['name'], 'ordered by revenue, highest first');
    }
}
