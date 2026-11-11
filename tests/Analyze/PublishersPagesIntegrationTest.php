<?php

declare(strict_types=1);

namespace Tests\Analyze;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecordBuilder;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;

/**
 * Analyze › Publishers, its download, and the Publisher filter of the other
 * report pages, each run as the signed-in account against the scratch
 * database (tests/Setup/fixtures/page-runner.php), over clicks the click
 * writer and the rollup wrote.
 *
 * Dana's shoe link took two clicks (keyword "pubpage shoes") and one more
 * click of hers recorded no link; one click came on no publisher link
 * (keyword "pubpage hats").
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class PublishersPagesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;
    use \Tests\Ltv\GrantsLtvRole;

    private const OWNER = 990921;
    private const DANA = 990923;
    private const ROLE = 990921;
    private const FIRST_CLICK = 99092101;
    private const SHOE_LINK = 4200000021;

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var array<string, int> */
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        self::$db->query("SET SESSION sql_mode=''");
        self::seed();
        self::$db->query(self::$strict);
        self::$sessions = sys_get_temp_dir() . '/p202-publisher-pages-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
            self::$db->close();
            self::$db = null;
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('UPDATE 202_users_pref SET user_pref_publisher_id = NULL, user_pref_time_predefined = '
            . "'alltime' WHERE user_id = " . self::OWNER);
    }

    private static function cleanUp(): void
    {
        $users = self::OWNER . ', ' . self::DANA;
        self::revokeLtvRole(self::OWNER, self::ROLE);
        self::q("DELETE FROM 202_user_role WHERE user_id IN ($users)");
        foreach (['202_users', '202_users_pref', '202_aff_campaigns', '202_dataengine'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
        self::q("DELETE FROM 202_keywords WHERE keyword LIKE 'pubpage %'");
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 9);
        $clickTables = [
            '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_record',
            '202_clicks_site', '202_clicks_publisher', '202_click_latest_visits', '202_attribution_rollup_dirty_clicks',
            '202_google', '202_clicks_variable',
        ];
        foreach ($clickTables as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
    }

    private static function seed(): void
    {
        self::user(self::OWNER);
        self::user(self::DANA);
        self::row('202_users_pref', ['user_id' => self::OWNER]);
        self::q("UPDATE 202_users SET install_hash = 'pubpage-install', p202_customer_api_key = 'page-runner'"
            . ' WHERE user_id = ' . self::OWNER);
        self::q("UPDATE 202_users SET install_hash = 'pubpage-install', user_fname = 'Dana', user_lname = 'Reyes',"
            . " user_public_publisher_id = 'd4n4r3y3s0' WHERE user_id = " . self::DANA);
        // The owner may see campaign figures and grant publishers; Dana is a Publisher.
        self::grantLtvRole(self::OWNER, self::ROLE);
        $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = 'add_users'");
        $addUsers = $found->fetch_row()[0] ?? null;
        if ($addUsers === null) {
            $addUsers = self::insert("INSERT INTO 202_permissions SET permission_description = 'add_users'");
            self::$ltvAddedPermissions[] = $addUsers;
        }
        self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $addUsers]);
        self::q("INSERT IGNORE INTO 202_roles (role_id, role_name) VALUES (6, 'Publisher')");
        self::row('202_user_role', ['user_id' => self::DANA, 'role_id' => 6]);

        $shoes = self::insert('INSERT INTO 202_aff_campaigns SET user_id = ' . self::OWNER . ", aff_network_id = 0,"
            . " aff_campaign_name = 'Running Shoes', aff_campaign_url = 'https://shop.example/', aff_campaign_payout = 1,"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 0');
        self::$ids = [
            'shoes' => $shoes,
            'kwShoes' => self::insert("INSERT INTO 202_keywords SET keyword = 'pubpage shoes'"),
            'kwHats' => self::insert("INSERT INTO 202_keywords SET keyword = 'pubpage hats'"),
        ];
        // [publisher or 0, the link's public id or 0, keyword]
        $clicks = [
            [self::DANA, self::SHOE_LINK, self::$ids['kwShoes']],
            [self::DANA, self::SHOE_LINK, self::$ids['kwShoes']],
            [self::DANA, 0, self::$ids['kwShoes']],
            [0, 0, self::$ids['kwHats']],
        ];
        $conn = new Connection(self::$db);
        $repo = new MysqlClickRepository($conn);
        foreach ($clicks as $n => [$publisher, $link, $keyword]) {
            $repo->recordClick(ClickRecordBuilder::fromLegacyArray([
                'user_id' => (string) self::OWNER,
                'aff_campaign_id' => (string) $shoes,
                'keyword_id' => (string) $keyword,
                'click_time' => (string) (time() - 3600 + $n),
                'click_cpc' => '0.25',
                'publisher_user_id' => (string) $publisher,
                'publisher_tracker_id_public' => (string) $link,
            ], self::FIRST_CLICK + $n));
            ClickRollupSql::rollUpClick($conn, self::FIRST_CLICK + $n);
        }
    }

    /** @param array<string, mixed> $get */
    private static function page(string $page, array $get = []): string
    {
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::OWNER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => '{}',
        ] + getenv();
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                dirname(__DIR__) . '/Setup/fixtures/page-runner.php', $page,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        self::assertNotSame(2, $code, "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, $err);
        self::assertStringNotContainsString('Warning', $err, 'the page warned: ' . $err);

        return $out;
    }

    /** The cells of each row of the first table in $html. @return list<list<string>> */
    private static function tableRows(string $html): array
    {
        self::assertSame(1, preg_match('~<table.*?</table>~s', $html, $table), 'the page has a table');
        preg_match_all('~<tr[^>]*>(.*?)</tr>~s', $table[0], $rows);

        return array_map(static function (string $row): array {
            preg_match_all('~<t[hd][^>]*>(.*?)</t[hd]>~s', $row, $cells);

            return array_map(
                static fn (string $cell): string => trim((string) preg_replace(
                    '/\s+/',
                    ' ',
                    html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                )),
                $cells[1]
            );
        }, $rows[1]);
    }

    public function testThePageListsEachPublishersLinksAndCampaigns(): void
    {
        $html = self::page('tracking202/analyze/publishers.php');
        $rows = self::tableRows($html);
        $byLabel = array_column($rows, null, 0);

        self::assertSame(['Dana Reyes'], $rows[1], 'the publisher heads its rows');
        self::assertSame(['2', '0', '0'], array_slice($byLabel['Link ' . self::SHOE_LINK . ' · Running Shoes'], 1, 3));
        self::assertSame('1', $byLabel['Link not recorded · Running Shoes'][1]);
        self::assertSame('3', $byLabel['All of Dana Reyes'][1]);
        self::assertSame('1', $byLabel['Clicks on no publisher link'][1]);
        self::assertSame('4', $byLabel['Totals for report'][1]);
        self::assertSame('$1.00', $byLabel['Totals for report'][6], 'four clicks at 25 cents');
        self::assertStringContainsString('did not record their link', $html);
        self::assertStringContainsString('publishers_download.php', $html);
    }

    public function testTheDownloadHasTheSameRows(): void
    {
        $lines = explode("\n", trim(self::page('tracking202/analyze/publishers_download.php')));

        self::assertSame([
            "Publisher\tLink\tCampaign\tClicks\tClick Throughs\tLeads\tConv Rate\tIncome\tCost\tNet\tROI",
            "Dana Reyes\t" . self::SHOE_LINK . "\tRunning Shoes\t2\t0\t0\t0.00\t0.00\t0.50\t-0.50\t-100.00",
            "Dana Reyes\tLink not recorded\tRunning Shoes\t1\t0\t0\t0.00\t0.00\t0.25\t-0.25\t-100.00",
            "No publisher\t\t\t1\t0\t0\t0.00\t0.00\t0.25\t-0.25\t-100.00",
        ], $lines);
    }

    public function testAnAnalyzeReportOffersThePublisherFilterAndNarrowsToIt(): void
    {
        $all = self::page('tracking202/analyze/keywords.php', ['range' => 'alltime', 'publisher_id' => '']);
        self::assertStringContainsString('pubpage hats', $all);
        self::assertStringContainsString('pubpage shoes', $all);
        self::assertMatchesRegularExpression('~<select[^>]*name="publisher_id"~', $all, 'the bar offers the filter');
        self::assertStringContainsString('>Dana Reyes</option>', $all);

        $dana = self::page('tracking202/analyze/keywords.php', ['range' => 'alltime', 'publisher_id' => (string) self::DANA]);
        self::assertStringContainsString('pubpage shoes', $dana);
        self::assertStringNotContainsString('pubpage hats', $dana, 'a click on no publisher link is left out');
        $shoes = array_column(self::tableRows($dana), null, 0)['pubpage shoes'] ?? null;
        self::assertNotNull($shoes);
        self::assertSame('3', $shoes[1]);
        self::assertMatchesRegularExpression(
            '~<option value="' . self::DANA . '"[^>]*selected[^>]*>Dana Reyes</option>~',
            $dana,
            'the stored filter is shown selected'
        );
    }

    public function testTheVisitorsListAppliesTheStoredPublisherFilter(): void
    {
        $all = self::page('tracking202/ajax/click_history.php');
        self::assertStringContainsString('pubpage hats', $all);
        self::assertStringContainsString('pubpage shoes', $all);

        self::q('UPDATE 202_users_pref SET user_pref_publisher_id = ' . self::DANA . ' WHERE user_id = ' . self::OWNER);
        $dana = self::page('tracking202/ajax/click_history.php');
        self::assertStringContainsString('pubpage shoes', $dana);
        self::assertStringNotContainsString('pubpage hats', $dana);
    }

    /** @return iterable<string, array{string}> */
    public static function overviewPages(): iterable
    {
        $pages = ['overview/breakdown.php', 'overview/day-parting.php', 'overview/group-overview.php',
            'visitors/index.php', 'spy/index.php'];
        foreach ($pages as $page) {
            yield $page => ['tracking202/' . $page];
        }
    }

    /** @dataProvider overviewPages */
    public function testTheOverviewFamilyOffersAndStoresThePublisherFilter(string $page): void
    {
        $html = self::page($page, ['publisher_id' => (string) self::DANA]);

        self::assertMatchesRegularExpression(
            '~<option value="' . self::DANA . '"[^>]*selected[^>]*>Dana Reyes</option>~',
            $html,
            'the filter is offered and shown as set'
        );
        $stored = self::$db->query('SELECT user_pref_publisher_id FROM 202_users_pref WHERE user_id = ' . self::OWNER);
        self::assertSame((string) self::DANA, (string) $stored->fetch_row()[0]);
    }

    public function testAnAccountWithNoPublishersIsNotOfferedTheFilter(): void
    {
        self::q('UPDATE 202_users SET user_active = 0 WHERE user_id = ' . self::DANA);
        try {
            $html = self::page('tracking202/analyze/keywords.php', ['range' => 'alltime', 'publisher_id' => '']);
        } finally {
            self::q('UPDATE 202_users SET user_active = 1 WHERE user_id = ' . self::DANA);
        }

        self::assertStringNotContainsString('name="publisher_id"', $html);
    }
}
