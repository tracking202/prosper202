<?php

declare(strict_types=1);

namespace Tests\License;

use Api\V3\Controllers\CapabilitiesController;
use Prosper202\License\CliAccessCache;
use Prosper202\License\ShellAccessCache;
use Tests\TestCase;

/**
 * A request that finds another one checking the same key waits for that
 * verdict rather than reading "unknown": on a cold cache, a burst of
 * /capabilities calls read shell: false for a licensed user, and wrote a
 * hold-off marker after the claimant's answer had landed.
 */
final class LicenceCheckClaimTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = 'claim-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        ShellAccessCache::invalidate($this->key);
        CliAccessCache::invalidate($this->key);
        parent::tearDown();
    }

    private function controller(): CapabilitiesController
    {
        $db = $this->createMysqliMock([
            'SELECT p202_customer_api_key FROM 202_users' => [['p202_customer_api_key' => $this->key]],
        ]);
        return new CapabilitiesController($db, 7);
    }

    private function shellAccess(CapabilitiesController $controller): bool
    {
        return (new \ReflectionMethod(CapabilitiesController::class, 'shellAccess'))->invoke($controller);
    }

    public function testTheClaimantsVerdictIsWaitedFor(): void
    {
        self::assertTrue(ShellAccessCache::beginCheck($this->key), 'the test holds the claim');
        $root = dirname(__DIR__, 2);
        $child = proc_open([
            PHP_BINARY, '-r',
            'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
            . ' usleep(300000);'
            . ' \Prosper202\License\ShellAccessCache::write(' . var_export($this->key, true) . ', true);'
            . ' \Prosper202\License\CliAccessCache::write(' . var_export($this->key, true) . ', true);'
            . ' \Prosper202\License\ShellAccessCache::endCheck(' . var_export($this->key, true) . ');',
        ], [], $pipes);
        self::assertIsResource($child);
        try {
            self::assertTrue($this->shellAccess($this->controller()), 'the landed verdict is the answer');
        } finally {
            proc_close($child);
        }
        self::assertFalse(ShellAccessCache::recentlyUnknown($this->key), 'no hold-off marker over a verdict');
    }

    /** Runs $php in a child process, with the cache classes loaded, as the claimant. */
    private function claimant(string $php)
    {
        $root = dirname(__DIR__, 2);
        $child = proc_open([PHP_BINARY, '-r', 'require ' . var_export($root . '/vendor/autoload.php', true) . '; '
            . 'use Prosper202\License\ShellAccessCache; use Prosper202\License\CliAccessCache; '
            . '$k = ' . var_export($this->key, true) . '; ' . $php], [], $pipes);
        self::assertIsResource($child);
        return $child;
    }

    public function testAWaiterLeavesAsSoonAsTheClaimantGotNoAnswer(): void
    {
        self::assertTrue(ShellAccessCache::beginCheck($this->key), 'the test holds the claim');
        $child = $this->claimant('usleep(300000); ShellAccessCache::markUnknown($k); '
            . 'CliAccessCache::markUnknown($k); ShellAccessCache::endCheck($k);');
        try {
            $started = microtime(true);
            self::assertFalse($this->shellAccess($this->controller()), 'no answer and nothing stale: closed');
            self::assertLessThan(2.0, microtime(true) - $started, 'it left when the claim was released');
        } finally {
            proc_close($child);
        }
    }

    public function testAWaiterReadsBothVerdictsOfOneAnswer(): void
    {
        self::assertTrue(ShellAccessCache::beginCheck($this->key), 'the test holds the claim');
        // The shell verdict lands first; the CLI one 0.6 s later, then the claim goes.
        $child = $this->claimant('usleep(200000); ShellAccessCache::write($k, true); usleep(600000); '
            . 'CliAccessCache::write($k, false); ShellAccessCache::endCheck($k);');
        try {
            self::assertFalse($this->controller()->cliAccess(), 'the answer said not paid');
        } finally {
            proc_close($child);
        }
    }

    public function testAVerdictThatDoesNotLandWritesNoHoldOff(): void
    {
        self::assertTrue(ShellAccessCache::beginCheck($this->key), 'the test holds the claim');
        try {
            $started = microtime(true);
            self::assertFalse($this->shellAccess($this->controller()), 'no verdict and nothing stale: closed');
            $waited = microtime(true) - $started;
            self::assertGreaterThanOrEqual(CapabilitiesController::CLAIM_WAIT_MS / 1000 - 0.05, $waited);
            self::assertTrue((new CapabilitiesController($this->createMysqliMock([
                'SELECT p202_customer_api_key FROM 202_users' => [['p202_customer_api_key' => $this->key]],
            ]), 7))->cliAccess(), 'unknown lets the CLI through');
        } finally {
            ShellAccessCache::endCheck($this->key);
        }
        self::assertFalse(ShellAccessCache::recentlyUnknown($this->key), 'a held check is not an outage');
        self::assertFalse(CliAccessCache::recentlyUnknown($this->key), 'a held check is not an outage');
    }

    public function testAKeyRowThatDoesNotComeBackIsUnknown(): void
    {
        $controller = new CapabilitiesController($this->createMysqliMock([]), 7);
        $load = new \ReflectionMethod(CapabilitiesController::class, 'loadClickServerKey');
        self::assertNull($load->invoke($controller), 'no row is unknown, not "no key"');
    }

    /**
     * @dataProvider gateRequests
     */
    public function testTheCliGateExemptsOnlyWhatExplainsARefusal(string $method, string $path, bool $exempt): void
    {
        self::assertSame($exempt, CapabilitiesController::isCliGateExempt($method, $path), "$method $path");
    }

    public function testTheRouterAsksTheGateItsExemptions(): void
    {
        $router = (string)file_get_contents(dirname(__DIR__, 2) . '/api/v3/index.php');
        $gate = [
            'if (\\Api\\V3\\Controllers\\CapabilitiesController::isGoCliUserAgent(',
            '&& !\\Api\\V3\\Controllers\\CapabilitiesController::isCliGateExempt($method, $path)',
            '&& !(new \\Api\\V3\\Controllers\\CapabilitiesController($db, $userId))->cliAccess()) {',
        ];
        $pattern = '/' . preg_quote($gate[0], '/') . '[^\n]*\n\s*' . preg_quote($gate[1], '/')
            . '\n\s*' . preg_quote($gate[2], '/') . '/';
        self::assertSame(1, preg_match(
            $pattern,
            $router
        ), 'the Go CLI gate is three conditions: the user agent, not exempt, not licensed');
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function gateRequests(): iterable
    {
        yield 'root' => ['GET', '/', true];
        yield 'capabilities' => ['GET', '/capabilities', true];
        yield 'health' => ['GET', '/system/health', true];
        yield 'version' => ['GET', '/system/version', true];
        yield 'info' => ['GET', '/system/info/', true];
        yield 'head info' => ['HEAD', '/system/info', true];
        yield 'retention write' => ['PUT', '/system/retention', false];
        yield 'deletion' => ['POST', '/system/retention/delete-before', false];
        yield 'retention read' => ['GET', '/system/retention', false];
        yield 'errors' => ['GET', '/system/errors', false];
        yield 'system root, which no route serves' => ['GET', '/system', true];
        yield 'system root write' => ['POST', '/system', false];
        yield 'a path that starts with system' => ['GET', '/systematic', false];
        yield 'campaigns' => ['GET', '/campaigns', false];
        yield 'info write' => ['POST', '/system/info', false];
        yield 'health prefix' => ['GET', '/system/healthx', false];
        yield 'capabilities below' => ['GET', '/capabilities/x', false];
        yield 'info below' => ['GET', '/system/info/x', false];
        yield 'version below' => ['GET', '/system/version/../retention', false];
    }
}
