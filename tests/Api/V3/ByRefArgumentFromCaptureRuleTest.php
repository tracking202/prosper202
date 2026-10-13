<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * ForbidByRefArgumentFromCaptureRule, run through the committed
 * phpstan.neon.dist (so an unregistered rule fails here) over a fixture that
 * plants the defect in every call form the rule claims to cover — a static
 * method (the shape that shipped in UpdateController::cpc()), an instance
 * method, a nullsafe call, a function, a named argument, a constructor, an
 * element of a captured array, and a closure's by-value `use` — beside the
 * shapes it must leave alone. Lines ending in `// REPORT` must be reported;
 * no other line may be.
 *
 * @group phpstan
 */
final class ByRefArgumentFromCaptureRuleTest extends TestCase
{
    private const IDENTIFIER = 'prosper202.byRefArgumentFromCapture';

    private const FIXTURE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace ByRefArgumentFromCaptureFixture;

        final class Labels
        {
            /** @param array<string, string>|null $sink */
            public function __construct(?array &$sink = null)
            {
                $sink = [];
            }

            /** @param array<string, string> $errors */
            public static function check(int $id, array &$errors): string
            {
                if ($id < 0) {
                    $errors['id'] = 'not yours';
                }
                return 'name';
            }

            /** @param array<string, string> $errors */
            public function checkOn(int $id, array &$errors): string
            {
                return self::check($id, $errors);
            }
        }

        /** @param array<int|string, mixed> $errors */
        function report(array &$errors): void
        {
            $errors[] = 'x';
        }

        final class Fixture
        {
            private Labels $labels;

            public function __construct()
            {
                $this->labels = new Labels();
            }

            /** @param callable(): mixed $fn */
            private function guard(callable $fn): mixed
            {
                return $fn();
            }

            public function reported(): void
            {
                $errors = [];
                $nested = ['a' => []];
                $labels = $this->labels;
                $this->guard(fn () => Labels::check(1, $errors));                        // REPORT
                $this->guard(fn () => $this->labels->checkOn(1, $errors));               // REPORT
                $this->guard(fn () => $labels?->checkOn(1, $errors));                    // REPORT
                $this->guard(fn () => report($errors));                                  // REPORT
                $this->guard(fn () => Labels::check(id: 1, errors: $errors));            // REPORT
                $this->guard(fn () => new Labels($errors));                              // REPORT
                $this->guard(fn () => report($nested['a']));                             // REPORT
                $this->guard(fn () => preg_match('/x/', 'x', $errors));                  // REPORT
                $this->guard(function () use ($errors): void { report($errors); });      // REPORT
                $this->guard(fn () => fn () => report($errors));                         // REPORT
            }

            public function notReported(int $id): void
            {
                $errors = [];
                $labels = $this->labels;
                Labels::check($id, $errors);
                $this->guard(function () use (&$errors): void { report($errors); });
                $this->guard(fn () => preg_match('/x/', 'x', $match) ? $match[0] : null);
                $this->guard(fn () => preg_match('/x/', 'x', $errors) ? $errors : null);
                $this->guard(fn (array $own) => report($own));
                $this->guard(fn () => strlen($errors === [] ? 'a' : 'b'));
                $this->guard(function () use ($errors): array { sort($errors); return $errors; });
                $this->guard(function () use ($errors): array { $copy = $errors; sort($copy); return $copy; });
                $this->guard(fn () => $labels->checkOn($id, ...[$errors]));
                $this->guard(fn () => Labels::check(...));
            }
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
            $this->markTestSkipped('Neither vendor/bin/phpstan nor phpstan.phar is present, so the rule is NOT being checked by this run.');
        }

        $errorLog = (string) tempnam(sys_get_temp_dir(), 'p202-byref-stderr-');
        $this->scratch[] = $errorLog;
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $binary, 'analyse', '-c', 'phpstan.neon.dist', '--no-progress', '--error-format=json', '--memory-limit=1G', $path],
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
        $this->assertIsArray($decoded, "PHPStan produced no JSON report.\nstdout: $stdout\nstderr: " . file_get_contents($errorLog));
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

    public function testEveryPlantedShapeIsReportedAndNothingElseIs(): void
    {
        $dir = sys_get_temp_dir() . '/p202-byref-' . bin2hex(random_bytes(6));
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
        $this->assertCount(10, $expected, 'the fixture lost some of its planted defects');

        $found = $this->reportedIn($dir);
        $this->assertCount(1, $found, 'findings outside the fixture: ' . var_export($found, true));
        $this->assertSame($expected, array_values($found)[0], 'the rule did not report exactly the planted defects');
    }

    public function testTheRuleIsRegisteredInTheCommittedConfig(): void
    {
        $config = (string) file_get_contents($this->repoRoot() . '/phpstan.neon.dist');
        $this->assertSame(
            1,
            preg_match('#^\s*-\s*\\\\?Prosper202\\\\PHPStan\\\\Rules\\\\ForbidByRefArgumentFromCaptureRule\s*$#m', $config),
            'ForbidByRefArgumentFromCaptureRule is not a live entry in phpstan.neon.dist\'s rules list; an unregistered rule never runs.'
        );
    }
}
