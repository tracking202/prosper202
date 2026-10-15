<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * ForbidDirectMysqliStmtCallRule, run through the committed
 * phpstan.neon.dist over a fixture that stands in for Connection. The one
 * call it exempts is Connection::execute()'s own, the checked execute the
 * message points to; a closure or an arrow function written inside that
 * method reports the method as its function, and was exempt too until the
 * rule asked whether the scope is an anonymous function. The stand-in takes
 * `object`, as the real method does. Every spelling of the call is planted:
 * a nullsafe call, and another case and the procedural functions, which the
 * rule did not see; and every receiver that may be a statement, an untyped
 * one and prepare()'s `mysqli_stmt|false`, which it passed over. A class of
 * its own with an `execute()` is not a statement. Lines ending in
 * `// REPORT` must be reported; no other line may be.
 *
 * @group phpstan
 */
final class DirectMysqliStmtCallRuleTest extends TestCase
{
    private const IDENTIFIER = 'prosper202.directStmtCall';

    private const FIXTURE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Prosper202\Database;

        final class Connection
        {
            public function execute(object $stmt): void
            {
                $stmt->execute();
                $run = function () use ($stmt): void { $stmt->execute(); };                    // REPORT
                $arrow = fn (): bool => $stmt->execute();                                       // REPORT
                $static = static fn (): bool => $stmt->execute();                               // REPORT
                $stmt->bind_param('i', $run);                                                   // REPORT
            }

            public function other(\mysqli_stmt $stmt): void
            {
                $stmt->execute();                                                               // REPORT
            }
        }

        final class Elsewhere
        {
            public function execute(\mysqli_stmt $stmt): void
            {
                $stmt->execute();                                                               // REPORT
            }

            public function spellings(?\mysqli_stmt $maybe, \mysqli_stmt $stmt, int $n): void
            {
                $maybe?->execute();                                                             // REPORT
                $stmt->EXECUTE();                                                               // REPORT
                mysqli_stmt_execute($stmt);                                                     // REPORT
                \mysqli_stmt_bind_param($stmt, 'i', $n);                                        // REPORT
            }

            public function receivers($untyped, \mysqli $db): void
            {
                $untyped->execute();                                                            // REPORT
                $prepared = $db->prepare('SELECT 1');
                $prepared->bind_param('i', $untyped);                                           // REPORT
                (new Job())->execute();
            }
        }

        final class Job
        {
            public function execute(): void
            {
            }
        }

        function execute(\mysqli_stmt $stmt): void
        {
            $stmt->execute();                                                                   // REPORT
        }
        PHP;

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->scratch) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        $this->scratch = [];
        parent::tearDown();
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, list<int>> file => the lines this rule reported */
    private function reportedIn(string $path): array
    {
        $binary = null;
        foreach (['/vendor/bin/phpstan', '/phpstan.phar'] as $candidate) {
            if (is_file($this->repoRoot() . $candidate)) {
                $binary = $this->repoRoot() . $candidate;
                break;
            }
        }
        if ($binary === null) {
            $this->markTestSkipped(
                'Neither vendor/bin/phpstan nor phpstan.phar is present, so the rule is NOT being checked by this run.'
            );
        }

        $errorLog = (string) tempnam(sys_get_temp_dir(), 'p202-stmt-call-stderr-');
        $this->scratch[] = $errorLog;
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY, $binary, 'analyse', '-c', 'phpstan.neon.dist',
                '--no-progress', '--error-format=json', '--memory-limit=1G', $path,
            ],
            [1 => ['pipe', 'w'], 2 => ['file', $errorLog, 'w']],
            $pipes,
            $this->repoRoot()
        );
        $this->assertIsResource($process, 'could not start PHPStan');
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        $decoded = json_decode($stdout, true);
        // No JSON is a crashed run, never "no findings" (CLAUDE.md #11).
        $this->assertIsArray(
            $decoded,
            "PHPStan produced no JSON report.\nstdout: $stdout\nstderr: " . file_get_contents($errorLog)
        );
        $this->assertArrayHasKey('files', $decoded, 'PHPStan report has no files section: ' . $stdout);

        $found = [];
        foreach ($decoded['files'] as $file => $report) {
            foreach ($report['messages'] as $message) {
                if (($message['identifier'] ?? '') === self::IDENTIFIER) {
                    $found[(string) $file][] = (int) $message['line'];
                }
            }
        }
        foreach ($found as &$lines) {
            sort($lines);
        }
        unset($lines);

        return $found;
    }

    public function testOnlyConnectionExecutesOwnCallIsExempt(): void
    {
        $dir = sys_get_temp_dir() . '/p202-stmt-call-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir, 0o700));
        $this->scratch[] = $dir;
        $fixture = $dir . '/Fixture.php';
        $this->assertNotFalse(file_put_contents($fixture, self::FIXTURE));
        $this->scratch[] = $fixture;

        $expected = [];
        foreach (explode("\n", (string) file_get_contents($fixture)) as $index => $line) {
            if (str_contains($line, '// REPORT')) {
                $expected[] = $index + 1;
            }
        }
        $this->assertCount(13, $expected, 'the fixture lost some of its planted defects');

        $found = $this->reportedIn($dir);
        $this->assertCount(1, $found, 'findings outside the fixture: ' . var_export($found, true));
        $this->assertSame($expected, array_values($found)[0], 'the rule did not report exactly the planted defects');
    }

    public function testTheRuleIsRegisteredInTheCommittedConfig(): void
    {
        $config = (string) file_get_contents($this->repoRoot() . '/phpstan.neon.dist');
        $this->assertSame(
            1,
            preg_match('#^\s*-\s*\\\\?Prosper202\\\\PHPStan\\\\Rules\\\\ForbidDirectMysqliStmtCallRule\s*$#m', $config),
            'ForbidDirectMysqliStmtCallRule is not a live entry in phpstan.neon.dist\'s rules list;'
                . ' an unregistered rule never runs.'
        );
    }
}
