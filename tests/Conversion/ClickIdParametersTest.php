<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Conversion\ClickIdParameters;
use Prosper202\User\PreferenceRules;

/**
 * The postback parameters an account says carry an ad click id: what the
 * settings page, the API and the CLIs may store, and what the postback
 * reads of a stored value.
 */
final class ClickIdParametersTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function lists(): iterable
    {
        yield 'none' => ['', ''];
        yield 'blank' => ["  \n", ''];
        yield 'one' => ['aff_sub3', 'aff_sub3'];
        yield 'case and spaces' => [' Aff_Sub3 ,  SUB5 ', 'aff_sub3,sub5'];
        yield 'spaces alone separate too' => ['aff_sub3 sub5', 'aff_sub3,sub5'];
        yield 'a repeat is one' => ['s1,s1,S1', 's1'];
        yield 'five' => ['a,b,c,d,e', 'a,b,c,d,e'];
        yield 'digits and hyphens' => ['5,click-id', '5,click-id'];
    }

    /** @dataProvider lists */
    public function testAListIsStoredAsItsNames(string $given, string $stored): void
    {
        self::assertSame($stored, ClickIdParameters::accepted($given));
        self::assertSame($stored === '' ? [] : explode(',', $stored), ClickIdParameters::parse($stored));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function refused(): iterable
    {
        yield 'a macro, not its name' => ['{aff_sub3}', 'is not a parameter name'];
        yield 'a dot, which PHP renames' => ['aff.sub', 'is not a parameter name'];
        yield 'leading underscore' => ['_x', 'is not a parameter name'];
        yield 'too long' => [str_repeat('a', 41), 'is not a parameter name'];
        yield 'six names' => ['a,b,c,d,e,f', 'At most 5'];
        yield 'the subid itself' => ['subid', 'already means something else'];
        yield 'the token' => ['aff_sub3,P202_Token', 'already means something else'];
        yield "OpenAI's click id" => ['aff_sub3,OPPREF', 'is read without being listed'];
        yield 'a list' => [['aff_sub3'], 'Must be a string'];
        yield 'a number' => [5, 'Must be a string'];
        yield 'null' => [null, 'Must be a string'];
    }

    /** @dataProvider refused */
    public function testAnythingElseIsRefusedSayingWhy(mixed $given, string $why): void
    {
        try {
            ClickIdParameters::accepted($given);
            self::fail('accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($why, $e->getMessage());
        }
    }

    public function testAnIdsOwnNameNeedsNoListing(): void
    {
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            try {
                ClickIdParameters::accepted($name);
                self::fail("$name accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('is read without being listed', $e->getMessage());
            }
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableStored(): iterable
    {
        yield 'not canonical' => ['Aff_Sub3'];
        yield 'spaced' => ['aff_sub3, sub5'];
        yield 'a reserved name' => ['subid'];
        yield 'an id name' => ['aff_sub3,gclid'];
        yield 'too many' => ['a,b,c,d,e,f'];
        yield 'null' => [null];
        yield 'a number' => [1];
    }

    /** @dataProvider unreadableStored */
    public function testAStoredValueThatIsNotSuchAListNamesNoParameter(mixed $stored): void
    {
        self::assertSame([], ClickIdParameters::parse($stored));
    }

    public function testTheApiHoldsThePreferenceToTheSameRule(): void
    {
        self::assertContains(ClickIdParameters::COLUMN, PreferenceRules::columns());
        [$clean, $errors] = PreferenceRules::validate([ClickIdParameters::COLUMN => ' Aff_Sub3,sub5 '], []);
        self::assertSame([ClickIdParameters::COLUMN => 'aff_sub3,sub5'], $clean);
        self::assertSame([], $errors);

        foreach (['gclid', '{aff_sub3}', ['aff_sub3'], 3, null] as $bad) {
            [$clean, $errors] = PreferenceRules::validate([ClickIdParameters::COLUMN => $bad], []);
            self::assertSame([], $clean);
            self::assertArrayHasKey(ClickIdParameters::COLUMN, $errors);
        }
    }

    public function testTheLongestListFitsItsColumn(): void
    {
        $longest = implode(',', array_map(
            static fn (int $i): string => $i . str_repeat('a', ClickIdParameters::MAX_NAME_LENGTH - 1),
            range(1, ClickIdParameters::MAX_NAMES)
        ));
        self::assertSame($longest, ClickIdParameters::accepted($longest));
        self::assertLessThanOrEqual(255, strlen($longest));
        self::assertMatchesRegularExpression(
            '/`' . ClickIdParameters::COLUMN . '` varchar\(255\) NOT NULL DEFAULT \'\'/',
            \Prosper202\Database\Tables\UserTables::usersPref()->createStatement
        );
    }
}
