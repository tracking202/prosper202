<?php

declare(strict_types=1);

namespace Tests\Setup;

use P202Build\ReleaseTree;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * tests/Setup/fixtures/traffic-source-repair-runner.php moves a traffic source
 * with no sign-in, for TrafficSourceOwnersIntegrationTest's child process. A
 * release zip leaves it out, nothing served includes it, and a checkout served
 * as the web root, which serves tests/ too, answers a request for it with a
 * 404 before it reads anything.
 */
final class TrafficSourceRepairRunnerIsNotServedTest extends TestCase
{
    private const REPO = __DIR__ . '/../..';
    private const RUNNER = 'tests/Setup/fixtures/traffic-source-repair-runner.php';

    public function testTheReleaseLeavesItOut(): void
    {
        require_once self::REPO . '/build/scripts/ReleaseTree.php';
        $tree = ReleaseTree::load(self::REPO . '/build/release-manifest.php');

        self::assertTrue(is_file(self::REPO . '/' . self::RUNNER));
        self::assertFalse($tree->ships(self::RUNNER));
    }

    public function testNothingOutsideTheTestsNamesIt(): void
    {
        $files = SourceScan::phpFiles();
        $naming = array_keys(array_filter(
            $files,
            static fn (string $source): bool => str_contains($source, 'traffic-source-repair-runner')
        ));

        self::assertGreaterThan(500, count($files), 'the scan read the served tree');
        self::assertSame([], $naming);
    }

    public function testARequestForItIsANotFoundThatRunsNothing(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        // A database it cannot reach, had it got that far: port 1 refuses.
        $env = ['PATH' => (string) getenv('PATH'), 'P202_TEST_DB_HOST' => '127.0.0.1', 'P202_TEST_DB_PORT' => '1'];
        $server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:$port", '-t', (string) realpath(self::REPO)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($server);
        try {
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $port);
                if ($socket !== false) {
                    fclose($socket);
                    break;
                }
                usleep(100000);
            }
            $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
            $body = file_get_contents("http://127.0.0.1:$port/" . self::RUNNER . '?7+1', false, $context);
            $status = $http_response_header[0] ?? '';
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        self::assertMatchesRegularExpression('#^HTTP/1\.[01] 404\b#', $status);
        self::assertSame('', $body);
    }
}
