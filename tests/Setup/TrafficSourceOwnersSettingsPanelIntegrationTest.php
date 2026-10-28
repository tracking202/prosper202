<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * Account › Settings tells an install admin about the traffic sources another
 * user's traffic source accounts name, lists them and moves one back
 * (202-config/functions-traffic-source-owners-ui.php), through
 * TrafficSourceOwnersController behind the routes' own checks: Admin, then
 * access_to_settings, then a user who may act on both users. Each request
 * runs 202-account/administration.php in a process of its own, as the web
 * server runs it (fixtures/settings-page-runner.php).
 *
 * TAKER (a Campaign manager) holds OWNER's source TAKEN, which OWNER's
 * accounts name and nobody else's: it goes back to OWNER. SHARED is in
 * TAKER's account and accounts of OWNER and THIRD name it: it moves only to
 * a user the operator chooses. ADMIN is an Admin; VIEWER's role has
 * access_to_settings and is not an admin role, so the page opens for VIEWER
 * and the routes refuse it.
 *
 * The report is install-wide: point it at a scratch database, never one an
 * instance uses. Needs a 202-config.php (connect.php exits without one) and
 * P202_TEST_DB_*.
 *
 * @group integration
 */
final class TrafficSourceOwnersSettingsPanelIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ADMIN = 880101;
    private const VIEWER = 880102;
    private const OWNER = 880103;
    private const TAKER = 880104;
    private const THIRD = 880105;
    /** An admin role's name with no permission: the page never opens for it. */
    private const BARE_ADMIN = 880106;
    /** A role with access_to_settings whose name is no admin role's. */
    private const SETTINGS_ROLE = 880101;
    private const BARE_ADMIN_ROLE = 880102;
    /** user => role: Admin 2, Campaign manager 3 */
    private const ROLES = [
        self::ADMIN => 2,
        self::VIEWER => self::SETTINGS_ROLE,
        self::OWNER => 3,
        self::TAKER => 3,
        self::THIRD => 3,
        self::BARE_ADMIN => self::BARE_ADMIN_ROLE,
    ];

    private const TAKEN = 880201;
    private const SHARED = 880202;
    /** [ppc_account_id, user, source] */
    private const ACCOUNTS = [
        [880301, self::OWNER, self::TAKEN],
        [880302, self::OWNER, self::TAKEN],
        [880303, self::OWNER, self::SHARED],
        [880304, self::THIRD, self::SHARED],
    ];

    private const SAVED = 1_000_000_000;
    private const RENAMED = 1_100_000_000;

    private const REVIEW = ['traffic_sources' => 'review'];
    private const HEADER = 'p202-page-header__title">Settings';

    private static string $sessions = '';
    private static bool $createdUserOne = false;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before the page ran.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        $seeder = new \Prosper202\Database\DataSeeder(self::$db);
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        require_once $root . '/202-config/version.php';
        require_once $root . '/202-config/functions-account-ui.php';
        $versions = self::$db->query('SELECT COUNT(*) FROM 202_version');
        if ((int) ($versions instanceof \mysqli_result ? $versions->fetch_row()[0] : 0) === 0) {
            self::row('202_version', ['version' => PROSPER202_VERSION]);
        }
        // The page reads user 1's preferences (the install's retention).
        $one = self::$db->query('SELECT 1 FROM 202_users WHERE user_id = 1');
        if ($one instanceof \mysqli_result && $one->num_rows === 0) {
            self::user(1);
            self::row('202_users_pref', ['user_id' => 1]);
            self::$createdUserOne = true;
        }
        self::$sessions = sys_get_temp_dir() . '/p202-settings-runner-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$createdUserOne) {
                self::q('DELETE FROM 202_users_pref WHERE user_id = 1');
                self::q('DELETE FROM 202_users WHERE user_id = 1');
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
        self::cleanUp();
        self::row('202_roles', ['role_id' => self::SETTINGS_ROLE, 'role_name' => 'settings only']);
        $found = self::$db->query(
            "SELECT permission_id FROM 202_permissions WHERE permission_description = 'access_to_settings'"
        );
        $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
        self::assertNotNull($permission, 'the seeder wrote access_to_settings');
        self::row('202_role_permission', ['role_id' => self::SETTINGS_ROLE, 'permission_id' => (int) $permission]);
        self::row('202_roles', ['role_id' => self::BARE_ADMIN_ROLE, 'role_name' => 'Administrator']);
        foreach (self::ROLES as $user => $role) {
            self::user($user);
            self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = $user");
            self::row('202_users_pref', ['user_id' => $user]);
            self::row('202_user_role', ['user_id' => $user, 'role_id' => $role]);
        }
        // Both sources are TAKER's now; OWNER's accounts were saved before.
        foreach ([self::TAKEN => 'Taken Source', self::SHARED => 'Shared Source'] as $id => $name) {
            self::row('202_ppc_networks', [
                'ppc_network_id' => $id,
                'user_id' => self::TAKER,
                'ppc_network_name' => $name,
                'ppc_network_time' => self::RENAMED,
            ]);
        }
        foreach (self::ACCOUNTS as [$id, $user, $source]) {
            self::row('202_ppc_accounts', [
                'ppc_account_id' => $id,
                'user_id' => $user,
                'ppc_network_id' => $source,
                'ppc_account_name' => 'Account ' . $id,
                'ppc_account_time' => self::SAVED,
            ]);
        }
        // Three clicks on TAKEN's accounts.
        foreach ([[880401, 880301], [880402, 880301], [880403, 880302]] as [$click, $account]) {
            self::row('202_clicks', [
                'click_id' => $click,
                'user_id' => self::OWNER,
                'ppc_account_id' => $account,
                'click_cpc' => 0,
                'click_time' => self::SAVED + 100,
            ]);
        }
    }

    private static function cleanUp(): void
    {
        $users = implode(',', array_keys(self::ROLES));
        foreach (['202_user_role', '202_users_pref', '202_users', '202_clicks', '202_dirty_hours'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
        self::q('DELETE FROM 202_ppc_networks WHERE ppc_network_id IN (' . self::TAKEN . ', ' . self::SHARED . ')');
        self::q('DELETE FROM 202_ppc_accounts WHERE ppc_account_id BETWEEN 880300 AND 880399');
        $roles = self::SETTINGS_ROLE . ', ' . self::BARE_ADMIN_ROLE;
        self::q("DELETE FROM 202_role_permission WHERE role_id IN ($roles)");
        self::q("DELETE FROM 202_roles WHERE role_id IN ($roles)");
    }

    /**
     * administration.php as $user.
     *
     * @param array<string, string> $get
     * @param array<string, string> $post a POST when not empty; token SESSION is the session's
     * @return array{out: string, status: int, flashes: list<array{kind: string, text: string}>}
     */
    private static function settings(int $user, array $get = [], array $post = []): array
    {
        self::requireScratch();
        $env = [
            'P202_TEST_REPORT_USER' => (string) $user,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => (string) json_encode((object) $post),
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                __DIR__ . '/fixtures/settings-page-runner.php', '202-account/administration.php'],
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
        $ended = preg_match('/\nP202-RUNNER-END (\{.*\})\n\z/', $out, $end);
        self::assertSame(1, $ended, "the page ran to its end: $err");
        $answer = json_decode($end[1], true, 8, JSON_THROW_ON_ERROR);

        return [
            'out' => substr($out, 0, -strlen($end[0])),
            'status' => (int) $answer['status'],
            'flashes' => $answer['flashes'],
        ];
    }

    /**
     * The body the panel's confirmation posts for TAKEN, as it was drawn.
     *
     * @return array<string, string>
     */
    private static function repairOfTaken(): array
    {
        return [
            'token' => 'SESSION',
            'traffic_source_repair' => (string) self::TAKEN,
            'owner_user_id' => (string) self::TAKER,
            'repair_to_user_id' => (string) self::OWNER,
        ];
    }

    private static function ownerOf(int $sourceId): int
    {
        $found = self::$db->query('SELECT user_id FROM 202_ppc_networks WHERE ppc_network_id = ' . $sourceId);
        $row = $found instanceof \mysqli_result ? $found->fetch_assoc() : null;
        self::assertIsArray($row, "source $sourceId exists");

        return (int) $row['user_id'];
    }

    private static function queued(string $where): int
    {
        $found = self::$db->query('SELECT COUNT(*) FROM 202_dirty_hours WHERE ' . $where);

        return (int) ($found instanceof \mysqli_result ? $found->fetch_row()[0] : -1);
    }

    /**
     * The one flash a redirect queued.
     *
     * @param array{status: int, flashes: list<array{kind: string, text: string}>} $answer
     */
    private static function flash(array $answer, string $kind): string
    {
        self::assertSame(303, $answer['status'], 'the POST redirects');
        self::assertCount(1, $answer['flashes'], (string) json_encode($answer['flashes']));
        self::assertSame($kind, $answer['flashes'][0]['kind'], $answer['flashes'][0]['text']);

        return $answer['flashes'][0]['text'];
    }

    /** The table row the panel drew for a source. */
    private static function rowOf(string $out, int $sourceId): string
    {
        $found = preg_match('#<tr data-ppc-network-id="' . $sourceId . '">.*?</tr>#s', $out, $m);
        self::assertSame(1, $found, "the panel lists $sourceId");

        return $m[0];
    }

    /** The confirmation the panel drew for a source. */
    private static function modalOf(string $out, int $sourceId): string
    {
        $found = preg_match('#<div class="modal fade" id="ts-repair-' . $sourceId . '".*?</form>#s', $out, $m);
        self::assertSame(1, $found, "a confirmation for $sourceId");

        return $m[0];
    }

    /** "name (user N)" as the panel writes a user. */
    private static function named(int $user): string
    {
        return 'rp' . $user . ' <span class="text-secondary">(user ' . $user . ')</span>';
    }

    public function testAnAdminIsToldAndARoleTheRoutesRefuseIsNot(): void
    {
        $admin = self::settings(self::ADMIN)['out'];
        self::assertStringContainsString('id="traffic-source-owners-notice"', $admin);
        self::assertStringContainsString('2 traffic sources are in one user&#039;s account', $admin);
        self::assertStringContainsString('1 can be moved back as it is; 1 needs you to choose the user', $admin);
        self::assertStringContainsString('administration.php?traffic_sources=review#traffic-source-owners', $admin);
        self::assertStringNotContainsString('id="traffic-source-owners"', $admin, 'the panel opens only when asked');

        foreach ([[], self::REVIEW] as $get) {
            $viewer = self::settings(self::VIEWER, $get)['out'];
            self::assertStringContainsString(self::HEADER, $viewer, 'the page opens for the role');
            self::assertStringNotContainsString('traffic-source-owners', $viewer, 'and says nothing of the sources');
            self::assertStringNotContainsString('Taken Source', $viewer);
        }

        $one = '1 traffic source is in one user&#039;s account';
        self::q('UPDATE 202_ppc_networks SET user_id = ' . self::OWNER . ' WHERE ppc_network_id = ' . self::TAKEN);
        self::assertStringContainsString($one, self::settings(self::ADMIN)['out']);
        self::q('UPDATE 202_ppc_networks SET user_id = ' . self::OWNER . ' WHERE ppc_network_id = ' . self::SHARED);
        self::assertStringContainsString($one, self::settings(self::ADMIN)['out'], 'THIRD still names SHARED');
        self::q('DELETE FROM 202_ppc_accounts WHERE ppc_account_id = 880304');
        $none = self::settings(self::ADMIN)['out'];
        self::assertStringContainsString(self::HEADER, $none);
        self::assertStringNotContainsString('traffic-source-owners-notice', $none, 'nothing listed, nothing said');
    }

    /**
     * The page asks the routes' two checks in the routes' order. Settings
     * does not open for a role without access_to_settings, so the second is
     * asked of the helper directly.
     */
    public function testThePanelsGateIsTheRoutes(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-traffic-source-owners-ui.php';
        self::assertInstanceOf(
            \Api\V3\Controllers\TrafficSourceOwnersController::class,
            \p202_ts_owners_controller(self::$db, self::ADMIN)
        );
        $refusals = [
            self::VIEWER => 'Admin access required.',
            self::BARE_ADMIN => "This account's role does not have the 'access_to_settings' permission.",
        ];
        foreach ($refusals as $user => $refusal) {
            try {
                \p202_ts_owners_controller(self::$db, $user);
                self::fail("user $user is refused");
            } catch (\Api\V3\AuthException $e) {
                self::assertSame(403, $e->getCode());
                self::assertSame($refusal, $e->getMessage());
            }
        }
    }

    public function testThePanelListsTheSourcesAsTheReportDoes(): void
    {
        $out = self::settings(self::ADMIN, self::REVIEW)['out'];
        self::assertStringContainsString('<section class="p202-panel" id="traffic-source-owners">', $out);
        self::assertStringContainsString('2 to review', $out);

        $taken = self::rowOf($out, self::TAKEN);
        self::assertStringContainsString('Taken Source', $taken);
        self::assertStringContainsString(self::named(self::TAKER), $taken, 'the user it is in');
        self::assertStringContainsString(
            '2 traffic source accounts (880301, 880302) · 3 clicks · 0 conversions',
            $taken,
            "OWNER's accounts, counted"
        );
        self::assertStringNotContainsString('you choose', $taken);
        self::assertSame(2, substr_count($taken, self::named(self::OWNER)), 'named by OWNER, and goes to OWNER');
        self::assertStringContainsString('data-bs-target="#ts-repair-' . self::TAKEN . '"', $taken);

        $confirm = self::modalOf($out, self::TAKEN);
        self::assertStringContainsString('name="traffic_source_repair" value="' . self::TAKEN . '"', $confirm);
        self::assertStringContainsString('name="owner_user_id" value="' . self::TAKER . '"', $confirm);
        self::assertStringContainsString('name="repair_to_user_id" value="' . self::OWNER . '"', $confirm);
        self::assertStringContainsString('name="token" value="p202pagerunnertoken"', $confirm);
        self::assertStringContainsString(
            '&ldquo;Taken Source&rdquo; moves from ' . self::named(self::TAKER) . ' to ' . self::named(self::OWNER)
                . ', whose 2 traffic source accounts name it (3 clicks · 0 conversions).',
            $confirm,
            'it names what moves, and where'
        );
        self::assertStringContainsString('administration.php?traffic_sources=review#traffic-source-owners"', $confirm);

        $shared = self::rowOf($out, self::SHARED);
        self::assertStringContainsString('you choose', $shared);
        self::assertStringContainsString('traffic source accounts of more than one other user name it', $shared);
        self::assertStringContainsString(self::named(self::THIRD), $shared);
        $choose = self::modalOf($out, self::SHARED);
        self::assertStringNotContainsString('repair_to_user_id', $choose, 'the user is chosen, not sent back');
        foreach ([self::OWNER, self::THIRD] as $user) {
            self::assertStringContainsString(
                'name="to_user_id" id="ts-repair-' . self::SHARED . '-to-' . $user . '" value="' . $user . '" required',
                $choose
            );
        }
    }

    public function testARepairMovesTheSourceBackAndItLeavesTheList(): void
    {
        self::assertStringContainsString(
            'Traffic source "Taken Source" (' . self::TAKEN . ') moved from user ' . self::TAKER . '\'s account'
                . ' to user ' . self::OWNER . '\'s',
            self::flash(self::settings(self::ADMIN, [], self::repairOfTaken()), 'ok')
        );
        self::assertSame(self::OWNER, self::ownerOf(self::TAKEN));
        self::assertSame(
            2,
            self::queued('user_id = ' . self::OWNER . ' AND ppc_account_id IN (880301, 880302)'),
            "OWNER's accounts are queued for the rollup"
        );

        $out = self::settings(self::ADMIN, self::REVIEW)['out'];
        self::assertStringNotContainsString('data-ppc-network-id="' . self::TAKEN . '"', $out);
        self::assertStringContainsString('data-ppc-network-id="' . self::SHARED . '"', $out);
        self::assertStringContainsString('1 traffic source is in one user&#039;s account', $out);
    }

    public function testAnAmbiguousSourceMovesOnlyToTheUserChosen(): void
    {
        $post = [
            'token' => 'SESSION',
            'traffic_source_repair' => (string) self::SHARED,
            'owner_user_id' => (string) self::TAKER,
        ];
        $text = self::flash(self::settings(self::ADMIN, [], $post), 'bad');
        self::assertStringContainsString('is ambiguous (several_users)', $text);
        self::assertStringContainsString('Nothing was moved.', $text);
        self::assertSame(self::TAKER, self::ownerOf(self::SHARED));

        $text = self::flash(self::settings(self::ADMIN, [], $post + ['to_user_id' => (string) self::ADMIN]), 'bad');
        self::assertStringContainsString('No traffic source account of user ' . self::ADMIN . ' names', $text);
        self::assertSame(self::TAKER, self::ownerOf(self::SHARED));

        self::flash(self::settings(self::ADMIN, [], $post + ['to_user_id' => (string) self::THIRD]), 'ok');
        self::assertSame(self::THIRD, self::ownerOf(self::SHARED));
    }

    public function testASourceThatMovedAfterThePanelWasReadIsRefused(): void
    {
        $confirm = self::modalOf(self::settings(self::ADMIN, self::REVIEW)['out'], self::TAKEN);
        preg_match_all('/<input type="hidden" name="([a-z_]+)" value="([^"]*)">/', $confirm, $fields, PREG_SET_ORDER);
        $post = array_column($fields, 2, 1);
        self::assertSame(['token', 'traffic_source_repair', 'owner_user_id', 'repair_to_user_id'], array_keys($post));
        $post['token'] = 'SESSION';

        // Meanwhile it moves to THIRD's account.
        self::q('UPDATE 202_ppc_networks SET user_id = ' . self::THIRD . ' WHERE ppc_network_id = ' . self::TAKEN);
        $text = self::flash(self::settings(self::ADMIN, [], $post), 'bad');
        self::assertStringContainsString(
            'is now in user ' . self::THIRD . '\'s account, not in user ' . self::TAKER . '\'s as the report you read',
            $text
        );
        self::assertStringContainsString('Nothing was moved.', $text);
        self::assertSame(self::THIRD, self::ownerOf(self::TAKEN));
        self::assertSame(0, self::queued('user_id = ' . self::OWNER), 'nothing was queued');
    }

    public function testARepairWithoutTheSessionTokenIsRefused(): void
    {
        $post = self::repairOfTaken();
        unset($post['token']);
        foreach ([$post, $post + ['token' => 'forged'], $post + ['token' => '']] as $sent) {
            $text = self::flash(self::settings(self::ADMIN, [], $sent), 'bad');
            self::assertSame(\P202_ACCOUNT_TOKEN_REFUSED, $text);
            self::assertSame(self::TAKER, self::ownerOf(self::TAKEN));
        }
    }

    public function testARoleTheRoutesRefuseCannotRepair(): void
    {
        $text = self::flash(self::settings(self::VIEWER, [], self::repairOfTaken()), 'bad');
        self::assertSame('Admin access required. Nothing was moved.', $text);
        self::assertSame(self::TAKER, self::ownerOf(self::TAKEN));
    }

    public function testASessionWhoseUserWasSwitchedOffOrDeletedIsRefusedAsItsKeyIs(): void
    {
        self::q('UPDATE 202_users SET user_active = 0 WHERE user_id = ' . self::ADMIN);
        $out = self::settings(self::ADMIN, self::REVIEW)['out'];
        self::assertStringContainsString(self::HEADER, $out);
        self::assertStringNotContainsString('traffic-source-owners', $out);
        $text = self::flash(self::settings(self::ADMIN, [], self::repairOfTaken()), 'bad');
        self::assertStringStartsWith('The signed-in account is switched off', $text);
        self::assertSame(self::TAKER, self::ownerOf(self::TAKEN));

        self::q('UPDATE 202_users SET user_active = 1, user_deleted = 1 WHERE user_id = ' . self::ADMIN);
        self::assertStringNotContainsString('traffic-source-owners', self::settings(self::ADMIN, self::REVIEW)['out']);
        $text = self::flash(self::settings(self::ADMIN, [], self::repairOfTaken()), 'bad');
        self::assertSame('The signed-in account no longer exists. Nothing was moved.', $text);
        self::assertSame(self::TAKER, self::ownerOf(self::TAKEN));
    }

    public function testAMoveTheRepairWouldRefuseIsNotOffered(): void
    {
        // An Admin acts on another Admin's account only with
        // add_edit_delete_admin, which the Admin role does not carry.
        self::q('UPDATE 202_user_role SET role_id = 2 WHERE user_id = ' . self::TAKER);
        $out = self::settings(self::ADMIN, self::REVIEW)['out'];
        $taken = self::rowOf($out, self::TAKEN);
        self::assertStringContainsString(
            'Changing an Admin or Super user account needs the &#039;add_edit_delete_admin&#039; permission',
            $taken
        );
        self::assertStringNotContainsString('data-bs-target', $taken);
        self::assertStringNotContainsString('id="ts-repair-' . self::TAKEN . '"', $out);
        self::assertStringContainsString('No user you may act on can take it.', self::rowOf($out, self::SHARED));
        self::assertStringNotContainsString('id="ts-repair-' . self::SHARED . '"', $out);

        $text = self::flash(self::settings(self::ADMIN, [], self::repairOfTaken()), 'bad');
        self::assertStringContainsString("needs the 'add_edit_delete_admin' permission", $text);
        self::assertStringEndsWith('Nothing was moved.', $text);
        self::assertSame(self::TAKER, self::ownerOf(self::TAKEN));
    }
}
