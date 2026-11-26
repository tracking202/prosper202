<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\CriterionMatcher;
use Prosper202\Rotator\VisitorFacts;
use Tests\Support\SourceScan;

/**
 * A country criterion against one visitor, as the redirects answer it. The
 * cases are the `countries` of tests/fixtures/rotator-criterion-values.json,
 * which `p202 rotator test` is held to as well (go-cli/cmd/verify_test.go):
 * the CLI read a code from the last brackets anywhere in a value and any
 * statement but is_not as is, so it predicted a rule the redirects skip.
 */
final class CountryCriterionValuesTest extends TestCase
{
    private const VECTORS = 'tests/fixtures/rotator-criterion-values.json';

    /** @return iterable<string, array{array<string, string>, string, string, bool}> */
    public static function countryValues(): iterable
    {
        $json = file_get_contents(SourceScan::repoRoot() . '/' . self::VECTORS);
        self::assertIsString($json, self::VECTORS . ' was not read');
        $vectors = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        foreach ($vectors['countries'] as $case) {
            yield $case['statement'] . ' ' . json_encode($case['stored']) . ' (' . $case['why'] . ')' => [
                $vectors['countries_visitor'], $case['statement'], $case['stored'], $case['matches'],
            ];
        }
    }

    /**
     * @dataProvider countryValues
     * @param array<string, string> $geo
     */
    public function testEachCountryValueMatchesAsTheRedirectsReadIt(
        array $geo,
        string $statement,
        string $stored,
        bool $matches
    ): void {
        $visitor = new VisitorFacts(
            '203.0.113.45',
            $geo,
            null,
            'Windows',
            'Desktop',
            'Chrome',
            [],
            null,
            null,
            static fn (): bool => false,
            static fn (): bool => false,
        );

        self::assertSame($matches, CriterionMatcher::criterion('country', $statement, $stored, $visitor));
    }

    public function testTheCasesCoverWhatTheCliMisread(): void
    {
        $cases = iterator_to_array(self::countryValues());
        self::assertGreaterThanOrEqual(20, count($cases));
        $stored = array_map(static fn (array $c): string => $c[2], $cases);
        foreach (['DE', 'Germany( DE )', 'Germany(DE) x', 'Germany'] as $value) {
            self::assertContains($value, $stored);
        }
    }
}
