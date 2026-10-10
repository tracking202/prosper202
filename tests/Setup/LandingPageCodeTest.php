<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Setup\LandingPageCode;

/**
 * The landing-page code is the pages' code, byte for byte. The expected
 * strings are not written here: they are what Setup › Get LP Code served
 * before LandingPageCode existed (fixtures/landing-page-code-e12cb03.json,
 * read out of the pages' answers on a live instance), so a change to a
 * snippet — a tab, a quote, the date's format — fails here, whether it was
 * made for the pages or for the API (GET /landing-pages/{id}/code), which
 * both build from this class.
 */
final class LandingPageCodeTest extends TestCase
{
    private const BASE = '//127.0.0.1:8103/';

    /** 2026-10-07 12:00 UTC, the day the fixture was captured: "Wed Oct, 2026". */
    private const NOW = 1791374400;

    private string $timezone = 'UTC';

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    /** @return array<string, array<string, string>> */
    private static function served(): array
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/landing-page-code-e12cb03.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);

        return $fixture;
    }

    public function testASimplePageGetsThePagesCode(): void
    {
        $served = self::served()['simple'];
        self::assertSame($served['loader'], LandingPageCode::loader(self::BASE, '611'));
        self::assertSame($served['outbound_link'], LandingPageCode::simpleOutboundLink(self::BASE, '611'));
        self::assertSame($served['outbound_php'], LandingPageCode::simpleOutboundPhp(self::BASE, '611', 'https://example.com/lp-a', self::NOW));
        self::assertSame($served['outbound_javascript'], LandingPageCode::simpleOutboundJavascript(self::BASE, '611'));
    }

    public function testAnAdvancedPageGetsThePagesCodeForEachOffer(): void
    {
        $served = self::served()['advanced'];
        self::assertSame($served['loader'], LandingPageCode::loader(self::BASE, '922'));
        self::assertSame($served['campaign_link'], LandingPageCode::campaignOutboundLink(self::BASE, '224'));
        self::assertSame($served['campaign_php'], LandingPageCode::campaignOutboundPhp(self::BASE, '224', 'EVAL Campaign B', 'https://example.com/adv-lp?x=1', self::NOW));
        self::assertSame($served['rotator_link'], LandingPageCode::rotatorOutboundLink(self::BASE, '6299370'));
        self::assertSame($served['rotator_php'], LandingPageCode::rotatorOutboundPhp(self::BASE, '6299370', 'EVAL Geo Split', 'https://example.com/adv-lp?x=1', self::NOW));
    }

    public function testTheFixtureIsWhatThePagesServedNotAnEmptyMatch(): void
    {
        $served = self::served();
        self::assertStringContainsString('landing.php?lpip=611&t202id=', $served['simple']['loader']);
        self::assertStringContainsString("created on Wed Oct, 2026\n", $served['simple']['outbound_php']);
        self::assertStringContainsString("\$tracking202outbound = '//127.0.0.1:8103/tracking202/redirect/lp.php?lpip=611&pci='", $served['simple']['outbound_php']);
        self::assertStringContainsString('off.php?acip=224&pci=', $served['advanced']['campaign_php']);
        self::assertStringContainsString("offrtr.php?rpi=6299370';", $served['advanced']['rotator_php']);
    }

    public function testTheBaseIsTheTrackingBaseWithoutItsScheme(): void
    {
        self::assertSame('//track.example.com/', LandingPageCode::protocolRelative('https://track.example.com/'));
        self::assertSame('//127.0.0.1:8103/p202/', LandingPageCode::protocolRelative('http://127.0.0.1:8103/p202/'));
        $this->expectException(\InvalidArgumentException::class);
        LandingPageCode::protocolRelative('track.example.com/');
    }

    public function testTheLegacyHelpersReadTheSameList(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-tracking202.php');
        self::assertStringContainsString('return \Prosper202\Setup\LandingPageCode::SEGMENTS;', $source, 'getDynamicContentSegments() is the class\'s list');
        self::assertStringContainsString("return \\Prosper202\\Setup\\LandingPageCode::loader('//' . getTrackingDomain() . get_absolute_url(), \$landing_page_id_public);", $source, 'generateTrackingLoaderSnippet() is the class\'s loader');
        self::assertArrayHasKey('t202Country', LandingPageCode::SEGMENTS);
        self::assertCount(19, LandingPageCode::SEGMENTS);
    }
}
