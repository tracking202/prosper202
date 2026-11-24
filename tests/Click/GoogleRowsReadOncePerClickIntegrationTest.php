<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecordBuilder;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\ParallelTrackingClick;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;
use Prosper202\Rotator\RecordedLink;

/**
 * 202_google's key is (click_id, gclid), so a click can have two rows. Each
 * reader of a click's gclid and UTM values reads them one per click
 * (AdClickIdColumns::sql() and utm()): the report rollup, the traffic source
 * pixels' tokens, gpb.php's and upx.php's read, the tracker URL's tokens, the
 * rotator's recorded link and parallel tracking's lookup. A click with two
 * rows gives one row and, for each value, the greatest of its rows'; a
 * click with one row gives that row's values, as the joins did.
 * (GoogleRowsAreNotJoinedTest refuses the join.)
 *
 * @group integration
 */
final class GoogleRowsReadOncePerClickIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990551;
    private const ONE = 99055101;
    private const TWO = 99055102;
    private const LANDING_PAGE = 990552;
    private const IP = 990553;

    private const SOURCE_ALPHA = 99055001;
    private const SOURCE_BETA = 99055002;
    private const MEDIUM = 99055011;
    private const CAMPAIGN = 99055021;

    private const CLICK_TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_variable',
        '202_google', '202_clicks_rotator', '202_clicks_record', '202_clicks_site', '202_click_latest_visits',
        '202_dataengine', '202_clicks_ad_ids',
    ];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {}'
                . ' public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
        self::cleanUp();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        foreach (self::CLICK_TABLES as $table) {
            self::q("DELETE FROM $table WHERE click_id IN (" . self::ONE . ', ' . self::TWO . ')');
        }
        self::q('DELETE FROM 202_utm_source WHERE utm_source_id IN (' . self::SOURCE_ALPHA . ', ' . self::SOURCE_BETA . ')');
        self::q('DELETE FROM 202_utm_medium WHERE utm_medium_id = ' . self::MEDIUM);
        self::q('DELETE FROM 202_utm_campaign WHERE utm_campaign_id = ' . self::CAMPAIGN);
    }

    private static function seed(): void
    {
        self::q('INSERT INTO 202_utm_source (utm_source_id, utm_source) VALUES (' . self::SOURCE_ALPHA . ", 'alpha'),"
            . ' (' . self::SOURCE_BETA . ", 'beta')");
        self::q('INSERT INTO 202_utm_medium (utm_medium_id, utm_medium) VALUES (' . self::MEDIUM . ", 'cpc')");
        self::q('INSERT INTO 202_utm_campaign (utm_campaign_id, utm_campaign) VALUES (' . self::CAMPAIGN . ", 'spring')");
        $repository = new MysqlClickRepository(new Connection(self::requireScratch()));
        foreach ([self::ONE => 'G-ONE', self::TWO => 'G-TWO'] as $clickId => $gclid) {
            $repository->recordClick(ClickRecordBuilder::fromLegacyArray([
                'user_id' => (string) self::USER,
                'click_time' => (string) time(),
                'click_cpc' => '0.10000',
                'landing_page_id' => (string) self::LANDING_PAGE,
                'ip_id' => (string) self::IP,
                'click_id_public' => (string) ($clickId + 1000),
                'gclid' => $gclid,
                'utm_source_id' => (string) self::SOURCE_ALPHA,
                'utm_medium_id' => (string) self::MEDIUM,
            ], $clickId));
        }
        // No writer makes a second row today; the key allows one.
        self::q('INSERT INTO 202_google SET click_id = ' . self::TWO . ", gclid = '', gbraid = '', wbraid = '',"
            . " ttclid = '', msclkid = '', fbclid = '', oppref = '', utm_source_id = " . self::SOURCE_BETA . ','
            . ' utm_medium_id = 0, utm_campaign_id = ' . self::CAMPAIGN . ', utm_term_id = 0, utm_content_id = 0');
        $rows = self::$db->query('SELECT click_id, COUNT(*) AS n FROM 202_google WHERE click_id IN ('
            . self::ONE . ', ' . self::TWO . ') GROUP BY click_id ORDER BY click_id');
        self::assertInstanceOf(\mysqli_result::class, $rows, self::$db->error);
        self::assertSame(
            [(string) self::ONE => '1', (string) self::TWO => '2'],
            array_column($rows->fetch_all(MYSQLI_ASSOC), 'n', 'click_id'),
            'the seed: one row, and two'
        );
    }

    /** @return array<int, array<string, string>> click => gclid and UTM values each reader should give */
    private static function expected(): array
    {
        return [
            self::ONE => ['gclid' => 'G-ONE', 'utm_source' => 'alpha', 'utm_medium' => 'cpc', 'utm_campaign' => '',
                'utm_term' => '', 'utm_content' => ''],
            // Each value the greatest of the rows': the second row's source and campaign, the first's medium.
            self::TWO => ['gclid' => 'G-TWO', 'utm_source' => 'beta', 'utm_medium' => 'cpc', 'utm_campaign' => 'spring',
                'utm_term' => '', 'utm_content' => ''],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private static function values(array $row): array
    {
        $values = [];
        foreach (array_keys(self::expected()[self::ONE]) as $name) {
            $values[$name] = (string) ($row[$name] ?? '');
        }

        return $values;
    }

    public function testTheRollupWritesOneRowWithEachUtmIdOfTheClick(): void
    {
        $conn = new Connection(self::requireScratch());
        $expected = [
            self::ONE => [(string) self::SOURCE_ALPHA, (string) self::MEDIUM, '0'],
            self::TWO => [(string) self::SOURCE_BETA, (string) self::MEDIUM, (string) self::CAMPAIGN],
        ];
        foreach ($expected as $clickId => $ids) {
            ClickRollupSql::rollUpClick($conn, $clickId);
            $rows = self::$db->query("SELECT utm_source_id, utm_medium_id, utm_campaign_id, clicks FROM 202_dataengine WHERE click_id = $clickId");
            self::assertInstanceOf(\mysqli_result::class, $rows, self::$db->error);
            $rows = $rows->fetch_all(MYSQLI_NUM);
            self::assertSame([[...$ids, '1']], $rows, "click $clickId: one row, one click");
        }
        // The range form, as the hourly job and a rebuild run it.
        self::q('DELETE FROM 202_dataengine WHERE click_id IN (' . self::ONE . ', ' . self::TWO . ')');
        $range = ClickRollupSql::insertSelect('202_dataengine', '2c.click_id IN (' . self::ONE . ', ' . self::TWO . ')');
        self::assertTrue(self::$db->query($range), self::$db->error);
        $rows = self::$db->query('SELECT click_id, utm_source_id, utm_medium_id, utm_campaign_id FROM 202_dataengine'
            . ' WHERE click_id IN (' . self::ONE . ', ' . self::TWO . ') ORDER BY click_id');
        self::assertInstanceOf(\mysqli_result::class, $rows, self::$db->error);
        self::assertSame(
            [[(string) self::ONE, ...$expected[self::ONE]], [(string) self::TWO, ...$expected[self::TWO]]],
            $rows->fetch_all(MYSQLI_NUM)
        );
    }

    public function testThePixelTokensNameEachValueOfTheClick(): void
    {
        foreach (self::expected() as $clickId => $values) {
            $click = TrafficSourcePixels::clickTokens(new Connection(self::requireScratch()), self::USER, $clickId);
            self::assertNotNull($click);
            self::assertSame($values, self::values($click['tokens']), "click $clickId");
        }
    }

    public function testThePostbackReadIsOneRowWithEachValueOfTheClick(): void
    {
        foreach (self::expected() as $clickId => $values) {
            $sql = p202PostbackClickSql($clickId);
            // Without its LIMIT, as many rows as the click has visits: one.
            $result = self::requireScratch()->query(preg_replace('/\s+LIMIT 1\z/', '', $sql));
            self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            self::assertCount(1, $rows, "click $clickId");
            self::assertSame($values, self::values($rows[0]), "click $clickId");
        }
    }

    public function testTheRecordedLinkNamesEachUtmValueOfTheClick(): void
    {
        foreach (self::expected() as $clickId => $values) {
            $link = RecordedLink::read(new Connection(self::requireScratch()), $clickId, self::USER);
            self::assertNotNull($link);
            unset($values['gclid']);
            self::assertSame($values, array_intersect_key($link['query'], $values), "click $clickId");
        }
    }

    public function testTheTrackerUrlTokensNameEachValueOfTheClick(): void
    {
        self::requireScratch();
        self::liftTrackerPlaceholders();
        $url = 'https://offer.example/?g=[[gclid]]&s=[[utm_source]]&m=[[utm_medium]]&c=[[utm_campaign]]&t=[[utm_term]]';
        foreach (self::expected() as $clickId => $values) {
            self::assertSame(
                'https://offer.example/?g=' . $values['gclid'] . '&s=' . $values['utm_source'] . '&m='
                    . $values['utm_medium'] . '&c=' . $values['utm_campaign'] . '&t=',
                replaceTrackerPlaceholders(self::$db, $url, (string) $clickId, self::USER),
                "click $clickId"
            );
        }
    }

    public function testParallelTrackingFindsTheClickByEitherRowsGclidOnly(): void
    {
        $conn = new Connection(self::requireScratch());
        $found = ParallelTrackingClick::find($conn, self::USER, self::LANDING_PAGE, 'G-TWO', self::IP, time());
        self::assertNotNull($found);
        self::assertSame(self::TWO, $found->clickId);
        self::assertSame(
            self::ONE,
            ParallelTrackingClick::find($conn, self::USER, self::LANDING_PAGE, 'G-ONE', self::IP, time())?->clickId
        );
        self::assertNull(ParallelTrackingClick::find($conn, self::USER, self::LANDING_PAGE, 'G-NONE', self::IP, time()));
    }

    /** The Visitors download, run as the signed-in account (tests/Setup/fixtures/page-runner.php). */
    public function testTheVisitorsDownloadListsEachClickOnceWithEachValue(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before the page ran.');
        }
        self::requireScratch();
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        self::assertInstanceOf(\mysqli_result::class, $found, self::$db->error);
        $versions = array_column($found->fetch_all(MYSQLI_ASSOC), 'version');
        $sessions = sys_get_temp_dir() . '/p202-page-runner-' . getmypid() . '-google-rows';
        self::assertTrue(is_dir($sessions) || mkdir($sessions, 0700), "could not create $sessions");
        $u = self::USER;
        try {
            self::q('DELETE FROM 202_version');
            self::q("INSERT INTO 202_version SET version = '" . PROSPER202_VERSION . "'");
            self::user($u);
            self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = $u");
            self::q("INSERT INTO 202_users_pref SET user_id = $u, user_account_currency = 'USD',"
                . " user_pref_time_predefined = '', user_pref_time_from = " . (time() - 86400)
                . ', user_pref_time_to = ' . (time() + 86400));
            $conn = new Connection(self::$db);
            ClickRollupSql::rollUpClick($conn, self::ONE);
            ClickRollupSql::rollUpClick($conn, self::TWO);

            $proc = proc_open(
                [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                    $root . '/tests/Setup/fixtures/page-runner.php', 'tracking202/visitors/download/index.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['P202_TEST_REPORT_USER' => (string) $u, 'P202_TEST_SESSION_DIR' => $sessions] + getenv()
            );
            self::assertIsResource($proc);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($proc), $err);
            self::assertStringNotContainsString('Warning', $err, $err);
        } finally {
            self::q("DELETE FROM 202_users_pref WHERE user_id = $u");
            self::q("DELETE FROM 202_users WHERE user_id = $u");
            self::q('DELETE FROM 202_version');
            foreach ($versions as $version) {
                self::q('INSERT INTO 202_version SET version = ' . self::str($version));
            }
            array_map('unlink', glob($sessions . '/sess_*') ?: []);
            rmdir($sessions);
        }

        $lines = explode("\n", rtrim($out, "\n"));
        $header = explode("\t", (string) array_shift($lines));
        self::assertContains('Subid', $header, "out: $out\nerr: $err");
        $listed = [];
        foreach ($lines as $line) {
            $cells = array_combine($header, explode("\t", $line));
            self::assertIsArray($cells, "a line of as many cells as the header: $line");
            $listed[] = (int) $cells['Subid'];
            self::assertArrayHasKey((int) $cells['Subid'], self::expected());
            self::assertSame(self::expected()[(int) $cells['Subid']], self::values($cells), 'click ' . $cells['Subid']);
        }
        sort($listed);
        self::assertSame([self::ONE, self::TWO], $listed, 'each click once');
    }

    /** connect2.php's replaceTrackerPlaceholders() as shipped, lifted out of the bootstrap. */
    private static function liftTrackerPlaceholders(): void
    {
        require_once __DIR__ . '/../Config/fixtures/lift-function.php';
        $connect2 = __DIR__ . '/../../202-config/connect2.php';
        foreach (['replaceTrackerPlaceholders', 'replaceClickPlaceholders', 'p202ClickUrlTokens', 'replaceTokens'] as $name) {
            if (!function_exists($name)) {
                eval(lift_function($connect2, $name));
            }
        }
        if (!function_exists('record_mysql_error')) {
            eval('function record_mysql_error($db, $sql = null, ?string $error = null): never'
                . ' { throw new \RuntimeException((string) $error . ": " . $db->error); }');
        }
        if (!function_exists('p202ProvenInstallToken')) {
            eval('function p202ProvenInstallToken($clickId): string { return ""; }');
        }
    }
}
