<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\JVZoo\JVZooKey;

/**
 * The one rule for the JVZIPN secret key: what the page and the API store
 * through parse() is what the endpoint signs with through stored(), and an
 * entry it cannot sign with is said to be no key.
 */
final class JVZooKeyTest extends TestCase
{
    public function testAKeyIsStoredTrimmedAndReadAsStored(): void
    {
        self::assertSame('7hQe2LmZp4WvX9cRb31', JVZooKey::parse("  7hQe2LmZp4WvX9cRb31\n"));
        self::assertSame('My JVZIPN Secret Key', JVZooKey::parse('My JVZIPN Secret Key'), 'one a seller typed');
        self::assertSame('clé-secrète', JVZooKey::parse('clé-secrète'));
        self::assertSame('', JVZooKey::parse('   '), '"" clears it');

        self::assertSame('7hQe2LmZp4WvX9cRb31', JVZooKey::stored(" 7hQe2LmZp4WvX9cRb31 \n"));
        self::assertNull(JVZooKey::stored(null));
        self::assertNull(JVZooKey::stored(''));
        self::assertFalse(JVZooKey::unreadable(null));
        self::assertFalse(JVZooKey::unreadable(' '));
    }

    /** @return array<string, array{string, string}> */
    public static function notKeys(): array
    {
        return [
            'two lines pasted' => ["7hQe2LmZp4Wv\nCvEEEE1", 'not text'],
            'a tab inside' => ["7hQe2LmZp4Wv\tCvEEEE1", 'not text'],
            'a byte that is not UTF-8' => ["cl\xE9", 'not text'],
            'longer than the column' => [str_repeat('k', 251), 'is 251 characters long; at most 250.'],
        ];
    }

    /** @dataProvider notKeys */
    public function testWhatCannotBeAKeyIsRefusedOnWriteAndNotReadAsOne(string $value, string $says): void
    {
        try {
            JVZooKey::parse($value);
            self::fail('accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringStartsWith('The key ', $e->getMessage());
            self::assertStringContainsString($says, $e->getMessage());
            self::assertStringNotContainsString(trim($value), $e->getMessage(), 'never by its text');
        }
        self::assertNull(JVZooKey::stored($value));
        self::assertTrue(JVZooKey::unreadable($value));
    }
}
