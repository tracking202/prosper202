<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Destinations\PiiHash;

/** Google's normalise-then-SHA-256 rule, and nothing guessed where it cannot apply. */
final class PiiHashTest extends TestCase
{
    public function testAnEmailIsTrimmedLowerCasedAndHashed(): void
    {
        self::assertSame(hash('sha256', 'jane.doe@example.com'), PiiHash::email('  Jane.Doe@Example.COM '));
    }

    public function testGmailDropsDotsAndTheTagFromTheLocalPart(): void
    {
        $expected = hash('sha256', 'janedoe@gmail.com');
        self::assertSame($expected, PiiHash::email('Jane.Doe+shop@gmail.com'));
        self::assertSame(hash('sha256', 'janedoe@googlemail.com'), PiiHash::email('jane.doe@googlemail.com'));
        // Positive control: another domain keeps its dots.
        self::assertNotSame($expected, PiiHash::email('jane.doe@gmail.co'));
    }

    public function testWhatIsNotAnEmailIsNotHashed(): void
    {
        foreach ([null, '', 'jane', 'a@b@example.com', 'jane @example.com', 'jane@localhost', '@example.com'] as $value) {
            self::assertNull(PiiHash::email($value), var_export($value, true));
        }
    }

    public function testAPhoneIsHashedInE164Only(): void
    {
        self::assertSame(hash('sha256', '+15551234567'), PiiHash::phone('+1 (555) 123-4567'));
        // Without its country code the number is not guessed into one.
        self::assertNull(PiiHash::phone('(555) 123-4567'));
        self::assertNull(PiiHash::phone('+0 555 123 4567'));
        self::assertNull(PiiHash::phone('+123'));
        self::assertNull(PiiHash::phone(null));
    }
}
