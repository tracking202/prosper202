<?php

declare(strict_types=1);

namespace Tests\GeoIp;

use PHPUnit\Framework\TestCase;
use Prosper202\GeoIp\GuardedCurlTransport;
use Prosper202\GeoIp\RefreshFailed;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Notifications\PostbackSender;

/**
 * The download goes through the webhooks' outbound guard and the senders'
 * pin: every option PostbackSender::curlOptions() sets is set to its value,
 * except the time limit a download of tens of megabytes needs and the
 * protocols, and a URL the guard refuses is never fetched (CLAUDE.md #24: what else is merged into a pin's
 * options, and in which order).
 */
final class GuardedCurlTransportTest extends TestCase
{
    /** The only options the download sets differently from the sender, or adds. */
    private const DOWNLOAD_ONLY = [
        CURLOPT_TIMEOUT, CURLOPT_LOW_SPEED_LIMIT, CURLOPT_LOW_SPEED_TIME,
        CURLOPT_PROTOCOLS, CURLOPT_REDIR_PROTOCOLS, CURLOPT_USERAGENT,
    ];

    public function testItCarriesTheGuardsWholeSetPinnedToTheApprovedAddress(): void
    {
        $url = 'https://1.1.1.1/geoip/databases/GeoLite2-City/download?suffix=tar.gz';
        $options = (new GuardedCurlTransport())->pinnedOptions($url);
        $sender = PostbackSender::curlOptions((new WebhookGuard())->check($url, [443]));

        foreach ($sender as $option => $value) {
            if (in_array($option, self::DOWNLOAD_ONLY, true)) {
                continue;
            }
            self::assertArrayHasKey($option, $options);
            self::assertSame($value, $options[$option], 'option ' . $option . ' is the sender\'s');
        }
        // An address literal is its own pin: curl connects to the address it names.
        self::assertSame($url, $options[CURLOPT_URL]);
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $options);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(GuardedCurlTransport::TIMEOUT, $options[CURLOPT_TIMEOUT]);
        $added = array_diff(array_keys($options), array_keys($sender), self::DOWNLOAD_ONLY);
        self::assertSame([], $added, 'nothing else is added');
        self::assertArrayNotHasKey(CURLOPT_CAINFO, $options, 'MaxMind is verified against the system trust store');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function refusedUrls(): iterable
    {
        yield 'plain http' => ['http://1.1.1.1/x', 'must start with https://'];
        yield 'a loopback address' => ['https://127.0.0.1/x', 'a loopback address'];
        yield 'a private address' => ['https://10.1.2.3/x', 'a private address'];
        yield 'link-local metadata' => ['https://169.254.169.254/latest/meta-data/', 'a link-local address'];
        yield 'carrier-grade NAT' => ['https://100.64.0.1/x', 'a carrier-grade NAT address'];
        yield 'a port the download does not use' => ['https://1.1.1.1:8443/x', 'only sent to port 443'];
        yield 'no host' => ['https:///x', 'is not in the form https://host/path'];
    }

    /** @dataProvider refusedUrls */
    public function testTheGuardRefusesWhatAWebhookCouldNotReachEither(string $url, string $fragment): void
    {
        $this->expectException(RefreshFailed::class);
        $this->expectExceptionMessage('The outbound guard refused');
        $this->expectExceptionMessage($fragment);
        (new GuardedCurlTransport())->get($url, ['1', 'key'], null, 1024);
    }

    public function testTheLoopbackTestOriginIsPinnedToItselfWithItsCa(): void
    {
        $transport = new GuardedCurlTransport('https://127.0.0.1:8151', '/tmp/ca.pem');
        $options = $transport->pinnedOptions('https://127.0.0.1:8151/r2/GeoLite2-City.tar.gz?X-Amz-Signature=x');

        self::assertSame('https://127.0.0.1:8151/r2/GeoLite2-City.tar.gz?X-Amz-Signature=x', $options[CURLOPT_URL]);
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $options, 'the address literal is its own pin');
        self::assertSame('/tmp/ca.pem', $options[CURLOPT_CAINFO]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame('', $options[CURLOPT_PROXY]);
    }

    /** @return iterable<string, array{0: GuardedCurlTransport, 1: string}> */
    public static function urlsCarryingASecret(): iterable
    {
        $signed = '/r2/GeoLite2-City.tar.gz?X-Amz-Credential=MMKEY&X-Amz-Signature=sekrit';
        yield 'a redirect the guard refuses' => [new GuardedCurlTransport(), 'https://1.1.1.1:8443' . $signed];
        yield 'user info' => [new GuardedCurlTransport(), 'https://user:sekrit@1.1.1.1:8443/x'];
        yield 'another origin in test mode' => [
            new GuardedCurlTransport('https://127.0.0.1:8151'),
            'https://127.0.0.1:8152' . $signed,
        ];
        yield 'after the deadline' => [new GuardedCurlTransport(null, null, time() - 1), 'https://1.1.1.1' . $signed];
    }

    /** @dataProvider urlsCarryingASecret */
    public function testARefusedUrlIsNamedByItsOriginAlone(GuardedCurlTransport $transport, string $url): void
    {
        try {
            $transport->get($url, ['1', 'key'], null, 1024);
            self::fail('fetched ' . $url);
        } catch (RefreshFailed $e) {
            self::assertStringNotContainsString('sekrit', $e->getMessage(), 'logged, stored and shown in Settings');
            self::assertStringNotContainsString('MMKEY', $e->getMessage());
            self::assertStringContainsString((string) parse_url($url, PHP_URL_HOST), $e->getMessage());
        }
    }

    public function testEveryRequestOfARunIsLimitedToTheTimeTheRunHasLeft(): void
    {
        $options = (new GuardedCurlTransport(null, null, time() + 100))->pinnedOptions('https://1.1.1.1/x');
        self::assertGreaterThanOrEqual(99, $options[CURLOPT_TIMEOUT]);
        self::assertLessThanOrEqual(100, $options[CURLOPT_TIMEOUT]);

        $this->expectException(RefreshFailed::class);
        $this->expectExceptionMessage('ran out of time');
        (new GuardedCurlTransport(null, null, time()))->get('https://1.1.1.1/x', ['1', 'key'], null, 1024);
    }

    public function testAnOptionCurlWillNotSetStopsTheRequest(): void
    {
        $ch = curl_init();
        self::assertNotFalse($ch);
        try {
            GuardedCurlTransport::apply($ch, [CURLOPT_SSLVERSION => 999, CURLOPT_PROXY => ''], 'pinned');
            self::fail('curl_setopt_array() stopped at an option and the request would have gone on without the rest');
        } catch (RefreshFailed $e) {
            self::assertStringContainsString('so nothing was sent', $e->getMessage());
        } finally {
            curl_close($ch);
        }
    }

    public function testEveryOptionTheTransportSetsGoesThroughApply(): void
    {
        $source = (string) file_get_contents(\Tests\Support\SourceScan::repoRoot()
            . '/202-config/GeoIp/GuardedCurlTransport.php');
        $calls = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            $name = ltrim(strtolower($token->text), '\\');
            $setsOptions = in_array($name, ['curl_setopt', 'curl_setopt_array'], true);
            if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $setsOptions) {
                $calls[] = $token->line;
            }
        }
        self::assertCount(1, $calls, 'one call sets curl options, the one apply() checks');
        $apply = strpos($source, 'public static function apply(');
        self::assertNotFalse($apply);
        self::assertGreaterThan(substr_count(substr($source, 0, $apply), "\n") + 1, $calls[0]);
    }

    public function testInTestModeNoOtherOriginIsFetched(): void
    {
        $transport = new GuardedCurlTransport('https://127.0.0.1:8151', null);
        foreach (['https://download.maxmind.com/x', 'https://127.0.0.1:8152/x', 'http://127.0.0.1:8151/x'] as $url) {
            try {
                $transport->get($url, ['1', 'key'], null, 1024);
                self::fail('fetched ' . $url . ' in test mode');
            } catch (RefreshFailed $e) {
                self::assertStringContainsString('In test mode', $e->getMessage());
            }
        }
    }
}
