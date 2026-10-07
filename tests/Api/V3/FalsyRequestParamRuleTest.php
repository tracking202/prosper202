<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * ForbidFalsyRequestParamTestRule, run through the committed
 * phpstan.neon.dist (so an unregistered rule fails here) over a fixture
 * tree that plants a truthiness test of a request parameter in every shape
 * the rule claims to cover, beside the presence tests and flag reads it must
 * leave alone, and the same defect in a file outside the api/ directory,
 * which it must not report. Lines ending in `// REPORT` must be reported,
 * once each; no other line may be.
 *
 * @group phpstan
 */
final class FalsyRequestParamRuleTest extends TestCase
{
    private const IDENTIFIER = 'prosper202.falsyRequestParam';

    private const FIXTURE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Api\V3\FalsyRequestParamFixture;

        final class Fixture
        {
            /**
             * @param array<string, mixed> $params
             * @param array<string, mixed> $payload
             * @return list<mixed>
             */
            public function reported(array $params, array $payload, string $key): array
            {
                $out = [];
                $out[] = !empty($params['period']);                     // REPORT
                $out[] = empty($payload['customer_ref']);               // REPORT
                $out[] = empty($params['a']['b']);                      // REPORT
                $out[] = empty($params['period'] ?? null);              // REPORT
                $out[] = empty($params[$key]);                          // REPORT
                $out[] = empty($_GET['period']);                        // REPORT
                $out[] = empty($_POST['period']);                       // REPORT
                $out[] = empty($_REQUEST['period']);                    // REPORT
                $out[] = empty($_COOKIE['period']);                     // REPORT
                $out[] = $params['sort'] ?: 'total_clicks';             // REPORT
                $out[] = ($params['sort'] ?? '') ?: 'total_clicks';     // REPORT
                $out[] = $params['x'] ? 1 : 2;                          // REPORT
                $out[] = !$params['cursor'];                            // REPORT
                $out[] = !($payload['customer_id'] ?? false);           // REPORT
                if ($params['campaign_id']) {                           // REPORT
                    $out[] = 1;
                } elseif ($payload['customer_id']) {                    // REPORT
                    $out[] = 2;
                }
                if (!empty($params['period'])) {                        // REPORT
                    $out[] = 3;
                }
                while ($params['more']) {                               // REPORT
                    break;
                }
                do {
                    $out[] = 4;
                } while ($params['more']);                              // REPORT
                for ($i = 0; $params['more']; $i++) {                   // REPORT
                    break;
                }
                $out[] = $params['a'] && $out;                          // REPORT
                $out[] = $out || $payload['b'];                         // REPORT
                $out[] = ($params['a'] and $out);                       // REPORT
                $out[] = ($out or $params['a']);                        // REPORT
                $out[] = ($params['a'] xor $out);                       // REPORT
                $out[] = $params['a'] == false;                         // REPORT
                $out[] = true != $params['a'];                          // REPORT
                $out[] = $params['a'] == 0;                             // REPORT
                $out[] = '' == $params['a'];                            // REPORT
                $out[] = $params['a'] != '0';                           // REPORT
                $out[] = static fn (array $params): bool => !empty($params['in_a_closure']); // REPORT

                return $out;
            }

            /**
             * @param array<string, mixed> $params
             * @param array<string, mixed> $payload
             * @param array<string, mixed> $options
             * @return list<mixed>
             */
            public function notReported(array $params, array $payload, array $options): array
            {
                $out = [];
                $out[] = isset($params['period']) && $params['period'] !== '';
                $out[] = array_key_exists('cursor', $params);
                $out[] = (bool) ($payload['prune'] ?? false);
                $out[] = boolval($params['flag'] ?? false);
                $out[] = filter_var($params['flag'] ?? false, FILTER_VALIDATE_BOOL);
                $out[] = !empty($options['incremental']);
                $period = $params['period'] ?? '';
                $out[] = $period !== '' ? 1 : 2;
                $out[] = empty($params);
                $out[] = !$payload;
                $out[] = ($params['a'] ?? '') === '0';
                $out[] = ($params['a'] ?? '') == 'x';
                $out[] = ($params['limit'] ?? 0) > 0;
                $out[] = !isset($params['x']);
                $out[] = $params['period'] ?? 'last7';

                return $out;
            }
        }
        PHP;

    private const OUTSIDE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Prosper202\FalsyRequestParamFixture;

        final class Outside
        {
            /** @param array<string, mixed> $params */
            public function notInTheApiTree(array $params): bool
            {
                return !empty($params['period']);
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

        $errorLog = (string) tempnam(sys_get_temp_dir(), 'p202-falsy-stderr-');
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
        $root = sys_get_temp_dir() . '/p202-falsy-' . bin2hex(random_bytes(6));
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
        $this->assertCount(31, $expected, 'the fixture lost some of its planted defects');

        $found = $this->reportedIn($root);
        $this->assertSame([realpath($fixture) ?: $fixture], array_keys($found), 'findings outside the api/ fixture: ' . var_export($found, true));
        $this->assertSame($expected, array_values($found)[0], 'the rule did not report exactly the planted defects, once each');
    }

    public function testTheRuleIsRegisteredInTheCommittedConfig(): void
    {
        $config = (string) file_get_contents($this->repoRoot() . '/phpstan.neon.dist');
        $this->assertSame(
            1,
            preg_match('#^\s*-\s*\\\\?Prosper202\\\\PHPStan\\\\Rules\\\\ForbidFalsyRequestParamTestRule\s*$#m', $config),
            'ForbidFalsyRequestParamTestRule is not a live entry in phpstan.neon.dist\'s rules list; an unregistered rule never runs.'
        );
    }
}
