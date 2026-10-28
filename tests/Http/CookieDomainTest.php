<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\CookieDomain;

/**
 * The Domain attribute for a cookie, from the Host header: the host name
 * without its port, or '' (no attribute, a host-only cookie) for an IP
 * literal, a dotless host or anything that is not a host name. The browser
 * behaviour each rule rests on was measured in Chromium (see CookieDomain).
 */
final class CookieDomainTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function hosts(): iterable
    {
        // Host names keep a Domain, without the port.
        yield 'a host name on a default port' => ['track.example.com', 'track.example.com'];
        yield 'a host name on a non-default port' => ['track.example.com:8443', 'track.example.com'];
        yield 'an explicit default port' => ['track.example.com:80', 'track.example.com'];
        yield 'upper case is folded' => ['TRACK.Example.COM:8080', 'track.example.com'];
        yield 'a subdomain' => ['sub.track.example.com:8080', 'sub.track.example.com'];
        yield 'an empty port' => ['track.example.com:', 'track.example.com'];
        yield 'a punycode name' => ['xn--bcher-kva.example:8080', 'xn--bcher-kva.example'];

        // IP literals get none, with or without a port.
        yield 'IPv4' => ['127.0.0.1', ''];
        yield 'IPv4 with a port' => ['127.0.0.1:8112', ''];
        yield 'bracketed IPv6' => ['[::1]', ''];
        yield 'bracketed IPv6 with a port' => ['[::1]:8080', ''];
        yield 'bracketed full IPv6 with a port' => ['[2001:db8::1]:443', ''];
        yield 'bare IPv6 (not valid Host syntax)' => ['::1', ''];
        yield 'bare IPv6 that ends in digits' => ['2001:db8::1', ''];
        yield 'a short IPv4 form a browser parses as an address' => ['10.1', ''];
        yield 'a hex last label a browser parses as an address' => ['foo.0x7f', ''];
        yield 'a numeric last label' => ['x.123:8080', ''];

        // Dotless hosts get none (Chromium stores Domain=localhost host-only anyway).
        yield 'localhost' => ['localhost', ''];
        yield 'localhost with a port' => ['localhost:8112', ''];
        yield 'an intranet name' => ['tracker:80', ''];

        // Not a host name: none, rather than a Domain the browser drops or
        // one setcookie() refuses with a ValueError.
        yield 'no Host header' => ['', ''];
        yield 'a non-numeric port' => ['track.example.com:abc', ''];
        yield 'two ports' => ['track.example.com:8080:9', ''];
        yield 'a trailing dot' => ['track.example.com.', ''];
        yield 'a semicolon' => ['a;b.example.com', ''];
        yield 'a comma' => ['a,b.example.com', ''];
        yield 'whitespace' => ['a b.example.com', ''];
        yield 'a leading space' => [' track.example.com', ''];
        yield 'a leading hyphen' => ['-bad.example.com', ''];
        yield 'raw UTF-8' => ['bücher.example', ''];
        yield 'an empty label' => ['a..example.com', ''];
    }

    /** @dataProvider hosts */
    public function testDomainForHost(string $host, string $expected): void
    {
        self::assertSame($expected, CookieDomain::forHost($host));
    }

    /** @dataProvider hosts */
    public function testEveryAnswerIsOneSetcookieAccepts(string $host): void
    {
        // A Domain setcookie() refuses throws a ValueError out of the click
        // path; whatever the Host header says, the answer must be usable.
        $domain = CookieDomain::forHost($host);
        self::assertDoesNotMatchRegularExpression('/[,; \t\r\n\x0b\x0c:\[\]]/', $domain);
    }

    public function testFromServerReadsTheHostHeaderNotServerName(): void
    {
        self::assertSame(
            'track.example.com',
            CookieDomain::fromServer(['HTTP_HOST' => 'track.example.com:8443', 'SERVER_NAME' => '_'])
        );
        self::assertSame('', CookieDomain::fromServer(['SERVER_NAME' => 'track.example.com']));
        self::assertSame('', CookieDomain::fromServer(['HTTP_HOST' => ['track.example.com']]));
    }

    public function testRememberMeCookieUsesTheSameRule(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-auth.php';
        $saved = $_SERVER;
        try {
            foreach (['track.example.com:8443', '[::1]:8080', '127.0.0.1:8112', 'localhost'] as $host) {
                $_SERVER['HTTP_HOST'] = $host;
                self::assertSame(CookieDomain::forHost($host), \AUTH::cookie_domain(), $host);
            }
        } finally {
            $_SERVER = $saved;
        }
    }
}
