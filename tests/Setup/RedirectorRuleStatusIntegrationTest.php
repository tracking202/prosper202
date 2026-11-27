<?php

declare(strict_types=1);

namespace Tests\Setup;

use Api\V3\Controllers\RotatorsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\RuleOrder;

/**
 * A rule's status is read one way by the redirects, Setup › Redirectors and
 * the API (Prosper202\Rotator\RuleStatus). A rule stored before 1.9 has no
 * status (NULL); the redirects pass it by, so the page shows it inactive and
 * a save that changes nothing leaves it not matching traffic.
 *
 * The page showed it active (only '0' read as inactive), and since its form
 * posts every rule's switch, saving anything wrote the rule 1: it began to
 * take visitors nobody had sent it (CLAUDE.md #4, #25).
 *
 * The page runs as the account in a process of its own
 * (fixtures/page-runner.php) and the redirect is served (php -S, rtr.php):
 * every rule here matches every visitor, so a click goes to the first rule
 * the redirects try. Needs a 202-config.php naming the scratch database and
 * P202_TEST_DB_*.
 *
 * @group integration
 */
final class RedirectorRuleStatusIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990271;
    private const ROLE = 990271;
    private const ROTATOR = 990271;
    private const UNSET = 990271;
    private const OFF = 990272;
    private const ON = 990273;
    private const TRACKER = 990271;
    /** rule id => name, stored status, and the host its visitors are sent to; tried in this order */
    private const RULES = [self::UNSET => ['no status', null, 'unset'], self::OFF => ['off', 0, 'off'], self::ON => ['on', 1, 'on']];

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';

    private static string $sessions = '';

    /** @var list<string>|null */
    private static ?array $versions = null;

    /** @var list<int> */
    private static array $addedPermissions = [];

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
        self::$versions = array_column($found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [], 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        self::user(self::USER);
        self::row('202_users_pref', ['user_id' => self::USER, 'user_pref_privacy' => 'disabled']);
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = " . self::USER);
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'rule status test']);
        foreach (['access_to_setup_section', 'remove_rotator_rule'] as $name) {
            $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = '$name'");
            $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
            if ($permission === null) {
                $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$name'");
                self::$addedPermissions[] = $permission;
            }
            self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        }
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ROLE]);
        self::$sessions = sys_get_temp_dir() . '/p202-rule-status-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202rs');
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::$base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$log !== '') {
            @unlink(self::$log);
        }
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

    protected function setUp(): void
    {
        self::requireScratch();
        self::clearRedirector();
        self::row('202_rotators', ['id' => self::ROTATOR, 'public_id' => self::ROTATOR, 'user_id' => self::USER,
            'name' => 'Status Redirector', 'default_url' => 'https://default.example/']);
        $position = 0;
        foreach (self::RULES as $id => [$name, $status, $host]) {
            self::row('202_rotator_rules', ['id' => $id, 'rotator_id' => self::ROTATOR, 'rule_name' => $name,
                'splittest' => 0, 'status' => $status, 'position' => ++$position]);
            self::row('202_rotator_rules_criteria', ['id' => $id, 'rotator_id' => self::ROTATOR, 'rule_id' => $id,
                'type' => 'country', 'statement' => 'is', 'value' => 'ALL']);
            self::row('202_rotator_rules_redirects', ['id' => $id, 'rule_id' => $id,
                'redirect_url' => "https://$host.example/", 'weight' => '100', 'name' => 'URL']);
        }
        self::row('202_trackers', [
            'tracker_id' => self::TRACKER, 'user_id' => self::USER, 'tracker_id_public' => self::TRACKER,
            'aff_campaign_id' => 0, 'text_ad_id' => 0, 'ppc_account_id' => 0, 'landing_page_id' => 0,
            'rotator_id' => self::ROTATOR, 'click_cloaking' => 0, 'tracker_time' => time(),
        ]);
    }

    public function testTheRedirectsTryOnlyARuleStoredActive(): void
    {
        self::assertSame([self::ON], self::tried(), 'a rule with no status, like one switched off, is passed by');
        self::assertSame('https://on.example/', self::click(), 'the first two rules match the visitor and are not tried');

        self::q('UPDATE 202_rotator_rules SET status = 0 WHERE id = ' . self::ON);
        self::assertSame('https://default.example/', self::click());
    }

    public function testThePageShowsARuleWithNoStatusAsTheRedirectsReadIt(): void
    {
        $out = self::page('tracking202/setup/rotator.php', ['rotator_id' => (string) self::ROTATOR]);

        self::assertSame(
            [self::UNSET => true, self::OFF => true, self::ON => false],
            self::inactiveSwitches($out),
            'the Inactive switch is on for every rule the redirects pass by'
        );
        foreach (self::RULES as [$name, $status]) {
            $line = self::listed($out, $name);
            self::assertSame($status !== 1, str_contains($line, '>inactive</span>'), "the list's pill for the rule \"$name\"");
        }
    }

    /** Saving the page as it rendered, nothing touched, switches no rule on. */
    public function testSavingThePageUnchangedSwitchesNoRuleOn(): void
    {
        $out = self::page('tracking202/setup/rotator.php', ['rotator_id' => (string) self::ROTATOR]);
        $data = [];
        // As p202-setup.js posts a card: its status is the Inactive switch.
        foreach (self::inactiveSwitches($out) as $id => $inactive) {
            [$name, , $host] = self::RULES[$id];
            $data[] = [
                'rule_id' => (string) $id, 'rule_name' => $name, 'status' => $inactive ? 'inactive' : 'active', 'split' => 'false',
                'criteria' => [['criteria_id' => (string) $id, 'type' => 'country', 'statement' => 'is', 'value' => 'ALL']],
                'redirects' => [['id' => (string) $id, 'type' => 'url', 'value' => "https://$host.example/"]],
            ];
        }

        $saved = self::page('tracking202/ajax/rotator.php', [], ['token' => 'SESSION', 'post_rules' => '1',
            'rotator_id' => (string) self::ROTATOR, 'default_type' => 'url', 'defaults' => 'https://default.example/', 'data' => $data]);

        self::assertStringStartsWith('DONE', trim($saved));
        self::assertNotSame('1', self::stored()[self::UNSET], 'the rule with no status was not switched on');
        self::assertSame([self::ON], self::tried(), 'the redirects try what they tried before the save');
        self::assertSame('https://on.example/', self::click(), 'and a visitor goes where one went before it');
        self::assertSame(['0', '1'], [self::stored()[self::OFF], self::stored()[self::ON]]);
    }

    /** Switching the rule on in the page is the operator's act, and is stored. */
    public function testSwitchingARuleWithNoStatusOnInThePageStoresItActive(): void
    {
        $saved = self::page('tracking202/ajax/rotator.php', [], ['token' => 'SESSION', 'post_rules' => '1',
            'rotator_id' => (string) self::ROTATOR, 'default_type' => 'url', 'defaults' => 'https://default.example/', 'data' => [[
                'rule_id' => (string) self::UNSET, 'rule_name' => 'no status', 'status' => 'active', 'split' => 'false',
                'criteria' => [['criteria_id' => (string) self::UNSET, 'type' => 'country', 'statement' => 'is', 'value' => 'ALL']],
                'redirects' => [['id' => (string) self::UNSET, 'type' => 'url', 'value' => 'https://unset.example/']],
            ]]]);

        self::assertStringStartsWith('DONE', trim($saved));
        self::assertSame('1', self::stored()[self::UNSET]);
        self::assertSame([self::UNSET], self::tried());
        self::assertSame('https://unset.example/', self::click());
    }

    /** The API answers the stored value, and a write that does not send status keeps it. */
    public function testTheApiAnswersTheStoredStatusAndKeepsOneItIsNotSent(): void
    {
        $rotators = new RotatorsController(self::$db, self::USER);
        $status = static fn (array $answer): array => array_column($answer['data']['rules'], 'status', 'id');

        self::assertSame([self::UNSET => null, self::OFF => 0, self::ON => 1], $status($rotators->get(self::ROTATOR)));

        $rotators->updateRule(self::ROTATOR, self::UNSET, ['rule_name' => 'renamed']);
        self::assertNull(self::stored()[self::UNSET], 'a rule PUT without status keeps none');

        $set = [];
        foreach (self::RULES as $id => [$name, , $host]) {
            $set[] = ['id' => $id, 'rule_name' => $name,
                'criteria' => [['id' => $id, 'type' => 'country', 'statement' => 'is', 'value' => 'ALL']],
                'redirects' => [['id' => $id, 'redirect_url' => "https://$host.example/"]]];
        }
        $set[0]['rule_name'] = 'renamed again';
        $rotators->setRules(self::ROTATOR, ['rules' => $set], false, static fn (): bool => true);
        self::assertSame([self::UNSET => null, self::OFF => '0', self::ON => '1'], self::stored(), 'so does a set');
        self::assertSame([self::ON], self::tried());

        $rotators->updateRule(self::ROTATOR, self::UNSET, ['status' => 0]);
        self::assertSame('0', self::stored()[self::UNSET]);
        $rotators->updateRule(self::ROTATOR, self::UNSET, ['status' => 1]);
        self::assertSame([self::UNSET, self::ON], self::tried(), 'switched on by name, it is tried');
        self::assertSame('https://unset.example/', self::click());
    }

    /** An empty status sends none, as null does: it was read as the default and switched a kept rule on. */
    public function testAnEmptyStatusKeepsAKeptRulesOwn(): void
    {
        $rotators = new RotatorsController(self::$db, self::USER);

        $rotators->updateRule(self::ROTATOR, self::OFF, ['status' => '', 'rule_name' => 'still off']);
        $rotators->updateRule(self::ROTATOR, self::UNSET, ['status' => '', 'rule_name' => 'still none']);

        self::assertSame([self::UNSET => null, self::OFF => '0', self::ON => '1'], self::stored());
    }

    /** The column takes any int. One that names no state takes no visitors, and a write has to name one. */
    public function testAStatusNoStateNamesTakesNoVisitorsUntilAWriteNamesOne(): void
    {
        self::q('UPDATE 202_rotator_rules SET status = 2 WHERE id = ' . self::UNSET);

        self::assertSame([self::ON], self::tried());
        self::assertSame('https://on.example/', self::click());
        $out = self::page('tracking202/setup/rotator.php', ['rotator_id' => (string) self::ROTATOR]);
        self::assertTrue(self::inactiveSwitches($out)[self::UNSET], 'the page shows it as the redirects read it');

        $rotators = new RotatorsController(self::$db, self::USER);
        self::assertSame(2, array_column($rotators->get(self::ROTATOR)['data']['rules'], 'status', 'id')[self::UNSET], 'the API answers what is stored');
        try {
            $rotators->updateRule(self::ROTATOR, self::UNSET, ['rule_name' => 'renamed']);
            self::fail('a write that keeps a status nothing reads was taken');
        } catch (ValidationException $e) {
            self::assertStringContainsString('send status 0 or 1', $e->getFieldErrors()['status'] ?? '');
        }
        self::assertSame('2', self::stored()[self::UNSET], 'nothing was written');

        $rotators->updateRule(self::ROTATOR, self::UNSET, ['status' => 0]);
        self::assertSame('0', self::stored()[self::UNSET]);
    }

    /** Where a click on the redirector's link sends a visitor. */
    private static function click(): string
    {
        $ch = curl_init(self::$base . '/tracking202/redirect/rtr.php?t202id=' . self::TRACKER);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36']);
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        self::assertSame(302, $status, $out . "\nserver log:\n" . (string) @file_get_contents(self::$log));
        self::assertSame(1, preg_match('~^Location: (\S+)\r?$~mi', $out, $m), $out);

        return $m[1];
    }

    /** @return list<int> the rules the redirects try, in order */
    private static function tried(): array
    {
        $read = static function (string $sql): array {
            $result = self::$db->query($sql);
            self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

            return $result->fetch_all(MYSQLI_ASSOC);
        };

        return array_map('intval', array_column(RuleOrder::activeRules(self::$db, self::ROTATOR, $read), 'rule_id'));
    }

    /** @return array<int, ?string> rule id => status as stored */
    private static function stored(): array
    {
        $result = self::$db->query('SELECT id, status FROM 202_rotator_rules WHERE rotator_id = ' . self::ROTATOR . ' ORDER BY id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'status', 'id');
    }

    /** @return array<int, bool> rule id => whether its card's Inactive switch is rendered on */
    private static function inactiveSwitches(string $out): array
    {
        $switches = [];
        foreach (array_keys(self::RULES) as $id) {
            $at = strpos($out, 'data-rule data-rule-id="' . $id . '"');
            self::assertNotFalse($at, "the editor has no card for rule $id");
            $card = substr($out, $at, (int) strpos($out, '</section>', $at) - $at);
            self::assertSame(1, preg_match('/<input[^>]*data-rule-field="inactive"([^>]*)>/', $card, $m), "rule $id has no Inactive switch");
            $switches[$id] = str_contains($m[1], 'checked');
        }

        return $switches;
    }

    private static function listed(string $out, string $name): string
    {
        $at = strpos($out, '<span class="p202-list__name">' . $name . '</span>');
        self::assertNotFalse($at, "the list does not name the rule $name");

        return substr($out, $at, (int) strpos($out, '</li>', $at) - $at);
    }

    private static function clearRedirector(): void
    {
        $ids = implode(', ', array_keys(self::RULES));
        self::q('DELETE FROM 202_rotator_rules_redirects WHERE rule_id IN (SELECT id FROM 202_rotator_rules WHERE rotator_id = '
            . self::ROTATOR . ") OR id IN ($ids)");
        self::q('DELETE FROM 202_rotator_rules_criteria WHERE rotator_id = ' . self::ROTATOR . " OR id IN ($ids)");
        self::q('DELETE FROM 202_rotator_rules WHERE rotator_id = ' . self::ROTATOR . " OR id IN ($ids)");
        self::q('DELETE FROM 202_rotators WHERE id = ' . self::ROTATOR);
        self::q('DELETE FROM 202_trackers WHERE tracker_id = ' . self::TRACKER);
    }

    private static function cleanUp(): void
    {
        self::clearRedirector();
        foreach (['202_user_role', '202_users_pref', '202_users', '202_mysql_errors'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    /**
     * @param array<string, string> $get
     * @param array<string, mixed> $post
     */
    private static function page(string $page, array $get, array $post = []): string
    {
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => $get === [] ? '{}' : (string) json_encode($get),
            'P202_TEST_POST' => $post === [] ? '{}' : (string) json_encode($post),
            'P202_TEST_AJAX' => $post === [] ? '0' : '1',
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                __DIR__ . '/fixtures/page-runner.php', $page],
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
}
