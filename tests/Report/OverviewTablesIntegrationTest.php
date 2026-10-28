<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * The Overview's tables (DataEngine's LpOverview, campaignOverview,
 * slp_direct_link_per_ppc and alp_per_ppc) for the rows that name nothing:
 * a direct link with no traffic source account, an advanced landing page
 * click with no campaign, a click naming another account's landing page.
 *
 * The rows are seeded as the rollup writes them (ClickRollupSql): a click
 * with no campaign, category or source carries NULL there, not 0.
 *
 * - The landing-page table grouped by the click's own landing page id, so
 *   a click naming another account's page was a second row beside the
 *   direct links, also named "[direct link]".
 * - The campaigns table showed the campaign's configured payout under "Avg
 *   payout", so the row of clicks with no campaign read "$" (the formatted
 *   NULL), and a campaign's row disagreed with the totals under it.
 * - The advanced landing pages split by traffic source asked for
 *   `aff_campaign_id IS FALSE`, which the NULL the rollup writes is not, so
 *   no advanced landing page click was ever listed there.
 *
 * Every reader runs as the account, in a child process that boots the app
 * (fixtures/account-scope-runner.php). Needs a 202-config.php and
 * P202_TEST_DB_*.
 *
 * @group integration
 */
final class OverviewTablesIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 990091;
    private const OTHER = 990092;
    /** The account's advanced landing page. */
    private const ALP = 990093;

    /** A direct link on the account's campaign, through no traffic source account, that converted. */
    private const DIRECT_CLICK = 99009101;
    /** A click on the account's advanced landing page, no campaign, no account. */
    private const ALP_CLICK = 99009102;
    /** A click naming another account's landing page, no campaign. */
    private const FOREIGN_LP_CLICK = 99009103;

    private static int $now = 0;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped(
                'No 202-config.php: connect.php would exit before any reader ran.'
                . ' tests/run-integration-suites.sh writes one.'
            );
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
        $tables = [
            '202_dataengine', '202_users_pref', '202_users', '202_aff_networks', '202_aff_campaigns',
            '202_landing_pages',
        ];
        foreach ([self::USER, self::OTHER] as $user) {
            foreach ($tables as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
    }

    private static function seed(): void
    {
        $t = self::$now;
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::row('202_users_pref', [
                'user_id' => $user, 'user_pref_time_predefined' => 'today', 'user_pref_show' => 'all',
            ]);
        }
        self::row('202_aff_networks', [
            'aff_network_id' => self::USER, 'user_id' => self::USER,
            'aff_network_name' => 'My Category', 'aff_network_time' => $t,
        ]);
        // A configured payout the clicks' average is not.
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => self::USER, 'user_id' => self::USER, 'aff_network_id' => self::USER,
            'aff_campaign_name' => 'My Offer', 'aff_campaign_url' => 'https://offer.example/',
            'aff_campaign_payout' => 77,
            'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t,
        ]);
        $pages = [[self::ALP, self::USER, 'My Advanced Page'], [self::OTHER, self::OTHER, 'Their Page']];
        foreach ($pages as [$id, $user, $name]) {
            self::row('202_landing_pages', [
                'landing_page_id' => $id, 'user_id' => $user, 'aff_campaign_id' => 0, 'landing_page_type' => 1,
                'landing_page_nickname' => $name, 'landing_page_url' => 'https://page.example/' . $id,
                'landing_page_time' => $t,
            ]);
        }

        $row = static fn (int $click, array $more): array => $more + [
            'user_id' => self::USER, 'click_id' => $click, 'click_time' => $t,
            'ppc_account_id' => 0, 'ppc_network_id' => null,
            'aff_campaign_id' => null, 'aff_network_id' => null, 'landing_page_id' => 0, 'text_ad_id' => null,
            'clicks' => 1, 'click_out' => 0, 'click_lead' => 0, 'leads' => 0, 'payout' => 0, 'income' => 0, 'cost' => 0,
        ];
        self::row('202_dataengine', $row(self::DIRECT_CLICK, [
            'aff_campaign_id' => self::USER, 'aff_network_id' => self::USER, 'click_out' => 1,
            'click_lead' => 1, 'leads' => 1, 'payout' => 12.5, 'income' => 12.5,
        ]));
        self::row('202_dataengine', $row(self::ALP_CLICK, ['landing_page_id' => self::ALP, 'click_alp' => 1]));
        self::row('202_dataengine', $row(self::FOREIGN_LP_CLICK, ['landing_page_id' => self::OTHER, 'click_alp' => 1]));
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
        self::assertIsArray($result, $out);

        return $result;
    }

    /**
     * A table's rows (its totals row last) as label => [clicks, payout].
     *
     * @param list<array<string, string>> $rows
     * @return list<array{string, string, string}>
     */
    private static function table(array $rows, string $label): array
    {
        $out = [];
        foreach ($rows as $row) {
            $total = isset($row['total_clicks']) && !isset($row['clicks']);
            $prefix = $total ? 'total_' : '';
            $out[] = [
                $total ? 'Totals' : (string) ($row[$label] ?? ''),
                (string) ($row[$prefix . 'clicks'] ?? ''),
                (string) ($row[$prefix . 'payout'] ?? ''),
            ];
        }

        return $out;
    }

    public function testTheLandingPageTableHasOneRowForTheClicksWithNoLandingPageOfTheAccounts(): void
    {
        self::assertSame([
            ['[direct link]', '2', '$12.50'],
            ['My Advanced Page', '1', '$0.00'],
            ['Totals', '3', '$12.50'],
        ], self::table(self::read('engine:LpOverview'), 'landing_page_nickname'), 'a click naming another'
            . ' account\'s landing page is counted with the clicks that have no landing page,'
            . ' not as a second "[direct link]" row');
    }

    public function testTheCampaignTableShowsEachRowsAveragePayout(): void
    {
        self::assertSame([
            ['[Landing Page/Smart Redirector Campaign]', '2', '$0.00'],
            ['My Offer', '1', '$12.50'],
            ['Totals', '3', '$12.50'],
        ], self::table(self::read('engine:campaignOverview'), 'aff_campaign_name'), 'Avg payout is income over'
            . ' leads in every row, as in the totals: not "$" for the clicks with no campaign,'
            . ' nor the configured $77.00');
    }

    public function testTheAdvancedLandingPageClicksWithNoCampaignAreSplitByTrafficSource(): void
    {
        $data = self::read('engine:alp_per_ppc');
        self::assertSame([(string) self::ALP], array_map('strval', array_keys($data)), 'the account\'s advanced'
            . ' landing page, and not the other account\'s page a click named');
        $page = $data[(string) self::ALP];
        self::assertSame('My Advanced Page', $page['total_landing_page_nickname'] ?? null);
        $accounts = array_map('strval', array_keys($page['ppc_accounts'] ?? []));
        self::assertSame(['0'], $accounts, 'its click, through no account');
        self::assertSame('1', $page['ppc_accounts']['0']['clicks'] ?? null);
    }

    public function testTheCampaignClicksAreSplitByTrafficSource(): void
    {
        $data = self::read('engine:slp_direct_link_per_ppc');
        self::assertSame([(string) self::USER], array_map('strval', array_keys($data)));
        $campaign = $data[(string) self::USER];
        self::assertSame('My Offer', $campaign['total_aff_campaign_name'] ?? null);
        self::assertSame('My Category', $campaign['total_aff_network_name'] ?? null);
        self::assertSame('1', $campaign['ppc_accounts']['0']['clicks'] ?? null);
    }
}
