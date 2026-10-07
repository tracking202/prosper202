<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * ForbidNumericCastOfRequestParamRule, run through the committed
 * phpstan.neon.dist (so an unregistered rule fails here) over a fixture tree
 * that plants a numeric cast of a query parameter in every shape the rule
 * claims to cover, beside the reads it must leave alone, and the same defect
 * in a file outside the api/ directory, which it must not report. Lines
 * ending in `// REPORT` must be reported, once each; no other line may be.
 *
 * @group phpstan
 */
final class NumericCastOfRequestParamRuleTest extends TestCase
{
    private const IDENTIFIER = 'prosper202.numericCastOfRequestParam';

    private const FIXTURE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Api\V3\NumericCastFixture;

        final class Fixture
        {
            /**
             * @param array<string, mixed> $params
             * @param array<string, mixed> $queryParams
             * @return list<mixed>
             */
            public function reported(array $params, array $queryParams, string $key): array
            {
                $out = [];
                $out[] = (int) $params['limit'];                         // REPORT
                $out[] = (int)($params['limit'] ?? 50);                  // REPORT
                $out[] = max(1, min(500, (int) ($params['limit'] ?? 50))); // REPORT
                $out[] = (integer) $params['offset'];                    // REPORT
                $out[] = (float) $params['min_value'];                   // REPORT
                $out[] = (double) $params['min_value'];                  // REPORT
                $out[] = intval($params['days']);                        // REPORT
                $out[] = intval($params['days'] ?? 90, 10);              // REPORT
                $out[] = floatval($params['ratio']);                     // REPORT
                $out[] = \intval($queryParams['months']);                // REPORT
                $out[] = (int) $params['a']['b'];                        // REPORT
                $out[] = (int) $params[$key];                            // REPORT
                $out[] = (int) $_GET['limit'];                           // REPORT
                $out[] = (int) $_POST['limit'];                          // REPORT
                $out[] = (int) $_REQUEST['limit'];                       // REPORT
                $out[] = (int) $_COOKIE['limit'];                        // REPORT
                $out[] = static fn (array $params): int => (int) $params['in_a_closure']; // REPORT

                return $out;
            }

            /**
             * @param array<string, mixed> $params
             * @param array<string, mixed> $payload
             * @param array<string, mixed> $options
             * @return list<mixed>
             */
            public function notReported(array $params, array $payload, array $options, int $id): array
            {
                $out = [];
                $out[] = (int) $payload['tracker_id_public'];
                $out[] = (int) $options['limit'];
                $out[] = (int) $id;
                $out[] = (string) $params['name'];
                $out[] = (bool) ($params['flag'] ?? false);
                $out[] = intval('12');
                $out[] = (int) count($params);
                $limit = $params['limit'] ?? '';
                $out[] = $limit;

                return $out;
            }
        }
        PHP;

    private const OUTSIDE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Prosper202\NumericCastFixture;

        final class Outside
        {
            /** @param array<string, mixed> $params */
            public function notInTheApiTree(array $params): int
            {
                return (int) $params['limit'];
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

        $errorLog = (string) tempnam(sys_get_temp_dir(), 'p202-numcast-stderr-');
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

    private function write(string $path, string $contents): void
    {
        $this->assertNotFalse(file_put_contents($path, $contents));
        $this->scratch[] = $path;
    }

    private function mkdir(string $path): void
    {
        $this->assertTrue(mkdir($path, 0o700));
        $this->scratch[] = $path;
    }

    public function testEveryPlantedShapeIsReportedOnceAndNothingElseIs(): void
    {
        // A Prosper202 tree in miniature: api/ beside 202-config/, and a
        // file outside api/ carrying the same defect.
        $root = sys_get_temp_dir() . '/p202-numcast-' . bin2hex(random_bytes(6));
        $this->mkdir($root);
        $this->mkdir($root . '/202-config');
        $this->mkdir($root . '/api');
        $this->mkdir($root . '/lib');
        $fixture = $root . '/api/Fixture.php';
        $this->write($fixture, self::FIXTURE);
        $this->write($root . '/lib/Outside.php', self::OUTSIDE);

        $expected = [];
        foreach (explode("\n", (string) file_get_contents($fixture)) as $index => $line) {
            if (str_contains($line, '// REPORT')) {
                $expected[] = $index + 1;
            }
        }
        $this->assertCount(17, $expected, 'the fixture lost some of its planted defects');

        $found = $this->reportedIn($root);
        $this->assertSame([realpath($fixture) ?: $fixture], array_keys($found), 'findings outside the api/ fixture: ' . var_export($found, true));
        $this->assertSame($expected, array_values($found)[0], 'the rule did not report exactly the planted defects, once each');
    }

    public function testTheRuleIsRegisteredInTheCommittedConfig(): void
    {
        $config = (string) file_get_contents($this->repoRoot() . '/phpstan.neon.dist');
        $this->assertSame(
            1,
            preg_match('#^\s*-\s*\\\\?Prosper202\\\\PHPStan\\\\Rules\\\\ForbidNumericCastOfRequestParamRule\s*$#m', $config),
            'ForbidNumericCastOfRequestParamRule is not a live entry in phpstan.neon.dist\'s rules list; an unregistered rule never runs.'
        );
    }
}
