<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\CloudflareRanges;
use Prosper202\Http\TrustedProxies;

/**
 * P202_TRUSTED_PROXIES: unset is Cloudflare's ranges, `none` and empty are
 * nothing, and a value that cannot be read trusts nothing and says which
 * entry, at most hourly (CLAUDE.md #11).
 */
final class TrustedProxiesTest extends TestCase
{
    private string|false $before = false;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->before = getenv(TrustedProxies::ENV);
        $this->dir = sys_get_temp_dir() . '/p202-trusted-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        putenv($this->before === false ? TrustedProxies::ENV : TrustedProxies::ENV . '=' . $this->before);
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    /** @return iterable<string, array{string, list<string>, list<string>}> setting, trusted, not trusted */
    public static function settings(): iterable
    {
        yield 'set but empty' => ['', [], ['10.0.0.1', '127.0.0.1', '::1', '172.64.0.1']];
        yield 'blank' => ["  \n ", [], ['172.64.0.1']];
        yield 'none' => ['none', [], ['10.0.0.1', '172.64.0.1']];
        yield 'NONE' => [' NONE ', [], ['172.64.0.1']];
        yield 'one address' => ['10.0.0.1', ['10.0.0.1'], ['10.0.0.2', '172.64.0.1']];
        yield 'one IPv6 address' => ['::1', ['::1'], ['::2', '127.0.0.1']];
        yield 'a range' => ['10.0.0.0/8', ['10.0.0.1', '10.255.255.255'], ['11.0.0.0', '9.255.255.255']];
        yield 'host bits in a range are masked' => ['10.1.2.3/8', ['10.200.0.1'], ['11.0.0.1']];
        yield 'commas and spaces' => ["10.0.0.0/8, 192.168.1.0/24\n fd00::/8", ['10.0.0.1', '192.168.1.9', 'fd12::1'], ['192.168.2.1']];
        yield 'cloudflare, any case' => ['Cloudflare', ['172.64.0.1', '2606:4700::1'], ['10.0.0.1']];
        yield 'cloudflare and a balancer' => ['cloudflare 10.0.0.0/8', ['172.64.0.1', '10.0.0.1'], ['192.168.0.1']];
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
        yield 'none with something else' => ['none, 10.0.0.1', 'none'];
        yield 'none with cloudflare' => ['cloudflare none', 'none'];
    }

    /** @dataProvider malformed */
    public function testAMalformedSettingTrustsNothingAndNamesTheEntry(string $setting, string $entry): void
    {
        $proxies = TrustedProxies::parse($setting);
        self::assertTrue($proxies->isEmpty());
        self::assertFalse($proxies->trusts('10.0.0.1'));
        self::assertFalse($proxies->trusts('172.64.0.1'), 'not even Cloudflare, the default');
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

    public function testUnsetIsCloudflareAndTheEnvironmentIsReadAtEachCall(): void
    {
        putenv(TrustedProxies::ENV);
        $unset = TrustedProxies::fromEnvironment($this->dir);
        self::assertTrue($unset->trusts('172.64.0.1'), 'unset: Cloudflare');
        self::assertTrue($unset->trusts('2a06:98c7:ffff::1'));
        self::assertFalse($unset->trusts('10.0.0.1'));
        putenv(TrustedProxies::ENV . '=');
        self::assertFalse(TrustedProxies::fromEnvironment($this->dir)->trusts('172.64.0.1'), 'set but empty: nothing');
        putenv(TrustedProxies::ENV . '=none');
        self::assertFalse(TrustedProxies::fromEnvironment($this->dir)->trusts('172.64.0.1'), 'none: nothing');
        putenv(TrustedProxies::ENV . '=10.0.0.1');
        self::assertTrue(TrustedProxies::fromEnvironment($this->dir)->trusts('10.0.0.1'));
        self::assertFalse(TrustedProxies::fromEnvironment($this->dir)->trusts('172.64.0.1'), 'a list replaces the default');
    }

    /**
     * The log says which entry, and again only an hour later: the mark
     * is a file named by the value in the install's state directory.
     */
    public function testAMalformedSettingIsLoggedAtMostHourly(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-trusted-log-');
        $logBefore = ini_set('error_log', $log);
        try {
            $value = '10.0.0.2/40-' . bin2hex(random_bytes(3));
            putenv(TrustedProxies::ENV . '=' . $value);
            self::assertTrue(TrustedProxies::fromEnvironment($this->dir)->isEmpty());
            $read = static fn (): string => (string) file_get_contents($log);
            self::assertSame(1, substr_count($read(), '"' . $value . '"'), 'logged once, naming the entry');
            $marks = glob($this->dir . '/trusted-proxies-logged-*') ?: [];
            self::assertCount(1, $marks, 'the mark is kept in the state directory');

            $method = new \ReflectionMethod(TrustedProxies::class, 'logAtMostHourly');
            $error = (string) TrustedProxies::parse($value)->error();
            $method->invoke(null, $error, $value, $this->dir, time() + 60);
            self::assertSame(1, substr_count($read(), '"' . $value . '"'), 'a minute later: not again');
            $method->invoke(null, $error, $value, $this->dir, time() + TrustedProxies::LOG_EVERY_SECONDS + 1);
            self::assertSame(2, substr_count($read(), '"' . $value . '"'), 'an hour later: again');

            $other = $value . 'x';
            $method->invoke(null, (string) TrustedProxies::parse($other)->error(), $other, $this->dir, time() + 120);
            self::assertSame(1, substr_count($read(), '"' . $other . '"'), 'another value is logged at once');

            $method->invoke(null, $error, $value, $this->dir . '/missing', time());
            self::assertSame(3, substr_count($read(), '"' . $value . '"'), 'no place for the mark: logged anyway');
        } finally {
            ini_set('error_log', $logBefore === false ? '' : $logBefore);
            @unlink($log);
        }
    }

    public function testTheSummarySaysWhatIsTrusted(): void
    {
        putenv(TrustedProxies::ENV);
        self::assertStringContainsString('not set: Cloudflare', TrustedProxies::summary());
        putenv(TrustedProxies::ENV . '=');
        self::assertStringContainsString('set but empty: no proxy', TrustedProxies::summary());
        putenv(TrustedProxies::ENV . '=none');
        self::assertStringContainsString('none: no proxy', TrustedProxies::summary());
        putenv(TrustedProxies::ENV . '=cloudflare, 10.0.0.0/8');
        self::assertSame('P202_TRUSTED_PROXIES = cloudflare, 10.0.0.0/8', TrustedProxies::summary());
        putenv(TrustedProxies::ENV . '=10.0.0.0/99');
        self::assertStringContainsString('"10.0.0.0/99"', TrustedProxies::summary());
    }
}
