<?php

declare(strict_types=1);

namespace Tests\AdPlatforms;

use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserAnswer;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;

/**
 * OpenAI's per-minute limits are counted where every process of the install
 * sees them (the install's ServerStateStore), so two processes sending with
 * one key share one count. Run as two real PHP processes at once, each
 * writing its own output file (CLAUDE.md, "concurrent processes").
 */
final class AdvertiserApiSharedLimitTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/p202-openai-quota-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dir);
        }
    }

    private static function api(string $dir, int $perEndpoint, int $overall): AdvertiserApi
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.20'], '203.0.113.0/24');
        $transport = static fn (array $options): array => ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.20', 'location' => null, 'body' => '{"id":"adacct_1"}'];

        return new AdvertiserApi(new WebhookSender($guard, $transport), null, new ServerStateStore($dir), $perEndpoint, $overall);
    }

    public function testTwoProcessesShareOneCount(): void
    {
        $child = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            $guard = new Prosper202\Attribution\WebhookGuard(static fn (string $h): array => ['203.0.113.20'], '203.0.113.0/24');
            $transport = static fn (array $o): array => ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.20', 'location' => null, 'body' => '{"id":"adacct_1"}'];
            $api = new Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi(new Prosper202\Attribution\WebhookSender($guard, $transport), null, new Api\V3\Support\ServerStateStore($argv[2]), 100, 6);
            $ok = 0;
            for ($i = 0; $i < 5; $i++) {
                $ok += $api->get('sk-ads-shared-key-0001', 'ad_account')->isOk() ? 1 : 0;
            }
            file_put_contents($argv[3], (string) $ok);
            PHP;
        $root = dirname(__DIR__, 2);
        new ServerStateStore($this->dir);
        $processes = [];
        foreach (['a', 'b'] as $name) {
            $processes[$name] = proc_open([PHP_BINARY, '-r', $child, '--', $root, $this->dir, $this->dir . '/out-' . $name], [], $pipes);
            self::assertIsResource($processes[$name]);
        }
        foreach ($processes as $process) {
            proc_close($process);
        }
        $sent = (int) file_get_contents($this->dir . '/out-a') + (int) file_get_contents($this->dir . '/out-b');

        self::assertSame(6, $sent, 'ten requests from two processes, six admitted: one count of 6 a minute, not one each');
    }

    public function testAHeldEndpointGivesBackItsShareOfTheOverallCount(): void
    {
        $api = self::api($this->dir, 2, 3);
        self::assertTrue($api->get('sk-ads-shared-key-0002', 'campaigns')->isOk());
        self::assertTrue($api->get('sk-ads-shared-key-0002', 'campaigns')->isOk());
        $held = $api->get('sk-ads-shared-key-0002', 'campaigns');
        self::assertSame(AdvertiserAnswer::RATE_LIMITED, $held->kind);
        self::assertStringContainsString('to GET /campaigns', (string) $held->error);
        self::assertTrue(self::api($this->dir, 2, 3)->get('sk-ads-shared-key-0002', 'ads')->isOk(), 'the held one did not use the third of the overall count');
        self::assertSame(AdvertiserAnswer::RATE_LIMITED, self::api($this->dir, 2, 3)->get('sk-ads-shared-key-0002', 'ad_groups')->kind, 'three in all, across objects');
    }

    public function testACounterThatCannotBeReadHoldsTheRequest(): void
    {
        $quota = new class () implements \Api\V3\Support\QuotaStore {
            public function reserveQuotaWindow(string $bucket, int $limit, int $windowSeconds, int $cost = 1): array
            {
                throw new \RuntimeException('the rate_limits directory cannot be written');
            }

            public function refundQuota(string $bucket, int $windowSeconds, int $cost, int $windowStart): bool
            {
                return false;
            }
        };
        $answer = (new AdvertiserApi(null, null, $quota))->get('sk-ads-shared-key-0003', 'ad_account');

        self::assertSame(AdvertiserAnswer::RATE_LIMITED, $answer->kind);
        self::assertStringContainsString('could not be read', (string) $answer->error);
    }
}
