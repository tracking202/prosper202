<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * The Overview chart (DataEngine::getChart(), for account_overview.php and
 * charts.php) counts the signed-in account's clicks and no one else's.
 *
 * Its query had no account condition: "Clicks (all)" summed every account's
 * clicks in the window — measured on a live instance, an account with 157
 * clicks was charted 1157 once another account had 1000 — and a campaign
 * line drew whichever account owned that campaign id. It now scopes as the
 * engine's other readers do.
 *
 * Each case boots the app in a child process against the scratch database
 * (fixtures/report-read-failure-runner.php, with no table locked).
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class ChartAccountScopeIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 990041;
    private const OTHER = 990042;
    private const OTHER_CAMPAIGN = 990042;

    private static string $root = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        if (!is_file(self::$root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any reader ran. tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once self::$root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        self::$versions = array_column($found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [], 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user, 'user_pref_time_predefined' => 'today', 'user_pref_show' => 'all']);
        }
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => self::OTHER_CAMPAIGN,
            'user_id' => self::OTHER,
            'aff_campaign_name' => 'Their Offer',
            'aff_network_id' => 0,
            'aff_campaign_url' => 'https://their.example/offer',
            'aff_campaign_payout' => 0,
            'aff_campaign_foreign_payout' => 0,
            'aff_campaign_time' => time(),
        ]);
        $now = time();
        // The account's own three clicks, and another account's thousand in
        // the same hour, on its own campaign.
        foreach ([[99004101, self::USER, 0, 3], [99004201, self::OTHER, self::OTHER_CAMPAIGN, 1000]] as [$click, $user, $campaign, $clicks]) {
            self::row('202_dataengine', [
                'user_id' => $user,
                'click_id' => $click,
                'click_time' => $now,
                'aff_campaign_id' => $campaign,
                'ppc_account_id' => 0,
                'landing_page_id' => 0,
                'payout' => 0,
                'clicks' => $clicks,
            ]);
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
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $user) {
            self::q('DELETE FROM 202_dataengine WHERE user_id = ' . $user);
            self::q('DELETE FROM 202_users_pref WHERE user_id = ' . $user);
            self::q('DELETE FROM 202_users WHERE user_id = ' . $user);
        }
        self::q('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id = ' . self::OTHER_CAMPAIGN);
    }

    /** The chart's one series, as the runner printed it. @return array<string, mixed> */
    private static function chart(string $campaign): array
    {
        self::requireScratch();
        $env = ['P202_TEST_REPORT_USER' => (string) self::USER, 'P202_TEST_CHART_CAMPAIGN' => $campaign] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/report-read-failure-runner.php', 'engine-chart', 'none'],
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
        self::assertCount(1, $result['series'] ?? [], $out);

        return $result['series'][0];
    }

    public function testAllCampaignsIsTheAccountsClicksOnly(): void
    {
        $series = self::chart('0');
        self::assertSame('Clicks (all)', $series['name']);
        self::assertSame(3, (int) array_sum(array_map('intval', $series['data'])), 'the chart counted another account\'s clicks');
    }

    public function testAnotherAccountsCampaignDrawsNothing(): void
    {
        $series = self::chart((string) self::OTHER_CAMPAIGN);
        self::assertSame(0, (int) array_sum(array_map('intval', $series['data'])), 'a campaign line drew another account\'s campaign');
        self::assertStringNotContainsString('Their Offer', (string) $series['name'], 'and named it');
    }
}
