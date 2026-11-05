<?php

declare(strict_types=1);

namespace Tests\Setup;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Setup\PurLinkTemplate;

final class PurLinkTemplateTest extends TestCase
{
    public function testSimplePageUsesTheCampaignTemplateUnchanged(): void
    {
        $url = 'https://offer.example/path?sid=[[subid]]&kw=[[t202kw]]#section';
        self::assertSame($url, PurLinkTemplate::simple($url));
    }

    /** @dataProvider advancedUrls */
    public function testAdvancedPageIdentifiesTheOfferBeforeTheFragment(string $url, string $expected): void
    {
        self::assertSame($expected, PurLinkTemplate::advanced($url, '123'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function advancedUrls(): iterable
    {
        yield 'no query' => ['https://offer.example/path', 'https://offer.example/path?acip=123'];
        yield 'existing query and fragment' => [
            'https://offer.example/path?sid=[[subid]]#part',
            'https://offer.example/path?sid=[[subid]]&acip=123#part',
        ];
        yield 'empty query' => ['https://offer.example/path?#part', 'https://offer.example/path?acip=123#part'];
        yield 'trailing ampersand' => ['https://offer.example/?x=1&', 'https://offer.example/?x=1&acip=123'];
        yield 'fragment is not query' => ['https://offer.example/#?x=1', 'https://offer.example/?acip=123#?x=1'];
    }

    /** @dataProvider refusedUrls */
    public function testRefusesUnsafeOrAmbiguousTemplates(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        PurLinkTemplate::advanced($url, '123');
    }

    /** @return iterable<string, array{string}> */
    public static function refusedUrls(): iterable
    {
        yield 'script URL' => ['javascript:alert(1)'];
        yield 'relative URL' => ['/offer'];
        yield 'embedded credentials' => ['https://name:secret@offer.example/'];
        yield 'newline' => ["https://offer.example/\nother"];
        yield 'existing acip' => ['https://offer.example/?x=1&ACIP=77'];
        yield 'encoded acip' => ['https://offer.example/?%61cip=77'];
    }

    /** @dataProvider refusedIds */
    public function testRefusesMalformedCampaignPublicIds(mixed $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        PurLinkTemplate::advanced('https://offer.example/', $id);
    }

    /** @return iterable<string, array{mixed}> */
    public static function refusedIds(): iterable
    {
        yield 'zero' => ['0'];
        yield 'exponent' => ['1e3'];
        yield 'suffix' => ['12x'];
        yield 'list' => [['123']];
    }
}
