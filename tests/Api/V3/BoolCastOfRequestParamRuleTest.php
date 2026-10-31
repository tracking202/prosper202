<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * ForbidBoolCastOfRequestParamRule, run through the committed
 * phpstan.neon.dist (so an unregistered rule fails here) over a fixture tree
 * that plants a flag read of a request value in every shape the rule claims
 * to cover, beside the reads it must leave alone, and the same defect in a
 * file outside the api/ directory, which it must not report. Lines ending in
 * `// REPORT` must be reported, once each; no other line may be.
 *
 * @group phpstan
 */
final class BoolCastOfRequestParamRuleTest extends TestCase
{
    private const IDENTIFIER = 'prosper202.boolCastOfRequestParam';

    private const FIXTURE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Api\V3\BoolCastFixture;

        final class Fixture
        {
            /**
             * @param array<string, mixed> $params
             * @param array<string, mixed> $queryParams
             * @param array<string, mixed> $payload
             * @return list<mixed>
             */
            public function reported(array $params, array $queryParams, array $payload, string $key): array
            {
                $out = [];
                $out[] = (bool) $payload['force_update'];                // REPORT
                $out[] = (bool)($payload['dry_run'] ?? false);           // REPORT
                $out[] = (boolean) $payload['prune'];                    // REPORT
                $out[] = !(bool) ($payload['skip_errors'] ?? false);     // REPORT
                $out[] = boolval($payload['incremental']);               // REPORT
                $out[] = \boolval($params['include_archived'] ?? '0');   // REPORT
                $out[] = (bool) $queryParams['active'];                  // REPORT
                $out[] = (bool) $payload['a']['b'];                      // REPORT
                $out[] = (bool) $payload[$key];                          // REPORT
                $out[] = (bool) $_GET['flag'];                           // REPORT
                $out[] = (bool) $_POST['flag'];                          // REPORT
                $out[] = (bool) $_REQUEST['flag'];                       // REPORT
                $out[] = (bool) $_COOKIE['flag'];                        // REPORT
                $out[] = filter_var($params['did_win'], FILTER_VALIDATE_BOOL); // REPORT
                $out[] = filter_var($payload['x'] ?? '', FILTER_VALIDATE_BOOLEAN); // REPORT
                $out[] = \filter_var($params['y'], \FILTER_VALIDATE_BOOLEAN, FILTER_FLAG_NONE); // REPORT
                $out[] = static fn (array $payload): bool => (bool) $payload['in_a_closure']; // REPORT

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
                $out[] = (bool) $options['dry_run'];
                $out[] = (bool) $id;
                $out[] = (bool) count($payload);
                $out[] = (int) $payload['limit'];
                $out[] = filter_var($params['did_win'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $out[] = filter_var($params['did_win'], FILTER_VALIDATE_BOOL, ['flags' => FILTER_NULL_ON_FAILURE]);
                $out[] = filter_var($params['did_win'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE | FILTER_FLAG_NONE);
                $out[] = filter_var($params['n'], FILTER_VALIDATE_INT);
                $out[] = boolval(true);
                $flag = $payload['dry_run'] ?? false;
                $out[] = $flag;

                return $out;
            }
        }
        PHP;

    private const OUTSIDE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Prosper202\BoolCastFixture;

        final class Outside
        {
            /** @param array<string, mixed> $payload */
            public function notInTheApiTree(array $payload): bool
            {
                return (bool) $payload['force_update'];
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

        $errorLog = (string) tempnam(sys_get_temp_dir(), 'p202-boolcast-stderr-');
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
        $root = sys_get_temp_dir() . '/p202-boolcast-' . bin2hex(random_bytes(6));
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
            preg_match('#^\s*-\s*\\\\?Prosper202\\\\PHPStan\\\\Rules\\\\ForbidBoolCastOfRequestParamRule\s*$#m', $config),
            'ForbidBoolCastOfRequestParamRule is not a live entry in phpstan.neon.dist\'s rules list; an unregistered rule never runs.'
        );
    }
}
