<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * Get Links' save (tracking202/ajax/generate_tracking_link.php) writes a
 * tracker in one Connection::transaction(): an edit deletes the tracker and
 * inserts it again, and a failure after the DELETE must leave the tracker
 * the account had. The page runs as the account in a process of its own
 * (fixtures/page-runner.php), as the web server runs it.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class TrackerLinkTransactionIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990081;
    private const ROLE = 990081;
    private const CAMPAIGN = 990081;
    /** A campaign the trigger below refuses a tracker on. */
    private const REFUSED_CAMPAIGN = 990082;
    private const NETWORK = 990081;

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    private static ?int $addedPermission = null;

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
        self::seed();
        self::$sessions = sys_get_temp_dir() . '/p202-tracker-link-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::q('DROP TRIGGER IF EXISTS p202_test_refuse_tracker');
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DELETE FROM 202_trackers WHERE user_id = ' . self::USER);
    }

    public function testALinkIsSavedAndAnEditReplacesItWhole(): void
    {
        $out = self::save(['aff_campaign_id' => (string) self::CAMPAIGN]);
        $created = self::trackers();
        self::assertCount(1, $created, $out);
        $public = (int) $created[0]['tracker_id_public'];
        self::assertGreaterThan(0, $public, 'the public id was written in the same transaction');
        self::assertStringContainsString('t202id=' . $public, $out);

        $out = self::save([
            'aff_campaign_id' => (string) self::CAMPAIGN,
            'click_cloaking' => '1',
            'edit_tracker' => '1',
            'tracker_id' => (string) $public,
        ]);
        $edited = self::trackers();
        self::assertCount(1, $edited, $out);
        self::assertNotSame($created[0]['tracker_id'], $edited[0]['tracker_id'], 'an edit writes the tracker again');
        self::assertSame((string) $public, $edited[0]['tracker_id_public'], 'under the public id it had');
        self::assertSame('1', $edited[0]['click_cloaking']);
    }

    public function testAnEditThatFailsAfterItsDeleteLeavesTheTrackerAsItWas(): void
    {
        self::save(['aff_campaign_id' => (string) self::CAMPAIGN]);
        $before = self::trackers();
        self::assertCount(1, $before);

        self::q("CREATE TRIGGER p202_test_refuse_tracker BEFORE INSERT ON 202_trackers FOR EACH ROW
            IF NEW.aff_campaign_id = " . self::REFUSED_CAMPAIGN . " THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tracker refused by the test';
            END IF");
        try {
            $out = self::save([
                'aff_campaign_id' => (string) self::REFUSED_CAMPAIGN,
                'edit_tracker' => '1',
                'tracker_id' => (string) $before[0]['tracker_id_public'],
            ]);
        } finally {
            self::q('DROP TRIGGER IF EXISTS p202_test_refuse_tracker');
        }

        $refused = self::requireScratch()->query("SELECT COUNT(*) FROM 202_mysql_errors WHERE user_id = " . self::USER
            . " AND mysql_error_text = 'tracker refused by the test'");
        self::assertInstanceOf(\mysqli_result::class, $refused);
        self::assertSame('1', (string) $refused->fetch_row()[0], 'the failure the page recorded is the trigger\'s');
        self::assertStringNotContainsString('t202id=', $out, 'no link for a save that failed');
        self::assertSame($before, self::trackers(), 'the DELETE rolled back with the failed INSERT');
    }

    private static function cleanUp(): void
    {
        $tables = [
            '202_user_role', '202_users_pref', '202_users', '202_aff_networks', '202_aff_campaigns',
            '202_trackers', '202_mysql_errors',
        ];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        if (self::$addedPermission !== null) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . self::$addedPermission);
            self::$addedPermission = null;
        }
    }

    private static function seed(): void
    {
        $t = time();
        $u = self::USER;
        self::user($u);
        self::row('202_users_pref', ['user_id' => $u]);
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = $u");
        $setup = "permission_description = 'access_to_setup_section'";
        $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE $setup");
        $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
        if ($permission === null) {
            self::$addedPermission = self::insert("INSERT INTO 202_permissions SET $setup");
            $permission = self::$addedPermission;
        }
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'tracker link test']);
        self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        self::row('202_user_role', ['user_id' => $u, 'role_id' => self::ROLE]);
        self::row('202_aff_networks', [
            'aff_network_id' => self::NETWORK, 'user_id' => $u,
            'aff_network_name' => 'Category', 'aff_network_time' => $t,
        ]);
        foreach ([self::CAMPAIGN, self::REFUSED_CAMPAIGN] as $campaign) {
            self::row('202_aff_campaigns', [
                'aff_campaign_id' => $campaign, 'user_id' => $u, 'aff_network_id' => self::NETWORK,
                'aff_campaign_name' => 'Offer ' . $campaign, 'aff_campaign_url' => 'https://offer.example/' . $campaign,
                'aff_campaign_payout' => 1, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t,
            ]);
        }
    }

    /** @return list<array<string, string>> the account's trackers */
    private static function trackers(): array
    {
        $found = self::requireScratch()->query('SELECT tracker_id, tracker_id_public, aff_campaign_id, click_cloaking'
            . ' FROM 202_trackers'
            . ' WHERE user_id = ' . self::USER . ' ORDER BY tracker_id');
        self::assertInstanceOf(\mysqli_result::class, $found);

        return array_map(static fn (array $r): array => array_map('strval', $r), $found->fetch_all(MYSQLI_ASSOC));
    }

    /**
     * What Get Links' save printed for a direct link, with $fields over the
     * form's other fields.
     *
     * @param array<string, string> $fields
     */
    private static function save(array $fields): string
    {
        self::requireScratch();
        $post = $fields + [
            'token' => 'SESSION',
            'tracker_type' => '0',
            'aff_network_id' => (string) self::NETWORK,
            'method_of_promotion' => 'directlink',
            'click_cloaking' => '0',
            'cost_type' => 'cpc',
            'cpc_dollars' => '0',
            'cpc_cents' => '10',
        ];
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => '{}',
            'P202_TEST_POST' => (string) json_encode($post),
            'P202_TEST_AJAX' => '1',
        ] + getenv();
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                __DIR__ . '/fixtures/page-runner.php', 'tracking202/ajax/generate_tracking_link.php',
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

        return $out;
    }
}
