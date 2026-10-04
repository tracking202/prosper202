<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;

/**
 * The real lp.php and off.php, run in a child PHP against a stand-in for
 * connect2.php (tests/fixtures/fallback-harness/), to see what each writes to
 * its MySQL-down fallback key. connect2.php itself needs a database and
 * memcached, so it cannot be loaded here.
 */
final class FallbackRedirectScriptsTest extends TestCase
{
    private const NEW_URL = 'https://offer.example/new?o=1';
    private const OLD_FALLBACK = 'https://retired.example/old&subid=p202';
    private const ACIP = '777';
    private const LPIP = '555';

    private static string $tree = '';

    public static function setUpBeforeClass(): void
    {
        $repo = dirname(__DIR__, 2);
        self::$tree = sys_get_temp_dir() . '/p202-fallback-' . bin2hex(random_bytes(4));
        mkdir(self::$tree . '/tracking202/redirect', 0777, true);
        mkdir(self::$tree . '/202-config', 0777, true);
        foreach (['lp.php', 'off.php'] as $script) {
            copy(self::script($repo, $script), self::$tree . '/tracking202/redirect/' . $script);
        }
        foreach (['connect2.php', 'harness-functions.php'] as $stub) {
            copy($repo . '/tests/fixtures/fallback-harness/' . $stub, self::$tree . '/202-config/' . $stub);
        }
        file_put_contents(self::$tree . '/202-config/class-dataengine-slim.php', "<?php\n");
    }

    public static function tearDownAfterClass(): void
    {
        $files = [
            'tracking202/redirect/lp.php', 'tracking202/redirect/off.php',
            '202-config/connect2.php', '202-config/harness-functions.php', '202-config/class-dataengine-slim.php',
        ];
        foreach ($files as $file) {
            @unlink(self::$tree . '/' . $file);
        }
        foreach (['tracking202/redirect', 'tracking202', '202-config', ''] as $dir) {
            @rmdir(self::$tree . '/' . $dir);
        }
    }

    /** A click with no public id: the branch that used to redirect and exit without refreshing. */
    public function testOffWithoutAPublicClickIdRefreshesTheFallback(): void
    {
        $run = self::off(pci: false, url: self::NEW_URL, cached: self::OLD_FALLBACK);

        self::assertSame([self::offKey() => self::NEW_URL . '&subid=p202'], $run['writes']);
    }

    public function testOffWithoutAPublicClickIdAndNoUrlDisablesTheFallback(): void
    {
        $run = self::off(pci: false, url: '', cached: self::OLD_FALLBACK);

        self::assertSame(
            [self::offKey() => ''],
            $run['writes'],
            'the suffix alone is truthy and would be redirected to'
        );
    }

    public function testOffWithoutAPublicClickIdLeavesACurrentFallbackAlone(): void
    {
        $run = self::off(pci: false, url: self::NEW_URL, cached: self::NEW_URL . '&subid=p202');

        self::assertContains(self::offKey(), $run['reads'], 'the run never reached the refresh');
        self::assertSame([], $run['writes']);
    }

    public function testOffWithAPublicClickIdRefreshesTheFallback(): void
    {
        $run = self::off(pci: true, url: self::NEW_URL, cached: self::OLD_FALLBACK);

        self::assertSame([self::offKey() => self::NEW_URL . '&subid=p202'], $run['writes']);
    }

    public function testOffWithAPublicClickIdAndNoUrlDisablesTheFallback(): void
    {
        $run = self::off(pci: true, url: '', cached: self::OLD_FALLBACK);

        self::assertSame([self::offKey() => ''], $run['writes']);
    }

    public function testLpRefreshesTheFallback(): void
    {
        $run = self::lp(url: self::NEW_URL, cached: self::OLD_FALLBACK);

        self::assertSame([self::lpKey() => self::NEW_URL . '&subid=p202'], $run['writes']);
    }

    public function testLpWithNoUrlDisablesTheFallback(): void
    {
        $run = self::lp(url: '', cached: self::OLD_FALLBACK);

        self::assertSame([self::lpKey() => ''], $run['writes']);
    }

    private static function offKey(): string
    {
        return md5('ac_' . self::ACIP . 'h');
    }

    private static function lpKey(): string
    {
        return md5('lp_' . self::LPIP . 'h');
    }

    /** @return array{reads: list<string>, writes: array<string, string>} */
    private static function off(bool $pci, string $url, string $cached): array
    {
        $row = [
            'click_id' => '5', 'user_id' => '1', 'first_click_time' => (string) time(), 'click_filtered' => '0',
            'landing_page_id' => '0', 'click_cloaking' => '0', 'aff_campaign_id' => '3', 'aff_campaign_rotate' => '0',
            'aff_campaign_url' => $url, 'aff_campaign_name' => 'Offer', 'aff_campaign_cloaking' => '0',
            'aff_campaign_payout' => '1.00',
        ];

        return self::runScript('off.php', [
            'get' => ['acip' => self::ACIP] + ($pci ? ['pci' => '9'] : []),
            // With a click cookie, off.php keeps the request's pci instead of looking one up by IP.
            'cookie' => $pci ? ['tracking202subid' => '5'] : [],
            'row' => $row,
            'cache' => [self::offKey() => $cached],
        ]);
    }

    /** @return array{reads: list<string>, writes: array<string, string>} */
    private static function lp(string $url, string $cached): array
    {
        $row = [
            'user_id' => '1', 'landing_page_id' => '4', 'landing_page_id_public' => self::LPIP,
            'aff_campaign_id' => '3',
            'aff_campaign_rotate' => '0', 'aff_campaign_url' => $url, 'aff_campaign_payout' => '1.00',
            'aff_campaign_cloaking' => '0', 'ppc_account_id' => '0', 'click_cpc' => '0', 'text_ad_id' => '0',
            'click_cloaking' => '0',
        ];

        return self::runScript('lp.php', [
            'get' => ['lpip' => self::LPIP],
            'cookie' => ['tracking202subid_a_3' => '5'],
            'row' => $row,
            'cache' => [self::lpKey() => $cached],
        ]);
    }

    /**
     * @param array<string, mixed> $request
     * @return array{reads: list<string>, writes: array<string, string>}
     */
    private static function runScript(string $script, array $request): array
    {
        $out = self::$tree . '/run-' . bin2hex(random_bytes(4)) . '.json';
        $env = json_encode($request + [
            'repo' => dirname(__DIR__, 2),
            'out' => $out,
            'server' => [
                'REMOTE_ADDR' => '203.0.113.9', 'SERVER_NAME' => 'track.example', 'HTTP_HOST' => 'track.example',
            ],
        ], JSON_THROW_ON_ERROR);
        $command = [
            PHP_BINARY, '-d', 'auto_prepend_file=' . dirname(__DIR__) . '/fixtures/fallback-harness/prepend.php',
            self::$tree . '/tracking202/redirect/' . $script,
        ];
        $pipesSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $pipesSpec, $pipes, null, ['P202_HARNESS' => $env] + getenv());
        self::assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        proc_close($proc);

        self::assertFileExists($out, "$script did not finish: $stdout $stderr");
        $result = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        unlink($out);
        self::assertStringNotContainsString('Fatal error', $stdout . $stderr);

        return ['reads' => $result['reads'], 'writes' => $result['writes']];
    }

    private static function script(string $repo, string $name): string
    {
        foreach (['/tracking202/redirect/', '/tracking202/Redirect/'] as $dir) {
            if (is_file($repo . $dir . $name)) {
                return $repo . $dir . $name;
            }
        }
        self::fail("$name not found");
    }
}
