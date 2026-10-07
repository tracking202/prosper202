<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\StoredVisitorIp;

/**
 * The visitor's address as the click path stores it: VisitorIp's, masked
 * (/24, /48) when privacy holds back for the visitor.
 */
final class StoredVisitorIpTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function masks(): iterable
    {
        yield 'IPv4 keeps its /24' => ['203.0.113.77', '203.0.113.0'];
        yield 'IPv4 already masked' => ['203.0.113.0', '203.0.113.0'];
        yield 'IPv6 keeps its /48' => ['2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3::'];
        yield 'compressed IPv6' => ['2a00:1450:4001:80b::200e', '2a00:1450:4001::'];
        // The string version split on ':' and kept the wrong groups here.
        yield 'loopback IPv6' => ['::1', '::'];
        yield 'a short prefix before ::' => ['fe80::1:2:3:4', 'fe80::'];
        yield 'IPv4-mapped IPv6' => ['::ffff:203.0.113.77', '::'];
        yield 'nothing' => ['', ''];
        yield 'not an address' => ['nope', ''];
    }

    /** @dataProvider masks */
    public function testTheMask(string $address, string $expected): void
    {
        self::assertSame($expected, StoredVisitorIp::mask($address));
    }

    public function testPrivacyMasksTheVisitorsAddress(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'REMOTE_ADDR' => '10.0.0.1'];
        self::assertSame('198.51.100.7', StoredVisitorIp::fromServer($server, false));
        self::assertSame('198.51.100.0', StoredVisitorIp::fromServer($server, true));
        self::assertSame('', StoredVisitorIp::fromServer([], true));
    }

    public function testConnect2sMaskIsTheSameMask(): void
    {
        // ipAddress() masks the $ip_address global through maskIpAddress();
        // it must give what is stored.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/connect2.php');
        $start = strpos($source, 'function maskIpAddress(');
        self::assertIsInt($start);
        $body = substr($source, $start, (int) strpos($source, "\n}\n", $start) - $start);
        self::assertStringContainsString('\Prosper202\Http\StoredVisitorIp::mask((string) $ip->address)', $body);
        self::assertStringNotContainsString('explode(', $body, 'the string-splitting mask is gone');
    }
}
