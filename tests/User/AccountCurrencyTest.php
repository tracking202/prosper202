<?php

declare(strict_types=1);

namespace Tests\User;

use Api\V3\Controllers\UsersController;
use PHPUnit\Framework\TestCase;

/**
 * The account currency, from the column to the rendered amount.
 *
 * Three lists, and the bugs have come from confusing two of them:
 *
 *   - what the settings page OFFERS, which is what an account may be set to;
 *   - what dollar_format() has a GLYPH for, a subset;
 *   - what the validator ADMITS, which must be the first, not the second.
 *
 * Admitting any three letters put "XYZ10.00" on every page that shows money,
 * indistinguishable to a reader from a real currency. Fixing that by admitting
 * only the glyph list then broke the six offered currencies that have no
 * glyph and correctly render as their own code.
 */
final class AccountCurrencyTest extends TestCase
{
    /**
     * The validator's set is what the settings page OFFERS, not what has a
     * glyph. Deriving it from the symbol table instead rejected six real
     * currencies — AUD, CAD, HKD, MXN, NZD, SGD — rewriting a legitimate
     * stored preference to USD, tagging conversion-ledger writes USD, and
     * making the API refuse a currency its own settings page lists.
     */
    public function testTheAdmittedSetIsExactlyWhatTheSettingsPageOffers(): void
    {
        $page = file_get_contents(dirname(__DIR__, 2) . '/202-account/account.php');
        $this->assertIsString($page);
        $this->assertSame(
            1,
            preg_match_all('/<option value="([A-Z]{3})"/', $page, $m) > 0 ? 1 : 0,
            'no currency options found in account.php; the extractor is broken'
        );

        $offered = array_values(array_unique($m[1]));
        sort($offered);
        $admitted = UsersController::SUPPORTED_CURRENCIES;
        sort($admitted);

        $this->assertSame($offered, $admitted, 'SUPPORTED_CURRENCIES must match the account page exactly');
        $this->assertGreaterThan(20, count($offered), 'far fewer options than expected');
    }

    /** Everything admitted is admitted unchanged. */
    public function testEveryAdmittedCurrencyPassesThroughUntouched(): void
    {
        foreach (UsersController::SUPPORTED_CURRENCIES as $code) {
            $this->assertSame($code, UsersController::normalizeCurrency($code));
        }
    }

    /**
     * The symbol table is a SUBSET, not the same list. A code in it that the
     * app does not offer would be a symbol nothing can ever select.
     */
    public function testEverySymbolBelongsToACurrencyTheAppOffers(): void
    {
        foreach (array_keys(UsersController::CURRENCY_SYMBOLS) as $code) {
            $this->assertContains(
                $code,
                UsersController::SUPPORTED_CURRENCIES,
                "$code has a symbol but is not a currency an account can be set to"
            );
        }
        $this->assertLessThanOrEqual(
            count(UsersController::SUPPORTED_CURRENCIES),
            count(UsersController::CURRENCY_SYMBOLS)
        );
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
