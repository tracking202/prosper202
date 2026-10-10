<?php

declare(strict_types=1);

namespace Tests\Auth;

use AUTH;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * AUTH::set_timezone() sets the request's zone from the signed-in user's
 * account, not from the copy the session took at sign-in, so the report
 * pages count "today" from the zone GET /reports/* counts it from
 * (api/v3 AccountTimezone) after the account's zone has changed elsewhere.
 *
 * @group integration
 */
final class SetTimezoneReadsTheAccountIntegrationTest extends TestCase
{
    private const FIRST_USER = 5741;

    private static ?\mysqli $db = null;

    /** @var array<string, mixed> */
    private array $session = [];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
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
        // 0-2 the accounts below; 5-8 the shapes the pages and the API must
        // agree on (3 and 4 are the failed reads', 9 has no row).
        $zones = [0 => 'Asia/Tokyo', 1 => '', 2 => 'Not/AZone']
            + [5 => '+05:30', 6 => 'america/new_york', 7 => 'GMT+5', 8 => 'Asia/Kolkata'];
        foreach ($zones as $n => $zone) {
            $id = self::FIRST_USER + $n;
            $db->query("INSERT INTO 202_users SET user_id = $id, user_name = 'tz$id', user_email = 'tz$id@example.com',"
                . " user_dash_email = '', user_pass = 'x', user_timezone = '" . $db->real_escape_string($zone) . "',"
                . " user_time_register = 0, install_hash = '', user_hash = ''");
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
        self::$db->query('DELETE FROM 202_users WHERE user_id BETWEEN ' . self::FIRST_USER . ' AND ' . (self::FIRST_USER + 9));
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        \DB::$conn = self::$db;
        $this->session = $_SESSION ?? [];
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        date_default_timezone_set('UTC');
    }

    /** @return iterable<string, array{int, string}> */
    public static function accounts(): iterable
    {
        yield "the account's zone, changed since sign-in" => [self::FIRST_USER, 'Asia/Tokyo'];
        yield 'no zone stored: UTC, as the API reads it' => [self::FIRST_USER + 1, 'UTC'];
        yield 'a zone PHP does not know: UTC, as the API reads it' => [self::FIRST_USER + 2, 'UTC'];
        yield 'no account row: the zone the session has' => [self::FIRST_USER + 9, 'America/New_York'];
    }

    /** @dataProvider accounts */
    public function testTheRequestZoneIsTheAccounts(int $userId, string $expected): void
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_timezone'] = 'America/New_York'; // what sign-in stored

        AUTH::set_timezone($_SESSION['user_timezone']);

        self::assertSame($expected, date_default_timezone_get());
        self::assertSame($expected, $_SESSION['user_timezone'], 'the session copy every other reader uses agrees');
    }

    /** @return iterable<string, array{int, int}> */
    public static function failedReads(): iterable
    {
        yield 'mysqli throws (strict reporting)' => [MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, self::FIRST_USER + 3];
        yield 'mysqli answers false (no reporting)' => [MYSQLI_REPORT_OFF, self::FIRST_USER + 4];
    }

    /**
     * A read that fails is not "keep the zone the session has". Answered
     * that way (a failed prepare, execute or get_result(), and any exception,
     * all read as null), one transient database error counted the page's
     * days in the zone captured at sign-in, which may no longer be the
     * account's, and said nothing. It throws, naming the account, and sets
     * no zone.
     *
     * @dataProvider failedReads
     */
    public function testAReadThatFailsThrowsAndSetsNoZone(int $reporting, int $userId): void
    {
        // A connection with no 202_users to read: the query cannot run.
        $broken = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            'information_schema',
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        \DB::$conn = $broken;
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_timezone'] = 'America/New_York';
        mysqli_report($reporting);
        $thrown = null;
        try {
            AUTH::set_timezone($_SESSION['user_timezone']);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            mysqli_report(MYSQLI_REPORT_STRICT);
            $broken->close();
        }

        self::assertNotNull($thrown, 'a read that failed answered as though it had read a zone');
        self::assertStringContainsString('time zone of account ' . $userId, $thrown->getMessage(), 'naming whose');
        self::assertSame('UTC', date_default_timezone_get(), 'no zone was set from a read that failed');
        self::assertSame('America/New_York', $_SESSION['user_timezone'], 'and the session copy is untouched');
    }

    /** @return iterable<string, array{int, string}> */
    public static function storedShapes(): iterable
    {
        yield 'an offset' => [self::FIRST_USER + 5, 'UTC'];
        yield 'a listed name in another case' => [self::FIRST_USER + 6, 'UTC'];
        yield 'GMT plus hours' => [self::FIRST_USER + 7, 'UTC'];
        yield 'a zone' => [self::FIRST_USER + 8, 'Asia/Kolkata'];
        yield 'not a zone at all' => [self::FIRST_USER + 2, 'UTC'];
    }

    /**
     * One stored value, one zone: the pages and the API (AccountZone::read(),
     * which GET /reports/* and the LTV cohorts use) read it the same way. A
     * stored +05:30 was UTC here and +05:30 there (measured on a live
     * instance), so the same day was two different spans of time.
     *
     * @dataProvider storedShapes
     */
    public function testThePagesAndTheApiReadOneStoredValueAsOneZone(int $userId, string $expected): void
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_timezone'] = 'America/New_York';

        AUTH::set_timezone($_SESSION['user_timezone']);
        $api = \Prosper202\Report\AccountZone::read(new \Prosper202\Database\Connection(self::$db), $userId);

        self::assertSame($expected, date_default_timezone_get(), 'the pages');
        self::assertSame($expected, $api, 'the API');
    }

    public function testWithoutASignedInUserTheArgumentStands(): void
    {
        unset($_SESSION['user_id'], $_SESSION['user_timezone']);

        AUTH::set_timezone('Europe/Paris');

        self::assertSame('Europe/Paris', date_default_timezone_get());
    }
}
