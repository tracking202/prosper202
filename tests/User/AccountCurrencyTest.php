<?php

declare(strict_types=1);

namespace Tests\User;

use Api\V3\Controllers\UsersController;
use PHPUnit\Framework\TestCase;

/**
 * The account currency, from the column to the rendered amount.
 *
 * These two have to agree about one set, and they used to disagree: the
 * validator admitted any three letters while dollar_format() knew twenty-one
 * currencies, so a stored "XYZ" put "XYZ10.00" on every page that shows
 * money — a string indistinguishable, to a reader, from a real currency.
 */
final class AccountCurrencyTest extends TestCase
{
    /**
     * Everything the validator admits must be something the renderer can
     * render. dollar_format()'s last resort is to print the code itself, so
     * a code with no symbol is exactly what this must not admit.
     */
    public function testEveryAdmittedCurrencyIsOneTheRendererHasASymbolFor(): void
    {
        foreach (UsersController::CURRENCY_SYMBOLS as $code => $sides) {
            $this->assertSame(
                $code,
                UsersController::normalizeCurrency($code),
                "$code is in the symbol table, so it must be admitted unchanged"
            );
            $this->assertNotSame(
                ['', ''],
                $sides,
                "$code would render as its own code, which is what the fallback does for unknown ones"
            );
        }
    }

    /**
     * And the renderer really reads that table, rather than holding a second
     * copy — which is what it did: twenty-one `if ($currency == ...)` lines,
     * one edit away from disagreeing with the validator forever.
     *
     * Asserted over the source rather than by calling dollar_format(),
     * because 202-config/functions-tracking202.php runs code at file scope
     * (there is a template_bottom() and a die() above this function) and
     * cannot be loaded into a test process — which is why
     * tests/DataEngine/HtmlReportFormatterTest.php stands in a stub for it.
     * Requiring it here fataled the whole suite on "Cannot redeclare
     * dollar_format()" while every suite run on its own stayed green.
     */
    public function testTheRendererReadsThatTableRatherThanKeepingItsOwn(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-tracking202.php');
        $this->assertIsString($source);

        $start = strpos($source, 'function dollar_format(');
        $this->assertIsInt($start, 'dollar_format() has moved out of functions-tracking202.php');
        $body = substr($source, $start, 4000);

        $this->assertStringContainsString(
            'UsersController::CURRENCY_SYMBOLS',
            $body,
            'dollar_format() no longer reads the one symbol table'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/if \(\$currency == .[A-Z]{3}.\)/',
            $body,
            'dollar_format() has grown a second, per-currency copy of the table'
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unrenderableValues(): array
    {
        return [
            'three letters nobody trades' => ['XYZ'],
            'two letters' => ['XX'],
            'empty column' => [''],
            'null column' => [null],
            'not a string' => [['USD']],
            'a whole sentence' => ['dollars please'],
        ];
    }

    /**
     * @dataProvider unrenderableValues
     */
    public function testAnythingElseFallsBackToTheDefault(mixed $raw): void
    {
        $this->assertSame(UsersController::DEFAULT_CURRENCY, UsersController::normalizeCurrency($raw));
    }

    /** Case and padding are storage noise, not a different currency. */
    public function testACodeIsRecognisedWhateverItsCaseOrPadding(): void
    {
        foreach (['eur', ' EUR ', "\tEuR\n"] as $raw) {
            $this->assertSame('EUR', UsersController::normalizeCurrency($raw));
        }
    }

    /**
     * The symbol can precede or follow the amount — Czech koruna and Turkish
     * lira are the two that follow — and a table entry with neither would
     * silently render as the bare code.
     */
    public function testEverySymbolTableEntryCarriesExactlyOneSideOfTheAmount(): void
    {
        foreach (UsersController::CURRENCY_SYMBOLS as $code => $sides) {
            $this->assertCount(2, $sides, "$code must be [before, after]");
            [$before, $after] = $sides;
            $this->assertNotSame(
                $before === '',
                $after === '',
                "$code must set exactly one of before/after, not both or neither"
            );
        }
    }
}
