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
[ -z "${P202_TRACE_PHPUNIT:-}" ] || { printf '%s\t' "$@"; echo; } >> "$P202_TRACE_PHPUNIT"
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
            'P202_TRACE_PHPUNIT' => $this->root . '/phpunit.log',
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

    public function testShardsRunEverySuiteExactlyOnceWithFreshDatabases(): void
    {
        $suites = [];
        for ($index = 0; $index < 8; $index++) {
            [$status, $output] = $this->runShard(['P202_IT_SHARD_COUNT' => '8', 'P202_IT_SHARD_INDEX' => (string) $index]);
            self::assertSame(0, $status, $output);
            preg_match_all('/^PASS\s+(tests\/Suite\d+Test\.php)/m', $output, $matches);
            self::assertCount(5, $matches[1], $output);
            $suites = array_merge($suites, $matches[1]);
        }
        self::assertCount(40, array_unique($suites));
        self::assertCount(40, file($this->root . '/mysql.log', FILE_IGNORE_NEW_LINES));
        self::assertContains('tests/Suite00Test.php', $suites);
        self::assertContains('tests/Suite39Test.php', $suites);
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

    private function weights(string $text): string
    {
        $path = $this->root . '/weights.txt';
        file_put_contents($path, $text);

        return $path;
    }

    /** @return array{list<array{string, string, int}>, list<int>} the PASS lines (suite, slice label, shard), the planned seconds of each shard */
    private function runAllShards(string $weightsFile, int $count = 8): array
    {
        $passed = [];
        $planned = [];
        for ($index = 0; $index < $count; $index++) {
            [$status, $output] = $this->runShard([
                'P202_IT_SHARD_COUNT' => (string) $count, 'P202_IT_SHARD_INDEX' => (string) $index,
                'P202_IT_WEIGHTS_FILE' => $weightsFile,
            ]);
            self::assertSame(0, $status, $output);
            preg_match_all('/^PASS\s+(tests\/Suite\d+Test\.php)(?: \(([^)]+)\))?\s/m', $output, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $passed[] = [$m[1], $m[2] ?? '', $index];
            }
            self::assertSame(1, preg_match('/planned (\d+) s/', $output, $load), $output);
            $planned[] = (int) $load[1];
        }

        return [$passed, $planned];
    }

    public function testASlicedSuiteRunsEachSliceOnceOnDifferentShards(): void
    {
        $weights = $this->weights(<<<'TXT'
# comments and blank lines are fine

30 tests/Suite03Test.php ::testOne$
20 tests/Suite03Test.php ::testTwo$|::testThree$
12 tests/Suite03Test.php *
25 tests/Suite07Test.php
TXT);
        [$passed, $planned] = $this->runAllShards($weights);

        // 39 whole suites and 3 slices of one, each once.
        self::assertCount(42, $passed);
        $bySuite = [];
        foreach ($passed as [$suite, $label, $shard]) {
            $bySuite[$suite][] = [$label, $shard];
        }
        self::assertCount(40, $bySuite);
        self::assertCount(3, $bySuite['tests/Suite03Test.php']);
        self::assertCount(3, array_unique(array_column($bySuite['tests/Suite03Test.php'], 0)), 'three different slices');
        self::assertCount(3, array_unique(array_column($bySuite['tests/Suite03Test.php'], 1)), 'on three different shards');
        foreach ($bySuite as $suite => $runs) {
            if ($suite !== 'tests/Suite03Test.php') {
                self::assertSame([['', $runs[0][1]]], $runs, $suite);
            }
        }
        // 38 suites at the default 3 s, plus 30 + 20 + 12 + 25: the 30 s slice alone is the longest shard.
        self::assertSame(201, array_sum($planned));
        self::assertSame(30, max($planned), implode(' ', $planned));
        self::assertGreaterThanOrEqual(24, min($planned), implode(' ', $planned));

        $filters = [];
        foreach (file($this->root . '/phpunit.log', FILE_IGNORE_NEW_LINES) as $line) {
            $args = explode("\t", rtrim($line, "\t"));
            if (str_ends_with(end($args), 'tests/Suite03Test.php')) {
                $filters[] = $args[array_search('--filter', $args, true) + 1];
            } else {
                self::assertNotContains('--filter', $args);
            }
        }
        $one = '/::testOne$/';
        $twoThree = '/::testTwo$|::testThree$/';
        $rest = '/^(?!.*(?:(?:::testOne$)|(?:::testTwo$|::testThree$)))/';
        sort($filters);
        $expected = [$one, $twoThree, $rest];
        sort($expected);
        self::assertSame($expected, $filters);
        // Each test is in exactly one slice, a test no slice names in the rest.
        foreach (['testOne' => $one, 'testTwo' => $twoThree, 'testThree' => $twoThree, 'testFour' => $rest] as $test => $owner) {
            foreach ([$one, $twoThree, $rest] as $filter) {
                self::assertSame($filter === $owner ? 1 : 0, preg_match($filter, 'Tests\\Suite03Test::' . $test), "$test against $filter");
            }
        }
    }

    /** @dataProvider unusableWeights */
    public function testAnUnusableWeightsFileFailsBeforeTouchingDatabase(string $text, string $message): void
    {
        [$status, $output] = $this->runShard([
            'P202_IT_SHARD_COUNT' => '8', 'P202_IT_SHARD_INDEX' => '0', 'P202_IT_WEIGHTS_FILE' => $this->weights($text),
        ]);
        self::assertSame(2, $status, $output);
        self::assertStringContainsString($message, $output);
        self::assertFileDoesNotExist($this->root . '/mysql.log');
    }

    public static function unusableWeights(): array
    {
        return [
            'slices with no rest' => ["5 tests/Suite03Test.php ::testOne\$\n", 'slices need exactly one'],
            'a rest alone' => ["5 tests/Suite03Test.php *\n", 'needs at least one slice'],
            'slices and a whole suite' => ["5 tests/Suite03Test.php\n5 tests/Suite03Test.php ::a\$\n5 tests/Suite03Test.php *\n", 'no whole-suite line'],
            'a slash in a slice' => ["5 tests/Suite03Test.php a/b\n5 tests/Suite03Test.php *\n", "cannot contain '/'"],
            'a zero weight' => ["0 tests/Suite03Test.php\n", 'bad line'],
            'a word for a weight' => ["slow tests/Suite03Test.php\n", 'bad line'],
        ];
    }

    public function testASuiteTheWeightsNameButTheSelectorDoesNotIsIgnored(): void
    {
        [$passed] = $this->runAllShards($this->weights("9 tests/Gone.php ::x\$\n9 tests/Gone.php *\n"));
        self::assertCount(40, $passed);
        [$status, $output] = $this->runShard([
            'P202_IT_SHARD_COUNT' => '8', 'P202_IT_SHARD_INDEX' => '0', 'P202_IT_WEIGHTS_FILE' => $this->weights("9 tests/Gone.php\n"),
        ]);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('tests/Gone.php is not a selected suite; ignored', $output);
    }

    public function testPlanOnlyPrintsEveryUnitOnceAndRunsNothing(): void
    {
        [$status, $output] = $this->runShard([
            'P202_IT_SHARD_COUNT' => '8', 'P202_IT_SHARD_INDEX' => '0', 'P202_IT_PLAN_ONLY' => '1',
            'P202_IT_WEIGHTS_FILE' => $this->weights("30 tests/Suite03Test.php ::testOne\$\n12 tests/Suite03Test.php *\n"),
        ]);
        self::assertSame(0, $status, $output);
        preg_match_all('/^plan\t(\d+)\t(\d+)\t(\S+)\t(.*)$/m', $output, $units, PREG_SET_ORDER);
        self::assertCount(41, $units);
        self::assertSame(['/::testOne$/', '/^(?!.*(?:(?:::testOne$)))/'], array_column(array_filter($units, static fn (array $u): bool => $u[3] === 'tests/Suite03Test.php'), 4));
        self::assertFileDoesNotExist($this->root . '/mysql.log');
        self::assertFileDoesNotExist($this->root . '/phpunit.log');
    }

    /** The runner's plan for the real suite list and the real weights file: every suite once, each sliced suite split into a partition of its tests. */
    public function testTheRealWeightsFileSplitsEachSlicedSuiteIntoAPartitionOfItsTests(): void
    {
        $repository = dirname(__DIR__, 2);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repository . '/tests/integration-suites.php'), $suites, $status);
        self::assertSame(0, $status);
        self::assertGreaterThan(30, count($suites));
        copy($repository . '/tests/integration-suite-weights.txt', $this->root . '/tests/integration-suite-weights.txt');
        $this->command('php', "#!/bin/bash\n" . implode("\n", array_map(static fn (string $s): string => 'echo ' . escapeshellarg($s), $suites)));

        [$status, $output] = $this->runShard([
            'P202_IT_SHARD_COUNT' => '8', 'P202_IT_SHARD_INDEX' => '0', 'P202_IT_PLAN_ONLY' => '1',
        ]);
        self::assertSame(0, $status, $output);
        self::assertStringNotContainsString('not a selected suite', $output, 'the weights file names a suite that no longer exists');
        preg_match_all('/^plan\t(\d+)\t(\d+)\t(\S+)\t(.*)$/m', $output, $units, PREG_SET_ORDER);

        $filtersOf = [];
        $load = array_fill(0, 8, 0);
        foreach ($units as [, $shard, $weight, $suite, $filter]) {
            $filtersOf[$suite][] = $filter;
            $load[(int) $shard] += (int) $weight;
        }
        self::assertSame($suites, array_keys($filtersOf), 'every suite is in the plan');
        self::assertLessThanOrEqual(1.1 * array_sum($load) / 8, max($load), 'a shard is planned well over its share: ' . implode(' ', $load));

        $sliced = array_filter($filtersOf, static fn (array $filters): bool => count($filters) > 1);
        self::assertNotSame([], $sliced);
        foreach ($sliced as $suite => $filters) {
            $source = (string) file_get_contents($repository . '/' . $suite);
            self::assertSame(1, preg_match('/^namespace\s+([^;]+);/m', $source, $namespace));
            self::assertSame(1, preg_match('/^(?:final\s+|abstract\s+)*class\s+(\w+)/m', $source, $class));
            require_once $repository . '/' . $suite;
            $names = self::testNames(new \PHPUnit\Framework\TestSuite(new \ReflectionClass(trim($namespace[1]) . '\\' . $class[1])));
            self::assertNotSame([], $names);
            $sliceSizes = array_fill_keys(array_keys($filters), 0);
            foreach ($names as $name) {
                $owners = array_keys(array_filter($filters, static fn (string $f): bool => preg_match($f, $name) === 1));
                self::assertCount(1, $owners, "$name is in " . count($owners) . " slices of $suite");
                $sliceSizes[$owners[0]]++;
            }
            self::assertNotContains(0, $sliceSizes, "a slice of $suite names no test");
        }
    }

    /** @return list<string> the names PHPUnit's --filter matches, one per test and data set */
    private static function testNames(\PHPUnit\Framework\Test $test): array
    {
        if ($test instanceof \PHPUnit\Framework\TestSuite) {
            return array_merge([], ...array_map(self::testNames(...), $test->tests()));
        }
        [$class, $name] = \PHPUnit\Util\Test::describe($test);

        return [$class . '::' . $name];
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
