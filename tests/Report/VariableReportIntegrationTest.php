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
    /** Another account, with the source a split moved there and that source's variable. */
    private const OTHER_USER = 990075;
    private const OTHER_SOURCE = 990075;
    private const THEIR_VAR = 990075;
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
            self::q("DELETE FROM $table WHERE user_id IN (" . self::USER . ', ' . self::OTHER_USER . ')');
        }
        $vars = implode(', ', [self::REMOVED, self::LIVE, self::OTHER_VAR, self::THEIR_VAR]);
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

    /**
     * A click whose variable set names another account's variable (its
     * source was split off into that account, TrafficSourceOwners) keeps the
     * value, unnamed: that account's label, and a rename there, never show.
     */
    public function testAnotherAccountsVariableUnderTheSameIdStaysUnnamed(): void
    {
        self::user(self::OTHER_USER);
        self::row('202_ppc_networks', [
            'ppc_network_id' => self::OTHER_SOURCE, 'user_id' => self::OTHER_USER,
            'ppc_network_name' => 'Their Source', 'ppc_network_time' => self::$now,
        ]);
        self::row('202_ppc_network_variables', [
            'ppc_variable_id' => self::THEIR_VAR, 'ppc_network_id' => self::OTHER_SOURCE, 'name' => 'Theirs',
            'parameter' => 'theirs', 'placeholder' => '{theirs}', 'deleted' => 0,
        ]);
        self::row('202_custom_variables', [
            'custom_variable_id' => self::THEIR_VAR, 'ppc_variable_id' => self::THEIR_VAR, 'variable' => 'T',
        ]);
        self::row('202_variable_sets2', ['variable_set_id' => self::CLICK, 'variables' => (string) self::THEIR_VAR]);
        try {
            $unnamed = 'Unnamed variable ' . self::THEIR_VAR . ' = T: 1';
            self::assertSame(['Gone (removed) = X: 1', 'Gone = X: 1', 'Keep = K: 1', $unnamed], self::groups());

            self::q("UPDATE 202_ppc_network_variables SET name = 'Renamed there' WHERE ppc_variable_id = "
                . self::THEIR_VAR);
            self::assertSame(
                ['Gone (removed) = X: 1', 'Gone = X: 1', 'Keep = K: 1', $unnamed],
                self::groups(),
                'a rename in the other account does not show'
            );
        } finally {
            self::q('DELETE FROM 202_variable_sets2 WHERE variable_set_id = ' . self::CLICK
                . ' AND variables = ' . self::THEIR_VAR);
            self::q('DELETE FROM 202_custom_variables WHERE custom_variable_id = ' . self::THEIR_VAR);
            self::q('DELETE FROM 202_ppc_network_variables WHERE ppc_variable_id = ' . self::THEIR_VAR);
            self::q('DELETE FROM 202_ppc_networks WHERE user_id = ' . self::OTHER_USER);
            self::q('DELETE FROM 202_users WHERE user_id = ' . self::OTHER_USER);
        }
    }

    /**
     * The values an account's clicks recorded while its traffic source
     * account named another user's source, before and after a split. Before:
     * the account's report shows none of them, whether the click's report row
     * holds no source (what the rollup writes for it) or still the other
     * user's (a row rolled up before the rollup read only the account's own).
     * After the split and the re-roll it queues (the row then holds the copy,
     * set here; TrafficSourceSplitIntegrationTest runs the re-roll itself):
     * they read under the copy's variable, by its name, beside the values
     * recorded since. So a split takes nothing the account could read.
     */
    public function testValuesRecordedOnAnotherUsersSourceReadUnderTheCopyAfterASplit(): void
    {
        self::requireScratch();
        $account = 990076;
        $click = self::CLICK + 1;
        $later = self::CLICK + 2;
        self::user(self::OTHER_USER);
        self::row('202_ppc_networks', [
            'ppc_network_id' => self::OTHER_SOURCE, 'user_id' => self::OTHER_USER,
            'ppc_network_name' => 'Their Source', 'ppc_network_time' => self::$now - 100,
        ]);
        self::row('202_ppc_network_variables', [
            'ppc_variable_id' => self::THEIR_VAR, 'ppc_network_id' => self::OTHER_SOURCE, 'name' => 'Placement',
            'parameter' => 'placement', 'placeholder' => '{placement}', 'deleted' => 0,
        ]);
        self::row('202_ppc_accounts', ['ppc_account_id' => $account, 'user_id' => self::USER,
            'ppc_network_id' => self::OTHER_SOURCE, 'ppc_account_name' => 'On their source',
            'ppc_account_time' => self::$now]);
        // The click's value, recorded while the click path still read the
        // source's variables whoever's it was.
        self::row('202_custom_variables', [
            'custom_variable_id' => self::THEIR_VAR, 'ppc_variable_id' => self::THEIR_VAR, 'variable' => 'sidebar',
        ]);
        self::row('202_variable_sets2', ['variable_set_id' => $click, 'variables' => (string) self::THEIR_VAR]);
        $row = [
            'user_id' => self::USER, 'click_id' => $click, 'click_time' => self::$now,
            'ppc_account_id' => $account, 'landing_page_id' => 0, 'variable_set_id' => (string) $click,
            'clicks' => 1, 'click_out' => 1, 'leads' => 0, 'payout' => 0, 'income' => 0, 'cost' => 0,
        ];
        $own = ['Gone (removed) = X: 1', 'Gone = X: 1', 'Keep = K: 1'];
        $copyId = 0;
        try {
            foreach ([null, self::OTHER_SOURCE] as $source) {
                self::q('DELETE FROM 202_dataengine WHERE click_id = ' . $click);
                self::row('202_dataengine', $row + ['ppc_network_id' => $source]);
                self::assertSame($own, self::groups(), 'before the split the value is not in the account\'s report');
            }

            $owners = new \Prosper202\Setup\TrafficSourceOwners(new \Prosper202\Database\Connection(self::$db));
            self::assertSame([], $owners->report(false)[0]['auto_split_blocked_by']);
            $done = $owners->splitAutomatically();
            self::assertCount(1, $done);
            $copyId = (int) $done[0]['copies'][0]['ppc_network_id'];
            self::q("UPDATE 202_dataengine SET ppc_network_id = $copyId WHERE click_id = $click");
            self::assertSame([...$own, 'Placement = sidebar: 1'], self::groups(), 'after: named by the copy');

            // A click after the split records under the copy's own variable: one group, two clicks.
            $copyVar = (int) self::$db->query("SELECT ppc_variable_id FROM 202_ppc_network_variables"
                . " WHERE ppc_network_id = $copyId")->fetch_row()[0];
            self::row('202_custom_variables', ['custom_variable_id' => $copyVar + 990000000,
                'ppc_variable_id' => $copyVar, 'variable' => 'sidebar']);
            self::row('202_variable_sets2', ['variable_set_id' => $later,
                'variables' => (string) ($copyVar + 990000000)]);
            self::row('202_dataengine', ['click_id' => $later, 'variable_set_id' => (string) $later,
                'ppc_network_id' => $copyId] + $row);
            self::assertSame([...$own, 'Placement = sidebar: 2'], self::groups());

            // The other user's rename does not show; the account's own does.
            self::q("UPDATE 202_ppc_network_variables SET name = 'Renamed there' WHERE ppc_variable_id = "
                . self::THEIR_VAR);
            self::assertSame([...$own, 'Placement = sidebar: 2'], self::groups());
            self::q("UPDATE 202_ppc_network_variables SET name = 'Slot' WHERE ppc_variable_id = $copyVar");
            self::assertSame([...$own, 'Slot = sidebar: 2'], self::groups());

            // A copy made before copies remembered their originals: the earlier value shows, unnamed.
            self::q("UPDATE 202_ppc_network_variables SET copied_from_ppc_variable_id = NULL"
                . " WHERE ppc_variable_id = $copyVar");
            self::assertSame(
                [...$own, 'Slot = sidebar: 1', 'Unnamed variable ' . self::THEIR_VAR . ' = sidebar: 1'],
                self::groups()
            );
        } finally {
            self::q("DELETE FROM 202_change_log WHERE entity = 'traffic_source_split'");
            self::q('DELETE FROM 202_dirty_hours WHERE user_id = ' . self::USER);
            self::q("DELETE FROM 202_dataengine WHERE click_id IN ($click, $later)");
            self::q("DELETE FROM 202_variable_sets2 WHERE variable_set_id IN ($click, $later)");
            self::q('DELETE FROM 202_custom_variables WHERE ppc_variable_id = ' . self::THEIR_VAR
                . ' OR custom_variable_id > 990000000');
            $sources = $copyId . ', ' . self::OTHER_SOURCE;
            self::q("DELETE FROM 202_ppc_network_variables WHERE ppc_network_id IN ($sources)");
            self::q("DELETE FROM 202_ppc_accounts WHERE ppc_account_id = $account");
            self::q("DELETE FROM 202_ppc_networks WHERE ppc_network_id IN ($copyId, " . self::OTHER_SOURCE . ')');
            self::q('DELETE FROM 202_users WHERE user_id = ' . self::OTHER_USER);
        }
    }

    /** @return list<string> each variable group as "name = value: clicks", sorted */
    private static function groups(): array
    {
        $groups = [];
        foreach ((array) self::read('engine:variable') as $source) {
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

        return $groups;
    }

    public function testLargeVariableDictionaryDoesNotLimitTheReportedValues(): void
    {
        $before = self::read('engine:variable');
        // Unrelated mapping rows change the query plan, never the report's scope.
        $first = 990071000;
        self::q('DELETE FROM 202_variable_sets2 WHERE variable_set_id BETWEEN '
            . $first . ' AND ' . ($first + 10000));
        try {
            for ($offset = 0; $offset <= 10000; $offset += 500) {
                $values = [];
                for ($i = $offset; $i < min($offset + 500, 10001); $i++) {
                    $values[] = '(' . ($first + $i) . ', 0)';
                }
                self::q('INSERT INTO 202_variable_sets2(variable_set_id, variables) VALUES ' . implode(',', $values));
            }
            self::assertSame($before, self::read('engine:variable'));
        } finally {
            self::q('DELETE FROM 202_variable_sets2 WHERE variable_set_id BETWEEN '
                . $first . ' AND ' . ($first + 10000));
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
        self::assertIsArray($result, $out);

        return $result;
    }
}
