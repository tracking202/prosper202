<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\SystemController;
use Api\V3\Exception\DatabaseException;
use Api\V3\Support\ServerStateStore;
use Tests\TestCase;

/**
 * SystemController against a mocked mysqli. Moved out of ControllerTest.php,
 * where PHPUnit runs only the class named after the file, so it never ran.
 */
final class SystemControllerBehaviorTest extends TestCase
{
    private function createResultMock(array $rows): \mysqli_result
    {
        /** @var \mysqli_result&\PHPUnit\Framework\MockObject\MockObject $result */
        $result = $this->getMockBuilder(\mysqli_result::class)
            ->disableOriginalConstructor()
            ->getMock();

        $index = 0;
        $result->method('fetch_assoc')->willReturnCallback(
            function () use (&$index, $rows) {
                return $rows[$index++] ?? null;
            }
        );

        return $result;
    }

    public function testCronStatusReadsExistingCronjobLogColumns(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $queries = [];
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$queries) {
                $queries[] = $sql;
                if (str_contains($sql, 'FROM 202_cronjobs')) {
                    return $this->createResultMock([
                        ['cronjob_type' => 'main', 'cronjob_time' => '1700000000'],
                    ]);
                }
                if (str_contains($sql, 'FROM 202_cronjob_logs')) {
                    return $this->createResultMock([
                        ['id' => 1, 'last_execution_time' => '1700001234'],
                    ]);
                }
                return false;
            }
        );

        $controller = new SystemController($db);
        $result = $controller->cronStatus();

        $this->assertArrayHasKey('data', $result);
        $this->assertSame(1, $result['data']['recent_logs'][0]['id']);
        $this->assertStringContainsString(
            'SELECT id, last_execution_time FROM 202_cronjob_logs',
            implode("\n", $queries)
        );
    }

    public function testErrorsQueryUsesMysqlErrorTextAlias(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $preparedSql = '';

        /** @var \mysqli_stmt&\PHPUnit\Framework\MockObject\MockObject $stmt */
        $stmt = $this->getMockBuilder(\mysqli_stmt::class)
            ->disableOriginalConstructor()
            ->getMock();
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn(
            $this->createResultMock([
                [
                    'mysql_error_id' => 10,
                    'mysql_error_time' => 1700002222,
                    'mysql_error_message' => 'bad query',
                    'mysql_error_sql' => 'SELECT * FROM nope',
                ],
            ])
        );
        $stmt->method('close')->willReturn(true);

        $db->method('prepare')->willReturnCallback(
            function (string $sql) use (&$preparedSql, $stmt) {
                $preparedSql = $sql;
                return $stmt;
            }
        );

        $controller = new SystemController($db);
        $result = $controller->errors(['limit' => 1]);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('bad query', $result['data'][0]['mysql_error_message']);
        $this->assertStringContainsString('mysql_error_text AS mysql_error_message', $preparedSql);
    }

    public function testDbStatsThrowsWhenDatabaseLookupFails(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $db->method('query')->willReturnCallback(
            function (string $sql) {
                if (str_contains($sql, 'SELECT DATABASE() as db')) {
                    return false;
                }
                return $this->createResultMock([]);
            }
        );

        $controller = new SystemController($db);

        $this->expectException(DatabaseException::class);
        $controller->dbStats();
    }

    public function testMetricsIncludesAlertsAndTracing(): void
    {
        $stateDir = sys_get_temp_dir() . '/p202-system-metrics-' . bin2hex(random_bytes(4));
        mkdir($stateDir, 0700, true);
        putenv('P202_SERVER_STATE_DIR=' . $stateDir);
        putenv('P202_ALERT_FAILURE_SPIKE=1');
        putenv('P202_ALERT_QUEUE_LAG_SECONDS=1');

        try {
            $store = new ServerStateStore($stateDir);
            $store->incrementMetric('jobs_failed', 2);
            $span = $store->startSpan('sync.execute', ['entity' => 'campaigns']);
            $store->endSpan($span, 'ok', ['done' => true]);

            $job = $store->createJob([
                'entity' => 'campaigns',
                'source' => ['url' => 'https://prod.example.com'],
                'target' => ['url' => 'https://stage.example.com'],
                'options' => [],
            ], 1);
            $job['status'] = 'queued';
            $job['next_run_at'] = time() - 10;
            $store->saveJob($job);

            $db = $this->createMysqliMock();
            $controller = new SystemController($db);
            $result = $controller->metrics();

            $this->assertArrayHasKey('data', $result);
            $this->assertArrayHasKey('alerts', $result['data']);
            $this->assertArrayHasKey('tracing', $result['data']);
            $this->assertNotEmpty($result['data']['alerts']['active']);
            $this->assertNotEmpty($result['data']['tracing']['recent_spans']);
        } finally {
            putenv('P202_SERVER_STATE_DIR');
            putenv('P202_ALERT_FAILURE_SPIKE');
            putenv('P202_ALERT_QUEUE_LAG_SECONDS');
            $this->removeDir($stateDir);
        }
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeDir($full);
                continue;
            }
            @unlink($full);
        }
        @rmdir($path);
    }
}
