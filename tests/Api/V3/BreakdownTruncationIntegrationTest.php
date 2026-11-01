<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * GET /reports/breakdown's `truncated`, run as SQL on a real server: the
 * LIMIT is bound, and only the server says how many grouped rows a page of
 * it answers (CLAUDE.md #9). 51 keywords, so the default page of 50 has one
 * row after it; the last eleven tie on clicks, so paging also crosses rows
 * only the id orders.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 6101 and 6102's rows.
 *
 * @group integration
 */
final class BreakdownTruncationIntegrationTest extends TestCase
{
    private const USER = 6101;
    private const OTHER = 6102;
    private const KEYWORDS = 51;

    private const STRICT = "SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,"
        . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'";

    private static ?\mysqli $db = null;

    /** @var list<int> */
    private static array $keywordIds = [];

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
        $db->query(self::STRICT);
        self::$db = $db;
        self::seed();
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
        foreach (self::$keywordIds as $id) {
            self::$db->query('DELETE FROM 202_keywords WHERE keyword_id = ' . $id);
        }
        foreach ([self::USER, self::OTHER] as $u) {
            self::$db->query("DELETE FROM 202_dataengine WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_users WHERE user_id = $u");
        }
        self::$keywordIds = [];
    }

    private static function seed(): void
    {
        self::cleanUp();
        self::q('INSERT INTO 202_users SET user_id = ' . self::USER . ", user_name = 'bt6101',"
            . " user_email = 'bt6101@example.com', user_dash_email = '', user_pass = 'x',"
            . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = ''");

        $now = time() - 3600;
        $click = 0;
        $row = static function (int $user, int $keywordId, int $clicks) use (&$click, $now): void {
            $click++;
            self::q('INSERT INTO 202_dataengine SET user_id = ' . $user . ', click_id = ' . (610100 + $click)
                . ", click_time = $now, ppc_account_id = 1, keyword_id = $keywordId, clicks = $clicks,"
                . ' landing_page_id = 0, ip_id = 0, click_referer_site_url_id = 0, device_id = 0, c1_id = 0,'
                . ' utm_source_id = 0, rotator_id = 0, rule_id = 0,'
                . ' click_out = 1, leads = 0, click_lead = 0, click_filtered = 0, click_bot = 0,'
                . ' payout = 0, income = 0, cost = 0');
        };
        for ($i = 0; $i < self::KEYWORDS; $i++) {
            self::q('INSERT INTO 202_keywords SET keyword = ' . "'bt keyword " . sprintf('%02d', $i) . "'");
            $id = (int) self::$db->insert_id;
            self::$keywordIds[] = $id;
            // Keywords 40..50 tie at one click.
            $row(self::USER, $id, max(1, 100 - $i * 2 - ($i >= 40 ? 100 : 0)));
        }
        // Another account's clicks on more keywords: never in USER's pages.
        foreach (array_slice(self::$keywordIds, 0, 10) as $id) {
            $row(self::OTHER, $id, 1000);
        }
    }

    /** @return array<string, mixed> */
    private function page(array $params): array
    {
        return (new ReportsController(self::$db, self::USER))->breakdown(['breakdown' => 'keyword'] + $params);
    }

    public function testTheDefaultPageSaysOneKeywordRemains(): void
    {
        $page = $this->page([]);

        self::assertCount(50, $page['data']);
        self::assertTrue($page['truncated']);
        self::assertSame(50, $page['limit']);
        self::assertSame(0, $page['offset']);
        $names = array_column($page['data'], 'name');
        self::assertNotContains('bt keyword 50', $names, 'the last of the tied keywords, by id, is the one left out');
    }

    public function testAPageOfExactlyTheRowsThereAreIsNotTruncated(): void
    {
        foreach (['51', '500'] as $limit) {
            $page = $this->page(['limit' => $limit]);

            self::assertCount(self::KEYWORDS, $page['data'], "limit $limit");
            self::assertFalse($page['truncated'], "limit $limit");
            self::assertSame((int) $limit, $page['limit']);
        }
    }

    public function testPagingUntilNotTruncatedReadsEveryKeywordOnce(): void
    {
        // 51 = 3 × 17: the last page is full, and only `truncated` says it is the last.
        $names = [];
        $flags = [];
        $offset = 0;
        do {
            $page = $this->page(['limit' => '17', 'offset' => (string) $offset]);
            self::assertSame($offset, $page['offset']);
            $names = [...$names, ...array_column($page['data'], 'name')];
            $flags[] = [count($page['data']), $page['truncated']];
            self::assertLessThan(10, count($flags), 'paging never ended');
            $offset += 17;
        } while ($page['truncated']);

        self::assertSame([[17, true], [17, true], [17, false]], $flags);
        self::assertCount(self::KEYWORDS, $names);
        self::assertSame($names, array_values(array_unique($names)), 'no keyword on two pages');
        $after = $this->page(['limit' => '17', 'offset' => '51']);
        self::assertSame([], $after['data']);
        self::assertFalse($after['truncated']);
    }

    public function testASortedPageIsCutAfterItsOwnOrder(): void
    {
        $page = $this->page(['limit' => '3', 'sort' => 'total_clicks', 'sort_dir' => 'ASC']);

        self::assertTrue($page['truncated']);
        self::assertSame(['bt keyword 40', 'bt keyword 41', 'bt keyword 42'], array_column($page['data'], 'name'));
        self::assertSame([1, 1, 1], array_column($page['data'], 'total_clicks'));
    }
}
