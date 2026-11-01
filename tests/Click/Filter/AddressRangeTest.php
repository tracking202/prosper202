<?php

declare(strict_types=1);

namespace Tests\Click\Filter;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\Filter\AddressRange;

/**
 * An entry's addresses, read strictly: what is stored is what was meant,
 * and a click's address falls in a block exactly when it should.
 */
final class AddressRangeTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, int}> text, canonical, family, prefix */
    public static function accepted(): iterable
    {
        yield 'one IPv4 address' => ['203.0.113.7', '203.0.113.7', 4, 32];
        yield 'one IPv4 address as a /32' => ['203.0.113.7/32', '203.0.113.7', 4, 32];
        yield 'an IPv4 /24' => ['203.0.113.0/24', '203.0.113.0/24', 4, 24];
        yield 'the widest IPv4 block' => ['198.0.0.0/8', '198.0.0.0/8', 4, 8];
        yield 'an IPv4 block ending mid-byte' => ['192.0.2.128/25', '192.0.2.128/25', 4, 25];
        yield 'spaces around it' => ["  198.51.100.9 \t", '198.51.100.9', 4, 32];
        yield 'one IPv6 address' => ['2001:db8::7', '2001:db8::7', 6, 128];
        yield 'IPv6 in capitals and long form' => ['2001:0DB8:0000::0007', '2001:db8::7', 6, 128];
        yield 'the documentation /32' => ['2001:db8::/32', '2001:db8::/32', 6, 32];
        yield 'the widest IPv6 block' => ['2001::/16', '2001::/16', 6, 16];
        yield 'an IPv6 /48' => ['2001:db8:85a3::/48', '2001:db8:85a3::/48', 6, 48];
        yield 'an IPv6 block ending mid-byte' => ['2001:db8:8000::/33', '2001:db8:8000::/33', 6, 33];
    }

    /** @dataProvider accepted */
    public function testAnAddressOrABlockIsReadAsWritten(string $text, string $canonical, int $family, int $prefix): void
    {
        $range = AddressRange::parse($text);

        self::assertSame($canonical, $range->text());
        self::assertSame($family, $range->family);
        self::assertSame($prefix, $range->prefix);
    }

    /** @return iterable<string, array{mixed, string}> value, part of the refusal */
    public static function refused(): iterable
    {
        yield 'empty' => ['', 'must be an IP address'];
        yield 'spaces' => ['   ', 'must be an IP address'];
        yield 'a word' => ['office', 'is not an IPv4 or IPv6 address'];
        yield 'a host name' => ['example.com', 'is not an IPv4 or IPv6 address'];
        yield 'an octet past 255' => ['203.0.113.256', 'is not an IPv4 or IPv6 address'];
        yield 'a leading zero' => ['203.0.113.07', 'is not an IPv4 or IPv6 address'];
        yield 'three octets' => ['203.0.113', 'is not an IPv4 or IPv6 address'];
        yield 'two slashes' => ['203.0.113.0/24/8', 'is not an IP address or a CIDR block'];
        yield 'an empty prefix' => ['203.0.113.0/', 'is not a prefix length'];
        yield 'a prefix past 32' => ['203.0.113.0/33', 'is not a prefix length for IPv4'];
        yield 'a prefix past 128' => ['2001:db8::/129', 'is not a prefix length for IPv6'];
        yield 'a signed prefix' => ['203.0.113.0/+24', 'is not a prefix length'];
        yield 'a prefix with a leading zero' => ['203.0.113.0/024', 'is not a prefix length'];
        yield 'a netmask, not a prefix' => ['203.0.113.0/255.255.255.0', 'is not a prefix length'];
        yield 'host bits set' => ['203.0.113.7/24', 'the /24 holding 203.0.113.7 is 203.0.113.0/24'];
        yield 'IPv6 host bits set' => ['2001:db8::1/32', 'is 2001:db8::/32'];
        yield 'an IPv4 block too wide' => ['10.0.0.0/7', 'too wide'];
        yield 'everything' => ['0.0.0.0/0', 'too wide'];
        yield 'an IPv6 block too wide' => ['2000::/3', 'too wide'];
        yield 'IPv4 written as IPv6' => ['::ffff:203.0.113.7', 'write it as 203.0.113.7'];
        yield 'a zone id' => ['fe80::1%eth0', 'is not an IPv4 or IPv6 address'];
        yield 'an address with a port' => ['203.0.113.7:80', 'is not an IPv4 or IPv6 address'];
        yield 'a number' => [2130706433, 'as text'];
        yield 'a list' => [['203.0.113.7'], 'as text'];
        yield 'null' => [null, 'as text'];
    }

    /** @dataProvider refused */
    public function testAnythingElseIsRefusedSayingWhy(mixed $value, string $why): void
    {
        try {
            AddressRange::parse($value);
            self::fail('accepted ' . var_export($value, true));
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($why, $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function membership(): iterable
    {
        yield 'the first address of a /24' => ['203.0.113.0/24', '203.0.113.0', true];
        yield 'the last address of a /24' => ['203.0.113.0/24', '203.0.113.255', true];
        yield 'just below a /24' => ['203.0.113.0/24', '203.0.112.255', false];
        yield 'just above a /24' => ['203.0.113.0/24', '203.0.114.0', false];
        yield 'the last address of a /25' => ['192.0.2.0/25', '192.0.2.127', true];
        yield 'the first past a /25' => ['192.0.2.0/25', '192.0.2.128', false];
        yield 'one address, itself' => ['198.51.100.7', '198.51.100.7', true];
        yield 'one address, its neighbour' => ['198.51.100.7', '198.51.100.8', false];
        yield 'an IPv6 /32, inside' => ['2001:db8::/32', '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff', true];
        yield 'an IPv6 /32, just past' => ['2001:db8::/32', '2001:db9::', false];
        yield 'an IPv6 /33, the last of its half' => ['2001:db8::/33', '2001:db8:7fff:ffff:ffff:ffff:ffff:ffff', true];
        yield 'an IPv6 /33, the other half' => ['2001:db8::/33', '2001:db8:8000::', false];
        yield 'IPv4 in an IPv6 block' => ['2001:db8::/32', '203.0.113.7', false];
        yield 'IPv6 in an IPv4 block' => ['203.0.113.0/24', '2001:db8::1', false];
        yield 'an IPv4-mapped click, as IPv4' => ['203.0.113.0/24', '::ffff:203.0.113.9', true];
    }

    /** @dataProvider membership */
    public function testAClickFallsInABlockExactlyWhenItShould(string $block, string $click, bool $inside): void
    {
        $packed = AddressRange::packedForMatch($click);
        self::assertNotNull($packed);
        self::assertSame($inside, AddressRange::parse($block)->contains($packed));
    }

    public function testAClickWithNoAddressMatchesNothing(): void
    {
        self::assertNull(AddressRange::packedForMatch(''));
        self::assertNull(AddressRange::packedForMatch('unknown'));
    }

    public function testAStoredRangeIsReadBackAsWritten(): void
    {
        $range = AddressRange::parse('2001:db8:85a3::/48');
        $stored = AddressRange::fromStored($range->family, $range->start, $range->prefix);

        self::assertSame($range->text(), $stored->text());
        self::assertSame($range->end, $stored->end);

        $this->expectException(\UnexpectedValueException::class);
        AddressRange::fromStored(4, $range->start, 24);
    }
}
