<?php

declare(strict_types=1);

namespace Tests\Auth;

use AUTH;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * Whose Prosper202 license key a session is held to is read, or the read
 * throws: a lookup that cannot answer does not answer (CLAUDE.md #11).
 *
 * AUTH::determineAccountOwnerId() answered the user's own id when its
 * prepare failed -- the same value as "no user of this install has a key" --
 * and begin_user_session() keeps the answer in the session for its whole
 * life: one transient database error at sign-in held a sub-user's every page
 * to its own, empty key, and require_valid_api_key() sent it to
 * api-key-required.php to enter one. lookupApiKeyForUser() read a failed
 * query as '' the same way. Both throw now, naming whose read it was, and the
 * owner is read before the session is written, so a sign-in whose lookup
 * fails leaves the request signed out rather than signed in without an
 * owner.
 *
 * @group integration
 */
final class AccountOwnerLookupIntegrationTest extends TestCase
{
    private const OWNER = 5761;
    private const SUB_USER = 5762;
    private const INSTALL = 'owner-lookup-test-install';

    private static ?\mysqli $db = null;

    /** @var array<string, mixed> */
    private array $session = [];

    private mixed $globalDb = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' if ($sql === null) { return \DB::getInstance()->getConnection()->query($dbOrSql); }'
                . ' return $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        require_once __DIR__ . '/../../202-config/functions-auth.php';
        if (!class_exists('DB', false)) {
            eval('class DB { public static $conn; public static function getInstance() { return new self(); }'
                . ' public function getConnection() { return self::$conn; } }');
        }
        self::$db = $db;
        self::cleanUp();
        foreach ([self::OWNER => 'licensed-key', self::SUB_USER => ''] as $id => $key) {
            $db->query("INSERT INTO 202_users SET user_id = $id, user_name = 'own$id',"
                . " user_email = 'own$id@example.com',"
                . " user_dash_email = '', user_pass = 'x', user_timezone = 'UTC', user_time_register = 0,"
                . " install_hash = '" . self::INSTALL . "', user_hash = '', user_active = 1, user_deleted = 0,"
                . " p202_customer_api_key = '$key'");
            $db->query("INSERT INTO 202_users_pref SET user_id = $id");
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    private static function cleanUp(): void
    {
        self::$db->query('DELETE FROM 202_users WHERE user_id IN (' . self::OWNER . ', ' . self::SUB_USER . ')');
        self::$db->query('DELETE FROM 202_users_pref WHERE user_id IN (' . self::OWNER . ', ' . self::SUB_USER . ')');
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        \DB::$conn = self::$db;
        // functions.php's _mysqli_query($sql) reads the global connection.
        $this->globalDb = $GLOBALS['db'] ?? null;
        $GLOBALS['db'] = self::$db;
        $this->session = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        $GLOBALS['db'] = $this->globalDb;
        mysqli_report(MYSQLI_REPORT_STRICT);
    }

    /** @return array<string, mixed> */
    private static function subUserRow(): array
    {
        return [
            'user_id' => self::SUB_USER,
            'user_name' => 'own' . self::SUB_USER,
            'install_hash' => self::INSTALL,
            'p202_customer_api_key' => '',
        ];
    }

    public function testASubUsersSessionIsHeldToTheInstallOwnersKey(): void
    {
        AUTH::begin_user_session(self::subUserRow());

        self::assertSame(self::OWNER, $_SESSION['account_owner_id']);
        $key = new \ReflectionMethod(AUTH::class, 'lookupApiKeyForUser');
        self::assertSame('licensed-key', $key->invoke(null, self::OWNER));
        self::assertSame('', $key->invoke(null, self::SUB_USER), 'a user with no key of its own has none');
    }

    /** @return iterable<string, array{int}> */
    public static function failedReads(): iterable
    {
        yield 'mysqli throws (strict reporting)' => [MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT];
        yield 'mysqli answers false (connect.php sets STRICT alone)' => [MYSQLI_REPORT_STRICT];
    }

    /**
     * @dataProvider failedReads
     */
    public function testAnOwnerLookupThatFailsThrowsAndSignsNobodyIn(int $reporting): void
    {
        $broken = $this->brokenConnection();
        mysqli_report($reporting);
        $thrown = null;
        try {
            AUTH::begin_user_session(self::subUserRow());
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            mysqli_report(MYSQLI_REPORT_STRICT);
            $broken->close();
        }

        self::assertNotNull($thrown, 'a lookup that failed answered an owner (the user itself)');
        self::assertStringContainsString('user ' . self::SUB_USER, $thrown->getMessage(), 'naming whose read it was');
        self::assertArrayNotHasKey('user_id', $_SESSION, 'the session is not signed in');
        self::assertArrayNotHasKey('account_owner_id', $_SESSION);
    }

    /**
     * @dataProvider failedReads
     */
    public function testALicenseKeyReadThatFailsThrows(int $reporting): void
    {
        $broken = $this->brokenConnection();
        mysqli_report($reporting);
        $key = new \ReflectionMethod(AUTH::class, 'lookupApiKeyForUser');
        $thrown = null;
        try {
            $key->invoke(null, self::OWNER);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            mysqli_report(MYSQLI_REPORT_STRICT);
            $broken->close();
        }

        self::assertNotNull(
            $thrown,
            "a read that failed answered '' (no key), which sends the session to api-key-required.php"
        );
        self::assertStringContainsString('license key of user ' . self::OWNER, $thrown->getMessage());
    }

    /** A connection with no Prosper202 tables to read: every query on it fails. */
    private function brokenConnection(): \mysqli
    {
        $broken = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            'information_schema',
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        \DB::$conn = $broken;
        $GLOBALS['db'] = $broken;

        return $broken;
    }
}
