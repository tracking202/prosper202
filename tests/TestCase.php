<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test class with common utilities and mocks for Prosper202 tests
 */
abstract class TestCase extends BaseTestCase
{
    protected array $originalGet = [];
    protected array $originalServer = [];
    protected array $originalCookie = [];
    protected array $originalSession = [];
    protected $mockDb;
    protected $mockMemcache;

    protected function setUp(): void
    {
        parent::setUp();

        // Store original globals
        $this->originalGet = $_GET ?? [];
        $this->originalServer = $_SERVER ?? [];
        $this->originalCookie = $_COOKIE ?? [];
        $this->originalSession = $_SESSION ?? [];

        // Set default server variables
        $_SERVER['HTTP_HOST'] = 'test.prosper202.com';
        $_SERVER['SERVER_NAME'] = 'test.prosper202.com';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test Agent';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1';
        $_SERVER['PHP_SELF'] = '/test.php';
    }

    protected function tearDown(): void
    {
        // Restore original globals
        $_GET = $this->originalGet;
        $_SERVER = $this->originalServer;
        $_COOKIE = $this->originalCookie;
        $_SESSION = $this->originalSession;

        parent::tearDown();
    }

    /**
     * Create a mock database connection
     */
    protected function createMockDb(array $queryResults = []): object
    {
        return new class($queryResults) {
            public int $insert_id = 1;
            public int $affected_rows = 1;
            public string $error = '';
            private array $queryResults;
            private array $preparedResults = [];

            public function __construct(array $queryResults = [])
            {
                $this->queryResults = $queryResults;
            }

            public function real_escape_string(string $str): string
            {
                return addslashes($str);
            }

            public function escape_string(string $str): string
            {
                return addslashes($str);
            }

            public function query(string $sql): object|bool
            {
                // Check for matching query results
                foreach ($this->queryResults as $pattern => $result) {
                    if (str_contains($sql, $pattern)) {
                        return $this->createResultSet($result);
                    }
                }

                // Return empty result set by default
                return $this->createResultSet([]);
            }

            public function prepare(string $sql): object|false
            {
                return new class($this, $sql, $this->queryResults) {
                    private $db;
                    private string $sql;
                    private array $queryResults;
                    private array $params = [];

                    public function __construct($db, string $sql, array $queryResults)
                    {
                        $this->db = $db;
                        $this->sql = $sql;
                        $this->queryResults = $queryResults;
                    }

                    public function bind_param(string $types, &...$vars): bool
                    {
                        // The $types parameter is accepted to match the real method signature,
                        // but is intentionally unused in this mock as type validation is not performed.
                        $this->params = $vars;
                        return true;
                    }

                    public function execute(): bool
                    {
                        return true;
                    }

                    public function get_result(): object
                    {
                        foreach ($this->queryResults as $pattern => $result) {
                            if (str_contains($this->sql, $pattern)) {
                                return $this->db->createResultSet($result);
                            }
                        }
                        return $this->db->createResultSet([]);
                    }

                    public function close(): bool
                    {
                        return true;
                    }
                };
            }

            public function createResultSet(array $rows): object
            {
                return new class($rows) {
                    private array $rows;
                    private int $index = 0;
                    public int $num_rows;

                    public function __construct(array $rows)
                    {
                        // Handle single row as array of rows
                        if (!empty($rows) && !isset($rows[0])) {
                            $rows = [$rows];
                        }
                        $this->rows = $rows;
                        $this->num_rows = count($rows);
                    }

                    public function fetch_assoc(): ?array
                    {
                        if ($this->index >= count($this->rows)) {
                            return null;
                        }
                        return $this->rows[$this->index++];
                    }

                    public function close(): bool
                    {
                        return true;
                    }
                };
            }

            public function setQueryResult(string $pattern, array $result): void
            {
                $this->queryResults[$pattern] = $result;
            }
        };
    }

    /**
     * Create a mock Memcache instance
     */
    protected function createMockMemcache(array $initialData = []): object
    {
        return new class($initialData) {
            private array $cache;

            public function __construct(array $initialData = [])
            {
                $this->cache = $initialData;
            }

            public function get(string $key)
            {
                return $this->cache[$key] ?? false;
            }

            public function set(string $key, $value, $flag = 0, int $expiration = 0): bool
            {
                // NOTE: $flag and $expiration are intentionally ignored in this mock.
                $this->cache[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->cache[$key]);
                return true;
            }

            public function flush(): bool
            {
                $this->cache = [];
                return true;
            }

            public function addToCache(string $key, $value): void
            {
                $this->cache[$key] = $value;
            }

            public function getCache(): array
            {
                return $this->cache;
            }
        };
    }

    /**
     * Set up session for testing
     */
    protected function setUpSession(array $sessionData = []): void
    {
        $_SESSION = array_merge([
            'user_id' => 1,
            'user_name' => 'testuser',
            'user_own_id' => 1,
            'user_timezone' => 'America/New_York',
            'session_fingerprint' => md5('session_fingerprint' . 'PHPUnit Test Agent' . 'test_session_id'),
            'session_time' => time(),
        ], $sessionData);
    }

    /**
     * Assert that a string contains all given substrings
     */
    protected function assertStringContainsAll(string $haystack, array $needles, string $message = ''): void
    {
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $haystack, $message ?: "Failed asserting that string contains '$needle'");
        }
    }

    /**
     * Create a PHPUnit mock of \mysqli that satisfies type hints.
     *
     * Uses PHPUnit mocks for \mysqli, \mysqli_stmt, and \mysqli_result
     * so return types are compatible with production type hints.
     *
     * @param array $queryResults  Pattern => result map (same format as createMockDb)
     */
    protected function createMysqliMock(array $queryResults = []): \mysqli
    {
        $testCase = $this;

        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($testCase, $queryResults) {
                return $testCase->buildStmtMock($sql, $queryResults);
            }
        );

        $db->method('query')->willReturnCallback(
            function (string $sql) use ($testCase, $queryResults) {
                return $testCase->buildResultMock($sql, $queryResults);
            }
        );

        $db->method('begin_transaction')->willReturn(true);
        $db->method('commit')->willReturn(true);
        $db->method('rollback')->willReturn(true);
        $db->method('real_escape_string')->willReturnCallback(
            function (string $str) {
                return addslashes($str);
            }
        );

        return $db;
    }

    /**
     * Build a mock \mysqli_stmt for a given SQL and result set.
     */
    public function buildStmtMock(string $sql, array $queryResults): \mysqli_stmt
    {
        $testCase = $this;

        /** @var \mysqli_stmt&\PHPUnit\Framework\MockObject\MockObject $stmt */
        $stmt = $this->getMockBuilder(\mysqli_stmt::class)
            ->disableOriginalConstructor()
            ->getMock();

        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('close')->willReturn(true);

        $stmt->method('get_result')->willReturnCallback(
            function () use ($testCase, $sql, $queryResults) {
                return $testCase->buildResultMock($sql, $queryResults);
            }
        );

        return $stmt;
    }

    /**
     * Build a mock \mysqli_result matching SQL against query result patterns.
     */
    public function buildResultMock(string $sql, array $queryResults): \mysqli_result
    {
        $matchedRows = [];
        foreach ($queryResults as $pattern => $result) {
            if (str_contains($sql, (string)$pattern)) {
                if (!empty($result) && !isset($result[0])) {
                    $matchedRows = [$result];
                } else {
                    $matchedRows = $result;
                }
                break;
            }
        }

        /** @var \mysqli_result&\PHPUnit\Framework\MockObject\MockObject $result */
        $result = $this->getMockBuilder(\mysqli_result::class)
            ->disableOriginalConstructor()
            ->getMock();

        $index = 0;
        $rows = $matchedRows;
        $result->method('fetch_assoc')->willReturnCallback(
            function () use (&$index, $rows) {
                return $rows[$index++] ?? null;
            }
        );

        // mysqli_result::close() has tentative return type void in PHP 8.4+;
        // PHPUnit rejects willReturn(true) for void methods, so leave unconfigured.

        return $result;
    }
}
