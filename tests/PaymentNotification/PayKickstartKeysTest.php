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
    private const SECRET = 'a-session-secret-of-sixty-four-bytes-0123456789abcdef0123456789';

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
        $token = static fn (string $key): string => PayKickstartKeys::removalToken($key, self::SECRET);
        self::assertSame(
            ['first-key-0001'],
            PayKickstartKeys::removed($stored, '2', $token('second-key-0002'), self::SECRET)
        );
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
        $stale = [
            ['2', $token('first-key-0001')],
            ['3', $token('third-key-0003')],
            ['0', $token('first-key-0001')],
            ['1x', $token('first-key-0001')],
            // What the form used to carry: the ending the page shows.
            ['2', "\u{2026}0002"],
            // The right key's token, made under another session's secret.
            ['2', PayKickstartKeys::removalToken('second-key-0002', str_repeat('b', 64))],
        ];
        foreach ($stale as [$place, $sent]) {
            try {
                PayKickstartKeys::removed($stored, $place, $sent, self::SECRET);
                self::fail("removed $place $sent");
            } catch (InvalidArgumentException $e) {
                self::assertStringStartsWith('The stored keys changed since this page was loaded', $e->getMessage());
            }
        }
        $twenty = array_map(static fn (int $i): string => sprintf('campaign-key-%02d', $i), range(1, 20));
        $this->expectExceptionMessage('At most 20 keys');
        PayKickstartKeys::added($twenty, 'campaign-key-21');
    }

    /**
     * Another tab removed a key and added one ending in the same four
     * characters at its place: the first tab's Remove, made for the old key,
     * removes nothing.
     */
    public function testAStaleRemoveDoesNotTakeANewKeyWithTheSameEnding(): void
    {
        $shown = ['campaign-one-AB12', 'campaign-two-0002'];
        $form = PayKickstartKeys::removalToken('campaign-one-AB12', self::SECRET);
        $now = ['campaign-new-AB12', 'campaign-two-0002'];
        self::assertSame(PayKickstartKeys::hint($shown[0]), PayKickstartKeys::hint($now[0]), 'the same ending');

        try {
            PayKickstartKeys::removed($now, '1', $form, self::SECRET);
            self::fail('the stale form removed the new key');
        } catch (InvalidArgumentException $e) {
            self::assertStringStartsWith('The stored keys changed since this page was loaded', $e->getMessage());
        }
        self::assertSame(['campaign-two-0002'], PayKickstartKeys::removed($shown, '1', $form, self::SECRET));
    }

    public function testATokenNamesTheWholeKeyAndNeverShowsIt(): void
    {
        $token = PayKickstartKeys::removalToken('campaign-one-AB12', self::SECRET);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $token);
        self::assertStringNotContainsString('AB12', $token);
        self::assertNotSame($token, PayKickstartKeys::removalToken('campaign-new-AB12', self::SECRET));
        self::assertNotSame($token, PayKickstartKeys::removalToken('campaign-one-AB12', str_repeat('b', 64)));
        $this->expectException(\LogicException::class);
        PayKickstartKeys::removalToken('campaign-one-AB12', '');
    }

    public function testThePageShowsTheLastFourCharacters(): void
    {
        self::assertSame("\u{2026}AB12", PayKickstartKeys::hint('secret-key-AB12'));
    }
}
