<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\TrustedProxies;
use Prosper202\Http\VisitorIp;

/**
 * The client's address every click endpoint records and every rate limit
 * keys on: REMOTE_ADDR, unless that is a proxy P202_TRUSTED_PROXIES lists,
 * whose X-Forwarded-For is walked from the right (CLAUDE.md #16).
 */
final class VisitorIpTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function servers(): iterable
    {
        $peer = ['REMOTE_ADDR' => '10.0.0.1'];
        $xff = static fn (string $value): array => ['HTTP_X_FORWARDED_FOR' => $value] + $peer;
        $lan = '10.0.0.0/8';

        yield 'no proxy: the peer' => [['REMOTE_ADDR' => '203.0.113.9'], '', '203.0.113.9'];

        // No setting: every header is the client's claim.
        yield 'no setting: X-Forwarded-For is ignored' => [$xff('198.51.100.7'), '', '10.0.0.1'];
        yield 'no setting: CF-Connecting-IP is ignored' => [['HTTP_CF_CONNECTING_IP' => '198.51.100.7'] + $peer, '', '10.0.0.1'];
        yield 'no setting: X-Real-IP is ignored' => [['HTTP_X_REAL_IP' => '198.51.100.7'] + $peer, '', '10.0.0.1'];
        yield 'a peer outside the list: ignored' => [$xff('198.51.100.7'), '192.168.0.0/16', '10.0.0.1'];
        yield 'a malformed setting trusts nothing' => [$xff('198.51.100.7'), '10.0.0.0/8, 10.0.0/33', '10.0.0.1'];
        yield 'a /0 trusts nothing' => [$xff('198.51.100.7'), '0.0.0.0/0', '10.0.0.1'];

        // A trusted peer: X-Forwarded-For from the right.
        yield 'one hop' => [$xff('198.51.100.7'), $lan, '198.51.100.7'];
        yield 'a single trusted address' => [$xff('198.51.100.7'), '10.0.0.1', '198.51.100.7'];
        yield 'a forged hop left of the real one is never reached' => [$xff('203.0.113.66, 198.51.100.7'), $lan, '198.51.100.7'];
        yield 'trusted hops are walked past' => [$xff('198.51.100.7, 10.0.0.2, 10.0.0.3'), $lan, '198.51.100.7'];
        yield 'padding around the hop is trimmed' => [$xff('  198.51.100.7 ,10.0.0.2'), $lan, '198.51.100.7'];
        yield 'an empty hop is skipped' => [$xff('198.51.100.7,,'), $lan, '198.51.100.7'];
        yield 'a hop with a port' => [$xff('198.51.100.7:8080'), $lan, '198.51.100.7'];
        yield 'an IPv6 hop with a port' => [$xff('[2001:DB8::7]:443'), $lan, '2001:db8::7'];
        yield 'IPv6 comes back in canonical form' => [$xff('2001:DB8:0:0::1'), $lan, '2001:db8::1'];
        yield 'garbage stops at the proxy that passed it on' => [$xff('198.51.100.7, nope, 10.0.0.2'), $lan, '10.0.0.2'];
        yield 'garbage from the peer itself is the peer' => [$xff('nope'), $lan, '10.0.0.1'];
        yield 'unknown is not an address' => [$xff('unknown'), $lan, '10.0.0.1'];
        yield 'an overlong header is the peer' => [$xff(str_repeat('A', 4000)), $lan, '10.0.0.1'];
        yield 'too many hops is the peer' => [$xff(implode(',', array_fill(0, VisitorIp::MAX_HOPS + 1, '10.0.0.9'))), $lan, '10.0.0.1'];
        yield 'every hop trusted: the leftmost' => [$xff('10.0.0.3, 10.0.0.2'), $lan, '10.0.0.3'];
        yield 'no header from a trusted peer: the peer' => [$peer, $lan, '10.0.0.1'];
        yield 'a header that is not a string is no header' => [['HTTP_X_FORWARDED_FOR' => ['198.51.100.7']] + $peer, $lan, '10.0.0.1'];
        yield 'CF-Connecting-IP is not read even from a trusted peer' => [
            ['HTTP_CF_CONNECTING_IP' => '203.0.113.50'] + $xff('198.51.100.7'), $lan, '198.51.100.7',
        ];
        yield 'Cloudflare, then a trusted balancer' => [
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.66, 198.51.100.7, 172.64.0.1'],
            'cloudflare 10.0.0.0/8',
            '198.51.100.7',
        ];
        yield 'Cloudflare not listed: its edge is the client' => [
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 172.64.0.1'],
            '10.0.0.0/8',
            '172.64.0.1',
        ];
        yield 'an IPv4-mapped peer is not its IPv4 range' => [['REMOTE_ADDR' => '::ffff:10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], $lan, '::ffff:10.0.0.1'];

        yield 'an IPv6 peer' => [['REMOTE_ADDR' => '::1'], '', '::1'];
        yield 'a peer that is not an address' => [['HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'REMOTE_ADDR' => 'nope'], $lan, ''];
        yield 'nothing at all (CLI)' => [[], $lan, ''];
    }

    /**
     * @dataProvider servers
     * @param array<string, mixed> $server
     */
    public function testResolvesTheClientsAddress(array $server, string $setting, string $expected): void
    {
        self::assertSame($expected, VisitorIp::fromServer($server, TrustedProxies::parse($setting)));
    }

    public function testKnownClientIsUnknownWhenAnUnlistedPeerForwards(): void
    {
        $lan = TrustedProxies::parse('10.0.0.0/8');
        foreach (VisitorIp::FORWARDING_HEADERS as $key) {
            $server = ['REMOTE_ADDR' => '192.0.2.1', $key => '198.51.100.7'];
            self::assertSame('', VisitorIp::knownClient($server, $lan), $key);
            self::assertSame('192.0.2.1', VisitorIp::fromServer($server, $lan), $key);
        }
        self::assertSame('192.0.2.1', VisitorIp::knownClient(['REMOTE_ADDR' => '192.0.2.1'], $lan));
        self::assertSame('198.51.100.7', VisitorIp::knownClient(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_X_REAL_IP' => '203.0.113.1'],
            $lan
        ));
    }

    public function testTheEnvironmentDecidesWhenNoneIsGiven(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];
        $before = getenv(TrustedProxies::ENV);
        try {
            putenv(TrustedProxies::ENV);
            self::assertSame('10.0.0.1', VisitorIp::fromServer($server), 'unset: nothing is trusted');
            putenv(TrustedProxies::ENV . '=10.0.0.0/8');
            self::assertSame('198.51.100.7', VisitorIp::fromServer($server));
        } finally {
            putenv($before === false ? TrustedProxies::ENV : TrustedProxies::ENV . '=' . $before);
        }
    }

    public function testTheRawHeaderIsAvailableForALogLineOnly(): void
    {
        self::assertSame('nope, 10.0.0.2', VisitorIp::forwardedForAsSent(['HTTP_X_FORWARDED_FOR' => 'nope, 10.0.0.2']));
        self::assertSame('', VisitorIp::forwardedForAsSent([]));
    }
}
