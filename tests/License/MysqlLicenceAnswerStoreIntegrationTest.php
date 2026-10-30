<?php

declare(strict_types=1);

namespace Tests\License;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;
use Prosper202\License\CliAccessCache;
use Prosper202\License\MysqlLicenceAnswerStore;
use Prosper202\License\ShellAccessCache;

/**
 * The grace period's record in a real database: it survives the temp-file
 * caches being cleared (a restart, a redeploy), holds the key only as its
 * hash, and a newer answer replaces the older one per question.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS,
 * P202_TEST_DB_NAME). It installs the schema there and empties
 * 202_licence_answers.
 *
 * @group integration
 */
final class MysqlLicenceAnswerStoreIntegrationTest extends TestCase
{
    private const int DAY = 86400;

    private static ?\mysqli $db = null;

    private string $key;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
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
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::assertTrue(self::$db->query('DELETE FROM 202_licence_answers'));
        $this->key = 'grace-it-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (isset($this->key)) {
            ShellAccessCache::invalidate($this->key);
            CliAccessCache::invalidate($this->key);
        }
    }

    public function testTheRecordOutlivesTheTempCaches(): void
    {
        $store = new MysqlLicenceAnswerStore(self::$db);
        ShellAccessCache::recordAnswer($store, $this->key, true);
        CliAccessCache::recordAnswer($store, $this->key, true);
        ShellAccessCache::invalidate($this->key);
        CliAccessCache::invalidate($this->key);
        $this->age('valid_at', 29 * self::DAY);
        $this->age('paid_at', 29 * self::DAY);

        $fresh = new MysqlLicenceAnswerStore(self::$db);
        self::assertTrue(ShellAccessCache::validWithinGrace($fresh, $this->key), 'valid 29 days ago');
        self::assertTrue(CliAccessCache::validWithinGrace($fresh, $this->key), 'paid 29 days ago');

        $this->age('valid_at', 31 * self::DAY);
        self::assertFalse(ShellAccessCache::validWithinGrace($fresh, $this->key), 'valid 31 days ago');
        self::assertTrue(CliAccessCache::validWithinGrace($fresh, $this->key), 'each question keeps its own time');
    }

    public function testANewerAnswerReplacesTheOlder(): void
    {
        $store = new MysqlLicenceAnswerStore(self::$db);
        $hash = hash('sha256', $this->key);
        $store->saveAnswer($hash, ShellAccessCache::QUESTION_VALID, true, 1_800_000_000);
        self::assertSame([true, 1_800_000_000], $store->lastAnswer($hash, ShellAccessCache::QUESTION_VALID));
        self::assertNull($store->lastAnswer($hash, ShellAccessCache::QUESTION_PAID), 'the other is unanswered');
        $store->saveAnswer($hash, ShellAccessCache::QUESTION_VALID, false, 1_800_000_100);
        self::assertSame([false, 1_800_000_100], $store->lastAnswer($hash, ShellAccessCache::QUESTION_VALID));
        $store->saveAnswer($hash, ShellAccessCache::QUESTION_PAID, true, 1_800_000_200);
        self::assertSame([false, 1_800_000_100], $store->lastAnswer($hash, ShellAccessCache::QUESTION_VALID));
        self::assertSame([true, 1_800_000_200], $store->lastAnswer($hash, ShellAccessCache::QUESTION_PAID));
        self::assertNull($store->lastAnswer(hash('sha256', 'another key'), ShellAccessCache::QUESTION_VALID));
    }

    public function testTheKeyIsStoredOnlyAsItsHash(): void
    {
        ShellAccessCache::recordAnswer(new MysqlLicenceAnswerStore(self::$db), $this->key, true);
        $rows = self::$db->query('SELECT * FROM 202_licence_answers');
        self::assertInstanceOf(\mysqli_result::class, $rows);
        $all = $rows->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $all);
        self::assertSame(hash('sha256', $this->key), $all[0]['key_sha256']);
        self::assertStringNotContainsString($this->key, json_encode($all, JSON_THROW_ON_ERROR));
    }

    public function testAMissingTableIsNotLicensedAndSaysWhy(): void
    {
        $store = new MysqlLicenceAnswerStore(self::$db);
        self::assertTrue(self::$db->query('RENAME TABLE 202_licence_answers TO 202_licence_answers_gone'));
        try {
            self::assertFalse(ShellAccessCache::validWithinGrace($store, $this->key));
            $this->expectException(\RuntimeException::class);
            $store->lastAnswer(hash('sha256', $this->key), ShellAccessCache::QUESTION_VALID);
        } finally {
            self::$db->query('RENAME TABLE 202_licence_answers_gone TO 202_licence_answers');
        }
    }

    private function age(string $column, int $age): void
    {
        $stmt = self::$db->prepare('UPDATE 202_licence_answers SET ' . $column . ' = ? WHERE key_sha256 = ?');
        self::assertInstanceOf(\mysqli_stmt::class, $stmt);
        $at = time() - $age;
        $hash = hash('sha256', $this->key);
        $stmt->bind_param('is', $at, $hash);
        self::assertTrue($stmt->execute());
        self::assertSame(1, $stmt->affected_rows);
        $stmt->close();
    }
}
