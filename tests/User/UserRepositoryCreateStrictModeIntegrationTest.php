<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\DataSeeder;
use Prosper202\Database\SchemaInstaller;
use Prosper202\User\MysqlUserRepository;

/**
 * MysqlUserRepository::create() makes an account under a strict sql_mode,
 * the API's, on the schema the installer builds: the user row with every
 * NOT NULL column that has no DEFAULT named (NewUserInsertsNameEveryRequiredColumnTest),
 * holding what POST /users gives it, and the preferences row and default
 * attribution model with it.
 *
 * @group integration
 */
final class UserRepositoryCreateStrictModeIntegrationTest extends TestCase
{
    /** tests/live/guard.sh's P202_SCRATCH_RE: a name that reads as disposable. */
    private const SCRATCH_RE
        = '/(^|[_-])(test|tests|scratch|tmp|temp|ci|eval|evals|fixture|sandbox|w[0-9]+|probe|check)([_-]|$)/';

    private const STRICT = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private static ?\mysqli $db = null;

    private static string $name = '';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        self::$name = (string) (getenv('P202_TEST_DB_NAME') ?: 'p202_integration_test') . '_user_repo_strict';
        self::assertMatchesRegularExpression(self::SCRATCH_RE, self::$name, 'the database dropped here is scratch');
        $root = dirname(__DIR__, 2);
        require_once $root . '/202-config/functions-auth.php';
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        $db = new \mysqli(
            $host,
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            '',
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertTrue($db->query('DROP DATABASE IF EXISTS `' . self::$name . '`'));
        self::assertTrue($db->query('CREATE DATABASE `' . self::$name . '`'));
        self::assertTrue($db->select_db(self::$name));
        $installed = (new SchemaInstaller($db))->install();
        self::assertTrue($installed->success, implode('; ', $installed->errors));
        (new DataSeeder($db))->seed();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->query('DROP DATABASE IF EXISTS `' . self::$name . '`');
            self::$db->close();
            self::$db = null;
        }
    }

    public function testAnAccountIsMadeUnderAStrictSqlModeWithTheValuesPostUsersGives(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        $db = self::$db;
        self::assertTrue($db->query("SET SESSION sql_mode = '" . self::STRICT . "'"));
        self::assertTrue($db->query("INSERT INTO 202_users SET user_id = 1, user_name = 'owner', user_pass = 'x',"
            . " user_email = 'owner@example.com', user_dash_email = 'owner@example.com', user_time_register = 1,"
            . " install_hash = 'install-hash-1', user_hash = ''"));

        $id = (new MysqlUserRepository(new Connection($db)))->create([
            'fname' => 'Ada', 'lname' => 'L', 'name' => 'strict-made', 'password' => 'a-password-1',
            'email' => 'strict@example.com', 'timezone' => 'UTC', 'active' => 1,
        ]);

        self::assertGreaterThan(1, $id);
        $row = $db->query('SELECT user_name, user_email, user_dash_email, install_hash, user_hash,'
            . " user_public_publisher_id, user_deleted, user_active FROM 202_users WHERE user_id = $id")->fetch_assoc();
        self::assertIsArray($row);
        $publisherId = (string) $row['user_public_publisher_id'];
        unset($row['user_public_publisher_id']);
        self::assertSame([
            'user_name' => 'strict-made', 'user_email' => 'strict@example.com',
            'user_dash_email' => 'strict@example.com', 'install_hash' => 'install-hash-1', 'user_hash' => '',
            'user_deleted' => '0', 'user_active' => '1',
        ], $row);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{10}\z/', $publisherId, 'a publisher id for t202pubid links');
        self::assertSame('1', (string) $db->query("SELECT COUNT(*) FROM 202_users_pref WHERE user_id = $id")->fetch_row()[0]);
        self::assertSame(
            '1',
            (string) $db->query("SELECT COUNT(*) FROM 202_attribution_models WHERE user_id = $id")->fetch_row()[0],
            'its default attribution model'
        );
    }
}
