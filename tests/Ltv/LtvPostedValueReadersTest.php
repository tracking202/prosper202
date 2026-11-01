<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;

/**
 * The LTV partials' readers of what they are posted
 * (tracking202/ajax/ltv_helpers.php): an id exactly or the page's sentence,
 * where `(int)` made `12abc` company 12 in a merge; one text value, where
 * `(string)` made a list "Array"; one of a list's values, where anything else
 * was read as the default. LtvPagesPostedIdsIntegrationTest runs the pages.
 */
final class LtvPostedValueReadersTest extends TestCase
{
    private const REFUSAL = 'Pick the company to delete from the list.';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/tracking202/ajax/ltv_helpers.php';
    }

    public function testAnIdIsReadExactlyOrRefusedInThePagesWords(): void
    {
        self::assertSame(12, p202_ltv_posted_id('12', self::REFUSAL));
        $refused = ['12abc', 'abc', '0', '-1', '', ' 12', '012', '+12', '1.5', '9223372036854775808', null, ['12']];
        foreach ($refused as $sent) {
            self::assertSame(
                self::REFUSAL,
                self::refusalOf(fn () => p202_ltv_posted_id($sent, self::REFUSAL)),
                var_export($sent, true)
            );
        }
    }

    public function testNoRowAskedForIsNoneAndAnythingElseIsAnId(): void
    {
        self::assertSame(0, p202_ltv_posted_id_or_none(null, self::REFUSAL));
        self::assertSame(0, p202_ltv_posted_id_or_none('', self::REFUSAL));
        self::assertSame(7, p202_ltv_posted_id_or_none('7', self::REFUSAL));
        foreach (['0', '7abc', '-7', ['7']] as $sent) {
            self::assertSame(self::REFUSAL, self::refusalOf(fn () => p202_ltv_posted_id_or_none($sent, self::REFUSAL)));
        }
    }

    public function testThePagersOffsetIsZeroOrAWholeNumber(): void
    {
        self::assertSame(0, p202_ltv_posted_offset(null));
        self::assertSame(0, p202_ltv_posted_offset(''));
        self::assertSame(0, p202_ltv_posted_offset('0'));
        self::assertSame(50, p202_ltv_posted_offset('50'));
        foreach (['50abc', '-50', '00', '050', '5e1', ['50']] as $sent) {
            self::assertSame(
                'Pick a page with Previous or Next.',
                self::refusalOf(fn () => p202_ltv_posted_offset($sent)),
                var_export($sent, true)
            );
        }
    }

    public function testATextValueIsOneStringAndAListIsRefused(): void
    {
        self::assertSame('', p202_ltv_posted_text(null, 'q'));
        self::assertSame(' Acme ', p202_ltv_posted_text(' Acme ', 'q'));
        self::assertSame(
            'The form sent "company_name" as a list; it takes one value.',
            self::refusalOf(fn () => p202_ltv_posted_text(['Acme'], 'company_name'))
        );
    }

    public function testAGroupIsFieldsOfTextAndNothingElse(): void
    {
        self::assertSame([], p202_ltv_posted_group(null, 'crm'));
        self::assertSame(['city' => 'Oslo'], p202_ltv_posted_group(['city' => 'Oslo'], 'crm'));
        self::assertSame(
            'The form sent "crm" as one value; it takes a group of fields.',
            self::refusalOf(fn () => p202_ltv_posted_group('Oslo', 'crm'))
        );
        self::assertSame(
            'The form sent "crm[city]" as a list; it takes one value.',
            self::refusalOf(fn () => p202_ltv_posted_group(['city' => ['Oslo']], 'crm'))
        );
    }

    public function testAChoiceIsOneOfTheListsValues(): void
    {
        $choices = ['' => 'All', 'active' => 'Active'];
        self::assertSame('', p202_ltv_posted_choice(null, $choices, '', 'Pick a status from the list.'));
        self::assertSame('', p202_ltv_posted_choice('', $choices, '', 'Pick a status from the list.'));
        self::assertSame('active', p202_ltv_posted_choice('active', $choices, '', 'Pick a status from the list.'));
        foreach (['activ', 'Active', ['active']] as $sent) {
            self::assertSame(
                'Pick a status from the list.',
                self::refusalOf(fn () => p202_ltv_posted_choice($sent, $choices, '', 'Pick a status from the list.'))
            );
        }
    }

    public function testARefusedValueIsTheDefaultAndTheFirstRefusalIsKept(): void
    {
        $refusal = null;
        self::assertSame(50, p202_ltv_posted_or(fn (): int => p202_ltv_posted_offset('50'), 0, $refusal));
        self::assertNull($refusal);
        self::assertSame(0, p202_ltv_posted_or(fn (): int => p202_ltv_posted_offset('5x'), 0, $refusal));
        self::assertSame('x', p202_ltv_posted_or(
            fn (): string => p202_ltv_posted_choice('y', ['x' => 'X'], 'x', 'Pick one.'),
            'x',
            $refusal
        ));
        self::assertSame('Pick a page with Previous or Next.', $refusal, 'the first refusal is the one said');
    }

    private static function refusalOf(callable $read): ?string
    {
        try {
            $read();
        } catch (\RuntimeException $refused) {
            return $refused->getMessage();
        }

        return null;
    }
}
