<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\CloudflareRanges;
use Prosper202\Http\TrustedProxies;

/**
 * P202_TRUSTED_PROXIES: a value that cannot be read trusts nothing and says
 * which entry (CLAUDE.md #11).
 */
final class TrustedProxiesTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, list<string>}> setting, trusted, not trusted */
    public static function settings(): iterable
    {
        yield 'unset' => ['', [], ['10.0.0.1', '127.0.0.1', '::1']];
        yield 'one address' => ['10.0.0.1', ['10.0.0.1'], ['10.0.0.2']];
        yield 'one IPv6 address' => ['::1', ['::1'], ['::2', '127.0.0.1']];
        yield 'a range' => ['10.0.0.0/8', ['10.0.0.1', '10.255.255.255'], ['11.0.0.0', '9.255.255.255']];
        yield 'host bits in a range are masked' => ['10.1.2.3/8', ['10.200.0.1'], ['11.0.0.1']];
        yield 'commas and spaces' => ["10.0.0.0/8, 192.168.1.0/24\n fd00::/8", ['10.0.0.1', '192.168.1.9', 'fd12::1'], ['192.168.2.1']];
        yield 'cloudflare, any case' => ['Cloudflare', ['172.64.0.1', '2606:4700::1'], ['10.0.0.1']];
        yield 'a family never matches the other' => ['10.0.0.0/8', [], ['::ffff:10.0.0.1']];
        yield 'not an address is never trusted' => ['10.0.0.0/8', [], ['', 'nope', '10.0.0']];
    }

    /**
     * @dataProvider settings
     * @param list<string> $trusted
     * @param list<string> $untrusted
     */
    public function testWhatASettingTrusts(string $setting, array $trusted, array $untrusted): void
    {
        $proxies = TrustedProxies::parse($setting);
        self::assertNull($proxies->error());
        foreach ($trusted as $address) {
            self::assertTrue($proxies->trusts($address), $address);
        }
        foreach ($untrusted as $address) {
            self::assertFalse($proxies->trusts($address), $address);
        }
    }

    /** @return iterable<string, array{string, string}> setting, the entry the error names */
    public static function malformed(): iterable
    {
        yield 'a prefix too long' => ['10.0.0.0/33', '10.0.0.0/33'];
        yield 'an IPv6 prefix too long' => ['::/129', '::/129'];
        yield 'a /0 trusts every peer' => ['0.0.0.0/0', '0.0.0.0/0'];
        yield 'an IPv6 /0' => ['::/0', '::/0'];
        yield 'a prefix that is not a number' => ['10.0.0.0/8a', '10.0.0.0/8a'];
        yield 'an empty prefix' => ['10.0.0.0/', '10.0.0.0/'];
        yield 'two slashes' => ['10.0.0.0/8/8', '10.0.0.0/8/8'];
        yield 'not an address' => ['10.0.0', '10.0.0'];
        yield 'a host name' => ['proxy.internal', 'proxy.internal'];
        yield 'a wildcard' => ['*', '*'];
        yield 'one bad entry among good ones' => ['10.0.0.0/8, 192.168.0.0/16x, cloudflare', '192.168.0.0/16x'];
        yield 'a semicolon is not a separator' => ['10.0.0.1;10.0.0.2', '10.0.0.1;10.0.0.2'];
    }

    /** @dataProvider malformed */
    public function testAMalformedSettingTrustsNothingAndNamesTheEntry(string $setting, string $entry): void
    {
        $proxies = TrustedProxies::parse($setting);
        self::assertTrue($proxies->isEmpty());
        self::assertFalse($proxies->trusts('10.0.0.1'));
        self::assertFalse($proxies->trusts('172.64.0.1'));
        self::assertStringContainsString('"' . $entry . '"', (string) $proxies->error());
        self::assertStringContainsString(TrustedProxies::ENV, (string) $proxies->error());
    }

    public function testCloudflareIsEveryPublishedRange(): void
    {
        $proxies = TrustedProxies::parse('cloudflare');
        foreach (CloudflareRanges::CIDRS as $cidr) {
            self::assertTrue($proxies->trusts(explode('/', $cidr)[0]), $cidr);
        }
    }

    public function testTheEnvironmentIsReadAtEachCall(): void
    {
        $before = getenv(TrustedProxies::ENV);
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-trusted-');
        $logBefore = ini_set('error_log', $log);
        try {
            putenv(TrustedProxies::ENV . '=10.0.0.1');
            self::assertTrue(TrustedProxies::fromEnvironment()->trusts('10.0.0.1'));
            putenv(TrustedProxies::ENV . '=10.0.0.2');
            self::assertFalse(TrustedProxies::fromEnvironment()->trusts('10.0.0.1'));
            putenv(TrustedProxies::ENV . '=10.0.0.2/40');
            self::assertTrue(TrustedProxies::fromEnvironment()->isEmpty());
            self::assertStringContainsString('"10.0.0.2/40"', (string) file_get_contents($log), 'a malformed setting is logged');
            putenv(TrustedProxies::ENV);
            self::assertTrue(TrustedProxies::fromEnvironment()->isEmpty());
        } finally {
            putenv($before === false ? TrustedProxies::ENV : TrustedProxies::ENV . '=' . $before);
            ini_set('error_log', $logBefore === false ? '' : $logBefore);
            @unlink($log);
        }
    }
}
