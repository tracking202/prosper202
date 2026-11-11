<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\CountryNames;
use Prosper202\Rotator\Criterion;
use Prosper202\Rotator\CriterionMatcher;
use Prosper202\Rotator\NeverMatches;
use Prosper202\Rotator\VisitorFacts;

/**
 * What Setup › Redirectors and the API say of a rule that sends no visitor
 * anywhere, and the country list the save normalizes with. A warning is a
 * claim about CriterionMatcher, so each one here is held to what the
 * matcher does with the same criterion.
 */
final class NeverMatchesTest extends TestCase
{
    private const READY = ['ipqs_key' => true, 'privacy_all' => false, 'isp' => true];

    private static function visitor(bool $fraud = true): VisitorFacts
    {
        return VisitorFacts::fromRequest(
            [],
            [],
            null,
            null,
            '203.0.113.45',
            ['country' => 'Türkiye', 'country_code' => 'TR', 'region' => 'Istanbul', 'city' => 'Istanbul'],
            'Example Telecom',
            'iOS',
            'Mobile',
            'Safari',
            static fn (): bool => false,
            static fn (): bool => $fraud,
        );
    }

    /** @return iterable<string, array{string, string, string, ?string}> type, statement, value, part of the warning */
    public static function criteria(): iterable
    {
        yield 'a country by name and code' => ['country', 'is', 'Türkiye(TR)', null];
        yield 'a country as a row stored before the normalizer' => ['country', 'is', 'tr', null];
        yield 'a country under the name an older database gave' => ['country', 'is', 'Turkey(TR)', null];
        yield 'a country with no code' => ['country', 'is', 'Anatolia', '"Anatolia" never matches'];
        yield 'a region with its country' => ['region', 'is', 'Istanbul(TR)', null];
        yield 'a region without its country' => ['region', 'is', 'Istanbul', '"Istanbul" never matches'];
        yield 'a city without its country' => ['city', 'is', 'Istanbul', '"Istanbul" never matches'];
        yield 'a device in capitals' => ['device', 'is', 'Mobile', null];
        yield 'a fixed set outside its values' => ['visitor', 'is', 'human', '"human" never matches a visitor rule'];
        yield 'an unreadable range' => ['iprange', 'is', 'nope', 'nope'];
        yield 'a type the redirects do not read' => ['weekday', 'is', 'Monday', 'do not read a "weekday" criterion'];
        yield 'a statement the redirects do not read' => ['country', 'isnt', 'Türkiye(TR)', 'not "isnt"'];
        yield 'every visitor, whatever the type' => ['weekday', 'isnt', 'all', null];
    }

    /** @dataProvider criteria */
    public function testAWarningIsWhatTheMatcherDoes(string $type, string $statement, string $value, ?string $warning): void
    {
        $said = NeverMatches::criterion($type, $statement, $value, self::READY);
        if ($warning === null) {
            self::assertNull($said);
            self::assertTrue(CriterionMatcher::criterion($type, $statement, $value, self::visitor()), 'and it matches its visitor');

            return;
        }
        self::assertStringContainsString($warning, (string) $said);
        self::assertFalse(CriterionMatcher::criterion($type, $statement, $value, self::visitor()), 'and it matches no visitor');
    }

    public function testAFraudRuleIsWarnedOfWhileNoVisitorCanBeScored(): void
    {
        $noKey = ['ipqs_key' => false] + self::READY;
        self::assertStringContainsString('No IPQualityScore key', (string) NeverMatches::criterion('fraud', 'is', 'true', $noKey));
        self::assertStringContainsString('No IPQualityScore key', (string) NeverMatches::criterion('fraud', 'is_not', 'true', $noKey));
        $heldBack = ['privacy_all' => true] + self::READY;
        self::assertStringContainsString('privacy setting', (string) NeverMatches::criterion('fraud', 'is', 'true', $heldBack));
        self::assertNull(NeverMatches::criterion('fraud', 'is', 'true', self::READY));
        self::assertNull(NeverMatches::criterion('fraud', 'is', 'ALL', $noKey), 'every visitor needs no score');
        self::assertSame(['fraud'], array_keys(NeverMatches::types($noKey)));
        self::assertSame(['isp'], array_keys(NeverMatches::types(['isp' => false] + self::READY)));
        self::assertSame([], NeverMatches::types(self::READY));
    }

    public function testARuleBehindOneThatTakesEveryVisitorIsNeverReached(): void
    {
        $rule = static fn (string $name, int $status, string $value): array => [
            'rule_name' => $name, 'status' => $status,
            'criteria' => [['type' => 'country', 'statement' => 'is', 'value' => $value]],
        ];
        $warnings = NeverMatches::rules([
            $rule('switched off', 0, 'ALL'),
            $rule('germany', 1, 'Germany(DE)'),
            $rule('everyone', 1, 'all'),
            $rule('late', 1, 'France(FR)'),
            $rule('late, off', 0, 'France(FR)'),
            $rule('later', 1, 'Narnia'),
        ], self::READY);
        self::assertSame([[3, null], [5, null], [5, 0]], array_map(
            static fn (array $w): array => [$w['rule'], $w['criterion']],
            $warnings
        ));
        self::assertSame('Rule 3 ("everyone") is tried first and matches every visitor, so this rule is never reached.', $warnings[0]['message']);
    }

    public function testACountryIsFoundByCodeNameOrWhatPeopleCallIt(): void
    {
        self::assertSame('US', CountryNames::code('us'));
        self::assertSame('US', CountryNames::code(' United States '));
        self::assertSame('GB', CountryNames::code('uk'));
        self::assertSame('TR', CountryNames::code('turkey'));
        self::assertNull(CountryNames::code('Narnia'));
        self::assertNull(CountryNames::code('ZZ'));
        self::assertSame('United States(US)', CountryNames::matching('us')[0], 'a code\'s own country comes first');
        self::assertContains('Australia(AU)', CountryNames::matching('us'));
        self::assertSame([], CountryNames::matching('  '));
        foreach (CountryNames::BY_CODE as $code => $name) {
            self::assertMatchesRegularExpression('/^[A-Z]{2}$/D', $code);
            self::assertStringNotContainsString(',', $name, "a comma would split $code's value in two");
            self::assertSame($code, Criterion::countryCode(CountryNames::value($code)));
            self::assertSame($code, CountryNames::code($name));
        }
    }

    public function testWhatAStoreRewroteIsListedValueByValue(): void
    {
        self::assertSame(
            [['uk', 'United Kingdom(GB)'], ['Canada', 'Canada(CA)']],
            Criterion::rewritten('country', 'uk, France(FR), Canada, Narnia')
        );
        self::assertSame('United Kingdom(GB),France(FR),Canada(CA),Narnia', Criterion::stored('country', 'uk, France(FR), Canada, Narnia'));
        self::assertSame([['Mobile', 'mobile']], Criterion::rewritten('device', 'Mobile, tablet'));
        self::assertSame([], Criterion::rewritten('country', 'us, ALL'), 'a value holding ALL is kept as it is');
        self::assertSame([], Criterion::rewritten('c1', 'US'), 'only a country and a device are rewritten');
        self::assertSame('United States(US)', Criterion::stored('country', 'us, US, United States'), 'one value, once');
    }
}
