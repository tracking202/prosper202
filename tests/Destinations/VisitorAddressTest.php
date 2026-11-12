<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Destinations\DestinationQueue;

/**
 * Which stored addresses a destination is given as the visitor's: a public
 * one stored unmasked, and none that names a network, a protocol range or
 * an IPv4 address wrapped in IPv6.
 */
final class VisitorAddressTest extends TestCase
{
    private static function address(string $ip): ?string
    {
        $m = new \ReflectionMethod(DestinationQueue::class, 'visitorAddress');

        return $m->invoke(null, $ip);
    }

    public function testOnlyAPublicUnmaskedAddressIsTheVisitors(): void
    {
        foreach (['198.51.100.7', '203.0.113.9', '2001:db8::7', '100.63.255.9', '100.128.0.9', '198.17.255.9', '198.20.0.9', '223.255.255.9', '192.0.1.9'] as $kept) {
            self::assertSame($kept, self::address($kept), $kept . ' is a visitor\'s address (the positive controls, the ranges\' neighbours included)');
        }
        $refused = [
            'masked' => '198.51.100.0',
            'private' => '10.0.0.9',
            'loopback' => '127.0.0.1',
            'shared (carrier-grade NAT)' => '100.64.0.9',
            'IETF protocol assignments' => '192.0.0.9',
            'benchmarking' => '198.18.0.9',
            'benchmarking, upper half' => '198.19.255.9',
            'multicast' => '224.0.0.9',
            'multicast, top' => '239.255.255.9',
            'NAT64 of a private address' => '64:ff9b::a00:9',
            'local-use NAT64' => '64:ff9b:1::a00:9',
            '6to4 of a private address' => '2002:a00:9::1',
            'Teredo' => '2001:0:4136:e378:8000:63bf:3fff:fdd2',
            'IPv6 multicast' => 'ff02::1:9',
            'not an address' => 'shop.example.com',
            'empty' => '',
        ];
        foreach ($refused as $why => $ip) {
            self::assertNull(self::address($ip), $why . ': ' . $ip);
        }
    }
}
