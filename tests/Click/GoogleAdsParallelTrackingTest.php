<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\GoogleAdsParallelTracking;

/**
 * Get Links' Google Ads pair for a landing-page tracker, and where pt.php
 * sends its caller: Google's stand-in for {lpurl}, the landing page's own
 * site, or the saved page for paid 1.9.56's templates with no lpurl.
 */
final class GoogleAdsParallelTrackingTest extends TestCase
{
    private const PAGE = 'https://shop.example/landing?x=1';
    private const IDS = ['gclid' => 'Cj0KCQ-abc', 'gbraid' => '', 'wbraid' => '', 'msclkid' => ''];

    public function testTheTemplateIsPaid1956sAddressWithLpurlLast(): void
    {
        self::assertSame(
            'https://track.example/tracking202/redirect/pt.php?t202id=12345&c1={campaignid}&t202kw={keyword}'
                . '&lpurl={lpurl}',
            GoogleAdsParallelTracking::trackingTemplate(
                'https://track.example/',
                '12345',
                '&c1={campaignid}&t202kw={keyword}'
            )
        );
    }

    public function testTheFinalUrlIsThePageAsSaved(): void
    {
        self::assertSame(self::PAGE, GoogleAdsParallelTracking::finalUrl(' ' . self::PAGE . "\n"));
    }

    public function testAPaid1956TemplateGoesToTheSavedPageWithTheClickIds(): void
    {
        self::assertSame(
            'https://shop.example/landing?x=1&promo=spring&gclid=Cj0KCQ-abc',
            GoogleAdsParallelTracking::redirectTarget(self::PAGE, '', self::IDS, 'promo=spring&')
        );
    }

    public function testTheClickIdsGoBeforeTheSavedPagesFragment(): void
    {
        self::assertSame(
            'https://shop.example/p?gclid=Cj0KCQ-abc#offer',
            GoogleAdsParallelTracking::redirectTarget('https://shop.example/p#offer', '', self::IDS, '')
        );
    }

    public function testGooglesStandInIsFollowedAsSent(): void
    {
        $standIn = 'https://www.google.com/asnc/dGVzdA';
        $target = GoogleAdsParallelTracking::redirectTarget(self::PAGE, $standIn, self::IDS, 'promo=1&');
        self::assertSame($standIn, $target);
    }

    public function testTheFinalUrlOnThePagesSiteIsFollowedKeepingItsOwnGclid(): void
    {
        $final = 'https://SHOP.example/other?utm_source=g&gclid=Cj0KCQ-abc';
        self::assertSame($final, GoogleAdsParallelTracking::redirectTarget(self::PAGE, $final, self::IDS, 'promo=1&'));
        self::assertSame(
            'https://shop.example/other?gclid=Cj0KCQ-abc',
            GoogleAdsParallelTracking::redirectTarget(self::PAGE, 'https://shop.example/other', self::IDS, 'promo=1&')
        );
    }

    /** @return iterable<string, array{string}> */
    public static function lpurlsNotFollowed(): iterable
    {
        yield 'another site' => ['https://elsewhere.example/landing'];
        yield 'a look-alike of Google' => ['https://www.google.com.elsewhere.example/asnc/x'];
        yield 'Google, not its stand-in' => ['https://www.google.com/url?q=https://elsewhere.example/'];
        yield 'the stand-in over http' => ['http://www.google.com/asnc/x'];
        yield 'a user in the authority' => ['https://elsewhere.example@shop.example/landing'];
        yield 'a backslash' => ['https://elsewhere.example\\@shop.example/landing'];
        yield 'not http' => ['javascript://shop.example/%0Aalert(1)'];
        yield 'no scheme' => ['//shop.example/landing'];
    }

    /** @dataProvider lpurlsNotFollowed */
    public function testAnLpurlOffThePagesSiteGoesToTheSavedPage(string $lpurl): void
    {
        self::assertSame(
            'https://shop.example/landing?x=1&gclid=Cj0KCQ-abc',
            GoogleAdsParallelTracking::redirectTarget(self::PAGE, $lpurl, self::IDS, '')
        );
    }
}
