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
            foreach (['202_users_pref', '202_users', '202_aff_campaigns', '202_landing_pages', '202_text_ads', '202_clicks'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
        foreach (['202_clicks_advance', '202_clicks_record', '202_clicks_site'] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
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
                self::row('202_aff_campaigns', ['aff_campaign_id' => $id, 'user_id' => $user, 'aff_network_id' => 0, 'aff_campaign_name' => "$who Offer",
                    'aff_campaign_url' => 'https://offer.example/' . $id, 'aff_campaign_payout' => 5, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t]);
                self::row('202_text_ads', ['text_ad_id' => $id, 'user_id' => $user, 'aff_campaign_id' => $id, 'landing_page_id' => 0, 'text_ad_name' => "$who Ad",
                    'text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'u', 'text_ad_time' => $t]);
            }
            self::row('202_landing_pages', ['landing_page_id' => $id, 'landing_page_id_public' => $id, 'user_id' => $user, 'aff_campaign_id' => $campaign,
                'landing_page_nickname' => "$who Page", 'landing_page_url' => 'https://page.example/' . $id, 'landing_page_time' => $t]);
        }
        // A's clicks: on its own ad and page, on OTHER's, and on neither.
        foreach ([[self::OWN_CLICK, self::USER], [self::FOREIGN_CLICK, self::OTHER], [self::BARE_CLICK, 0]] as [$click, $named]) {
            self::row('202_clicks', ['click_id' => $click, 'user_id' => self::USER, 'aff_campaign_id' => $named, 'ppc_account_id' => 0,
                'landing_page_id' => $named, 'click_cpc' => 0.1, 'click_payout' => 0, 'click_time' => $t]);
            self::row('202_clicks_advance', ['click_id' => $click, 'text_ad_id' => $named, 'ip_id' => 0, 'country_id' => 0, 'region_id' => 0,
                'city_id' => 0, 'platform_id' => 0, 'browser_id' => 0, 'device_id' => 0]);
            self::row('202_clicks_record', ['click_id' => $click, 'click_id_public' => $click, 'click_out' => 1]);
        }
    }

    /** What a reader returned, as the runner printed it. */
    private static function read(string $reader): array
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
        self::assertStringStartsWith('RETURNED ', $out, $out . $err);
        $result = json_decode(substr(trim($out), 9), true);
        self::assertIsArray($result, $out);
        self::assertArrayNotHasKey('error', $result, "$reader answered an error: " . json_encode($result));

        return $result;
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
