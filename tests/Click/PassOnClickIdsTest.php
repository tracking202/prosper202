<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\PassOnClickIds;

/**
 * The pass-on of a redirect drops an ad click id its destination carries,
 * and nothing else.
 */
final class PassOnClickIdsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function clickIds(): iterable
    {
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            yield $name => [$name];
        }
    }

    /** @dataProvider clickIds */
    public function testAClickIdTheUrlCarriesIsNotPassedOnAgain(string $name): void
    {
        self::assertSame(
            'promo=spring&x=1&',
            PassOnClickIds::withoutCarried(
                'promo=spring&' . $name . '=V1&x=1&',
                'https://offer.example/go?aff=7&' . $name . '=V1&sub=123'
            )
        );
    }

    /** @dataProvider clickIds */
    public function testAClickIdTheUrlDoesNotCarryIsPassedOn(string $name): void
    {
        $passOn = 'promo=spring&' . $name . '=V1&';
        self::assertSame($passOn, PassOnClickIds::withoutCarried($passOn, 'https://offer.example/go?aff=7&sub=123'));
        self::assertSame($passOn, PassOnClickIds::withoutCarried($passOn, 'https://offer.example/go'));
    }

    public function testAFilledValueIsCarriedWhateverItIs(): void
    {
        // Written in by hand, or cut to its column: one gbraid either way.
        foreach (['gbraid=hand-written', 'gbraid=' . str_repeat('a', 150)] as $carried) {
            self::assertSame(
                'promo=1&',
                PassOnClickIds::withoutCarried(
                    'promo=1&gbraid=' . str_repeat('a', 160) . '&',
                    'https://o.example/?' . $carried
                )
            );
        }
    }

    /** A click id the URL names with no value (no click filled it) carries nothing: the link's id goes on. */
    public function testAnEmptyValueCarriesNothing(): void
    {
        foreach (['s=p&gbraid=', 's=p&gbraid', 'gbraid=&s=p', 'gbraid&s=p', 's=p&gbraid=&gbraid'] as $query) {
            $url = 'https://o.example/x?' . $query;
            self::assertSame([], PassOnClickIds::carriedBy($url), $query);
            self::assertSame('a=1&gbraid=REAL&', PassOnClickIds::withoutCarried('a=1&gbraid=REAL&', $url), $query);
        }
        // Another id of the URL is still carried while this one is empty.
        self::assertSame(
            'gbraid=REAL&',
            PassOnClickIds::withoutCarried('gbraid=REAL&wbraid=W&', 'https://o.example/?gbraid=&wbraid=W')
        );
    }

    public function testOnlyTheClickIdsAreDropped(): void
    {
        // A duplicate of another parameter is passed on as before.
        self::assertSame(
            'sub=9&country=US&',
            PassOnClickIds::withoutCarried(
                'sub=9&ttclid=T&country=US&',
                'https://o.example/?sub=1&country=[[x]]&ttclid=T'
            )
        );
    }

    public function testTheFragmentIsNotTheQuery(): void
    {
        self::assertSame([], PassOnClickIds::carriedBy('https://o.example/p#gbraid=G'));
        self::assertSame('gbraid=G&', PassOnClickIds::withoutCarried('gbraid=G&', 'https://o.example/p#?gbraid=G'));
        self::assertSame(['gbraid' => true], PassOnClickIds::carriedBy('https://o.example/p?gbraid=G#x'));
    }

    public function testNamesAreReadAsPhpReadsThem(): void
    {
        self::assertSame(['wbraid' => true], PassOnClickIds::carriedBy('https://o.example/?%77braid=W'));
        self::assertSame('', PassOnClickIds::withoutCarried('w%62raid=W&', 'https://o.example/?wbraid=W'));
        // Another name: a list, a prefix, another case.
        self::assertSame([], PassOnClickIds::carriedBy('https://o.example/?gbraid[]=G&gbraidx=G&GBRAID=G'));
    }

    /** An outage's cached URL keeps its tokens: the link's id still goes on. */
    public function testAnUnfilledTokenCarriesNothing(): void
    {
        foreach (['gbraid=[[gbraid]]', 'gbraid=%5B%5Bgbraid%5D%5D', 'gbraid=[[GBRAID]]', 'gbraid=[[]]'] as $raw) {
            self::assertSame([], PassOnClickIds::carriedBy('https://o.example/?' . $raw), $raw);
            self::assertSame('gbraid=G&', PassOnClickIds::withoutCarried('gbraid=G&', 'https://o.example/?' . $raw));
        }
        // A filled value that only holds a token's brackets is carried.
        self::assertSame(['gbraid' => true], PassOnClickIds::carriedBy('https://o.example/?gbraid=a[[b]]'));
    }

    public function testWithoutDropsTheNamesGiven(): void
    {
        self::assertSame('promo=1&', PassOnClickIds::without('promo=1&gbraid=G&', ['gbraid' => true]));
        self::assertSame('promo=1&gbraid=G&', PassOnClickIds::without('promo=1&gbraid=G&', []));
    }

    public function testEverythingDroppedLeavesNothingToAdd(): void
    {
        self::assertSame(
            '',
            PassOnClickIds::withoutCarried('gbraid=G&wbraid=W&', 'https://o.example/?wbraid=W&gbraid=G')
        );
        self::assertSame('', PassOnClickIds::withoutCarried('gbraid=G', 'https://o.example/?gbraid=G'));
        self::assertSame('', PassOnClickIds::withoutCarried('', 'https://o.example/?gbraid=G'));
    }

    public function testThePiecesKeptAreKeptAsTheyWere(): void
    {
        // getPrePopVars() writes values raw; what it wrote goes on unchanged.
        self::assertSame(
            'q=a b&r=%41&',
            PassOnClickIds::withoutCarried('q=a b&ttclid=T&r=%41&', 'https://o.example/?ttclid=T')
        );
    }
}
