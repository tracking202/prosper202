<?php

declare(strict_types=1);

namespace Tests\Report;

use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\PpcAccountsController;
use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\ClickRollupSql;

/**
 * A click's report row (202_dataengine) follows the Setup values it was
 * rolled up under: a re-roll takes them again, and moving a traffic source
 * account or a campaign queues its clicks for the cron job's re-roll
 * (CLAUDE.md #31).
 *
 * The row copies two values from Setup rather than from the click: the
 * traffic source of its account (ppc_network_id) and the category of its
 * campaign (aff_network_id). The rollup's ON DUPLICATE KEY UPDATE refreshed
 * fourteen of its columns, ppc_network_id and text_ad_id not among them, and
 * nothing re-rolled a moved account's or campaign's older clicks; so after a
 * move the breakdown by traffic source, which groups by the row's copy,
 * disagreed with the Overview, which looks the account's source up.
 *
 * The cron pass runs as the cron job runs it (DataEngine::processDirtyHours()
 * in a process that boots the app, fixtures/account-scope-runner.php); the
 * moves go through the API controllers.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class RollupRefreshIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 990081;
    private const OTHER = 990082;
    /** The source and category USER's account and campaign are moved to. */
    private const MOVED_TO = 990083;

    /** USER's click on its own account, campaign and text ad, twenty days back. */
    private const CLICK = 99008101;
    /** USER's click naming OTHER's account and text ad, rolled up before 229df10. */
    private const STALE_CLICK = 99008102;

    private static int $now = 0;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped(
                'No 202-config.php: connect.php would exit before the cron pass ran.'
                . ' tests/run-integration-suites.sh writes one.'
            );
        }
        if (!self::connectScratch()) {
            return;
        }
        // The app the cron pass boots runs only on a schema at its version.
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::$now = time();
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

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        self::seed();
    }

    private static function cleanUp(): void
    {
        $tables = [
            '202_dataengine', '202_dirty_hours', '202_users_pref', '202_users', '202_aff_networks',
            '202_aff_campaigns', '202_ppc_networks', '202_ppc_accounts', '202_text_ads', '202_clicks',
        ];
        foreach ([self::USER, self::OTHER] as $user) {
            foreach ($tables as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
        $clicks = self::CLICK . ', ' . self::STALE_CLICK;
        foreach (['202_clicks_advance', '202_clicks_record', '202_dataengine'] as $table) {
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
        foreach ([[self::USER, self::USER], [self::OTHER, self::OTHER], [self::MOVED_TO, self::USER]] as [$id, $user]) {
            self::row('202_aff_networks', [
                'aff_network_id' => $id, 'user_id' => $user,
                'aff_network_name' => "Category $id", 'aff_network_time' => $t,
            ]);
            self::row('202_ppc_networks', [
                'ppc_network_id' => $id, 'user_id' => $user,
                'ppc_network_name' => "Source $id", 'ppc_network_time' => $t,
            ]);
        }
        foreach ([self::USER, self::OTHER] as $id) {
            self::row('202_aff_campaigns', [
                'aff_campaign_id' => $id, 'user_id' => $id, 'aff_network_id' => $id, 'aff_campaign_name' => "Offer $id",
                'aff_campaign_url' => 'https://offer.example/' . $id, 'aff_campaign_payout' => 1,
                'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t,
            ]);
            self::row('202_ppc_accounts', [
                'ppc_account_id' => $id, 'user_id' => $id, 'ppc_network_id' => $id,
                'ppc_account_name' => "Account $id", 'ppc_account_time' => $t,
            ]);
            self::row('202_text_ads', [
                'text_ad_id' => $id, 'user_id' => $id, 'aff_campaign_id' => $id, 'landing_page_id' => 0,
                'text_ad_name' => "Ad $id", 'text_ad_headline' => 'h', 'text_ad_description' => 'd',
                'text_ad_display_url' => 'u', 'text_ad_time' => $t,
            ]);
        }
        foreach ([[self::CLICK, self::USER], [self::STALE_CLICK, self::OTHER]] as [$id, $named]) {
            self::row('202_clicks', [
                'click_id' => $id, 'user_id' => self::USER, 'aff_campaign_id' => self::USER, 'ppc_account_id' => $named,
                'landing_page_id' => 0, 'click_cpc' => 0, 'click_payout' => 0, 'click_time' => $t - 20 * 86400,
            ]);
            self::row('202_clicks_advance', [
                'click_id' => $id, 'text_ad_id' => $named, 'ip_id' => 0, 'country_id' => 0, 'region_id' => 0,
                'city_id' => 0, 'platform_id' => 0, 'browser_id' => 0, 'device_id' => 0,
            ]);
            self::row('202_clicks_record', ['click_id' => $id, 'click_id_public' => $id]);
        }
    }

    private static function reroll(int $click): void
    {
        self::q(ClickRollupSql::insertSelect('202_dataengine', '2c.click_id = ' . $click));
    }

    /** @return array<string, string|null>|null the click's report row's Setup columns */
    private static function reportRow(int $click): ?array
    {
        $result = self::$db->query(
            'SELECT ppc_account_id, ppc_network_id, aff_campaign_id, aff_network_id, text_ad_id, clicks'
            . ' FROM 202_dataengine WHERE click_id = ' . $click
        );
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        self::assertLessThanOrEqual(1, count($rows), 'one report row per click');

        return $rows[0] ?? null;
    }

    /**
     * A report row as the assertions spell it.
     *
     * @return array<string, string|null>
     */
    private static function expected(?int $account, ?int $source, ?int $campaign, ?int $category, ?int $ad): array
    {
        $id = static fn (?int $value): ?string => $value === null ? null : (string) $value;

        return [
            'ppc_account_id' => $id($account), 'ppc_network_id' => $id($source), 'aff_campaign_id' => $id($campaign),
            'aff_network_id' => $id($category), 'text_ad_id' => $id($ad), 'clicks' => '1',
        ];
    }

    /** @return list<array<string, string>> USER's queued re-rolls */
    private static function queued(): array
    {
        $result = self::$db->query(
            'SELECT ppc_account_id, aff_campaign_id, click_time_from, processed + 0 AS processed'
            . ' FROM 202_dirty_hours WHERE user_id = ' . self::USER . ' ORDER BY id'
        );
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /** The cron job's dirty-hours pass, in a process that boots the app. */
    private static function runCronPass(): void
    {
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_FROM' => (string) (self::$now - 3600),
            'P202_TEST_TO' => (string) (self::$now + 3600),
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/account-scope-runner.php', 'dirty-hours'],
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
        self::assertSame('RETURNED {"left":0}', trim($out), 'the pass processed and cleared the queue: ' . $out . $err);
    }

    public function testAReRollTakesTheSourceAndCategoryTheClickHasNow(): void
    {
        self::reroll(self::CLICK);
        self::assertSame(
            self::expected(self::USER, self::USER, self::USER, self::USER, self::USER),
            self::reportRow(self::CLICK)
        );

        $to = self::MOVED_TO;
        self::q("UPDATE 202_ppc_accounts SET ppc_network_id = $to WHERE ppc_account_id = " . self::USER);
        self::q("UPDATE 202_aff_campaigns SET aff_network_id = $to WHERE aff_campaign_id = " . self::USER);
        self::reroll(self::CLICK);

        self::assertSame(
            self::expected(self::USER, self::MOVED_TO, self::USER, self::MOVED_TO, self::USER),
            self::reportRow(self::CLICK),
            'the row re-rolled after the move names the source and category the account and campaign are in now'
        );
    }

    public function testAReRollClearsAnotherAccountsRecordsFromARowRolledUpBeforeTheJoinsWereTied(): void
    {
        // The row as the rollup wrote it before 229df10: the source and text
        // ad of the other account's records the click named.
        self::row('202_dataengine', [
            'user_id' => self::USER, 'click_id' => self::STALE_CLICK, 'click_time' => self::$now - 20 * 86400,
            'ppc_account_id' => self::OTHER, 'ppc_network_id' => self::OTHER, 'aff_campaign_id' => self::USER,
            'aff_network_id' => self::USER, 'landing_page_id' => 0, 'text_ad_id' => self::OTHER, 'clicks' => 1,
            'click_out' => 0, 'leads' => 0, 'payout' => 0, 'income' => 0, 'cost' => 0,
        ]);
        self::reroll(self::STALE_CLICK);

        self::assertSame(
            self::expected(self::OTHER, null, self::USER, self::USER, null),
            self::reportRow(self::STALE_CLICK),
            'a re-roll writes what a fresh rollup writes: the other account\'s source and text ad read as none'
        );
    }

    public function testMovingAnAccountToAnotherSourceQueuesItsClicksForTheCronPass(): void
    {
        self::reroll(self::CLICK);
        (new PpcAccountsController(self::$db, self::USER))->update(self::USER, ['ppc_network_id' => self::MOVED_TO]);

        self::assertSame(
            [[
                'ppc_account_id' => (string) self::USER, 'aff_campaign_id' => '0',
                'click_time_from' => '0', 'processed' => '0',
            ]],
            self::queued(),
            'every click the account has up to now is queued'
        );
        self::runCronPass();
        self::assertSame(
            (string) self::MOVED_TO,
            self::reportRow(self::CLICK)['ppc_network_id'] ?? 'no row',
            'the cron pass rolls the account\'s clicks up under the source it is in now'
        );
    }

    public function testMovingACampaignToAnotherCategoryQueuesItsClicksForTheCronPass(): void
    {
        self::reroll(self::CLICK);
        (new CampaignsController(self::$db, self::USER))->update(self::USER, ['aff_network_id' => self::MOVED_TO]);

        self::assertSame(
            [[
                'ppc_account_id' => '0', 'aff_campaign_id' => (string) self::USER,
                'click_time_from' => '0', 'processed' => '0',
            ]],
            self::queued(),
            'every click the campaign has up to now is queued'
        );
        self::runCronPass();
        self::assertSame(
            (string) self::MOVED_TO,
            self::reportRow(self::CLICK)['aff_network_id'] ?? 'no row',
            'the cron pass rolls the campaign\'s clicks up under the category it is in now'
        );
    }

    public function testAnUpdateThatMovesNothingQueuesNothing(): void
    {
        (new PpcAccountsController(self::$db, self::USER))
            ->update(self::USER, ['ppc_account_name' => 'Renamed', 'ppc_network_id' => self::USER]);
        (new CampaignsController(self::$db, self::USER))
            ->update(self::USER, ['aff_campaign_name' => 'Renamed', 'aff_network_id' => self::USER]);

        self::assertSame([], self::queued(), 'a rename re-rolls nothing');
    }
}
