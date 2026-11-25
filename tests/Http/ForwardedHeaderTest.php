<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\ForwardedHeader;

/** The RFC 7239 grammar, read strictly: what does not parse is no list. */
final class ForwardedHeaderTest extends TestCase
{
    /** @return iterable<string, array{string, list<array<string, string>>}> */
    public static function lists(): iterable
    {
        yield 'one node' => ['for=192.0.2.60', [['for' => '192.0.2.60']]];
        yield 'the RFC example' => [
            'for=192.0.2.60;proto=http;by=203.0.113.43',
            [['for' => '192.0.2.60', 'proto' => 'http', 'by' => '203.0.113.43']],
        ];
        yield 'two elements' => ['for=192.0.2.43, for=198.51.100.17', [['for' => '192.0.2.43'], ['for' => '198.51.100.17']]];
        yield 'quoted IPv6 with a port' => ['For="[2001:db8:cafe::17]:4711"', [['for' => '[2001:db8:cafe::17]:4711']]];
        yield 'names fold, values do not' => ['FOR=_Hidden;PROTO=HTTPS', [['for' => '_Hidden', 'proto' => 'HTTPS']]];
        yield 'a comma inside quotes' => ['for="a,b";proto=https', [['for' => 'a,b', 'proto' => 'https']]];
        yield 'an escape inside quotes' => ['for="x\"y"', [['for' => 'x"y']]];
        yield 'spaces around separators' => ['for=192.0.2.1 ; proto=https ,  for=10.0.0.1', [['for' => '192.0.2.1', 'proto' => 'https'], ['for' => '10.0.0.1']]];
        yield 'empty elements and pairs' => [', for=192.0.2.1;;proto=https,,', [['for' => '192.0.2.1', 'proto' => 'https']]];
        yield 'two headers joined by PHP' => ['for=192.0.2.1, for=10.0.0.2', [['for' => '192.0.2.1'], ['for' => '10.0.0.2']]];
    }

    /**
     * @dataProvider lists
     * @param list<array<string, string>> $expected
     */
    public function testParsesAList(string $value, array $expected): void
    {
        self::assertSame($expected, ForwardedHeader::elements($value));
    }

    /** @return iterable<string, array{string}> */
    public static function notLists(): iterable
    {
        yield 'empty' => [''];
        yield 'a bare address (X-Forwarded-For form)' => ['192.0.2.60'];
        yield 'an unquoted IPv6 node' => ['for=[2001:db8::1]'];
        yield 'an unclosed quote' => ['for="192.0.2.60'];
        yield 'a dangling escape' => ['for="x\\'];
        yield 'no value' => ['for=;proto=https'];
        yield 'no name' => ['=192.0.2.1'];
        yield 'text after a value' => ['for=192.0.2.1 x'];
        yield 'a pair given twice' => ['for=192.0.2.1;for=10.0.0.1'];
        yield 'too long' => ['for=192.0.2.1' . str_repeat(', for=192.0.2.1', 400)];
        yield 'too many elements' => [implode(', ', array_fill(0, ForwardedHeader::MAX_ELEMENTS + 1, 'for=10.0.0.9'))];
    }

    /** @dataProvider notLists */
    public function testWhatDoesNotParseIsNoList(string $value): void
    {
        self::assertNull(ForwardedHeader::elements($value));
    }

    /** @return iterable<string, array{string, string}> */
    public static function nodes(): iterable
    {
        yield 'IPv4' => ['192.0.2.60', '192.0.2.60'];
        yield 'IPv4 with a port' => ['192.0.2.60:8080', '192.0.2.60'];
        yield 'IPv4 with an obfuscated port' => ['192.0.2.60:_p1', '192.0.2.60'];
        yield 'bracketed IPv6' => ['[2001:DB8::17]', '2001:db8::17'];
        yield 'bracketed IPv6 with a port' => ['[2001:db8:cafe::17]:4711', '2001:db8:cafe::17'];
        yield 'unknown' => ['unknown', ''];
        yield 'obfuscated' => ['_hidden', ''];
        yield 'obfuscated, address-like' => ['_192.0.2.1', ''];
        yield 'bare IPv6 (not allowed by the RFC)' => ['2001:db8::17', ''];
        yield 'IPv4 in brackets' => ['[192.0.2.1]', ''];
        yield 'a port too long' => ['192.0.2.60:123456', ''];
        yield 'not an address' => ['300.0.0.1', ''];
        yield 'nothing' => ['', ''];
    }

    /** @dataProvider nodes */
    public function testANodeIsAnAddressOrNothing(string $node, string $expected): void
    {
        self::assertSame($expected, ForwardedHeader::nodeAddress($node));
    }
}
