<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartKeys;

/**
 * The PayKickstart IPN secret keys as the page and the API write them and
 * as the receiver reads them back.
 */
final class PayKickstartKeysTest extends TestCase
{
    private const NOT_A_CHARACTER = 'has a character that is not a letter, a digit or punctuation;'
        . ' paste it as PayKickstart shows it.';
    private const LENGTH = ' characters long; a PayKickstart IPN secret key is 8 to 128.';

    public function testKeysAreSeparatedByCommasSpacesOrLinesAndKeptOnceInTheirOrder(): void
    {
        self::assertSame(['first-key-0001', 'Second_Key.2', 'third+key/3='], PayKickstartKeys::parse(
            " first-key-0001,Second_Key.2\n\tthird+key/3=, first-key-0001 "
        ));
        self::assertSame([], PayKickstartKeys::parse(''));
        self::assertSame([], PayKickstartKeys::parse(" \n "));
        self::assertSame("a-key-0001\nb-key-0002", PayKickstartKeys::serialize(['a-key-0001', 'b-key-0002']));
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        return [
            'too short' => ['good-key-01,short', 'Key 2 is 5' . self::LENGTH],
            'too long' => [str_repeat('k', 129), 'The key is 129' . self::LENGTH],
            'not ASCII' => ['clé-secrète-1', 'The key ' . self::NOT_A_CHARACTER],
            'a control character' => ["bad\x01key-0001", 'The key ' . self::NOT_A_CHARACTER],
        ];
    }

    /** @dataProvider refused */
    public function testAKeyThatIsNotOneIsRefusedByItsPlaceNeverByItsText(string $raw, string $message): void
    {
        try {
            PayKickstartKeys::parse($raw);
            self::fail('accepted ' . $raw);
        } catch (InvalidArgumentException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public function testAtMostTwentyKeys(): void
    {
        $twenty = array_map(static fn (int $i): string => sprintf('campaign-key-%02d', $i), range(1, 20));
        self::assertCount(20, PayKickstartKeys::parse(implode(',', $twenty)));

        $this->expectExceptionMessage('At most 20 keys, one for each PayKickstart campaign; 21 were given.');
        PayKickstartKeys::parse(implode(',', [...$twenty, 'campaign-key-21']));
    }

    public function testAStoredEntryThatIsNotAKeyVerifiesNothingAndIsCounted(): void
    {
        self::assertSame(['keys' => ['good-key-0001', 'good-key-0002'], 'unreadable' => 2], PayKickstartKeys::stored(
            "good-key-0001\nshort\n\ngood-key-0002\nclé-secrète-1\ngood-key-0001"
        ));
        self::assertSame(['keys' => [], 'unreadable' => 0], PayKickstartKeys::stored(null));
        self::assertSame(['keys' => [], 'unreadable' => 0], PayKickstartKeys::stored(''));
    }

    public function testThePageAddsAKeyOnceAndRemovesTheOneItShowed(): void
    {
        $stored = ['first-key-0001', 'second-key-0002'];

        self::assertSame([...$stored, 'third-key-0003'], PayKickstartKeys::added($stored, ' third-key-0003 '));
        self::assertSame(['first-key-0001'], PayKickstartKeys::removed($stored, '2', "\u{2026}0002"));
        foreach (
            [
                ['', 'Paste the IPN secret key of a PayKickstart campaign.'],
                ['second-key-0002', 'That key is stored already.'],
                ['short', 'The key is 5' . self::LENGTH],
            ] as [$pasted, $message]
        ) {
            try {
                PayKickstartKeys::added($stored, $pasted);
                self::fail('added ' . $pasted);
            } catch (InvalidArgumentException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
        $stale = [['2', "\u{2026}0001"], ['3', "\u{2026}0003"], ['0', "\u{2026}0001"], ['1x', "\u{2026}0001"]];
        foreach ($stale as [$place, $hint]) {
            try {
                PayKickstartKeys::removed($stored, $place, $hint);
                self::fail("removed $place $hint");
            } catch (InvalidArgumentException $e) {
                self::assertStringStartsWith('The stored keys changed since this page was loaded', $e->getMessage());
            }
        }
        $twenty = array_map(static fn (int $i): string => sprintf('campaign-key-%02d', $i), range(1, 20));
        $this->expectExceptionMessage('At most 20 keys');
        PayKickstartKeys::added($twenty, 'campaign-key-21');
    }

    public function testThePageShowsTheLastFourCharacters(): void
    {
        self::assertSame("\u{2026}AB12", PayKickstartKeys::hint('secret-key-AB12'));
    }

    public function testAPreferencesRowAnswersHowManyKeysNeverTheKeys(): void
    {
        $row = PayKickstartKeys::withoutKeys([
            'user_id' => 5, 'cb_key' => 'kept', PayKickstartKeys::COLUMN => "good-key-0001\ngood-key-0002\nshort",
        ]);
        self::assertSame(['user_id' => 5, 'cb_key' => 'kept', 'paykickstart_ipn_keys_stored' => 2], $row);
        self::assertSame(
            ['paykickstart_ipn_keys_stored' => 0],
            PayKickstartKeys::withoutKeys([PayKickstartKeys::COLUMN => null])
        );
        self::assertSame(
            ['user_id' => 5],
            PayKickstartKeys::withoutKeys(['user_id' => 5]),
            'a row read without the column'
        );
    }
}
