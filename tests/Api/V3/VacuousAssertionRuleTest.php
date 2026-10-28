<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * `$this->assertSame('verified-only', 'verified-only');` was the attribution
 * report suite's only check of the verified-only default. It compared a
 * literal with itself, so the suite was green against every implementation of
 * that default — including the regressed ones. A reviewer caught it; nothing
 * mechanical could have.
 *
 * VacuousAssertionRule is the mechanical check. This test is the other half of
 * it, for a reason worth stating plainly: tests/ is deliberately absent from
 * `paths` in phpstan.neon.dist. Analysing it there reports pre-existing
 * findings from the other rules, and — whenever dev dependencies are not
 * installed — one non-ignorable "extends unknown class
 * PHPUnit\Framework\TestCase" per suite. So the PHPStan job registers the rule
 * but never shows it a test file, and a rule that sees nothing is as dead as
 * an unregistered one. This test runs the committed config over tests/ and
 * fails on this rule's identifier alone, which is what gives the rule its
 * reach; it also proves the registration, because it goes through
 * phpstan.neon.dist rather than instantiating the rule directly.
 *
 * @group phpstan
 */
final class VacuousAssertionRuleTest extends TestCase
{
    private const IDENTIFIER = 'prosper202.vacuousAssertion';

    /**
     * Every call form and operand shape the rule claims to cover, plus the
     * shapes it must leave alone. Lines ending in `// VACUOUS` must be
     * reported; every other line must not be.
     *
     * The first line is the defect exactly as it shipped.
     */
    private const FIXTURE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace VacuousAssertionRuleFixture;

        use PHPUnit\Framework\Assert;

        const FOO_CONST = 'foo';

        class Fixture
        {
            public const BAR = 'bar';

            public function reported(string $x): void
            {
                $this->assertSame('verified-only', 'verified-only');           // VACUOUS
                $this->assertSame('a', "a");                                   // VACUOUS
                self::assertSame(1, 1);                                        // VACUOUS
                static::assertEquals(true, true);                              // VACUOUS
                Assert::assertNotSame(null, null);                             // VACUOUS
                \PHPUnit\Framework\Assert::assertSame(self::BAR, self::BAR);    // VACUOUS
                $this->assertSame([1, 'a' => 2], [1, 'a' => 2]);               // VACUOUS
                $this->assertSame(1.5, 1.5, 'a message');                      // VACUOUS
                $this->assertGreaterThan(3, 3);                                // VACUOUS
                $this->assertNotEquals('x', 'x');                              // VACUOUS
                $this->assertStringContainsString('n', 'n');                   // VACUOUS
                $this->assertSame(-1, -1);                                     // VACUOUS
                $this->assertSame('a' . 'b', 'a' . 'b');                       // VACUOUS
                $this->assertSame($x, $x);                                     // VACUOUS
                $this->assertEqualsWithDelta(1.0, 1.0, 0.1);                   // VACUOUS
                $this->assertSame(FOO_CONST, FOO_CONST);                       // VACUOUS
                $this->assertSame([$x], [$x]);                                 // VACUOUS
                $this->assertSame(false, FALSE);                               // VACUOUS
            }

            public function notReported(string $x, string $y, string $method): void
            {
                $this->assertSame('a', 'b');
                $this->assertSame(1, 2);
                $this->assertSame(1, 1.0);
                $this->assertSame('a', $x);
                $this->assertSame($x, $y);
                $this->assertSame('a', $this->makeA());
                $this->assertSame($this->makeA(), $this->makeA());
                $this->assertSame("v{$x}", "v{$x}");
                $this->assertSame(__LINE__, __LINE__);
                $this->assertSame([1], [1, 2]);
                $this->assertContains('a', 'a');
                $this->assertTrue(true);
                $this->assertSame('a');
                $this->{$method}('a', 'a');
                // Conservative misses, documented rather than fixed: a spread
                // and named arguments both break the positional reading of
                // which two operands are compared, and self:: / static:: are
                // not textually the same class.
                $this->assertSame(...['a', 'a']);
                $this->assertSame(expected: 'a', actual: 'a');
                $this->assertSame(self::BAR, static::BAR);
            }

            private function makeA(): string
            {
                return 'a';
            }

            public function __call(string $name, array $arguments): void
            {
            }

            public static function __callStatic(string $name, array $arguments): void
            {
            }
        }
        PHP;

    /** @var string[] directories to remove in tearDown */
    private array $scratchDirs = [];

    /** @var string[] files to remove in tearDown */
    private array $scratchFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchFiles as $file) {
            @unlink($file);
        }
        $this->scratchFiles = [];

        foreach ($this->scratchDirs as $dir) {
            foreach ((array)glob($dir . '/*') as $file) {
                @unlink((string)$file);
            }
            @rmdir($dir);
        }
        $this->scratchDirs = [];

        parent::tearDown();
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * PHPStan as the CI jobs get it: the Composer binary when dev
     * dependencies are installed, the phar a sandbox downloads otherwise.
     */
    private function phpstanBinary(): ?string
    {
        foreach (['/vendor/bin/phpstan', '/phpstan.phar'] as $candidate) {
            $path = $this->repoRoot() . $candidate;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Analyses $path with the COMMITTED config — so an unregistered rule
     * fails this test — and returns the lines this rule reported.
     *
     * @return array<string, int[]> absolute file path => sorted line numbers
     */
    private function vacuousAssertionsIn(string $path): array
    {
        $binary = $this->phpstanBinary();
        if ($binary === null) {
            // Skipping is the only option in a sandbox with a partial
            // vendor/, but it means the invariant is unguarded for that run —
            // testTheRuleIsRegisteredInTheCommittedConfig() is the part that
            // still executes, and CI installs dev dependencies.
            $this->markTestSkipped(
                'Neither vendor/bin/phpstan nor phpstan.phar is present, so the rule cannot be run '
                . 'and vacuous assertions in tests/ are NOT being checked by this run.'
            );
        }

        $command = [
            PHP_BINARY,
            $binary,
            'analyse',
            '-c',
            'phpstan.neon.dist',
            '--no-progress',
            '--error-format=json',
            $path,
        ];

        // stderr goes to a file, not a second pipe: draining stdout first
        // while the child blocks writing a full stderr pipe would deadlock,
        // and PHPStan can be chatty there on an unexpected runtime.
        $errorLog = (string)tempnam(sys_get_temp_dir(), 'p202-vacuous-stderr-');
        $this->scratchFiles[] = $errorLog;

        $descriptors = [1 => ['pipe', 'w'], 2 => ['file', $errorLog, 'w']];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $this->repoRoot());
        $this->assertIsResource($process, 'could not start PHPStan');

        $stdout = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        $stderr = (string)file_get_contents($errorLog);

        $decoded = json_decode($stdout, true);
        // A crashed or memory-starved run prints no JSON. Decoding that to
        // null and reading it as "no findings" would make this test pass by
        // failing to look (CLAUDE.md #11).
        $this->assertIsArray(
            $decoded,
            sprintf("PHPStan produced no JSON report.\nstdout: %s\nstderr: %s", $stdout, $stderr)
        );
        $this->assertArrayHasKey('files', $decoded, 'PHPStan report has no files section: ' . $stdout);

        $found = [];
        foreach ($decoded['files'] as $file => $report) {
            foreach ($report['messages'] as $message) {
                if (($message['identifier'] ?? '') !== self::IDENTIFIER) {
                    continue;
                }
                $found[(string)$file][] = (int)$message['line'];
            }
        }
        foreach ($found as &$lines) {
            sort($lines);
        }
        unset($lines);

        return $found;
    }

    public function testEveryShapeTheRuleClaimsToCoverIsReportedAndNothingElseIs(): void
    {
        $dir = sys_get_temp_dir() . '/p202-vacuous-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir, 0o700), 'could not create scratch directory');
        $this->scratchDirs[] = $dir;

        $fixture = $dir . '/Fixture.php';
        $this->assertNotFalse(file_put_contents($fixture, self::FIXTURE), 'could not write fixture');

        // The planted defects must actually be in the file that gets
        // analysed, and there must be more than one of them — a fixture that
        // silently failed to write its cases would otherwise "pass".
        $written = (string)file_get_contents($fixture);
        $expected = [];
        foreach (explode("\n", $written) as $index => $line) {
            if (str_contains($line, '// VACUOUS')) {
                $expected[] = $index + 1;
            }
        }
        $this->assertCount(18, $expected, 'the fixture lost some of its planted defects');

        $found = $this->vacuousAssertionsIn($dir);

        // Keyed by whichever spelling of the path PHPStan echoes back; the
        // fixture is the only file in the directory.
        $this->assertCount(1, $found, 'the rule reported findings outside the fixture: ' . var_export($found, true));
        $this->assertStringEndsWith('/Fixture.php', (string)array_key_first($found));
        $this->assertSame(
            $expected,
            array_values($found)[0],
            'the rule did not report exactly the planted defects'
        );
    }

    public function testNoAssertionInTheSuiteComparesAnExpressionWithItself(): void
    {
        $found = $this->vacuousAssertionsIn($this->repoRoot() . '/tests');

        $formatted = [];
        foreach ($found as $file => $lines) {
            foreach ($lines as $line) {
                $formatted[] = '  ' . str_replace($this->repoRoot() . '/', '', $file) . ':' . $line;
            }
        }

        $this->assertSame([], $formatted, sprintf(
            "These assertions compare an expression with itself, so their outcome does not depend "
            . "on the code under test:\n%s\nAssert the value the test actually produces.",
            implode("\n", $formatted)
        ));
    }

    public function testTheRuleIsRegisteredInTheCommittedConfig(): void
    {
        // Registration is what the two tests above exercise; asserting it
        // separately is what tells you WHY they broke if someone drops the
        // line. phpstan.neon is gitignored for local overrides, so the .dist
        // file is the one CI runs.
        $config = (string)file_get_contents($this->repoRoot() . '/phpstan.neon.dist');

        $this->assertSame(
            1,
            preg_match('#^\s*-\s*\\\\?Prosper202\\\\PHPStan\\\\Rules\\\\VacuousAssertionRule\s*$#m', $config),
            'VacuousAssertionRule is not a live entry in phpstan.neon.dist\'s rules list; '
            . 'an unregistered rule never runs, and a commented-out one is unregistered.'
        );
    }
}
