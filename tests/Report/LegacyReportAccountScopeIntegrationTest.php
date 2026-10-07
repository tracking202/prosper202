<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\ClickRollupSql;

/**
 * The legacy report pages name a campaign, category, traffic source and its
 * account, landing page, text ad or redirector only when it is the click's
 * own account's (CLAUDE.md #27).
 *
 * A click names those by id, and before 229df10 nothing stopped a tracker
 * naming another account's; a Setup record (a traffic source account, a
 * campaign, a landing page, a text ad) could name another account's parent
 * the same way. Those rows remain in installs. Every reader below joined the
 * named table on its id alone, so the account's own clicks were shown under
 * another account's names. The rule is ReportsController::dimensionJoin()'s:
 * such a row still counts, and the foreign name is simply absent.
 *
 * Account A (USER) has four clicks in the window: one on its own records,
 * one naming another account's (OTHER's) campaign, category, source,
 * account, landing page, text ad, redirector, rule and redirect, one
 * through A's own traffic source account and campaign that are filed under
 * OTHER's source and category, and one with no campaign through OTHER's
 * landing page (an advanced landing page click) and account. Every reader runs as A, in a child process
 * that boots the app (fixtures/account-scope-runner.php).
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class LegacyReportAccountScopeIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 990061;
    private const OTHER = 990062;
    /** A's own Setup records that name OTHER's parent. */
    private const STRAY = 990063;
    /** A redirector, rule and redirect that no longer exist. */
    private const REMOVED = 990064;

    private const OWN_CLICK = 99006101;
    private const FOREIGN_CLICK = 99006102;
    private const STRAY_CLICK = 99006103;
    private const ALP_CLICK = 99006106;
    /** Clicks the rollup is run on, ten days back: outside every window read. */
    private const ROLLUP_FOREIGN = 99006104;
    private const ROLLUP_OWN = 99006105;

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
        $ids = implode(', ', [self::USER, self::OTHER, self::STRAY]);
        $clicks = implode(', ', [self::OWN_CLICK, self::FOREIGN_CLICK, self::STRAY_CLICK, self::ALP_CLICK, self::ROLLUP_FOREIGN, self::ROLLUP_OWN]);
        foreach ([self::USER, self::OTHER] as $user) {
            foreach (['202_dataengine', '202_users_pref', '202_users', '202_aff_networks', '202_aff_campaigns', '202_ppc_networks',
                '202_ppc_accounts', '202_landing_pages', '202_text_ads', '202_rotators', '202_clicks'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
        foreach (['202_clicks_advance', '202_clicks_record', '202_clicks_rotator', '202_dataengine'] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        self::q("DELETE FROM 202_rotator_rules WHERE id IN ($ids)");
        self::q("DELETE FROM 202_rotator_rules_redirects WHERE id IN ($ids)");
        self::q("DELETE FROM 202_variable_sets2 WHERE variable_set_id IN ($ids)");
        self::q("DELETE FROM 202_custom_variables WHERE custom_variable_id IN ($ids)");
        self::q("DELETE FROM 202_ppc_network_variables WHERE ppc_variable_id IN ($ids)");
    }

    private static function seed(): void
    {
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user, 'user_pref_time_predefined' => 'today', 'user_pref_show' => 'all']);
        }
        $t = self::$now;
        // Setup records: each account's own, and A's strays that name OTHER's parent.
        foreach ([[self::USER, self::USER, 'My'], [self::OTHER, self::OTHER, 'Their'], [self::STRAY, self::USER, 'My Stray']] as [$id, $user, $who]) {
            $parent = $id === self::STRAY ? self::OTHER : $id;
            if ($id !== self::STRAY) {
                self::row('202_aff_networks', ['aff_network_id' => $id, 'user_id' => $user, 'aff_network_name' => "$who Category", 'aff_network_time' => $t]);
                self::row('202_ppc_networks', ['ppc_network_id' => $id, 'user_id' => $user, 'ppc_network_name' => "$who Source", 'ppc_network_time' => $t]);
            }
            self::row('202_aff_campaigns', ['aff_campaign_id' => $id, 'user_id' => $user, 'aff_network_id' => $parent, 'aff_campaign_name' => "$who Offer",
                'aff_campaign_url' => 'https://offer.example/' . $id, 'aff_campaign_payout' => 77, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t]);
            self::row('202_ppc_accounts', ['ppc_account_id' => $id, 'user_id' => $user, 'ppc_network_id' => $parent, 'ppc_account_name' => "$who Account", 'ppc_account_time' => $t]);
            self::row('202_landing_pages', ['landing_page_id' => $id, 'user_id' => $user, 'aff_campaign_id' => $parent, 'landing_page_nickname' => "$who Page",
                'landing_page_url' => 'https://page.example/' . $id, 'landing_page_time' => $t]);
            self::row('202_text_ads', ['text_ad_id' => $id, 'user_id' => $user, 'aff_campaign_id' => $parent, 'landing_page_id' => 0, 'text_ad_name' => "$who Ad",
                'text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'u', 'text_ad_time' => $t]);
        }
        self::row('202_rotators', ['id' => self::OTHER, 'public_id' => self::OTHER, 'user_id' => self::OTHER, 'name' => 'Their Rotator']);
        self::row('202_rotator_rules', ['id' => self::OTHER, 'rotator_id' => self::OTHER, 'rule_name' => 'Their Rule']);
        self::row('202_rotator_rules_redirects', ['id' => self::OTHER, 'rule_id' => self::OTHER, 'name' => 'Their Redirect']);
        // OTHER's source's variable, recorded on A's click through it.
        self::row('202_ppc_network_variables', ['ppc_variable_id' => self::OTHER, 'ppc_network_id' => self::OTHER, 'name' => 'Their Var', 'parameter' => 'tv']);
        self::row('202_custom_variables', ['custom_variable_id' => self::OTHER, 'ppc_variable_id' => self::OTHER, 'variable' => 'x']);
        self::row('202_variable_sets2', ['variable_set_id' => self::OTHER, 'variables' => (string) self::OTHER]);

        // A's clicks as the rollup wrote them before this change: the ids the
        // click named, whoever's they were.
        $click = static fn (int $id, int $campaign, int $category, int $account, int $source, array $more = []): array => $more + [
            'user_id' => self::USER, 'click_id' => $id, 'click_time' => $t, 'aff_campaign_id' => $campaign, 'aff_network_id' => $category,
            'ppc_account_id' => $account, 'ppc_network_id' => $source, 'landing_page_id' => 0, 'text_ad_id' => 0,
            'clicks' => 1, 'click_out' => 1, 'leads' => 0, 'payout' => 0, 'income' => 0, 'cost' => 0,
        ];
        // The account's own click went through a redirector since removed:
        // it is grouped as none, as the foreign one is (ReportSummaryForm).
        self::row('202_dataengine', $click(self::OWN_CLICK, self::USER, self::USER, self::USER, self::USER, [
            'rotator_id' => self::REMOVED, 'rule_id' => self::REMOVED, 'rule_redirect_id' => self::REMOVED,
        ]));
        self::row('202_dataengine', $click(self::FOREIGN_CLICK, self::OTHER, self::OTHER, self::OTHER, self::OTHER, [
            'landing_page_id' => self::OTHER, 'text_ad_id' => self::OTHER, 'rotator_id' => self::OTHER, 'rule_id' => self::OTHER,
            'rule_redirect_id' => self::OTHER, 'variable_set_id' => (string) self::OTHER,
        ]));
        self::row('202_dataengine', $click(self::STRAY_CLICK, self::STRAY, self::OTHER, self::STRAY, self::OTHER, [
            'landing_page_id' => self::STRAY, 'text_ad_id' => self::STRAY,
        ]));
        self::row('202_dataengine', $click(self::ALP_CLICK, 0, 0, self::OTHER, self::OTHER, ['landing_page_id' => self::OTHER]));

        // Two raw clicks for the rollup itself, ten days back.
        foreach ([[self::ROLLUP_FOREIGN, self::OTHER], [self::ROLLUP_OWN, self::USER]] as [$id, $named]) {
            self::row('202_clicks', ['click_id' => $id, 'user_id' => self::USER, 'aff_campaign_id' => $named, 'ppc_account_id' => $named,
                'landing_page_id' => $named, 'click_cpc' => 0, 'click_payout' => 0, 'click_time' => $t - 10 * 86400]);
            self::row('202_clicks_advance', ['click_id' => $id, 'text_ad_id' => $named, 'ip_id' => 0, 'country_id' => 0, 'region_id' => 0,
                'city_id' => 0, 'platform_id' => 0, 'browser_id' => 0, 'device_id' => 0]);
            self::row('202_clicks_record', ['click_id' => $id, 'click_id_public' => $id]);
        }
    }

    /** What a reader returned, as the runner printed it. */
    private static function read(string $reader): mixed
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
        self::assertNotNull($result, $out);

        return $result;
    }

    /** Another account's name anywhere in what a reader returned. */
    private static function assertNamesNothingOfTheOtherAccount(mixed $result, string $reader): void
    {
        $json = (string) json_encode($result);
        self::assertSame(0, preg_match_all('/Their [A-Z][a-z]+/', $json, $m), "$reader named another account's record: " . implode(', ', array_unique($m[0] ?? [])));
    }

    public function testTheRollupNamesOnlyTheClicksOwnAccountsRecords(): void
    {
        self::requireScratch();
        foreach ([self::ROLLUP_FOREIGN, self::ROLLUP_OWN] as $click) {
            self::q(ClickRollupSql::insertSelect('202_dataengine', '2c.click_id = ' . $click, true));
        }
        $rows = [];
        $result = self::$db->query('SELECT click_id, aff_campaign_id, aff_network_id, ppc_account_id, ppc_network_id, landing_page_id, text_ad_id, clicks'
            . ' FROM 202_dataengine WHERE click_id IN (' . self::ROLLUP_FOREIGN . ', ' . self::ROLLUP_OWN . ')');
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $rows[(int) $row['click_id']] = $row;
        }
        self::assertSame([
            'click_id' => (string) self::ROLLUP_FOREIGN, 'aff_campaign_id' => null, 'aff_network_id' => null,
            'ppc_account_id' => (string) self::OTHER, 'ppc_network_id' => null, 'landing_page_id' => (string) self::OTHER,
            'text_ad_id' => null, 'clicks' => '1',
        ], $rows[self::ROLLUP_FOREIGN] ?? null, 'a click naming another account\'s records is rolled up and counted, naming none of them');
        self::assertSame([
            'click_id' => (string) self::ROLLUP_OWN, 'aff_campaign_id' => (string) self::USER, 'aff_network_id' => (string) self::USER,
            'ppc_account_id' => (string) self::USER, 'ppc_network_id' => (string) self::USER, 'landing_page_id' => (string) self::USER,
            'text_ad_id' => (string) self::USER, 'clicks' => '1',
        ], $rows[self::ROLLUP_OWN] ?? null, 'a click on the account\'s own records keeps them');
    }

    /** @return iterable<string, array{string}> */
    public static function engineReports(): iterable
    {
        foreach (['campaignOverview', 'LpOverview', 'slp_direct_link_per_ppc', 'alp_per_ppc', 'textad', 'landingpage', 'variable'] as $type) {
            yield $type => [$type];
        }
    }

    /** @dataProvider engineReports */
    public function testTheEngineReportsNameNothingOfAnotherAccount(string $type): void
    {
        self::assertNamesNothingOfTheOtherAccount(self::read('engine:' . $type), "DataEngine '$type'");
    }

    /** @return iterable<string, array{string}> */
    public static function engineTotals(): iterable
    {
        foreach (['campaignOverview', 'textad', 'landingpage'] as $type) {
            yield $type => [$type];
        }
    }

    /** @dataProvider engineTotals */
    public function testTheEngineStillCountsEveryClick(string $type): void
    {
        $rows = self::read('engine:' . $type);
        self::assertIsArray($rows);
        $total = end($rows);
        self::assertSame('4', (string) ($total['total_clicks'] ?? ''), "DataEngine '$type': the report's total is the account's four clicks: " . json_encode($total));
    }

    /** @return iterable<string, array{string}> */
    public static function groupLevels(): iterable
    {
        foreach (['DETAIL_LEVEL_CAMPAIGN', 'DETAIL_LEVEL_AFFILIATE_NETWORK', 'DETAIL_LEVEL_PPC_NETWORK', 'DETAIL_LEVEL_PPC_ACCOUNT',
            'DETAIL_LEVEL_LANDING_PAGE', 'DETAIL_LEVEL_TEXT_AD', 'DETAIL_LEVEL_ROTATOR', 'DETAIL_LEVEL_ROTATOR_RULE',
            'DETAIL_LEVEL_ROTATOR_RULE_REDIRECT'] as $level) {
            yield $level => [$level];
        }
    }

    /** @dataProvider groupLevels */
    public function testGroupOverviewNamesNothingOfAnotherAccountAndCountsEveryClick(string $level): void
    {
        $groups = self::read('group:' . $level);
        self::assertNamesNothingOfTheOtherAccount($groups, "Group Overview $level");
        self::assertSame(4, array_sum(array_column($groups, 'clicks')), "Group Overview $level counts the account's four clicks: " . json_encode($groups));
    }

    public function testTheVisitorsListReadsAnotherAccountsSourceAsNoSource(): void
    {
        self::requireScratch();
        $filter = static fn (string $network) => self::q("UPDATE 202_users_pref SET user_pref_ppc_network_id = '$network' WHERE user_id = " . self::USER);
        try {
            // "[No traffic source]": the click through another account's
            // account, and the one through A's account filed under another
            // account's source, both read as having none.
            $filter('16777215');
            $none = self::read('visitors');
            sort($none['listed']);
            self::assertSame([self::FOREIGN_CLICK, self::STRAY_CLICK, self::ALP_CLICK], $none['listed'], 'the list reads another account\'s source as no source');
            self::assertSame(3, $none['rows'], 'and the count above it agrees with the list');

            // The other account's source, named by id: none of A's clicks.
            $filter((string) self::OTHER);
            $theirs = self::read('visitors');
            self::assertSame([], $theirs['listed'], 'a filter naming another account\'s source matches none of this account\'s clicks');
            self::assertSame(0, $theirs['rows'], 'and counts none');

            $filter((string) self::USER);
            $own = self::read('visitors');
            self::assertSame([self::OWN_CLICK], $own['listed']);
            self::assertSame(1, $own['rows']);
        } finally {
            $filter('0');
        }
    }

    public function testTheFilterMenusNameNothingOfAnotherAccount(): void
    {
        $lists = self::read('lists');
        self::assertNamesNothingOfTheOtherAccount($lists, 'the filter menus');
        foreach (['store', 'overview'] as $which) {
            self::assertStringNotContainsString('My Stray Account', (string) json_encode($lists[$which]['ppc_account_id']), "$which: an account filed under another account's source is left out, as one under a deleted source is");
            self::assertStringNotContainsString('My Stray Offer', (string) json_encode($lists[$which]['aff_campaign_id']), "$which: a campaign filed under another account's category is left out");
            self::assertSame(['My Source' => [(string) self::USER => 'My Account']], $lists[$which]['ppc_account_id']);
        }
        self::assertSame('My Stray Page', $lists['store']['landing_page_id']['No campaign'][(string) self::STRAY] ?? null, 'a landing page naming another account\'s campaign is listed under No campaign');
        self::assertSame('My Stray Ad', $lists['store']['text_ad_id']['No campaign'][(string) self::STRAY] ?? null, 'and so is a text ad');
    }
}
