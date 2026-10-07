<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\VisitorIp;

/**
 * The visitor's address every click endpoint records and routes on. The
 * precedence and the SERVER_ADDR rule are the ones connect.php and
 * connect2.php applied before; validation and canonical form are new (a
 * header of `nope` used to be stored as an address).
 */
final class VisitorIpTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function servers(): iterable
    {
        $peer = ['REMOTE_ADDR' => '10.0.0.1'];
        $xff = static fn (string $value): array => ['HTTP_X_FORWARDED_FOR' => $value] + $peer;

        yield 'no proxy: the peer' => [['REMOTE_ADDR' => '203.0.113.9'], '203.0.113.9'];
        yield 'X-Forwarded-For' => [$xff('198.51.100.7'), '198.51.100.7'];
        yield 'a chain keeps the leftmost hop' => [$xff('198.51.100.7, 10.0.0.2, 10.0.0.3'), '198.51.100.7'];
        yield 'padding around the hop is trimmed' => [$xff('  198.51.100.7 ,10.0.0.2'), '198.51.100.7'];
        yield 'CF-Connecting-IP beats X-Forwarded-For' => [
            ['HTTP_CF_CONNECTING_IP' => '203.0.113.50'] + $xff('198.51.100.7'),
            '203.0.113.50',
        ];
        yield 'X-Real-IP beats Client-IP beats X-Forwarded-For' => [
            ['HTTP_X_REAL_IP' => '203.0.113.60', 'HTTP_CLIENT_IP' => '203.0.113.61'] + $xff('198.51.100.7'),
            '203.0.113.60',
        ];
        yield 'an invalid chosen header falls back to the peer, not the next header' => [
            ['HTTP_CF_CONNECTING_IP' => 'nope'] + $xff('198.51.100.7'),
            '10.0.0.1',
        ];
        yield 'garbage X-Forwarded-For falls back to the peer' => [$xff('nope'), '10.0.0.1'];
        yield 'an overlong header falls back to the peer' => [$xff(str_repeat('A', 4000)), '10.0.0.1'];
        yield 'an unknown leftmost hop falls back to the peer' => [$xff('unknown, 198.51.100.7'), '10.0.0.1'];
        yield 'a hop with a port is not an address' => [$xff('198.51.100.7:8080'), '10.0.0.1'];
        yield 'X-Forwarded-For naming this server is ignored' => [
            ['SERVER_ADDR' => '10.0.0.5'] + $xff('10.0.0.5'),
            '10.0.0.1',
        ];
        yield 'a header of "0" is no header (empty() semantics)' => [
            ['HTTP_CF_CONNECTING_IP' => '0'] + $xff('198.51.100.7'),
            '198.51.100.7',
        ];
        yield 'a header that is not a string is no header' => [
            ['HTTP_X_FORWARDED_FOR' => ['198.51.100.7']] + $peer,
            '10.0.0.1',
        ];
        yield 'IPv6 comes back in canonical form' => [$xff('2001:DB8:0:0::1'), '2001:db8::1'];
        yield 'an IPv6 peer' => [['REMOTE_ADDR' => '::1'], '::1'];
        yield 'nothing valid at all' => [['HTTP_X_FORWARDED_FOR' => 'nope', 'REMOTE_ADDR' => 'also nope'], ''];
        yield 'nothing at all (CLI)' => [[], ''];
    }

    /**
     * @dataProvider servers
     * @param array<string, mixed> $server
     */
    public function testResolvesTheVisitorsAddress(array $server, string $expected): void
    {
        self::assertSame($expected, VisitorIp::fromServer($server));
    }

    public function testThePrecedenceIsTheOneTheBootstrapsHad(): void
    {
        // connect.php and connect2.php's match(true), in its order: changing
        // it moves every click's recorded address behind a CDN.
        self::assertSame([
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_X_SUCURI_CLIENTIP',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
        ], VisitorIp::FORWARDING_HEADERS);
    }

    public function testEveryHeaderOutranksTheOnesAfterIt(): void
    {
        $headers = VisitorIp::FORWARDING_HEADERS;
        foreach ($headers as $rank => $key) {
            $server = ['REMOTE_ADDR' => '10.0.0.1'];
            foreach ($headers as $otherRank => $other) {
                $server[$other] = '198.51.100.' . (10 + $otherRank);
            }
            // Drop every header ranked above this one: this one must win.
            foreach (array_slice($headers, 0, $rank) as $higher) {
                unset($server[$higher]);
            }
            self::assertSame('198.51.100.' . (10 + $rank), VisitorIp::fromServer($server), $key);
        }
    }

    public function testTheRawHeaderIsAvailableForALogLineOnly(): void
    {
        self::assertSame('nope, 10.0.0.2', VisitorIp::forwardedForAsSent(['HTTP_X_FORWARDED_FOR' => 'nope, 10.0.0.2']));
        self::assertSame('', VisitorIp::forwardedForAsSent([]));
    }
}
