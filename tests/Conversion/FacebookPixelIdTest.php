<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\FacebookPixelId;

/**
 * A Bot202 Facebook Pixel Assistant pixel's code: the bare Pixel ID paid
 * 1.9.56 stored, or Meta's base code or <noscript> image pasted whole.
 */
final class FacebookPixelIdTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?string}> */
    public static function codes(): iterable
    {
        yield 'the bare id, as paid 1.9.56 stored it' => ['123456789012345', '123456789012345'];
        yield 'with spaces around it' => ["  123456789012345\n", '123456789012345'];
        yield 'the base code' => ["<script>!function(f,b,e,v,n,t,s){}(window);\nfbq('init', '123456789012345');\nfbq('track', 'PageView');</script>", '123456789012345'];
        yield 'the base code, double quotes and no space' => ['fbq("init","987654321098765");', '987654321098765'];
        yield 'the noscript image' => ['<noscript><img height="1" width="1" src="https://www.facebook.com/tr?id=555555555555555&ev=PageView&noscript=1"/></noscript>', '555555555555555'];
        yield 'the noscript image, escaped' => ['<img src="https://www.facebook.com/tr?ev=PageView&amp;id=555555555555555&amp;noscript=1">', '555555555555555'];
        yield 'a URL that is not a pixel' => ['https://example.com/?id=123456789012345', null];
        yield 'letters' => ['abc123', null];
        yield 'too short' => ['1234', null];
        yield 'empty' => ['', null];
        yield 'not text' => [null, null];
    }

    /** @dataProvider codes */
    public function testTheIdIsReadFromWhatWasStored(mixed $code, ?string $expected): void
    {
        self::assertSame($expected, FacebookPixelId::fromCode($code));
    }
}
