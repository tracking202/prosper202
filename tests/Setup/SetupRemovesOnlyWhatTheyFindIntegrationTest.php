<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * A Setup page's Remove says "Removed" only when it removed one of the
 * account's own records (BKX5).
 *
 * The campaign category, campaign, landing page, text ad, traffic source and
 * traffic source account pages ran their tied UPDATE and redirected to
 * ?deleted=1 whatever it matched, so another account's id, one removed
 * already, or an id that is not one read as removed; `12abc` compared as
 * text removed record 12. Get Links' remove answered 200 the same way, and
 * the list took the row off the page. Each page runs as A in a process of
 * its own (fixtures/page-runner.php), as the web server runs it.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class SetupRemovesOnlyWhatTheyFindIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990271;
    private const OTHER = 990272;
    private const ROLE = 990271;
    /** A's second record of each kind, removed by its id with letters after it. */
    private const SUFFIXED = 990273;

    /**
     * kind => [page, parameter, table, id column, deleted column, sentence].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private const KINDS = [
        'campaign category' => ['tracking202/setup/aff_networks.php', 'delete_aff_network_id', '202_aff_networks', 'aff_network_id', 'aff_network_deleted',
            'That campaign category was not found on this account. Nothing was removed.'],
        'campaign' => ['tracking202/setup/aff_campaigns.php', 'delete_aff_campaign_id', '202_aff_campaigns', 'aff_campaign_id', 'aff_campaign_deleted',
            'That campaign was not found on this account. Nothing was removed.'],
        'landing page' => ['tracking202/setup/landing_pages.php', 'delete_landing_page_id', '202_landing_pages', 'landing_page_id', 'landing_page_deleted',
            'That landing page was not found on this account. Nothing was removed.'],
        'text ad' => ['tracking202/setup/text_ads.php', 'delete_text_ad_id', '202_text_ads', 'text_ad_id', 'text_ad_deleted',
            'That text ad was not found on this account. Nothing was removed.'],
        'traffic source' => ['tracking202/setup/ppc_accounts.php', 'delete_ppc_network_id', '202_ppc_networks', 'ppc_network_id', 'ppc_network_deleted',
            'That traffic source was not found on this account. Nothing was removed.'],
        'traffic source account' => ['tracking202/setup/ppc_accounts.php', 'delete_ppc_account_id', '202_ppc_accounts', 'ppc_account_id', 'ppc_account_deleted',
            'That traffic source account was not found on this account. Nothing was removed.'],
    ];

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<int> the permission rows this class added */
    private static array $addedPermissions = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran. tests/run-integration-suites.sh writes one.');
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
        self::seed();
        self::$sessions = sys_get_temp_dir() . '/p202-page-runner-removes-' . getmypid();
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
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $user) {
            foreach (['202_user_role', '202_users_pref', '202_users', '202_aff_networks', '202_aff_campaigns', '202_ppc_networks',
                '202_ppc_accounts', '202_landing_pages', '202_text_ads', '202_trackers', '202_dirty_hours'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    private static function seed(): void
    {
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user]);
        }
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = " . self::USER);
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'remove test']);
        foreach (['access_to_setup_section', 'remove_campaign_category', 'remove_campaign', 'remove_landing_page', 'remove_text_ad',
            'remove_traffic_source', 'remove_traffic_source_account', 'remove_tracker'] as $name) {
            $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = '$name'");
            $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
            if ($permission === null) {
                $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$name'");
                self::$addedPermissions[] = $permission;
            }
            self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        }
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ROLE]);

        $t = time() - 3600;
        foreach ([self::USER, self::OTHER, self::SUFFIXED] as $id) {
            $owner = $id === self::OTHER ? self::OTHER : self::USER;
            self::row('202_aff_networks', ['aff_network_id' => $id, 'user_id' => $owner, 'aff_network_name' => "Category $id", 'aff_network_time' => $t]);
            self::row('202_aff_campaigns', ['aff_campaign_id' => $id, 'user_id' => $owner, 'aff_network_id' => $id, 'aff_campaign_name' => "Offer $id",
                'aff_campaign_url' => 'https://offer.example/' . $id, 'aff_campaign_payout' => 9, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t]);
            self::row('202_landing_pages', ['landing_page_id' => $id, 'user_id' => $owner, 'aff_campaign_id' => $id, 'landing_page_nickname' => "Page $id",
                'landing_page_url' => 'https://lp.example/' . $id, 'landing_page_time' => $t]);
            self::row('202_text_ads', ['text_ad_id' => $id, 'user_id' => $owner, 'aff_campaign_id' => $id, 'landing_page_id' => 0, 'text_ad_name' => "Ad $id",
                'text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'u', 'text_ad_time' => $t]);
            self::row('202_ppc_networks', ['ppc_network_id' => $id, 'user_id' => $owner, 'ppc_network_name' => "Source $id", 'ppc_network_time' => $t]);
            self::row('202_ppc_accounts', ['ppc_account_id' => $id, 'user_id' => $owner, 'ppc_network_id' => $id, 'ppc_account_name' => "Account $id",
                'ppc_account_time' => $t]);
            self::row('202_trackers', ['tracker_id' => $id, 'user_id' => $owner, 'tracker_id_public' => $id, 'aff_campaign_id' => $id, 'text_ad_id' => 0,
                'ppc_account_id' => $id, 'landing_page_id' => 0, 'rotator_id' => 0, 'click_cpc' => 0.1, 'click_cloaking' => 0, 'tracker_time' => $t]);
        }
    }

    /**
     * What a page printed, run as A.
     *
     * @param array<string, string> $get
     * @param array<string, string> $post a POST's fields, when it is one
     */
    private static function page(string $page, array $get = [], array $post = []): string
    {
        self::requireScratch();
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => (string) json_encode((object) $post),
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', __DIR__ . '/fixtures/page-runner.php', $page],
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
        self::assertStringNotContainsString('Fatal error', $out . $err, "$page: $err");
        self::assertStringNotContainsStringIgnoringCase('database error', $out, "$page: $err");

        return $out;
    }

    /** A record's owner, deleted flag and stamp, as "user|deleted|time". */
    private static function state(string $table, string $idColumn, string $deletedColumn, int $id): string
    {
        $prefix = substr($idColumn, 0, -3);
        $found = self::$db->query("SELECT CONCAT(user_id, '|', $deletedColumn, '|', {$prefix}_time) FROM $table WHERE $idColumn = $id");
        self::assertInstanceOf(\mysqli_result::class, $found, (string) self::$db->error);

        return (string) ($found->fetch_row()[0] ?? '');
    }

    /** @return iterable<string, array{string}> */
    public static function kinds(): iterable
    {
        foreach (array_keys(self::KINDS) as $kind) {
            yield $kind => [$kind];
        }
    }

    /** @dataProvider kinds */
    public function testARemoveSaysRemovedOnlyWhenItRemovedTheAccountsOwn(string $kind): void
    {
        [$page, $param, $table, $idColumn, $deletedColumn, $refused] = self::KINDS[$kind];
        $remove = static fn (string $id): string => self::page($page, [$param => $id, 'token' => 'p202pagerunnertoken']);

        $theirs = self::state($table, $idColumn, $deletedColumn, self::OTHER);
        self::assertSame(self::OTHER . '|0|', substr($theirs, 0, strlen(self::OTHER . '|0|')), "$kind: seeded");
        $out = $remove((string) self::OTHER);
        self::assertStringContainsString($refused, $out, "another account's $kind is not found");
        self::assertSame($theirs, self::state($table, $idColumn, $deletedColumn, self::OTHER), "another account's $kind is as it was");

        $suffixed = self::state($table, $idColumn, $deletedColumn, self::SUFFIXED);
        foreach ([self::SUFFIXED . 'abc', '0' . self::SUFFIXED, '0', '-1', '99999999', ''] as $notOne) {
            self::assertStringContainsString($refused, $remove($notOne), "$kind: '$notOne' names nothing");
        }
        self::assertSame($suffixed, self::state($table, $idColumn, $deletedColumn, self::SUFFIXED), "$kind: an id with letters after it removed nothing");

        self::assertSame('', $remove((string) self::USER), "the account's own $kind is removed, and the page answers with a redirect");
        $removed = self::state($table, $idColumn, $deletedColumn, self::USER);
        self::assertSame(self::USER . '|1|', substr($removed, 0, strlen(self::USER . '|1|')), "$kind: marked removed");

        // Within the same second a second mark would change nothing and count
        // no row whether or not it is refused; an older stamp makes it count.
        self::q("UPDATE $table SET " . substr($idColumn, 0, -3) . '_time = 1000 WHERE ' . $idColumn . ' = ' . self::USER);
        $removed = self::state($table, $idColumn, $deletedColumn, self::USER);
        self::assertStringContainsString($refused, $remove((string) self::USER), "a $kind removed already is not removed again");
        self::assertSame($removed, self::state($table, $idColumn, $deletedColumn, self::USER), "$kind: and its stamp is as the remove left it");
    }

    /** What Get Links' remove answered, with its status. */
    private static function removeLink(string $id): string
    {
        return self::page('tracking202/ajax/delete_tracker.php', [], ['token' => 'SESSION', 'tracker_id' => $id]);
    }

    private static function links(int $id): int
    {
        $found = self::$db->query("SELECT COUNT(*) FROM 202_trackers WHERE tracker_id = $id");
        self::assertInstanceOf(\mysqli_result::class, $found, (string) self::$db->error);

        return (int) $found->fetch_row()[0];
    }

    public function testGetLinksRemovesOnlyTheAccountsOwnLink(): void
    {
        $refused = 'it was not found on this account';
        self::assertStringContainsString($refused, self::removeLink((string) self::OTHER));
        self::assertSame(1, self::links(self::OTHER), 'another account\'s link is kept');
        self::assertStringContainsString($refused, self::removeLink(self::SUFFIXED . 'abc'));
        self::assertSame(1, self::links(self::SUFFIXED), 'an id with letters after it removed nothing');

        self::assertStringNotContainsString($refused, self::removeLink((string) self::USER));
        self::assertSame(0, self::links(self::USER), 'the account\'s own link is removed');
        self::assertStringContainsString($refused, self::removeLink((string) self::USER), 'and a second remove finds nothing');
    }
}
