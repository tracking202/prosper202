<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\PurLinkDestination;

final class PurLinkDestinationTest extends TestCase
{
    private const CAMPAIGN = [
        'aff_campaign_rotate' => 1,
        'aff_campaign_url' => 'https://shop.example/go?aff=7&sid=[[subid]]',
        'aff_campaign_url_2' => 'https://second.example/in?sid=[[subid]]',
        'aff_campaign_url_3' => '',
        'aff_campaign_url_4' => null,
    ];
    private const CHOSEN = 'https://second.example/in?sid=[[subid]]';

    public function testALinkOfTheCampaignWithAnotherQueryIsKept(): void
    {
        $link = 'https://shop.example/go?aff=7&sid=[[subid]]&acip=491&u=https%3A%2F%2Fshop.example%2Fproduct%2F9';
        self::assertSame($link, PurLinkDestination::choose(self::CHOSEN, $link, self::CAMPAIGN));
        $second = 'https://SECOND.example:443/in?sid=[[subid]]&u=x';
        self::assertSame($second, PurLinkDestination::choose(self::CHOSEN, $second, self::CAMPAIGN));
    }

    /** @dataProvider plainLinks */
    public function testTheCampaignsOwnPurLinkLeavesRotationAlone(string $link): void
    {
        self::assertSame(self::CHOSEN, PurLinkDestination::choose(self::CHOSEN, $link, self::CAMPAIGN));
    }

    public static function plainLinks(): iterable
    {
        yield 'simple page' => ['https://shop.example/go?aff=7&sid=[[subid]]'];
        yield 'advanced page' => ['https://shop.example/go?aff=7&sid=[[subid]]&acip=491'];
    }

    /** @dataProvider refused */
    public function testAnythingElseGoesWhereTheCampaignSays(mixed $requested): void
    {
        self::assertSame(self::CHOSEN, PurLinkDestination::choose(self::CHOSEN, $requested, self::CAMPAIGN));
    }

    public static function refused(): iterable
    {
        yield 'absent' => [null];
        yield 'a list' => [['https://shop.example/go?u=1']];
        yield 'empty' => [''];
        yield 'another host' => ['https://evil.example/go?aff=7'];
        yield 'a host that only starts the same' => ['https://shop.example.evil.example/go?u=1'];
        yield 'another path' => ['https://shop.example/other?u=1'];
        yield 'a path that only starts the same' => ['https://shop.example/go/more?u=1'];
        yield 'another scheme' => ['http://shop.example/go?u=1'];
        yield 'another scheme on the same port' => ['http://shop.example:443/go?u=1'];
        yield 'another port' => ['https://shop.example:8443/go?u=1'];
        yield 'user info' => ['https://shop.example@evil.example/go?u=1'];
        yield 'user info naming the host' => ['https://evil.example@shop.example/go?u=1'];
        yield 'a backslash' => ['https://shop.example\\@evil.example/go?u=1'];
        yield 'no scheme' => ['//shop.example/go?u=1'];
        yield 'relative' => ['/go?u=1'];
        yield 'not http' => ['javascript://shop.example/go?u=1'];
        yield 'a line break' => ["https://shop.example/go?u=1\r\nX: y"];
        yield 'a space' => ['https://shop.example/go?u=1 2'];
        yield 'too long' => ['https://shop.example/go?u=' . str_repeat('a', 2048)];
    }

    /** @dataProvider tokensTheUrlLacks */
    public function testATokenTheCampaignsUrlLacksIsLeftEmptyAndTheLinkKept(string $token): void
    {
        $link = 'https://shop.example/go?aff=7&sid=[[subid]]&u=deep&x=';
        self::assertSame($link, PurLinkDestination::choose(self::CHOSEN, $link . $token, self::CAMPAIGN));
    }

    public static function tokensTheUrlLacks(): iterable
    {
        yield 'a token' => ['[[c1]]'];
        yield 'in other case' => ['[[CpC]]'];
        yield 'two of them' => ['[[c1]][[gclid]]'];
    }

    /** @dataProvider bracketsThatAreNoWholeToken */
    public function testBracketsThatAreNotAWholeTokenGoWhereTheCampaignSays(string $query): void
    {
        $link = 'https://shop.example/go?aff=7&sid=[[subid]]&' . $query;
        self::assertSame(self::CHOSEN, PurLinkDestination::choose(self::CHOSEN, $link, self::CAMPAIGN));
    }

    public static function bracketsThatAreNoWholeToken(): iterable
    {
        yield 'an opening pair alone' => ['x=a[[b'];
        yield 'a closing pair alone' => ['x=a]]b'];
        yield 'a pair around a token the URL has' => ['x=[[c[[subid]]]]'];
        yield 'a pair around a token the URL lacks' => ['x=[[su[[c1]]bid]]'];
        yield 'a pair around two tokens the URL lacks' => ['x=[[c[[c2]]p[[c3]]c]]'];
        yield 'a name the replacer does not know' => ['x=[[nothing]]'];
    }

    public function testEveryTokenTheReplacerKnowsIsLeftEmptyWhereTheUrlLacksIt(): void
    {
        $tokens = (new \ReflectionClass(\Prosper202\Conversion\TrafficSourcePixels::class))->getConstant('TOKENS');
        $names = array_merge(...array_values($tokens));
        self::assertGreaterThan(30, count($names));
        foreach ($names as $name) {
            $link = "https://shop.example/go?aff=7&x=[[$name]]";
            $expected = $name === 'subid' ? $link : 'https://shop.example/go?aff=7&x=';
            self::assertSame($expected, PurLinkDestination::choose(self::CHOSEN, $link, self::CAMPAIGN), $name);
        }
    }

    public function testATokenCountsOnlyFromTheUrlWhosePlaceTheLinkTakes(): void
    {
        $campaign = [
            'aff_campaign_rotate' => 1,
            'aff_campaign_url' => 'https://shop.example/go?sid=[[subid]]',
            'aff_campaign_url_2' => 'https://second.example/in?sid=[[subid]]&k=[[t202kw]]',
        ];
        $onSecond = 'https://second.example/in?k=[[t202kw]]&u=1';
        self::assertSame($onSecond, PurLinkDestination::choose(self::CHOSEN, $onSecond, $campaign));
        $onFirst = 'https://shop.example/go?k=[[t202kw]]&u=1';
        self::assertSame(
            'https://shop.example/go?k=&u=1',
            PurLinkDestination::choose(self::CHOSEN, $onFirst, $campaign)
        );
    }

    public function testSingleBracketsAreNoToken(): void
    {
        $link = 'https://shop.example/go?aff=7&sid=[[subid]]&size[]=9&size[]=10';
        self::assertSame($link, PurLinkDestination::choose(self::CHOSEN, $link, self::CAMPAIGN));
    }

    public function testACampaignThatDoesNotRotateTakesItsFirstUrlsPlaceOnly(): void
    {
        $campaign = ['aff_campaign_rotate' => 0] + self::CAMPAIGN;
        $first = 'https://shop.example/go?aff=7&u=1';
        $chosen = 'https://shop.example/go?aff=7&sid=[[subid]]';
        self::assertSame($first, PurLinkDestination::choose($chosen, $first, $campaign));
        $second = 'https://second.example/in?sid=[[subid]]&u=x';
        self::assertSame($chosen, PurLinkDestination::choose($chosen, $second, $campaign));
        self::assertSame($second, PurLinkDestination::choose($chosen, $second, self::CAMPAIGN));
    }

    public function testACampaignWithNoUrlTakesNothing(): void
    {
        self::assertSame('', PurLinkDestination::choose('', 'https://shop.example/go?u=1', []));
    }
}
