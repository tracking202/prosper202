<?php

declare(strict_types=1);

namespace Tests\GeoIp;

use PHPUnit\Framework\TestCase;
use Prosper202\GeoIp\Credentials;
use Prosper202\GeoIp\HttpResponse;
use Prosper202\GeoIp\MaxMindDownload;
use Prosper202\GeoIp\RefreshFailed;

/**
 * MaxMind's download as its documentation and its live endpoint describe
 * it: the permalinks, Basic authentication to MaxMind only (never to the R2
 * URL it redirects to), redirects followed by hand and bounded, and every
 * refusal said with what to do. The transport here is a script; the real
 * one is exercised against a TLS server in GeoIpRefreshIntegrationTest.
 */
final class MaxMindDownloadTest extends TestCase
{
    private const SHA = 'aa61f2b9c5e9a4d0c3b2e1f00112233445566778899aabbccddeeff001122334';

    public function testThePermalinksAreMaxMinds(): void
    {
        $download = new MaxMindDownload($this->script([]));

        $permalink = 'https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=';
        self::assertSame($permalink . 'tar.gz', $download->url('tar.gz'));
        self::assertSame($permalink . 'tar.gz.sha256', $download->url('tar.gz.sha256'));
    }

    public function testTheChecksumIsFetchedWithTheCredentialsAndTheRedirectWithout(): void
    {
        $r2 = 'https://mm-prod-geoip-databases.a2649acb697e2c09b632799562c076f2.r2.cloudflarestorage.com'
            . '/geoip-databases/GeoLite2-City/x.tar.gz.sha256?X-Amz-Signature=abc';
        $transport = $this->script([
            new HttpResponse(302, $r2, '', 0),
            new HttpResponse(200, null, self::SHA . "  GeoLite2-City_20261023.tar.gz\n", 96),
        ]);

        $checksum = (new MaxMindDownload($transport))->checksum(new Credentials('123456', 'key_abc'));

        self::assertSame(['sha256' => self::SHA, 'filename' => 'GeoLite2-City_20261023.tar.gz'], $checksum);
        self::assertSame([
            [
                'https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz.sha256',
                ['123456', 'key_abc'],
            ],
            [$r2, null],
        ], $transport->calls);
    }

    public function testARelativeRedirectStaysOnMaxMindAndStillGoesWithoutTheCredentials(): void
    {
        $transport = $this->script([
            new HttpResponse(307, '/elsewhere?suffix=tar.gz.sha256', '', 0),
            new HttpResponse(200, null, self::SHA . ' GeoLite2-City_20261023.tar.gz', 90),
        ]);

        (new MaxMindDownload($transport))->checksum(new Credentials('1', 'key_abc'));

        self::assertSame(['https://download.maxmind.com/elsewhere?suffix=tar.gz.sha256', null], $transport->calls[1]);
    }

    public function testMoreThanThreeRedirectsAreStopped(): void
    {
        $loop = new HttpResponse(302, 'https://example.net/again', '', 0);
        $transport = $this->script([$loop, $loop, $loop, $loop, $loop]);

        $this->expectRefusal('redirected more than 3 times');
        try {
            (new MaxMindDownload($transport))->checksum(new Credentials('1', 'key_abc'));
        } finally {
            self::assertCount(4, $transport->calls);
        }
    }

    public function testARedirectWithNoUsableLocationIsRefused(): void
    {
        foreach ([null, '', '//other.example/x', 'ftp://x/y', 'relative/path'] as $location) {
            try {
                (new MaxMindDownload($this->script([new HttpResponse(302, $location, '', 0)])))
                    ->checksum(new Credentials('1', 'key_abc'));
                self::fail('followed ' . var_export($location, true));
            } catch (RefreshFailed $e) {
                self::assertStringContainsString('no usable Location', $e->getMessage());
            }
        }
    }

    /** @return iterable<string, array{0: int, 1: string}> */
    public static function refusals(): iterable
    {
        yield 'wrong credentials' => [
            401,
            'licence key (401: "Your account ID or license key could not be authenticated.")',
        ];
        yield 'revoked' => [403, 'refused the download (403'];
        yield 'no such edition' => [404, 'has no GeoLite2-City download'];
        yield 'download limit' => [429, 'download limit for this account is reached (429'];
        yield 'outage' => [503, 'answered 503'];
        yield 'anything else' => [418, 'answered 418'];
    }

    /** @dataProvider refusals */
    public function testEveryRefusalIsSaid(int $status, string $fragment): void
    {
        $body = $status === 401 ? 'Your account ID or license key could not be authenticated.' : 'nope';
        $this->expectRefusal($fragment);
        (new MaxMindDownload($this->script([new HttpResponse($status, null, $body, 0)])))
            ->checksum(new Credentials('1', 'key_abc'));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function badChecksumFiles(): iterable
    {
        yield 'empty' => ['', 'does not read as a SHA-256'];
        yield 'an html page' => ['<html><body>Sign in</body></html>', 'does not read as a SHA-256'];
        yield 'md5' => ['d41d8cd98f00b204e9800998ecf8427e  GeoLite2-City_20261023.tar.gz', 'not read as a SHA-256'];
        $twoLines = self::SHA . "  GeoLite2-City_20261023.tar.gz\n" . self::SHA . '  x';
        yield 'two lines' => [$twoLines, 'not read as a SHA-256'];
        yield 'another edition' => [self::SHA . '  GeoLite2-Country_20261023.tar.gz', 'not a GeoLite2-City archive'];
        yield 'a csv' => [self::SHA . '  GeoLite2-City-CSV_20261023.zip', 'not a GeoLite2-City archive'];
    }

    /** @dataProvider badChecksumFiles */
    public function testAChecksumFileThatIsNotOneIsRefused(string $body, string $fragment): void
    {
        $this->expectRefusal($fragment);
        (new MaxMindDownload($this->script([new HttpResponse(200, null, $body, strlen($body))])))
            ->checksum(new Credentials('1', 'key_abc'));
    }

    public function testTheArchiveGoesToTheSinkAndItsSizeIsAnswered(): void
    {
        $transport = $this->script([
            new HttpResponse(302, 'https://r2.example/x.tar.gz', '', 0),
            new HttpResponse(200, null, '', 4242),
        ]);
        $sink = fopen('php://memory', 'w+b');

        self::assertSame(4242, (new MaxMindDownload($transport))->archive(new Credentials('1', 'key_abc'), $sink));
        self::assertSame($sink, $transport->sinks[0]);
        self::assertSame(MaxMindDownload::MAX_ARCHIVE_BYTES, $transport->limits[0]);
        self::assertSame(MaxMindDownload::MAX_CHECKSUM_BYTES, 1024);
    }

    /** @return iterable<string, array{0: string}> */
    public static function overridesRefused(): iterable
    {
        yield 'http' => ['http://127.0.0.1:8151'];
        yield 'a public host' => ['https://download.maxmind.com'];
        yield 'localhost, which DNS answers' => ['https://localhost:8151'];
        yield 'another private address' => ['https://10.0.0.5:8151'];
        yield 'a path' => ['https://127.0.0.1:8151/geoip'];
        yield 'credentials in it' => ['https://u:p@127.0.0.1:8151'];
        yield 'a query' => ['https://127.0.0.1:8151?x=1'];
        yield 'not a URL' => ['127.0.0.1:8151'];
    }

    /** @dataProvider overridesRefused */
    public function testTheTestOverrideTakesOnlyAnHttpsLoopbackOrigin(string $value): void
    {
        $this->expectRefusal('only an https:// origin on 127.0.0.1 or [::1]');
        MaxMindDownload::loopbackOrigin($value);
    }

    public function testTheTestOverrideAcceptsTheLoopbackOrigins(): void
    {
        self::assertSame('https://127.0.0.1:8151', MaxMindDownload::loopbackOrigin('https://127.0.0.1:8151'));
        self::assertSame('https://127.0.0.1:8151', MaxMindDownload::loopbackOrigin('https://127.0.0.1:8151/'));
        self::assertSame('https://[::1]:8151', MaxMindDownload::loopbackOrigin('https://[::1]:8151'));
        self::assertSame('https://127.0.0.1', MaxMindDownload::loopbackOrigin('https://127.0.0.1:443'));
    }

    public function testABadOverrideInTheEnvironmentStopsTheRunRatherThanReachingMaxMind(): void
    {
        $before = getenv(MaxMindDownload::ENV_ORIGIN);
        putenv(MaxMindDownload::ENV_ORIGIN . '=https://evil.example');
        try {
            $this->expectRefusal('nothing was downloaded');
            MaxMindDownload::fromEnvironment();
        } finally {
            putenv($before === false ? MaxMindDownload::ENV_ORIGIN : MaxMindDownload::ENV_ORIGIN . '=' . $before);
        }
    }

    public function testTheCredentialRulesRefuseWhatMaxMindNeverIssues(): void
    {
        self::assertNull(Credentials::accountIdError('123456'));
        self::assertNull(Credentials::licenseKeyError('AbCdEf_0123456789abcdefghijklmnopqrstu_mmk'));
        self::assertNull(Credentials::licenseKeyError('ABCDEF123456'));
        foreach (['', '0', '012', '12a', '1.5', ' 12', str_repeat('9', 11)] as $account) {
            self::assertNotNull(Credentials::accountIdError($account), var_export($account, true));
        }
        foreach (['', 'short', 'has space in it', "line\nbreak", 'colon:in:it', str_repeat('k', 129)] as $key) {
            self::assertNotNull(Credentials::licenseKeyError($key), var_export($key, true));
        }
    }

    /** @param list<HttpResponse> $responses */
    private function script(array $responses): ScriptedTransport
    {
        return new ScriptedTransport($responses);
    }

    private function expectRefusal(string $fragment): void
    {
        $this->expectException(RefreshFailed::class);
        $this->expectExceptionMessage($fragment);
    }
}
