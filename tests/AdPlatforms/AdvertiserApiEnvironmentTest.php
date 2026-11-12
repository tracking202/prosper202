<?php

declare(strict_types=1);

namespace Tests\AdPlatforms;

use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserAnswer;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi;

/**
 * The loopback endpoint the live pass points the install at
 * (P202_OPENAI_ADS_ENDPOINT): the real transport, over TLS verified against
 * the CA the environment names, to tests/fixtures/openai-ads/fake_openai_ads.py.
 * Anything but an https loopback origin is refused when the client is built.
 */
final class AdvertiserApiEnvironmentTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $tls = '';

    public static function setUpBeforeClass(): void
    {
        self::$tls = sys_get_temp_dir() . '/p202-fake-openai-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $script = dirname(__DIR__) . '/fixtures/openai-ads/fake_openai_ads.py';
        $process = proc_open(['python3', $script, '--port', '0', '--tls-dir', self::$tls, '--api-key', 'sk-ads-live-env-test-1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return;
        }
        $line = (string) fgets($pipes[1]);
        if (preg_match('/^READY (\d+)/', $line, $m) === 1) {
            self::$server = $process;
            self::$port = (int) $m[1];
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        foreach (['cert.pem', 'key.pem'] as $file) {
            @unlink(self::$tls . '/' . $file);
        }
        @rmdir(self::$tls);
        putenv(AdvertiserApi::ENV_ENDPOINT);
        putenv(AdvertiserApi::ENV_CA_FILE);
    }

    protected function setUp(): void
    {
        if (self::$server === null) {
            self::markTestSkipped('The fake Advertiser API did not start (python3 and openssl are needed).');
        }
    }

    public function testTheLoopbackEndpointIsReachedOverVerifiedTls(): void
    {
        putenv(AdvertiserApi::ENV_ENDPOINT . '=https://127.0.0.1:' . self::$port);
        putenv(AdvertiserApi::ENV_CA_FILE . '=' . self::$tls . '/cert.pem');
        $answer = AdvertiserApi::fromEnvironment()->get('sk-ads-live-env-test-1', 'ad_account');
        self::assertTrue($answer->isOk(), (string) $answer->error);
        self::assertSame('adacct_123', $answer->json['id']);

        $refused = AdvertiserApi::fromEnvironment()->get('sk-ads-live-other-key', 'ad_account');
        self::assertSame(AdvertiserAnswer::KEY_REFUSED, $refused->kind);

        putenv(AdvertiserApi::ENV_CA_FILE);
        $untrusted = AdvertiserApi::fromEnvironment()->get('sk-ads-live-env-test-1', 'ad_account');
        self::assertSame(AdvertiserAnswer::UNAVAILABLE, $untrusted->kind, 'verification stays on: a certificate nobody named is not trusted');
    }

    /** @return iterable<string, array{string}> */
    public static function refusedEndpoints(): iterable
    {
        yield 'another host' => ['https://api.example.com'];
        yield 'plain http' => ['http://127.0.0.1:8242'];
        yield 'a path' => ['https://127.0.0.1:8242/v1'];
        yield 'credentials' => ['https://user:pass@127.0.0.1:8242'];
    }

    /** @dataProvider refusedEndpoints */
    public function testAnEndpointOffThisMachineIsRefused(string $endpoint): void
    {
        putenv(AdvertiserApi::ENV_ENDPOINT . '=' . $endpoint);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('it exists for tests');
        AdvertiserApi::fromEnvironment();
    }
}
