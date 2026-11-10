<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\PurLinkDestination;

final class PurLinkDestinationTest extends TestCase
{
    private const CAMPAIGN = [
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

    public function testACampaignWithNoUrlTakesNothing(): void
    {
        self::assertSame('', PurLinkDestination::choose('', 'https://shop.example/go?u=1', []));
    }
}
