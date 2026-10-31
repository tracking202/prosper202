<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/** Exercises the CI runner with disposable commands instead of a database. */
final class IntegrationShardRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/p202-shards-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/tests/live', 0777, true);
        mkdir($this->root . '/bin');
        mkdir($this->root . '/logs');
        copy(dirname(__DIR__) . '/run-integration-suites.sh', $this->root . '/tests/run-integration-suites.sh');
        copy(dirname(__DIR__) . '/live/guard.sh', $this->root . '/tests/live/guard.sh');
        file_put_contents($this->root . '/202-config.php', '<?php');
        $this->command('php', <<<'SH'
#!/bin/bash
for i in $(seq 0 39); do printf 'tests/Suite%02dTest.php\n' "$i"; done
SH);
        $this->command('mysql', <<<'SH'
#!/bin/bash
printf '%s\n' "$*" >> "$P202_TRACE"
[ "${P202_FAKE_MYSQL_FAIL:-0}" != 1 ]
SH);
        $this->command('phpunit', <<<'SH'
#!/bin/bash
case "${P202_FAKE_RESULT:-pass}" in
  skip) echo 'OK, but incomplete, skipped, or risky tests!'; exit 0 ;;
  die) exit 0 ;;
  fail) echo 'FAILURES!'; exit 1 ;;
  *) echo 'OK (1 test, 1 assertion)' ;;
esac
SH);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function command(string $name, string $source): void
    {
        file_put_contents($this->root . '/bin/' . $name, $source . "\n");
        chmod($this->root . '/bin/' . $name, 0755);
    }

    /** @return array{int, string} */
    private function runShard(array $environment = []): array
    {
        $env = array_merge(getenv(), [
            'PATH' => $this->root . '/bin:' . getenv('PATH'),
            'P202_TEST_DB_NAME' => 'p202_shard_test',
            'P202_PHPUNIT' => $this->root . '/bin/phpunit',
            'P202_IT_LOG_DIR' => $this->root . '/logs',
            'P202_TRACE' => $this->root . '/mysql.log',
        ], $environment);
        foreach (['P202_IT_SHARD_COUNT', 'P202_IT_SHARD_INDEX'] as $key) {
            if (!array_key_exists($key, $environment)) {
                unset($env[$key]);
            }
        }
        $process = proc_open(['bash', $this->root . '/tests/run-integration-suites.sh'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $this->root, $env);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }

    /** @dataProvider shardCounts */
    public function testShardsRunEverySuiteExactlyOnceWithFreshDatabases(int $count): void
    {
        $suites = [];
        for ($index = 0; $index < $count; $index++) {
            [$status, $output] = $this->runShard(['P202_IT_SHARD_COUNT' => (string) $count, 'P202_IT_SHARD_INDEX' => (string) $index]);
            self::assertSame(0, $status, $output);
            preg_match_all('/^PASS\s+(tests\/Suite\d+Test\.php)/m', $output, $matches);
            self::assertCount(intdiv(40, $count), $matches[1], $output);
            $suites = array_merge($suites, $matches[1]);
        }
        self::assertCount(40, array_unique($suites));
        self::assertCount(40, file($this->root . '/mysql.log', FILE_IGNORE_NEW_LINES));
        self::assertContains('tests/Suite00Test.php', $suites);
        self::assertContains('tests/Suite39Test.php', $suites);
    }

    public static function shardCounts(): array
    {
        return [[4], [8]];
    }

    public function testDefaultRunsAllSuites(): void
    {
        [$status, $output] = $this->runShard();
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('40 suites, 40 tests passed; 0 suite(s) failed', $output);
    }

    /** @dataProvider invalidShards */
    public function testInvalidShardsFailBeforeTouchingDatabase(string $count, string $index): void
    {
        [$status, $output] = $this->runShard(['P202_IT_SHARD_COUNT' => $count, 'P202_IT_SHARD_INDEX' => $index]);
        self::assertSame(2, $status, $output);
        self::assertStringContainsString('invalid integration shard', $output);
        self::assertFileDoesNotExist($this->root . '/mysql.log');
    }

    public static function invalidShards(): array
    {
        return [['0', '0'], ['8', '8'], ['41', '0'], ['8', '-1'], ['08', '0'], ['x', '0']];
    }

    /** @dataProvider failingResults */
    public function testFailedSkippedAndInterruptedSuitesFailTheShard(string $result): void
    {
        [$status, $output] = $this->runShard([
            'P202_IT_SHARD_COUNT' => '40', 'P202_IT_SHARD_INDEX' => '0', 'P202_FAKE_RESULT' => $result,
        ]);
        self::assertNotSame(0, $status, $output);
        self::assertStringContainsString('1 suite(s) failed', $output);
    }

    public function testDatabaseFailureFailsTheShard(): void
    {
        [$status, $output] = $this->runShard([
            'P202_IT_SHARD_COUNT' => '8', 'P202_IT_SHARD_INDEX' => '0', 'P202_FAKE_MYSQL_FAIL' => '1',
        ]);
        self::assertSame(2, $status, $output);
        self::assertStringContainsString('could not recreate', $output);
    }

    public static function failingResults(): array
    {
        return [['fail'], ['skip'], ['die']];
    }
}
