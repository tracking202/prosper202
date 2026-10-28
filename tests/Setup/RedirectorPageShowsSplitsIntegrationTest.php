<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * Setup › Redirector shows a rule with more than one destination as a split
 * test, whatever its splittest says.
 *
 * The redirects never read splittest: they split a rule's visitors between
 * its destinations by weight whenever it has more than one. The API stores
 * such a rule with splittest 0 (a rule sent with two redirects and no
 * splittest). The editor showed it with the switch off, which shows and
 * posts only the first destination, so saving the redirector removed the
 * others with nothing said (measured in a browser); the list named its
 * destinations without their weights. The switch is on for such a rule, so
 * the editor shows each destination with its weight and its Save keeps them,
 * and the list says it is a split test.
 *
 * The page runs as the account in a process of its own
 * (fixtures/page-runner.php). Needs a 202-config.php and P202_TEST_DB_*.
 *
 * @group integration
 */
final class RedirectorPageShowsSplitsIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990231;
    private const ROLE = 990231;
    private const ROTATOR = 990231;
    /** Rule ids: one destination, two with splittest 0, one with splittest 1. */
    private const ONE = 990231;
    private const TWO = 990232;
    private const SPLIT_ONE = 990233;

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<int> */
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
        self::$sessions = sys_get_temp_dir() . '/p202-page-runner-split-' . getmypid();
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
        $rules = self::ONE . ', ' . self::TWO . ', ' . self::SPLIT_ONE;
        self::q("DELETE FROM 202_rotator_rules_redirects WHERE rule_id IN ($rules)");
        self::q("DELETE FROM 202_rotator_rules_criteria WHERE rule_id IN ($rules)");
        self::q("DELETE FROM 202_rotator_rules WHERE id IN ($rules)");
        foreach (['202_user_role', '202_users_pref', '202_users', '202_rotators'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
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
        self::user(self::USER);
        self::row('202_users_pref', ['user_id' => self::USER]);
        // A licence key on file (the session has already validated it).
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = " . self::USER);
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'split test']);
        $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = 'access_to_setup_section'");
        $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
        if ($permission === null) {
            $permission = self::insert("INSERT INTO 202_permissions SET permission_description = 'access_to_setup_section'");
            self::$addedPermissions[] = $permission;
        }
        self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ROLE]);

        self::row('202_rotators', ['id' => self::ROTATOR, 'public_id' => self::ROTATOR, 'user_id' => self::USER,
            'name' => 'Split Redirector', 'default_url' => 'https://default.example/']);
        $rules = [
            [self::ONE, 'one plain', 0, [['https://one.example/', '0']]],
            [self::TWO, 'two by weight', 0, [['https://a.example/', '60'], ['https://b.example/', '40']]],
            [self::SPLIT_ONE, 'one split', 1, [['https://s.example/', '100']]],
        ];
        foreach ($rules as $position => [$id, $name, $splittest, $redirects]) {
            self::row('202_rotator_rules', ['id' => $id, 'rotator_id' => self::ROTATOR, 'rule_name' => $name,
                'splittest' => $splittest, 'status' => 1, 'position' => $position + 1]);
            self::row('202_rotator_rules_criteria', ['rotator_id' => self::ROTATOR, 'rule_id' => $id,
                'type' => 'country', 'statement' => 'is', 'value' => 'ALL']);
            foreach ($redirects as [$url, $weight]) {
                self::row('202_rotator_rules_redirects', ['rule_id' => $id, 'redirect_url' => $url, 'weight' => $weight, 'name' => $url]);
            }
        }
    }

    /** @param array<string, string> $get */
    private static function page(string $page, array $get): string
    {
        self::requireScratch();
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => '{}',
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

        return $out;
    }

    /** The editor's card of one rule: from its section to the section's end. */
    private static function card(string $out, int $rule): string
    {
        $at = strpos($out, 'data-rule data-rule-id="' . $rule . '"');
        self::assertNotFalse($at, "the editor has no card for rule $rule");
        $end = strpos($out, '</section>', $at);
        self::assertNotFalse($end);

        return substr($out, $at, $end - $at);
    }

    /** The list's line for one rule, by its name. */
    private static function listed(string $out, string $name): string
    {
        $at = strpos($out, '<span class="p202-list__name">' . $name . '</span>');
        self::assertNotFalse($at, "the list does not name the rule $name");
        $end = strpos($out, '</li>', $at);
        self::assertNotFalse($end);

        return substr($out, $at, $end - $at);
    }

    public function testARuleWithMoreThanOneDestinationIsShownAsTheSplitTestTheRedirectsMakeOfIt(): void
    {
        $out = self::page('tracking202/setup/rotator.php', ['rotator_id' => (string) self::ROTATOR]);

        $two = self::card($out, self::TWO);
        self::assertStringContainsString('data-rule-field="split" checked', $two, 'the switch is on, so the editor shows and saves every destination');
        self::assertStringContainsString('data-redirect-list data-split="1"', $two);
        self::assertSame(2, substr_count($two, 'data-redirect data-redirect-id='), 'with both destinations');
        self::assertStringContainsString('value="60"', $two, 'and their weights');
        self::assertStringContainsString('value="40"', $two);
        $line = self::listed($out, 'two by weight');
        self::assertStringContainsString('split test', $line);
        self::assertStringContainsString('https://a.example/ (weight 60), https://b.example/ (weight 40)', $line);

        // One destination: as the rule says.
        self::assertStringNotContainsString('data-rule-field="split" checked', self::card($out, self::ONE));
        self::assertStringNotContainsString('split test', self::listed($out, 'one plain'));
        self::assertStringNotContainsString('(weight', self::listed($out, 'one plain'));
        self::assertStringContainsString('data-rule-field="split" checked', self::card($out, self::SPLIT_ONE));
        self::assertStringContainsString('https://s.example/ (weight 100)', self::listed($out, 'one split'));
    }
}
