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

        // RFC 7239 Forwarded, walked the same way.
        $fwd = static fn (string $value): array => ['HTTP_FORWARDED' => $value] + $peer;
        yield 'no setting: Forwarded is ignored' => [$fwd('for=198.51.100.7'), '', '10.0.0.1'];
        yield 'Forwarded: one node' => [$fwd('for=198.51.100.7'), $lan, '198.51.100.7'];
        yield 'Forwarded: a forged node to the left' => [$fwd('for=203.0.113.66, for=198.51.100.7;proto=https'), $lan, '198.51.100.7'];
        yield 'Forwarded: listed nodes walked past' => [$fwd('for=198.51.100.7, for=10.0.0.2'), $lan, '198.51.100.7'];
        yield 'Forwarded: quoted IPv6 with a port' => [$fwd('for="[2001:DB8::7]:4711"'), $lan, '2001:db8::7'];
        yield 'Forwarded: an IPv4 port' => [$fwd('for="198.51.100.7:8080"'), $lan, '198.51.100.7'];
        yield 'Forwarded: obfuscated stops at the proxy that passed it on' => [$fwd('for=198.51.100.7, for=_hidden, for=10.0.0.2'), $lan, '10.0.0.2'];
        yield 'Forwarded: unknown from the peer itself is the peer' => [$fwd('for=unknown'), $lan, '10.0.0.1'];
        yield 'Forwarded: an element without for= names nobody' => [$fwd('proto=https'), $lan, '10.0.0.1'];
        yield 'Forwarded that does not parse is the peer' => [$fwd('for="198.51.100.7'), $lan, '10.0.0.1'];
        yield 'Forwarded in X-Forwarded-For form does not parse' => [$fwd('198.51.100.7'), $lan, '10.0.0.1'];
        // Both: never merged; agreeing they are believed, differing the peer.
        yield 'both name the same client' => [
            ['HTTP_FORWARDED' => 'for=198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.66, 198.51.100.7, 10.0.0.2'] + $peer,
            $lan,
            '198.51.100.7',
        ];
        yield 'a client\'s Forwarded passed through beside a proxy\'s X-Forwarded-For' => [
            ['HTTP_FORWARDED' => 'for=203.0.113.66', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'] + $peer,
            $lan,
            '10.0.0.1',
        ];
        yield 'a client\'s X-Forwarded-For passed through beside a proxy\'s Forwarded' => [
            ['HTTP_FORWARDED' => 'for=198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.66'] + $peer,
            $lan,
            '10.0.0.1',
        ];
        yield 'both, Forwarded unparseable' => [
            ['HTTP_FORWARDED' => 'for=', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'] + $peer,
            $lan,
            '10.0.0.1',
        ];

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

    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function forwardedProtos(): iterable
    {
        $peer = ['REMOTE_ADDR' => '10.0.0.1'];
        yield 'the client\'s element says https' => [['HTTP_FORWARDED' => 'for=198.51.100.7;proto=https, for=10.0.0.2;proto=http'] + $peer, true];
        yield 'in capitals' => [['HTTP_FORWARDED' => 'for=198.51.100.7;proto=HTTPS'] + $peer, true];
        yield 'the client\'s element says http' => [['HTTP_FORWARDED' => 'for=198.51.100.7;proto=http, for=10.0.0.2;proto=https'] + $peer, false];
        yield 'a forged element to the left is not the client\'s' => [['HTTP_FORWARDED' => 'for=203.0.113.6;proto=https, for=198.51.100.7;proto=http'] + $peer, false];
        yield 'an obfuscated client: unknown' => [['HTTP_FORWARDED' => 'for=_hidden;proto=https'] + $peer, false];
        yield 'X-Forwarded-For named another client' => [['HTTP_FORWARDED' => 'for=203.0.113.6;proto=https', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'] + $peer, false];
        yield 'both agree' => [['HTTP_FORWARDED' => 'for=198.51.100.7;proto=https', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'] + $peer, true];
        yield 'an unlisted peer' => [['HTTP_FORWARDED' => 'for=198.51.100.7;proto=https', 'REMOTE_ADDR' => '192.0.2.1'], false];
    }

    /**
     * @dataProvider forwardedProtos
     * @param array<string, string> $server
     */
    public function testTheSchemeIsTheClientsElements(array $server, bool $https): void
    {
        self::assertSame($https, VisitorIp::forwardedProtoIsHttps($server, TrustedProxies::parse('10.0.0.0/8')));
    }

    public function testTheSightingNamesThePeerAndTheHeadersNeverTheirValues(): void
    {
        $lan = TrustedProxies::parse('10.0.0.0/8');
        self::assertSame(
            ['peer' => '192.0.2.1', 'headers' => ['HTTP_X_FORWARDED_FOR', 'HTTP_FORWARDED']],
            VisitorIp::unlistedPeerForwarding(
                ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_FORWARDED' => 'for=x', 'HTTP_X_OTHER' => '1'],
                $lan
            )
        );
        self::assertNull(VisitorIp::unlistedPeerForwarding(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], $lan), 'a listed peer');
        self::assertNull(VisitorIp::unlistedPeerForwarding(['REMOTE_ADDR' => '192.0.2.1'], $lan), 'no header');
        self::assertNull(VisitorIp::unlistedPeerForwarding(['HTTP_X_FORWARDED_FOR' => '1.2.3.4'], $lan), 'no peer');
    }

    public function testTheEnvironmentDecidesWhenNoneIsGiven(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];
        $edge = ['REMOTE_ADDR' => '172.64.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.66, 198.51.100.7'];
        $before = getenv(TrustedProxies::ENV);
        try {
            putenv(TrustedProxies::ENV);
            self::assertSame('10.0.0.1', VisitorIp::fromServer($server), 'unset: a balancer is not trusted');
            self::assertSame('198.51.100.7', VisitorIp::fromServer($edge), 'unset: Cloudflare is');
            putenv(TrustedProxies::ENV . '=none');
            self::assertSame('172.64.0.1', VisitorIp::fromServer($edge), 'none: not even Cloudflare');
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
