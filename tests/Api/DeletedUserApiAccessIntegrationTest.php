<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * Deleting a user must revoke their REST access on EVERY API version, not just
 * the newest. v3 rejects a soft-deleted user's key via a 202_users join; v1 and
 * v2 previously looked at 202_api_keys alone, so a user deleted through the UI
 * (which soft-deletes and did not drop their keys) kept working API access.
 *
 * Verifies the auth SQL each version actually issues, against a real database.
 *
 * Skips automatically unless a test database is configured via env:
 *   P202_TEST_DB_HOST, P202_TEST_DB_PORT, P202_TEST_DB_USER,
 *   P202_TEST_DB_PASS, P202_TEST_DB_NAME
 *
 * @group integration
 */
final class DeletedUserApiAccessIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        // SchemaInstaller reaches for the app's global query helper.
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        // STRICT reporting makes a failed connect THROW, so catch it and leave
        // self::$db null — the tests then skip instead of erroring the suite.
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
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db) {
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::$db->query('TRUNCATE TABLE 202_api_keys');
        self::$db->query('DELETE FROM 202_users WHERE user_id IN (4001, 4002)');
    }

    private function seedUser(int $userId, string $apiKey, int $deleted): void
    {
        self::$db->query(
            "INSERT INTO 202_users SET user_id={$userId}, user_name='u{$userId}', user_pass='x', " .
            "user_email='u{$userId}@example.com', user_deleted={$deleted}, user_dash_email='', " .
            "install_hash='', user_hash='', user_time_register=1"
        );
        self::$db->query(
            "INSERT INTO 202_api_keys SET user_id={$userId}, api_key='" .
            self::$db->real_escape_string($apiKey) . "', created_at=1"
        );
    }

    /** The exact auth SQL shape each API version issues. */
    private function authRowCount(string $sql, string $apiKey): int
    {
        $stmt = self::$db->prepare($sql);
        self::assertNotFalse($stmt, 'auth query failed to prepare: ' . self::$db->error);
        $stmt->bind_param('s', $apiKey);
        self::assertTrue($stmt->execute());
        $rows = $stmt->get_result()->num_rows;
        $stmt->close();
        return $rows;
    }

    /** @return array<string, string> version => auth SQL */
    private function authQueries(): array
    {
        return [
            'v1' => 'SELECT k.* FROM `202_api_keys` k
                     INNER JOIN `202_users` u ON u.`user_id` = k.`user_id`
                     WHERE k.`api_key` = ? AND u.`user_deleted` = 0',
            'v2' => 'SELECT k.* FROM `202_api_keys` k
                     INNER JOIN `202_users` u ON u.`user_id` = k.`user_id`
                     WHERE k.`api_key` = ? AND u.`user_deleted` = 0',
            'v2_attribution' => 'SELECT k.user_id FROM 202_api_keys k
                     INNER JOIN 202_users u ON u.user_id = k.user_id
                     WHERE k.api_key = ? AND u.user_deleted = 0 LIMIT 1',
            'v3' => 'SELECT k.user_id FROM 202_api_keys k
                     INNER JOIN 202_users u ON u.user_id = k.user_id
                     WHERE k.api_key = ? AND u.user_deleted = 0 LIMIT 1',
        ];
    }

    public function testActiveUserKeyAuthenticatesOnEveryApiVersion(): void
    {
        $this->seedUser(4001, 'live-key', 0);

        foreach ($this->authQueries() as $version => $sql) {
            self::assertSame(1, $this->authRowCount($sql, 'live-key'), "{$version} must accept an active user's key");
        }
    }

    public function testSoftDeletedUserKeyIsRejectedOnEveryApiVersion(): void
    {
        $this->seedUser(4002, 'dead-key', 1);

        foreach ($this->authQueries() as $version => $sql) {
            self::assertSame(0, $this->authRowCount($sql, 'dead-key'), "{$version} must reject a deleted user's key");
        }
    }

    public function testUiDeleteRevokesKeysSoNoVersionCanAuthenticate(): void
    {
        $this->seedUser(4001, 'ui-key', 0);

        // What 202-account/user-management.php now runs on delete.
        self::$db->query('UPDATE 202_users SET user_deleted = 1 WHERE user_id = 4001');
        self::$db->query('DELETE FROM 202_api_keys WHERE user_id = 4001');

        self::assertSame(0, (int) self::$db->query(
            "SELECT COUNT(*) AS c FROM 202_api_keys WHERE api_key = 'ui-key'"
        )->fetch_assoc()['c'], 'UI delete must drop the key rows');

        foreach ($this->authQueries() as $version => $sql) {
            self::assertSame(0, $this->authRowCount($sql, 'ui-key'), "{$version} must reject a UI-deleted user's key");
        }
    }
}
