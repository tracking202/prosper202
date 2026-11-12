<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\AdClickIdColumns;
use Prosper202\Database\Exceptions\QueryException;

/**
 * What reads as the window before the 1.9.79 upgrade: the server's 1054
 * naming gbraid, wbraid, ttclid, msclkid or fbclid (or, before 1.9.80's,
 * oppref), however the statement
 * named it and in whatever language the server answers. Anything else is a
 * failure, which the click path does not swallow.
 */
final class AdClickIdColumnsTest extends TestCase
{
    /** @return iterable<string, array{\Throwable, bool}> */
    public static function failures(): iterable
    {
        // As Connection words a failed prepare.
        $prepare = static fn (string $error): QueryException => new QueryException(
            'Failed to prepare MySQL statement: ' . $error
        );
        $strict = static fn (string $column): \mysqli_sql_exception => new \mysqli_sql_exception(
            "Unknown column '$column' in 'field list'",
            1054
        );
        yield 'an INSERT naming it' => [$prepare("Unknown column 'gbraid' in 'field list' [errno 1054]"), true];
        yield 'named by alias' => [$prepare("Unknown column '2g.ttclid' in 'field list' [errno 1054]"), true];
        yield 'strict reporting' => [$strict('wbraid'), true];
        yield 'the msclkid' => [$prepare("Unknown column 'msclkid' in 'field list' [errno 1054]"), true];
        yield 'the fbclid, by alias' => [$prepare("Unknown column 'g.fbclid' in 'field list' [errno 1054]"), true];
        yield 'the oppref' => [$prepare("Unknown column 'oppref' in 'field list' [errno 1054]"), true];
        yield 'a column that starts with one' => [
            $prepare("Unknown column 'msclkid_x' in 'field list' [errno 1054]"),
            false,
        ];
        yield 'another language' => [$prepare("Unbekanntes Tabellenfeld 'ttclid' in field list [errno 1054]"), true];
        yield 'wrapped' => [new \RuntimeException('recording click 1 failed', 0, $strict('gbraid')), true];
        yield 'another column' => [$prepare("Unknown column 'gclidx' in 'field list' [errno 1054]"), false];
        yield 'a column that ends in one' => [$prepare("Unknown column 'xttclid' in 'field list' [errno 1054]"), false];
        yield 'another error naming it' => [
            new QueryException("MySQL execute failed: Data too long for column 'ttclid' at row 1 [errno 1406]"),
            false,
        ];
        yield 'a missing table' => [$prepare("Table 'p.202_google' doesn't exist [errno 1146]"), false];
        yield 'no errno' => [new \RuntimeException("Unknown column 'gbraid' in 'field list'"), false];
    }

    /** @dataProvider failures */
    public function testOnlyTheServersUnknownColumnForOneOfThemIsTheWindow(\Throwable $e, bool $missing): void
    {
        self::assertSame($missing, AdClickIdColumns::missing($e));
    }

    /**
     * A database the 1.9.79 upgrade reached and the 1.9.80 one has not
     * answers 1054 for the oppref alone: the writer and of() then keep the
     * 1.9.79 ids rather than none.
     */
    public function testOnlyTheOpprefIsTheWindowBeforeThe1980Upgrade(): void
    {
        $prepare = static fn (string $column): QueryException => new QueryException(
            "Failed to prepare MySQL statement: Unknown column '$column' in 'field list' [errno 1054]"
        );
        self::assertTrue(AdClickIdColumns::missingSince1980($prepare('oppref')));
        self::assertTrue(AdClickIdColumns::missingSince1980(new \RuntimeException('x', 0, $prepare('g.oppref'))));
        self::assertFalse(AdClickIdColumns::missingSince1980($prepare('gbraid')));
        self::assertFalse(AdClickIdColumns::missingSince1980($prepare('fbclid')));
        self::assertFalse(AdClickIdColumns::missingSince1980($prepare('oppref_x')));
    }

    public function testATokenForOneOfThemIsNamedInAUrl(): void
    {
        self::assertTrue(AdClickIdColumns::namedIn('https://o.example/?t=[[TTCLID]]'));
        self::assertTrue(AdClickIdColumns::namedIn('https://o.example/?g=[[gbraid]]&w=[[wbraid]]'));
        self::assertTrue(AdClickIdColumns::namedIn('https://o.example/?m=[[msclkid]]'));
        self::assertTrue(AdClickIdColumns::namedIn('https://o.example/?f=[[FBCLID]]'));
        self::assertTrue(AdClickIdColumns::namedIn('https://o.example/?o=[[oppref]]'));
        self::assertFalse(AdClickIdColumns::namedIn('https://o.example/?g=[[gclid]]&s=[[subid]]'));
        self::assertFalse(AdClickIdColumns::namedIn('https://o.example/?ttclid=x'));
    }
}
