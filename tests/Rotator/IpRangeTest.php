<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\IpRange;

/** The range forms the paid 1.9.56 redirector's ip_in_range() read, and IPv6. */
final class IpRangeTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?bool}> range, address, contained (null: cannot be read) */
    public static function ranges(): iterable
    {
        yield 'CIDR, inside' => ['203.0.113.0/24', '203.0.113.200', true];
        yield 'CIDR, outside' => ['203.0.113.0/24', '203.0.114.1', false];
        yield 'CIDR /25 upper half' => ['203.0.113.0/25', '203.0.113.128', false];
        yield 'CIDR /0' => ['0.0.0.0/0', '198.51.100.1', true];
        yield 'partial network, missing octets 0' => ['203.0/16', '203.0.77.1', true];
        yield 'netmask' => ['198.51.100.0/255.255.255.0', '198.51.100.9', true];
        yield 'netmask, outside' => ['198.51.100.0/255.255.255.0', '198.51.101.9', false];
        yield 'netmask with a wildcard' => ['198.51.100.0/255.255.255.*', '198.51.100.9', true];
        yield 'wildcard' => ['192.0.2.*', '192.0.2.77', true];
        yield 'wildcard, outside' => ['192.0.2.*', '192.0.3.77', false];
        yield 'span' => ['192.0.2.10-192.0.2.20', '192.0.2.20', true];
        yield 'span, outside' => ['192.0.2.10-192.0.2.20', '192.0.2.21', false];
        yield 'single address' => ['192.0.2.10', '192.0.2.10', true];
        yield 'IPv6 CIDR' => ['2001:db8::/32', '2001:DB8:1::5', true];
        yield 'IPv6 CIDR, outside' => ['2001:db8::/32', '2001:db9::1', false];
        yield 'IPv6 span' => ['2001:db8::1-2001:db8::ff', '2001:db8::10', true];
        yield 'an IPv4 visitor and an IPv6 range' => ['2001:db8::/32', '192.0.2.1', false];
        yield 'an IPv6 visitor and an IPv4 range' => ['192.0.2.0/24', '2001:db8::1', false];
        yield 'reversed span' => ['192.0.2.20-192.0.2.10', '192.0.2.15', null];
        yield 'no range' => ['example', '192.0.2.1', null];
        yield 'a prefix too long' => ['192.0.2.0/33', '192.0.2.1', null];
        yield 'no visitor address' => ['192.0.2.0/24', '', null];
    }

    /** @dataProvider ranges */
    public function testAnAddressIsInARangeAsTheRangeIsWritten(string $range, string $ip, ?bool $contained): void
    {
        self::assertSame($contained, IpRange::contains($range, $ip));
    }

    public function testARangeThatCannotBeReadIsRefusedByName(): void
    {
        self::assertNull(IpRange::refusal('203.0.113.0/24, 192.0.2.*, 2001:db8::/48'));
        $refusal = (string) IpRange::refusal('203.0.113.0/24, 203.0.113.0/40, nope');
        self::assertStringContainsString('"203.0.113.0/40", "nope" is not an IP range', $refusal);
    }
}
