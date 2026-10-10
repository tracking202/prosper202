<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\RequestHost;

/**
 * What a Host header may hold before it reaches a redirect Location, a
 * cookie value or a cookie's Domain.
 */
final class RequestHostTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> Host header, host[:port] or null */
    public static function hosts(): iterable
    {
        yield 'a host name' => ['track.example.com', 'track.example.com'];
        yield 'a host name and a port' => ['track.example.com:8443', 'track.example.com:8443'];
        yield 'case is folded' => ['Track.Example.COM:8080', 'track.example.com:8080'];
        yield 'an empty port is no port' => ['track.example.com:', 'track.example.com'];
        yield 'a leading zero in the port' => ['track.example.com:0080', 'track.example.com:80'];
        yield 'localhost' => ['localhost:8112', 'localhost:8112'];
        yield 'a Docker service name' => ['p202_web:8080', 'p202_web:8080'];
        yield 'a trailing dot' => ['track.example.com.', 'track.example.com.'];
        yield 'punycode' => ['xn--bcher-kva.example', 'xn--bcher-kva.example'];
        yield 'IPv4' => ['127.0.0.1:8112', '127.0.0.1:8112'];
        yield 'bracketed IPv6' => ['[2001:DB8::1]:8443', '[2001:db8::1]:8443'];
        yield 'bracketed IPv6, no port' => ['[::1]', '[::1]'];

        yield 'nothing' => ['', null];
        yield 'a path' => ['evil.example/x', null];
        yield 'credentials' => ['evil.example@other.example', null];
        yield 'a query' => ['evil.example?x', null];
        yield 'a fragment' => ['evil.example#x', null];
        yield 'a backslash' => ['evil.example\\x', null];
        yield 'a quote' => ['evil.example"', null];
        yield 'a space' => ['a b.example', null];
        yield 'CR LF' => ["track.example.com\r\nSet-Cookie: x=1", null];
        yield 'a semicolon' => ['a;b.example', null];
        yield 'two ports' => ['track.example.com:8080:9', null];
        yield 'a non-numeric port' => ['track.example.com:http', null];
        yield 'port 0' => ['track.example.com:0', null];
        yield 'a port past 65535' => ['track.example.com:65536', null];
        yield 'a bare IPv6 address' => ['2001:db8::1', null];
        yield 'an unclosed bracket' => ['[::1', null];
        yield 'brackets around a name' => ['[track.example.com]', null];
        yield 'text after the brackets' => ['[::1]x', null];
        yield 'an empty label' => ['a..example', null];
        yield 'a leading hyphen' => ['-a.example', null];
        yield 'a trailing hyphen' => ['a-.example', null];
        yield 'a label of 64' => [str_repeat('a', 64) . '.example', null];
        yield 'raw UTF-8' => ['bücher.example', null];
        yield 'only a dot' => ['.', null];
    }

    /** @dataProvider hosts */
    public function testFromServer(string $header, ?string $expected): void
    {
        self::assertSame($expected, RequestHost::fromServer(['HTTP_HOST' => $header]));
    }

    public function testAnAddressIsToldFromAName(): void
    {
        self::assertSame(['name' => '127.0.0.1', 'port' => null, 'ip' => true], RequestHost::parse('127.0.0.1'));
        self::assertSame(['name' => '[::1]', 'port' => 8080, 'ip' => true], RequestHost::parse('[::1]:8080'));
        self::assertSame(['name' => '10.1', 'port' => null, 'ip' => false], RequestHost::parse('10.1'));
    }

    public function testNoHostHeader(): void
    {
        self::assertNull(RequestHost::fromServer([]));
        self::assertNull(RequestHost::fromServer(['HTTP_HOST' => ['track.example.com']]));
        self::assertNull(RequestHost::fromServer(['SERVER_NAME' => 'track.example.com']));
    }
}
