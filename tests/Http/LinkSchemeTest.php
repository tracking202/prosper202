<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\LinkScheme;

/**
 * A browser drops control characters and spaces around a URL and tabs and
 * newlines inside it before it reads the scheme, so each spelling here is a
 * javascript: URL to a browser, and safe_url() (a blocklist read on the
 * bytes as sent) let the first three through into Visitors and Spy.
 */
final class LinkSchemeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-tracking202.php';
    }

    /** @return iterable<string, array{string}> */
    public static function scriptUrls(): iterable
    {
        yield 'tab inside the scheme' => ["java\tscript:alert(1)"];
        yield 'leading control byte' => ["\x01javascript:alert(1)"];
        yield 'newline inside the scheme' => ["java\nscript:alert(1)"];
        yield 'carriage return inside' => ["jav\rascript:alert(1)"];
        yield 'leading NUL' => ["\x00javascript:alert(1)"];
        yield 'DEL inside' => ["java\x7fscript:alert(1)"];
        yield 'space before the colon' => ['javascript :alert(1)'];
        yield 'leading spaces' => ['   javascript:alert(1)'];
        yield 'upper case' => ['JaVaScRiPt:alert(1)'];
        yield 'plain' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
        yield 'escape in the tail' => ["javascript\x0c:alert(1)"];
        yield 'other scheme' => ['file:///etc/passwd'];
    }

    /** @dataProvider scriptUrls */
    public function testALinkWithAnotherSchemeIsRefused(string $url): void
    {
        self::assertSame('', LinkScheme::forLink($url));
        self::assertSame('', \safe_url($url));
    }

    /** @dataProvider scriptUrls */
    public function testARedirectWithAnotherSchemeIsRefused(string $url): void
    {
        self::assertNull(LinkScheme::forRedirect($url));
    }

    /** @return iterable<string, array{string}> */
    public static function links(): iterable
    {
        yield 'https' => ['https://example.com/a?b=c#d'];
        yield 'http upper case' => ['HTTP://example.com/'];
        yield 'scheme-relative' => ['//example.com/x'];
        yield 'root-relative' => ['/tracking202/x.php?y=1'];
        yield 'relative' => ['page.html'];
        yield 'colon after the path starts' => ['/a:b'];
        yield 'colon in the query' => ['?next=javascript:x'];
    }

    /** @dataProvider links */
    public function testALinkWithoutAnotherSchemePassesAsSent(string $url): void
    {
        self::assertSame($url, LinkScheme::forLink('  ' . $url . "\n"));
    }

    public function testARedirectIsAnAbsoluteHttpUrl(): void
    {
        self::assertSame('https://example.com/offer?a=1', LinkScheme::forRedirect('https://example.com/offer?a=1'));
        self::assertSame('http://example.com', LinkScheme::forRedirect(' http://example.com '));
        foreach (['//example.com/x', '/x', 'example.com/offer', 'https:', 'https://', 'https:/x', ''] as $url) {
            self::assertNull(LinkScheme::forRedirect($url), $url);
        }
    }

    public function testTheSchemeIsReadAsTheBrowserReadsIt(): void
    {
        self::assertSame('javascript', LinkScheme::schemeOf("\x01 java\tscript:x"));
        self::assertSame('https', LinkScheme::schemeOf('HTTPS://x'));
        self::assertNull(LinkScheme::schemeOf('/a:b'));
        self::assertNull(LinkScheme::schemeOf('1http://x'));
    }
}
