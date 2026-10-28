<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookRefused;

/**
 * The SSRF guard for export webhooks (plan §7.1): every address class the
 * IANA special-purpose registries mark as not globally reachable, the
 * spellings of an address a resolver reads differently from a person, IPv6
 * and the IPv4 addresses it can carry, what a resolver answers, and the
 * operator's allowlist — which cannot reach the metadata endpoints.
 */
final class WebhookGuardTest extends TestCase
{
    /** @param array<string, list<string>> $dns */
    private static function guard(array $dns = [], string $allow = ''): WebhookGuard
    {
        return new WebhookGuard(static fn (string $host): array => $dns[$host] ?? [], $allow);
    }

    private static function refused(WebhookGuard $guard, string $url): string
    {
        try {
            $guard->check($url);
        } catch (WebhookRefused $e) {
            return $e->getMessage();
        }
        self::fail($url . ' was accepted');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function refusedLiterals(): iterable
    {
        yield 'this network' => ['https://0.0.0.0/', 'this network'];
        yield 'this network, other' => ['https://0.1.2.3/', 'this network'];
        yield 'private 10/8' => ['https://10.0.0.1/', 'private'];
        yield 'private 10/8 top' => ['https://10.255.255.255/', 'private'];
        yield 'CGNAT' => ['https://100.64.0.1/', 'carrier-grade NAT'];
        yield 'CGNAT top' => ['https://100.127.255.254/', 'carrier-grade NAT'];
        yield 'loopback' => ['https://127.0.0.1/', 'loopback'];
        yield 'loopback other' => ['https://127.255.255.254/', 'loopback'];
        yield 'link-local metadata' => ['https://169.254.169.254/latest/meta-data/', 'link-local'];
        yield 'link-local' => ['https://169.254.0.1/', 'link-local'];
        yield 'private 172.16/12' => ['https://172.16.0.1/', 'private'];
        yield 'private 172.31' => ['https://172.31.255.255/', 'private'];
        yield 'IETF assignments' => ['https://192.0.0.8/', 'IETF'];
        yield 'Oracle metadata' => ['https://192.0.0.192/', 'metadata'];
        yield 'TEST-NET-1' => ['https://192.0.2.10/', 'documentation'];
        yield '6to4 relay' => ['https://192.88.99.1/', '6to4'];
        yield 'private 192.168/16' => ['https://192.168.1.1/', 'private'];
        yield 'benchmarking' => ['https://198.18.0.1/', 'benchmarking'];
        yield 'benchmarking top' => ['https://198.19.255.255/', 'benchmarking'];
        yield 'TEST-NET-2' => ['https://198.51.100.7/', 'documentation'];
        yield 'TEST-NET-3' => ['https://203.0.113.9/', 'documentation'];
        yield 'multicast' => ['https://224.0.0.1/', 'multicast'];
        yield 'multicast top' => ['https://239.255.255.255/', 'multicast'];
        yield 'reserved' => ['https://240.0.0.1/', 'reserved'];
        yield 'broadcast' => ['https://255.255.255.255/', 'reserved'];
        yield 'Alibaba metadata' => ['https://100.100.100.200/', 'metadata'];
        yield 'Azure WireServer' => ['https://168.63.129.16/', 'metadata'];
        yield 'IPv6 unspecified' => ['https://[::]/', 'unspecified'];
        yield 'IPv6 loopback' => ['https://[::1]/', 'loopback'];
        yield 'IPv6 loopback, long form' => ['https://[0:0:0:0:0:0:0:1]/', 'loopback'];
        yield 'IPv6 link-local' => ['https://[fe80::1]/', 'link-local'];
        yield 'IPv6 unique-local' => ['https://[fc00::1]/', 'unique-local'];
        yield 'IPv6 unique-local fd' => ['https://[fd12:3456::1]/', 'unique-local'];
        yield 'AWS IPv6 metadata' => ['https://[fd00:ec2::254]/', 'metadata'];
        yield 'IPv6 site-local' => ['https://[fec0::1]/', 'site-local'];
        yield 'IPv6 multicast' => ['https://[ff02::1]/', 'multicast'];
        yield 'IPv6 documentation' => ['https://[2001:db8::1]/', 'documentation'];
        yield 'IPv6 documentation 3fff' => ['https://[3fff::1]/', 'documentation'];
        yield 'IPv6 6to4 of loopback' => ['https://[2002:7f00:1::1]/', '6to4'];
        yield 'IPv6 Teredo' => ['https://[2001:0:4136:e378::1]/', 'IETF'];
        yield 'IPv6 discard' => ['https://[100::1]/', 'discard'];
        yield 'IPv4-compatible (deprecated)' => ['https://[::127.0.0.1]/', 'not a global unicast'];
        yield 'IPv4-mapped loopback' => ['https://[::ffff:127.0.0.1]/', 'loopback address (carried inside an IPv6 address)'];
        yield 'IPv4-mapped loopback, hex' => ['https://[::ffff:7f00:1]/', 'loopback address (carried inside an IPv6 address)'];
        yield 'IPv4-mapped metadata' => ['https://[::ffff:169.254.169.254]/', 'link-local'];
        yield 'IPv4-mapped private' => ['https://[::ffff:10.0.0.1]/', 'private'];
        yield 'NAT64 of metadata' => ['https://[64:ff9b::a9fe:a9fe]/', 'link-local'];
        yield 'NAT64 local-use' => ['https://[64:ff9b:1::1]/', 'local-use NAT64'];
    }

    /** @dataProvider refusedLiterals */
    public function testEveryNonGlobalAddressIsRefused(string $url, string $why): void
    {
        $message = self::refused(self::guard(), $url);
        self::assertStringContainsString($why, $message);
        self::assertStringContainsString('Webhooks are only sent to public addresses', $message);
    }

    /** @return iterable<string, array{0: string}> */
    public static function spellings(): iterable
    {
        // Each of these is 127.0.0.1 (or 10.0.0.1) to inet_aton, and so to
        // gethostbyname() and to many HTTP clients.
        yield 'decimal' => ['https://2130706433/'];
        yield 'hex' => ['https://0x7f000001/'];
        yield 'octal whole' => ['https://017700000001/'];
        yield 'octal dotted' => ['https://0177.0.0.1/'];
        yield 'leading-zero octet' => ['https://010.0.0.1/'];
        yield 'padded octets' => ['https://127.000.000.001/'];
        yield 'short form' => ['https://127.1/'];
        yield 'three parts' => ['https://10.0.1/'];
        yield 'hex dotted' => ['https://0x7f.0.0.1/'];
        yield 'hex short' => ['https://0x7f.1/'];
        yield 'octet over 255' => ['https://256.0.0.1/'];
        yield 'trailing dot numeric' => ['https://127.0.0.1./'];
    }

    /** @dataProvider spellings */
    public function testOtherSpellingsOfAnAddressAreRefusedByName(string $url): void
    {
        $guard = self::guard(['0x7f000001' => ['127.0.0.1'], '0x7f.1' => ['127.0.0.1'], '0x7f.0.0.1' => ['127.0.0.1']]);
        $message = self::refused($guard, $url);
        self::assertMatchesRegularExpression('/dotted form|not a host name|single-label/', $message);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function malformed(): iterable
    {
        yield 'http' => ['http://example.com/', 'must start with https://'];
        yield 'no scheme' => ['example.com/hook', 'must start with https://'];
        yield 'ftp' => ['ftp://example.com/', 'must start with https://'];
        yield 'scheme-relative' => ['//example.com/', 'must start with https://'];
        yield 'user info' => ['https://user:pass@example.com/', 'not in the form'];
        yield 'user info hiding the host' => ['https://example.com@10.0.0.1/', 'not in the form'];
        yield 'fragment' => ['https://example.com/#@10.0.0.1', 'not in the form'];
        yield 'backslash' => ['https://example.com\\@10.0.0.1/', 'not in the form'];
        yield 'space' => ['https://example.com/a b', 'not in the form'];
        yield 'newline' => ["https://example.com/\nHost: x", 'not in the form'];
        yield 'non-ascii host' => ['https://exämple.com/', 'not in the form'];
        yield 'zone id' => ['https://[fe80::1%25eth0]/', 'not in the form'];
        yield 'port 0' => ['https://example.com:0/', 'not a port'];
        yield 'port too big' => ['https://example.com:65536/', 'not a port'];
        yield 'port leading zero' => ['https://example.com:0443/', 'not a port'];
        yield 'empty host' => ['https:///path', 'not in the form'];
        yield 'bad IPv6' => ['https://[1:2:3]/', 'not an IPv6 address'];
        yield 'single label' => ['https://intranet/', 'single-label'];
        yield 'localhost' => ['https://localhost/', 'single-label'];
        yield 'dot localhost' => ['https://api.localhost/', 'local name'];
        yield 'dot local' => ['https://printer.local/', 'local name'];
        yield 'dot internal' => ['https://metadata.google.internal/', 'local name'];
        yield 'label with underscore' => ['https://a_b.example.com/', 'not in the form'];
        yield 'label ending in hyphen' => ['https://a-.example.com/', 'not a host name'];
        yield 'trailing dot' => ['https://example.com./', 'not a host name'];
        yield 'too long' => ['https://example.com/' . str_repeat('a', 500), 'longer than'];
    }

    /** @dataProvider malformed */
    public function testUrlsThatAreNotPlainlySpelledAreRefused(string $url, string $why): void
    {
        self::assertStringContainsString($why, self::refused(self::guard(['example.com' => ['93.184.216.34']]), $url));
    }

    public function testPublicAddressesAndNamesAreAccepted(): void
    {
        $guard = self::guard([
            'hooks.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
            'v6only.example.com' => ['2606:4700:4700::1111'],
        ]);

        $t = $guard->check('https://hooks.example.com/p202/export?source=attribution');
        self::assertSame('hooks.example.com', $t->host);
        self::assertSame(443, $t->port);
        self::assertFalse($t->ipLiteral);
        self::assertSame(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'], $t->addresses);
        self::assertSame('hooks.example.com:443:93.184.216.34', $t->resolveEntry());

        $v6 = $guard->check('https://v6only.example.com:8443/hook');
        self::assertSame('v6only.example.com:8443:[2606:4700:4700::1111]', $v6->resolveEntry());

        // Just outside each refused block, and IP literals, which are
        // connected to as written (no name to pin).
        foreach (['https://8.8.8.8/' => '8.8.8.8', 'https://1.1.1.1:8443/x' => '1.1.1.1', 'https://172.15.255.255/' => '172.15.255.255',
                  'https://172.32.0.1/' => '172.32.0.1', 'https://100.63.255.255/' => '100.63.255.255', 'https://100.128.0.1/' => '100.128.0.1',
                  'https://198.17.255.255/' => '198.17.255.255', 'https://198.20.0.1/' => '198.20.0.1', 'https://[2606:4700::1111]/' => '2606:4700::1111',
                  'https://[::ffff:8.8.8.8]/' => '::ffff:8.8.8.8'] as $url => $address) {
            $target = self::guard()->check($url);
            self::assertTrue($target->ipLiteral, $url);
            self::assertSame($address, $target->pinned(), $url);
            self::assertNull($target->resolveEntry(), $url . ' is connected to as written');
        }
        $upper = self::guard(['example.com' => ['93.184.216.34']])->check('HTTPS://EXAMPLE.COM/Path');
        self::assertSame('example.com:443:93.184.216.34', $upper->resolveEntry(), 'the host is compared and pinned in lower case');
    }

    /** @return iterable<string, array{0: list<string>, 1: string}> */
    public static function resolverAnswers(): iterable
    {
        yield 'resolves to loopback' => [['127.0.0.1'], 'resolves to 127.0.0.1, which is a loopback'];
        yield 'one bad answer among good ones' => [['93.184.216.34', '10.1.2.3'], 'resolves to 10.1.2.3'];
        yield 'metadata' => [['169.254.169.254'], 'link-local'];
        yield 'IPv6 loopback' => [['93.184.216.34', '::1'], 'resolves to ::1'];
        yield 'IPv4-mapped private' => [['::ffff:192.168.0.1'], 'private address (carried inside an IPv6 address)'];
        yield 'nothing' => [[], 'does not resolve'];
        yield 'not an address' => [['not-an-ip'], 'which is not an address'];
    }

    /**
     * @dataProvider resolverAnswers
     * @param list<string> $answers
     */
    public function testEveryResolvedAddressIsChecked(array $answers, string $why): void
    {
        self::assertStringContainsString($why, self::refused(self::guard(['hooks.example.com' => $answers]), 'https://hooks.example.com/'));
    }

    public function testTheAllowlistAdmitsOnlyItsNetworks(): void
    {
        $guard = self::guard(['hooks.corp.example' => ['10.20.0.5']], '127.0.0.2/32, 10.20.0.0/16 fd12:3456::/32');
        self::assertSame('127.0.0.2', $guard->check('https://127.0.0.2:8443/hook')->pinned());
        self::assertSame(['10.20.0.5'], $guard->check('https://hooks.corp.example/')->addresses);
        self::assertSame('fd12:3456::9', $guard->check('https://[fd12:3456::9]/')->pinned());
        self::assertSame('::ffff:127.0.0.2', $guard->check('https://[::ffff:127.0.0.2]/')->pinned());
        self::assertStringContainsString('loopback', self::refused($guard, 'https://127.0.0.1/'));
        self::assertStringContainsString('private', self::refused($guard, 'https://10.21.0.1/'));
    }

    public function testTheAllowlistNeverReachesMetadataOrReservedSpace(): void
    {
        $guard = self::guard([], '169.254.0.0/16, 100.64.0.0/10, 192.0.0.0/24, 0.0.0.0/0, fe80::/10, fc00::/7, ::/0');
        foreach (['https://169.254.169.254/' => 'link-local', 'https://100.100.100.200/' => 'metadata', 'https://192.0.0.192/' => 'metadata',
                  'https://168.63.129.16/' => 'metadata', 'https://0.0.0.0/' => 'this network', 'https://224.0.0.1/' => 'multicast',
                  'https://255.255.255.255/' => 'reserved', 'https://[fe80::1]/' => 'link-local', 'https://[fd00:ec2::254]/' => 'metadata',
                  'https://[::]/' => 'unspecified', 'https://[ff02::1]/' => 'multicast', 'https://[::ffff:169.254.169.254]/' => 'link-local'] as $url => $why) {
            self::assertStringContainsString($why, self::refused($guard, $url), $url);
        }
        // What the allowlist does widen, it widens.
        self::assertSame('10.0.0.1', $guard->check('https://10.0.0.1/')->pinned());
    }

    /** @return iterable<string, array{0: string}> */
    public static function badAllowlists(): iterable
    {
        yield 'no prefix' => ['10.0.0.0'];
        yield 'not an address' => ['intranet/8'];
        yield 'prefix too long v4' => ['10.0.0.0/33'];
        yield 'prefix too long v6' => ['fd00::/129'];
        yield 'leading zero prefix' => ['10.0.0.0/08'];
        yield 'one bad among good' => ['127.0.0.2/32, 10.0.0.0/x'];
        yield 'negative' => ['10.0.0.0/-1'];
    }

    /** @dataProvider badAllowlists */
    public function testAnAllowlistThatDoesNotParseRefusesEverything(string $value): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('P202_WEBHOOK_ALLOW_NETWORKS entry');
        new WebhookGuard(static fn (): array => ['93.184.216.34'], $value);
    }

    public function testAnEmptyAllowlistIsNoAllowlist(): void
    {
        self::assertSame([], WebhookGuard::parseAllowlist(" , \n "));
        self::assertStringContainsString('private', self::refused(self::guard([], ''), 'https://10.0.0.1/'));
    }
}
