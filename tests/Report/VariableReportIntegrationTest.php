<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * Analyze › Custom Variables counts a click once in each of its variables'
 * groups (DataEngine::doVariableReport()).
 *
 * A click records every variable its traffic source has
 * (202_variable_sets2: one row per variable), and the report joins those
 * rows and sums them. It grouped by variable *name*, so a variable removed
 * in Setup and added again under the same name — both of which the
 * redirects recorded, until they read live variables only — put the click
 * into one name's group twice: measured live, "Gone VALX 2" for one click,
 * with the report's own total at 1. Grouped by variable, each counts it
 * once, and the removed one says it was removed.
 *
 * Runs the engine as the account, in a child process that boots the app
 * (fixtures/account-scope-runner.php). Needs a 202-config.php (connect.php
 * exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class VariableReportIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 990071;
    private const SOURCE = 990071;
    private const REMOVED = 990072;
    private const LIVE = 990073;
    private const OTHER_VAR = 990074;
    private const CLICK = 99007101;

    private static int $now = 0;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any reader ran.'
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
        foreach (['202_dataengine', '202_users_pref', '202_users', '202_ppc_networks', '202_ppc_accounts'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        $vars = implode(', ', [self::REMOVED, self::LIVE, self::OTHER_VAR]);
        self::q('DELETE FROM 202_dataengine WHERE click_id = ' . self::CLICK);
        self::q('DELETE FROM 202_variable_sets2 WHERE variable_set_id = ' . self::CLICK);
        self::q("DELETE FROM 202_custom_variables WHERE custom_variable_id IN ($vars)");
        self::q("DELETE FROM 202_ppc_network_variables WHERE ppc_variable_id IN ($vars)");
    }

    private static function seed(): void
    {
        self::user(self::USER);
        self::row('202_users_pref', [
            'user_id' => self::USER, 'user_pref_time_predefined' => 'today', 'user_pref_show' => 'all',
        ]);
        self::row('202_ppc_networks', [
            'ppc_network_id' => self::SOURCE, 'user_id' => self::USER, 'ppc_network_name' => 'Var Source',
            'ppc_network_time' => self::$now,
        ]);
        // "Gone" removed in Setup and added again under its name and
        // parameter; and one more variable.
        $variables = [[self::REMOVED, 'Gone', 1], [self::LIVE, 'Gone', 0], [self::OTHER_VAR, 'Keep', 0]];
        foreach ($variables as [$id, $name, $deleted]) {
            self::row('202_ppc_network_variables', [
                'ppc_variable_id' => $id, 'ppc_network_id' => self::SOURCE, 'name' => $name,
                'parameter' => strtolower($name), 'placeholder' => '{' . strtolower($name) . '}', 'deleted' => $deleted,
            ]);
            self::row('202_custom_variables', [
                'custom_variable_id' => $id, 'ppc_variable_id' => $id, 'variable' => $name === 'Gone' ? 'X' : 'K',
            ]);
            // One click recorded the value under each of its source's rows.
            self::row('202_variable_sets2', ['variable_set_id' => self::CLICK, 'variables' => (string) $id]);
        }
        self::row('202_dataengine', [
            'user_id' => self::USER, 'click_id' => self::CLICK, 'click_time' => self::$now,
            'ppc_network_id' => self::SOURCE,
            'ppc_account_id' => 0, 'landing_page_id' => 0, 'variable_set_id' => (string) self::CLICK,
            'clicks' => 1, 'click_out' => 1, 'leads' => 0, 'payout' => 0, 'income' => 0, 'cost' => 0,
        ]);
    }

    public function testEachVariableCountsTheClickOnceAndARemovedOneSaysSo(): void
    {
        $result = self::read('engine:variable');
        $groups = [];
        foreach ((array) $result as $source) {
            if (!is_array($source) || !isset($source['variables']) || !is_array($source['variables'])) {
                continue;
            }
            foreach ($source['variables'] as $variable) {
                foreach ((array) ($variable['values'] ?? []) as $value) {
                    $groups[] = $value['variable_name'] . ' = ' . $value['variable_value'] . ': ' . $value['clicks'];
                }
            }
        }
        sort($groups);
        self::assertSame(['Gone (removed) = X: 1', 'Gone = X: 1', 'Keep = K: 1'], $groups);
        $totals = end($result);
        self::assertSame('1', (string) ($totals['total_clicks'] ?? ''), 'the report\'s own total is one click');
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
}
