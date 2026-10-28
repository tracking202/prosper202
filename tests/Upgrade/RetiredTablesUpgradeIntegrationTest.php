<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\UserTables;

/**
 * A table the installer no longer declares leaves an upgraded database only
 * when a step drops it: the reconciler never emits a destructive statement.
 * _upgrade_measurement_tables() takes the step's drops beside its
 * definitions, and its answer — which alone decides whether the step
 * persists its version — is false when a drop fails or is not a
 * `DROP TABLE IF EXISTS` of 202_ tables. Run here on a server with the 1.9.76
 * step's own arguments (UpgradeLadderTest pins that the step passes them):
 * 202_messaging_conversations and 202_messaging_messages go, the analytics
 * table beside them stays, a second run finds nothing to do, and a step that
 * converged logs nothing (tests/live/upgrade-equals-install.sh reads every
 * "Prosper202 upgrade" line as a step that did not).
 *
 * Loading functions-upgrade.php pulls in the real DataEngine, which breaks
 * later suites that stub it, so the test runs in its own process.
 *
 * @group integration
 */
final class RetiredTablesUpgradeIntegrationTest extends TestCase
{
    private const DROP = 'DROP TABLE IF EXISTS `202_messaging_messages`, `202_messaging_conversations`';

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheStepDropsTheChatTablesAndKeepsTheAnalytics(): void
    {
        $db = $this->connect();
        $log = $this->captureLog();

        $db->query('DROP TABLE IF EXISTS 202_users_pref');
        $this->assertTrue($db->query(UserTables::usersPref()->createStatement));
        // The shapes the 1.9.61 step created.
        $this->assertTrue($db->query('CREATE TABLE IF NOT EXISTS `202_messaging_conversations` (`id` int(11) unsigned NOT NULL AUTO_INCREMENT, `user_id` mediumint(8) unsigned NOT NULL, `external_id` varchar(100) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB'));
        $this->assertTrue($db->query('CREATE TABLE IF NOT EXISTS `202_messaging_messages` (`id` int(11) unsigned NOT NULL AUTO_INCREMENT, `conversation_id` int(11) unsigned NOT NULL, `body` mediumtext NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB'));
        $this->assertTrue($db->query("INSERT INTO 202_messaging_conversations SET user_id = 1, external_id = 'c1'"));
        $this->assertTrue($db->query('CREATE TABLE IF NOT EXISTS `202_messaging_events_probe` (`id` int(11) NOT NULL) ENGINE=InnoDB'));

        $this->assertTrue(_upgrade_measurement_tables([UserTables::usersPref()], [self::DROP]));
        $this->assertSame([], $this->tables($db, ['202_messaging_conversations', '202_messaging_messages']), 'the chat tables are gone');
        $this->assertSame(['202_messaging_events_probe'], $this->tables($db, ['202_messaging_events_probe']), 'a table the step does not name stays');

        // A re-run (the version write failed after the drop) finds nothing to do.
        $this->assertTrue(_upgrade_measurement_tables([UserTables::usersPref()], [self::DROP]));
        $this->assertStringNotContainsString('Prosper202 upgrade', (string) file_get_contents($log), 'a step that converged logs nothing');

        $db->query('DROP TABLE IF EXISTS 202_messaging_events_probe');
        unlink($log);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testARetirementThatIsNotADropOfOurTablesFailsTheStepAndRunsNothing(): void
    {
        $db = $this->connect();
        $log = $this->captureLog();
        $this->assertTrue($db->query('CREATE TABLE IF NOT EXISTS `202_retire_probe` (`id` int(11) NOT NULL) ENGINE=InnoDB'));

        foreach ([
            'DROP TABLE `202_retire_probe`',
            'DROP TABLE IF EXISTS `202_retire_probe`; DELETE FROM 202_users',
            'DROP TABLE IF EXISTS `users`',
            'TRUNCATE `202_retire_probe`',
            'drop table if exists `202_retire_probe`',
        ] as $refused) {
            $this->assertFalse(_upgrade_measurement_tables([], [$refused]), $refused);
            $this->assertSame(['202_retire_probe'], $this->tables($db, ['202_retire_probe']), "$refused did not run");
        }
        $this->assertStringContainsString('refused a retirement', (string) file_get_contents($log));

        $this->assertTrue(_upgrade_measurement_tables([], ['DROP TABLE IF EXISTS `202_retire_probe`']));
        $this->assertSame([], $this->tables($db, ['202_retire_probe']));

        // A drop the server refuses (a foreign key still points at the
        // table) fails the step, naming the statement.
        $db->query('DROP TABLE IF EXISTS 202_retire_child');
        $db->query('DROP TABLE IF EXISTS 202_retire_parent');
        $this->assertTrue($db->query('CREATE TABLE `202_retire_parent` (`id` int(11) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB'));
        $this->assertTrue($db->query('CREATE TABLE `202_retire_child` (`parent_id` int(11) NOT NULL, FOREIGN KEY (`parent_id`) REFERENCES `202_retire_parent` (`id`)) ENGINE=InnoDB'));
        $this->assertFalse(_upgrade_measurement_tables([], ['DROP TABLE IF EXISTS `202_retire_parent`']));
        $this->assertSame(['202_retire_parent'], $this->tables($db, ['202_retire_parent']));
        $this->assertStringContainsString('failed to remove a retired table: DROP TABLE IF EXISTS `202_retire_parent`', (string) file_get_contents($log));
        $db->query('DROP TABLE IF EXISTS 202_retire_child');
        $db->query('DROP TABLE IF EXISTS 202_retire_parent');
        unlink($log);
    }

    private function connect(): \mysqli
    {
        $name = getenv('P202_TEST_DB_NAME') ?: '';
        if ($name === '' || (getenv('P202_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('Set P202_TEST_DB_HOST and P202_TEST_DB_NAME to a scratch database.');
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            $name,
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        if ($db->connect_errno) {
            self::markTestSkipped('The scratch database could not be reached: ' . $db->connect_error);
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        require_once __DIR__ . '/../../202-config/functions-upgrade.php';
        $GLOBALS['db'] = $db;

        return $db;
    }

    private function captureLog(): string
    {
        $log = tempnam(sys_get_temp_dir(), 'p202-retired-tables-upgrade-');
        $this->assertIsString($log);
        ini_set('error_log', $log);

        return $log;
    }

    /**
     * @param list<string> $names
     * @return list<string> those of $names that exist
     */
    private function tables(\mysqli $db, array $names): array
    {
        $found = [];
        foreach ($names as $name) {
            $result = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($name) . "'");
            $this->assertInstanceOf(\mysqli_result::class, $result);
            if ($result->num_rows === 1) {
                $found[] = $name;
            }
        }

        return $found;
    }
}
