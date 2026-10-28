<?php

declare(strict_types=1);

namespace Tests\Click;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickExit;
use Prosper202\Click\OfferRoute;

final class ClickExitTest extends TestCase
{
    /** @return iterable<string, array{\Closure(): mixed, string}> */
    public static function refused(): iterable
    {
        yield 'no account' => [static fn () => new ClickExit(0, 5), 'names an account and a click, not 0 and 5'];
        yield 'no click' => [static fn () => new ClickExit(1, -5), 'names an account and a click, not 1 and -5'];
        yield 'cloaking -1' => [static fn () => new ClickExit(1, 5, cloaking: -1), "cloaking is 0 or 1, not -1"];
        yield 'cloaking without the click-out mark' => [
            static fn () => new ClickExit(1, 5, clickedOut: false, cloaking: 1),
            'records its cloaking with the click-out mark',
        ];
        yield 'a negative outbound URL' => [
            static fn () => new ClickExit(1, 5, outboundSiteUrlId: -1),
            'outbound URL is an id or 0, not -1',
        ];
        yield 'no campaign' => [static fn () => new OfferRoute(0, '1.00'), "campaign is a positive id, not 0"];
        yield 'an empty payout' => [static fn () => new OfferRoute(3, ''), "payout is a decimal number, not ''"];
        yield 'a payout with words' => [
            static fn () => new OfferRoute(3, '12abc'),
            "payout is a decimal number, not '12abc'",
        ];
        yield 'an exponent' => [static fn () => new OfferRoute(3, '1e3'), "payout is a decimal number, not '1e3'"];
    }

    /**
     * @dataProvider refused
     * @param \Closure(): mixed $make
     */
    public function testAValueItsColumnCannotHoldIsRefused(\Closure $make, string $why): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($why);
        $make();
    }

    public function testTheDefaultsAreAClickOutThatKeepsItsCloaking(): void
    {
        $exit = new ClickExit(1, 5);

        self::assertTrue($exit->clickedOut);
        self::assertNull($exit->cloaking);
        self::assertNull($exit->offer);
        self::assertNull($exit->rotator);
        self::assertSame(0, $exit->outboundSiteUrlId);
        self::assertSame('-0.50', (new OfferRoute(3, '-0.50'))->payout);
    }
}
