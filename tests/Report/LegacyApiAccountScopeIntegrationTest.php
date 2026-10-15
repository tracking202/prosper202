<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * The legacy API (api/v1, api/v2) names a text ad, landing page or campaign
 * only when it is the click's own account's (CLAUDE.md #27).
 *
 * Its reports joined the dimension a click names on its id alone, the
 * table's name built from the report type (`202_{$type}`), and its
 * WordPress plugin feed joined a landing page's campaign the same way. A
 * click or landing page naming another account's record -- nothing stopped
 * one before 229df10 -- was reported under that account's name.
 *
 * Account A (USER) has three clicks today: one on its own text ad and
 * landing page, one naming another account's (OTHER's), and one with
 * neither; and a landing page naming OTHER's campaign. Each report runs as
 * A, in a booted app (fixtures/account-scope-runner.php).
 *
 * The WordPress feed answers in production today. The two reports do not:
 * their endpoints throw a TypeError before any SQL runs (strict types, and
 * one window handed to both date() and real_escape_string()), so the runner
 * loads the file without its strict-types declaration and the reports run
 * the SQL they would run once that is repaired -- tied, so a repair of the
 * endpoint cannot bring the leak back with it.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class LegacyApiAccountScopeIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 990081;
    private const OTHER = 990082;
    private const STRAY = 990083;
    private const OWN_CLICK = 99008101;
    private const FOREIGN_CLICK = 99008102;
    private const BARE_CLICK = 99008103;

    private static int $now = 0;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any reader ran. tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        self::$versions = array_column($found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [], 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        self::$now = time();
        self::seed();
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
        }
    }

    private static function cleanUp(): void
    {
        $clicks = implode(', ', [self::OWN_CLICK, self::FOREIGN_CLICK, self::BARE_CLICK]);
        foreach ([self::USER, self::OTHER] as $user) {
            $tables = [
                '202_users_pref', '202_users', '202_api_keys', '202_aff_campaigns', '202_aff_networks',
                '202_landing_pages', '202_text_ads', '202_clicks',
            ];
            foreach ($tables as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
        foreach (['202_clicks_advance', '202_clicks_record', '202_clicks_site', '202_clicks_tracking'] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        self::q('DELETE FROM 202_tracking_c1 WHERE c1_id = ' . self::USER);
        $places = implode(', ', [self::USER, self::OTHER]);
        self::q("DELETE FROM 202_locations_country WHERE country_id IN ($places)");
        self::q("DELETE FROM 202_locations_city WHERE city_id IN ($places)");
        self::q("DELETE FROM 202_locations_isp WHERE isp_id IN ($places)");
    }

    private static function seed(): void
    {
        $t = self::$now;
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user]);
        }
        foreach ([[self::USER, self::USER, self::USER, 'My'], [self::OTHER, self::OTHER, self::OTHER, 'Their'], [self::STRAY, self::USER, self::OTHER, 'My Stray']] as [$id, $user, $campaign, $who]) {
            if ($id !== self::STRAY) {
                self::row('202_aff_campaigns', ['aff_campaign_id' => $id, 'aff_campaign_id_public' => $id, 'user_id' => $user, 'aff_network_id' => 0, 'aff_campaign_name' => "$who Offer",
                    'aff_campaign_url' => 'https://offer.example/' . $id, 'aff_campaign_payout' => 5, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t]);
                self::row('202_text_ads', ['text_ad_id' => $id, 'user_id' => $user, 'aff_campaign_id' => $id, 'landing_page_id' => 0, 'text_ad_name' => "$who Ad",
                    'text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'u', 'text_ad_time' => $t]);
            }
            self::row('202_landing_pages', ['landing_page_id' => $id, 'landing_page_id_public' => $id, 'user_id' => $user, 'aff_campaign_id' => $campaign,
                'landing_page_nickname' => "$who Page", 'landing_page_url' => 'https://page.example/' . $id, 'landing_page_time' => $t]);
        }
        foreach ([[self::USER, 'My Network'], [self::OTHER, 'Their Network']] as [$user, $network]) {
            self::row('202_aff_networks', [
                'aff_network_id' => $user, 'user_id' => $user, 'aff_network_name' => $network, 'aff_network_time' => $t,
            ]);
        }
        // Two places, so each location report has more than one row.
        foreach ([[self::USER, 'Here'], [self::OTHER, 'There']] as [$place, $at]) {
            self::row('202_locations_country', ['country_id' => $place, 'country_code' => 'ZZ', 'country_name' => $at]);
            self::row('202_locations_city', ['city_id' => $place, 'main_country_id' => $place, 'city_name' => $at]);
            self::row('202_locations_isp', ['isp_id' => $place, 'isp_name' => "$at ISP"]);
        }
        // A's clicks: on its own ad and page, on OTHER's, and on neither;
        // from here, there, and nowhere.
        foreach ([[self::OWN_CLICK, self::USER], [self::FOREIGN_CLICK, self::OTHER], [self::BARE_CLICK, 0]] as [$click, $named]) {
            self::row('202_clicks', ['click_id' => $click, 'user_id' => self::USER, 'aff_campaign_id' => $named, 'ppc_account_id' => 0,
                'landing_page_id' => $named, 'click_cpc' => 0.1, 'click_payout' => 0, 'click_time' => $t]);
            self::row('202_clicks_advance', ['click_id' => $click, 'text_ad_id' => $named, 'ip_id' => 0, 'country_id' => $named, 'region_id' => 0,
                'city_id' => $named, 'platform_id' => 0, 'browser_id' => 0, 'device_id' => 0, 'isp_id' => $named]);
            self::row('202_clicks_record', ['click_id' => $click, 'click_id_public' => $click, 'click_out' => 1]);
        }
        // c1 "0" on A's own click alone: a real value, which a filter on it finds.
        self::row('202_tracking_c1', ['c1_id' => self::USER, 'c1' => '0']);
        self::row('202_clicks_tracking', [
            'click_id' => self::OWN_CLICK, 'c1_id' => self::USER, 'c2_id' => 0, 'c3_id' => 0, 'c4_id' => 0,
        ]);
    }

    /** What a reader returned, as the runner printed it. */
    private static function read(string $reader, bool $errorExpected = false): array
    {
        $out = self::output($reader);
        self::assertStringStartsWith('RETURNED ', $out, $out);
        $result = json_decode(substr(trim($out), 9), true);
        self::assertIsArray($result, $out);
        if (!$errorExpected) {
            self::assertArrayNotHasKey('error', $result, "$reader answered an error: " . json_encode($result));
        }

        return $result;
    }

    /** Everything a reader printed, refusals included. */
    private static function output(string $reader): string
    {
        self::requireScratch();
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_FROM' => (string) (self::$now - 3600),
            'P202_TEST_TO' => (string) (self::$now + 3600),
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/account-scope-runner.php', $reader],
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
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");
        self::assertNotSame('', trim($out), "$reader printed nothing: $err");

        return $out;
    }

    private static function assertNamesNothingOfTheOtherAccount(array $result, string $reader): void
    {
        preg_match_all('/Their [A-Z][a-z]+/', (string) json_encode($result), $m);
        self::assertSame([], array_values(array_unique($m[0])), "$reader named another account's record");
    }

    /** @return iterable<string, array{string}> */
    public static function versions(): iterable
    {
        yield 'v1' => ['v1'];
        yield 'v2' => ['v2'];
    }

    /** @dataProvider versions */
    public function testTheTextAdReportCountsAnotherAccountsAdAsNone(string $version): void
    {
        $report = self::read("api-$version:text_ads");
        self::assertNamesNothingOfTheOtherAccount($report, "$version text_ads");
        $rows = array_column($report['text_ads'] ?? [], 'clicks', 'text_ad_name');
        ksort($rows);
        self::assertEquals(['My Ad' => 1, '[no text ad]' => 2], $rows, "$version: the click on another account's ad is counted, in the none row: " . json_encode($report));
        self::assertEquals(3, $report['totals']['clicks'] ?? null, 'and the report still counts all three');
    }

    /** @dataProvider versions */
    public function testTheLandingPageReportNamesNoOtherAccountsPage(string $version): void
    {
        $report = self::read("api-$version:landing_pages");
        self::assertNamesNothingOfTheOtherAccount($report, "$version landing_pages");
        self::assertEquals(3, $report['totals']['clicks'] ?? null, "$version: every click is counted: " . json_encode($report));
        self::assertContains('My Page', array_column($report['landing_pages'] ?? [], 'landing_page'));
    }

    /**
     * A statement the server refuses is answered as the API's error, on both
     * versions: v2's copy of reportQuery() went through _mysqli_query(), which
     * on the click bootstrap printed the HTML error page into the JSON answer.
     *
     * @dataProvider versions
     */
    public function testARefusedReportStatementIsAnsweredAsTheApisError(string $version): void
    {
        self::assertSame(
            ['msg' => 'Database error', 'error' => true, 'status' => 500],
            self::read("api-$version:refused", true)
        );
    }

    /** @return iterable<string, array{string, string, string, list<string|null>}> */
    public static function locationReports(): iterable
    {
        foreach (['v1', 'v2'] as $version) {
            yield "$version countries" => [$version, 'countries', 'country_name', [null, 'Here', 'There']];
            yield "$version cities" => [$version, 'cities', 'city_name', [null, 'Here', 'There']];
            // The carriers report leaves out clicks with no ISP.
            yield "$version carriers" => [$version, 'carriers', 'isp_name', ['Here ISP', 'There ISP']];
        }
    }

    /**
     * Each row of a location report, named as the report was asked for. The
     * row loop renamed the report's table to its answer's name, so the second
     * row's statement joined nothing (a 500); a report with no rows answered
     * under the table's name (locations_isp).
     *
     * @dataProvider locationReports
     * @param list<string|null> $names
     */
    public function testALocationReportAnswersEveryRowUnderItsOwnName(
        string $version,
        string $report,
        string $column,
        array $names
    ): void {
        $result = self::read("api-$version:$report");
        self::assertArrayHasKey($report, $result, json_encode($result));
        $found = array_column($result[$report], $column);
        sort($found);
        self::assertSame($names, $found, json_encode($result));
        self::assertEquals(count($names), $result['totals']['clicks'] ?? null);
    }

    /**
     * A campaign filter, through runReports() as each endpoint calls it: v2
     * hands the user id as an int, which the campaign lookup refused (a 500).
     *
     * @dataProvider versions
     */
    public function testACampaignFilterAnswersOnBothVersions(string $version): void
    {
        $report = self::read("api-$version:cid=" . self::USER);
        self::assertSame(['My Ad'], array_column($report['text_ads'] ?? [], 'text_ad_name'), json_encode($report));
        self::assertEquals(1, $report['totals']['clicks'] ?? null);
    }

    /**
     * A c1 of "0" is a value to filter on, and a cid of 0 (or none) is every
     * campaign; c1 was tested for truth, so "0" read as no filter and the
     * report answered every click.
     *
     * @dataProvider versions
     */
    public function testCZeroIsAFilterAndCidZeroIsEveryCampaign(string $version): void
    {
        $all = self::read("api-$version:report?");
        self::assertEquals(3, $all['totals']['clicks'] ?? null, 'no filter: all three clicks');
        $every = self::read("api-$version:report?cid=0");
        self::assertEquals(3, $every['totals']['clicks'] ?? null, 'cid=0: every campaign');
        $filtered = self::read("api-$version:report?c1=0");
        self::assertEquals(1, $filtered['totals']['clicks'] ?? null, json_encode($filtered));
        self::assertSame(['My Ad'], array_column($filtered['text_ads'] ?? [], 'text_ad_name'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function cidsThatNameNoCampaign(): iterable
    {
        foreach (['v1', 'v2'] as $version) {
            yield "$version, a negative id" => [$version, 'cid=-1'];
            // MySQL reads 990081abc as 990081, the account's own campaign.
            yield "$version, the account's id with letters after it" => [$version, 'cid=' . self::USER . 'abc'];
            yield "$version, a list" => [$version, 'cid%5B%5D=' . self::USER];
            // MySQL reads 0990081 as 990081 too.
            yield "$version, the account's id with a zero before it" => [$version, 'cid=0' . self::USER];
        }
    }

    /**
     * A cid that is not exactly an id is the API's "Campaign not found", as
     * one of another account is: `> 0` let -1 through as every campaign.
     *
     * @dataProvider cidsThatNameNoCampaign
     */
    public function testACidThatIsNotAnIdIsRefused(string $version, string $query): void
    {
        $said = self::output("api-$version:report?$query");
        self::assertStringNotContainsString('RETURNED ', $said, 'answered a report');
        self::assertStringContainsString('"Campaign not found"', $said);
    }

    /** @return iterable<string, array{string, string}> */
    public static function listedCustomVariables(): iterable
    {
        foreach (['v1', 'v2'] as $version) {
            foreach (['c1', 'c2', 'c3', 'c4'] as $variable) {
                yield "$version $variable" => [$version, $variable];
            }
        }
    }

    /**
     * A list is no value to filter by, and is refused: it read as no filter,
     * and the report answered every click as though filtered.
     *
     * @dataProvider listedCustomVariables
     */
    public function testAListedCustomVariableIsRefused(string $version, string $variable): void
    {
        $said = self::output("api-$version:report?{$variable}%5B%5D=0");
        self::assertStringNotContainsString('RETURNED ', $said, 'answered a report');
        self::assertStringContainsString("\"$variable takes one value\"", $said);
    }

    /**
     * The WordPress plugin reads `error: '0'` as saved. An UPDATE the server
     * refused answered that too, and a failed read was a fatal on the false.
     */
    public function testTheWordPressPluginsCallsAnswerAFailedStatementAsTheDatabaseError(): void
    {
        $page = self::USER + 3;
        self::row('202_landing_pages', [
            'landing_page_id' => $page, 'landing_page_id_public' => $page, 'user_id' => self::USER,
            'aff_campaign_id' => self::USER, 'landing_page_nickname' => 'My Plugin Page',
            'landing_page_url' => 'https://page.example/wp', 'landing_page_time' => self::$now,
        ]);
        $update = 'api-v1:wp?type=wp_update_lp&page_type=alp&page_title=Saved'
            . '&page_url=https%3A%2F%2Fpage.example%2Fsaved&lp_pid=' . $page;
        self::assertSame(['error' => '0'], self::read($update, true));
        $saved = self::$db->query("SELECT landing_page_nickname FROM 202_landing_pages WHERE landing_page_id = $page");
        self::assertSame('[WP] Saved', $saved instanceof \mysqli_result ? ($saved->fetch_row()[0] ?? null) : null);

        $databaseError = ['msg' => 'Database error', 'error' => true, 'status' => 500];
        self::withoutTable('202_landing_pages', function () use ($update, $databaseError): void {
            self::assertSame($databaseError, self::read($update, true), 'wp_update_lp');
            self::assertSame(
                $databaseError,
                self::read('api-v1:wp?type=wp_create_lp&page_type=alp&page_title=New'
                    . '&page_url=https%3A%2F%2Fpage.example%2Fnew', true),
                'wp_create_lp'
            );
            self::assertSame($databaseError, self::read('api-v1:wp?type=get_data_for_wp', true), 'get_data_for_wp');
        });
    }

    /** @return iterable<string, array{string}> a public id as a format, `%d` */
    public static function publicIdsThatNameNothing(): iterable
    {
        // MySQL compared each as a number, and read it as the id itself.
        yield 'letters after the id' => ['%dabc'];
        yield 'a zero before the id' => ['0%d'];
        yield 'a sign before the id' => ['%%2B%d'];
        yield 'a space before the id' => ['%%20%d'];
        yield 'a decimal point' => ['%d.0'];
        yield 'an exponent' => ['%de0'];
        // The text `Array`, which named nothing, and the update of nothing
        // answered saved.
        yield 'a list' => ['%%5B%%5D=%d'];
    }

    /**
     * The WordPress plugin names a landing page (lp_pid) and a campaign
     * (slp_page_campaign) by public id, read exactly: compared as text, the
     * id with anything MySQL drops from a number named the record, and the
     * page was rewritten or made on it. Anything else is the plugin's
     * `error: '1'`, as a campaign that is not the account's is, and nothing
     * is written.
     *
     * @dataProvider publicIdsThatNameNothing
     */
    public function testTheWordPressPluginsCallsReadAPublicIdExactly(string $format): void
    {
        $page = self::USER + 4;
        self::q("DELETE FROM 202_landing_pages WHERE landing_page_id = $page");
        self::row('202_landing_pages', [
            'landing_page_id' => $page, 'landing_page_id_public' => $page, 'user_id' => self::USER,
            'aff_campaign_id' => self::USER, 'landing_page_nickname' => 'My Exact Page',
            'landing_page_url' => 'https://page.example/exact', 'landing_page_time' => self::$now,
        ]);
        $pages = static function (): array {
            $found = self::$db->query('SELECT landing_page_id, landing_page_nickname, landing_page_type,'
                . ' aff_campaign_id FROM 202_landing_pages WHERE user_id = ' . self::USER
                . ' ORDER BY landing_page_id');
            self::assertInstanceOf(\mysqli_result::class, $found);

            return $found->fetch_all(MYSQLI_ASSOC);
        };
        $before = $pages();
        $title = '&page_title=Changed&page_url=https%3A%2F%2Fpage.example%2Fchanged';
        $exactPage = '&lp_pid=' . $page;
        $exactCampaign = '&slp_page_campaign=' . self::USER;
        $badPage = '&lp_pid=' . sprintf($format, $page);
        $badCampaign = '&slp_page_campaign=' . sprintf($format, self::USER);
        if (str_starts_with($format, '%%5B')) {
            $badPage = '&lp_pid' . sprintf($format, $page);
            $badCampaign = '&slp_page_campaign' . sprintf($format, self::USER);
        }

        // Each names one thing wrongly and everything else exactly.
        $calls = [
            'wp_update_lp alp' => 'type=wp_update_lp&page_type=alp' . $title . $badPage,
            'wp_update_lp slp, the page' => 'type=wp_update_lp&page_type=slp' . $title . $badPage . $exactCampaign,
            'wp_update_lp slp, the campaign' => 'type=wp_update_lp&page_type=slp' . $title . $exactPage . $badCampaign,
            'wp_create_lp slp' => 'type=wp_create_lp&page_type=slp' . $title . $badCampaign,
        ];
        foreach ($calls as $call => $query) {
            self::assertSame(['error' => '1'], self::read("api-v1:wp?$query", true), $call);
            self::assertSame($before, $pages(), "$call wrote nothing");
        }

        // The exact ids name the page and the campaign.
        self::assertSame(
            ['error' => '0'],
            self::read('api-v1:wp?type=wp_update_lp&page_type=slp' . $title . $exactPage . $exactCampaign, true)
        );
        $changed = self::$db->query("SELECT landing_page_nickname FROM 202_landing_pages"
            . " WHERE landing_page_id = $page");
        self::assertInstanceOf(\mysqli_result::class, $changed);
        self::assertSame('[WP] Changed', $changed->fetch_row()[0] ?? null);
    }

    /** The key check each endpoint makes first, with nothing failing. */
    public function testTheKeyCheckReaderAnswersWithNothingFailing(): void
    {
        $key = self::legacyKey();
        $report = self::read("api-v1:auth?fail=none&apikey=$key");
        self::assertArrayHasKey('text_ads', $report, json_encode($report));
        $authorized = self::read("api-v2:auth?fail=none&apikey=$key", true);
        self::assertSame(['Authorized', 'UTC'], [$authorized['msg'] ?? null, $authorized['user_timezone'] ?? null]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function failedFetches(): iterable
    {
        foreach (['v1', 'v2'] as $version) {
            yield "$version, the key's row" => [$version, 'key'];
            yield "$version, the account's time zone" => [$version, 'user'];
        }
    }

    /**
     * The same, with the fetch of the key's row or of the account's time
     * zone failing: a failed fetch is false, which read as no row refused the
     * key as a deactivated account's, gave v2 every report in UTC, and was a
     * TypeError on v1.
     *
     * @dataProvider failedFetches
     */
    public function testAFailedFetchOfTheKeyOrTheZoneIsTheDatabaseError(string $version, string $failing): void
    {
        self::assertSame(
            ['msg' => 'Database error', 'error' => true, 'status' => 500],
            self::read("api-$version:auth?fail=$failing&apikey=" . self::legacyKey(), true)
        );
    }

    /** An API key of USER's, made once. */
    private static function legacyKey(): string
    {
        $key = 'p202-legacy-fetch-' . self::USER;
        $found = self::$db->query("SELECT COUNT(*) FROM 202_api_keys WHERE api_key = '$key'");
        if ((int) ($found instanceof \mysqli_result ? $found->fetch_row()[0] : 0) === 0) {
            self::row('202_api_keys', ['user_id' => self::USER, 'api_key' => $key, 'created_at' => self::$now]);
        }

        return $key;
    }

    /** v2's categories with the statement refused: `or die()` answered an empty 200. */
    public function testV2CategoriesAnswerAFailedStatementAsTheDatabaseError(): void
    {
        self::withoutTable('202_aff_networks', function (): void {
            self::assertSame(
                ['msg' => 'Database error', 'error' => true, 'status' => 500],
                self::read('api-v2:categories', true)
            );
        });
    }

    /** $table renamed away while $run runs, so every statement naming it is refused. */
    private static function withoutTable(string $table, callable $run): void
    {
        self::q("RENAME TABLE `$table` TO `{$table}_away`");
        try {
            $run();
        } finally {
            self::q("RENAME TABLE `{$table}_away` TO `$table`");
        }
    }

    /** v2's categories, as categories/index.php asks for them (an int user id, which was a 500). */
    public function testV2ListsTheAccountsCategories(): void
    {
        $listed = self::read('api-v2:categories');
        $names = array_column($listed['categories'] ?? [], 'category_name');
        self::assertSame(['My Network'], $names, json_encode($listed));
    }

    public function testTheWordPressFeedNamesNoOtherAccountsCampaign(): void
    {
        $feed = self::read('api-v1:get_data_for_wp');
        self::assertNamesNothingOfTheOtherAccount($feed, 'v1 get_data_for_wp');
        $pages = array_column($feed[0]['slp'] ?? [], 'aff_campaign_name', 'landing_page_nickname');
        self::assertArrayHasKey('My Stray Page', $pages, json_encode($feed));
        self::assertNull($pages['My Stray Page'], 'the page naming another account\'s campaign is listed with no campaign');
        self::assertSame('My Offer', $pages['My Page']);
    }
}
