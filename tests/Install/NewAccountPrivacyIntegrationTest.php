<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\DataSeeder;
use Prosper202\Database\SchemaInstaller;
use Tests\Support\AccountCreationPaths;
use Tests\Support\ColumnShape;

/**
 * On a fresh install every account starts with privacy 'eu' (Enabled for
 * European Traffic): the installer's own account and one made through each
 * other path (Settings › Users, POST /users, MysqlUserRepository), on the
 * schema the installer builds, in a database of its own created empty.
 *
 * @group integration
 */
final class NewAccountPrivacyIntegrationTest extends TestCase
{
    /** tests/live/guard.sh's P202_SCRATCH_RE: a name that reads as disposable. */
    private const SCRATCH_RE
        = '/(^|[_-])(test|tests|scratch|tmp|temp|ci|eval|evals|fixture|sandbox|w[0-9]+|probe|check)([_-]|$)/';

    private static ?\mysqli $server = null;

    private static string $name = '';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        self::$name = (string) (getenv('P202_TEST_DB_NAME') ?: 'p202_integration_test') . '_new_account_privacy';
        self::assertMatchesRegularExpression(self::SCRATCH_RE, self::$name, 'the database dropped here is scratch');
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        self::$server = new \mysqli(
            $host,
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            '',
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->query('DROP DATABASE IF EXISTS `' . self::$name . '`');
            self::$server->close();
            self::$server = null;
        }
    }

    public function testEveryAccountOfAFreshInstallStartsAtEu(): void
    {
        if (self::$server === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        $db = self::$server;
        self::assertTrue($db->query('DROP DATABASE IF EXISTS `' . self::$name . '`'));
        self::assertTrue($db->query('CREATE DATABASE `' . self::$name . '`'));
        self::assertTrue($db->select_db(self::$name));

        // What INSTALL::install_databases() runs before the account.
        $installed = (new SchemaInstaller($db))->install();
        self::assertTrue($installed->success, implode('; ', $installed->errors));
        (new DataSeeder($db))->seed();

        self::assertSame(
            ['type' => 'varchar(100)', 'nullable' => false, 'default' => 'eu'],
            ColumnShape::of($db, '202_users_pref', 'user_pref_privacy')
        );

        $made = AccountCreationPaths::makeOneEach($db, 'fresh');
        self::assertSame(['installer', 'settings-users', 'repository', 'api'], array_keys($made));
        self::assertSame(
            array_fill_keys(array_values($made), 'eu'),
            AccountCreationPaths::privacyOf($db, array_values($made)),
            'each path made the account with its preferences row, and each row starts at eu'
        );
    }
}
