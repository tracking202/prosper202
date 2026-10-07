<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\UserTables;

/**
 * The scheduled click deletion's time (202_users_pref.user_delete_data_before,
 * see Prosper202\Click\ClickRetention) arrives on an upgraded install where
 * the installer puts it: the 1.9.75 -> 1.9.76 rung reconciles 202_users_pref
 * against the installer's definition, and afterwards the table is the fresh
 * install's, column for column and in the same order — the comparison
 * tests/live/upgrade-equals-install.sh makes across the whole ladder. An id
 * an install scheduled before the column existed is still there after it.
 *
 * Loading functions-upgrade.php pulls in the real DataEngine, which breaks
 * later suites that stub it, so the test runs in its own process.
 *
 * @group integration
 */
final class ScheduledDeletionUpgradeIntegrationTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheRungAddsTheColumnWhereTheInstallerDeclaresIt(): void
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
        ini_set('error_log', sys_get_temp_dir() . '/p202-scheduled-deletion-upgrade-it.log');

        $db->query('DROP TABLE IF EXISTS 202_users_pref');
        $this->assertTrue($db->query(UserTables::usersPref()->createStatement));
        $fresh = (string) $db->query('SHOW CREATE TABLE 202_users_pref')->fetch_row()[1];
        // MariaDB writes the display width (int(10)); MySQL 8.0.19 and later
        // do not (int), which is what CI's MySQL 8.0 prints.
        $this->assertMatchesRegularExpression('/`user_delete_data_before` int(?:\(10\))? unsigned DEFAULT NULL,/', $fresh);

        // The 1.9.75 shape: the column is not there yet, and the install
        // scheduled a deletion by id.
        $this->assertTrue($db->query('ALTER TABLE 202_users_pref DROP COLUMN user_delete_data_before'));
        $this->assertTrue($db->query('INSERT INTO 202_users_pref SET user_id = 1, user_delete_data_clickid = 4242'));

        $this->assertTrue(_upgrade_measurement_tables([UserTables::usersPref()]));

        $shape = static fn (): string => (string) $db->query('SHOW CREATE TABLE 202_users_pref')->fetch_row()[1];
        $this->assertSame($fresh, $shape(), 'the upgraded table is the installed one');
        $this->assertSame(
            ['user_delete_data_clickid' => '4242', 'user_delete_data_before' => null],
            $db->query('SELECT user_delete_data_clickid, user_delete_data_before FROM 202_users_pref WHERE user_id = 1')
                ->fetch_assoc(),
            'the id scheduled before the upgrade is kept, to be honoured as it was'
        );
        // A second run finds nothing to do.
        $this->assertTrue(_upgrade_measurement_tables([UserTables::usersPref()]));
        $this->assertSame($fresh, $shape());

        // Leave the table as the installer makes it, empty.
        $db->query('DROP TABLE IF EXISTS 202_users_pref');
        $this->assertTrue($db->query(UserTables::usersPref()->createStatement));
    }
}
