<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\Criterion;
use Tests\Support\SourceScan;

/**
 * A redirector rule's criterion value, read one way by every reader.
 *
 * rtr.php read `ALL` and `all` as every visitor and offrtr.php only `ALL`,
 * so one rule stored as `all` sent a visitor to the rule from a redirector
 * link and to the default from a landing page's offer link. The spellings
 * are the ones writers stored (tests/fixtures/rotator-criterion-values.json,
 * which go-cli's twins read too); every reader of a criterion's value goes
 * through Criterion, and none spells ALL itself.
 */
final class CriterionTest extends TestCase
{
    private const VECTORS = 'tests/fixtures/rotator-criterion-values.json';

    /**
     * Every served file that names the criteria table, with what it does
     * with a criterion's value.
     *
     * @var array<string, string>
     */
    private const READERS = [
        'tracking202/redirect/rtr.php' => 'reads',
        'tracking202/redirect/offrtr.php' => 'reads',
        'tracking202/ajax/sort_rotator.php' => 'reads',
        'tracking202/setup/rotator.php' => 'reads',
        'tracking202/ajax/rotator.php' => 'reads',
        'api/v3/Controllers/RotatorsController.php' => 'reads',
        '202-config/Database/Schema/TableRegistry.php' => 'names the table; reads no value',
        '202-config/functions-upgrade.php' => 'creates the table; reads no value',
    ];

    /** @return iterable<string, array{string, list<string>, bool, string}> */
    public static function storedValues(): iterable
    {
        $json = file_get_contents(SourceScan::repoRoot() . '/' . self::VECTORS);
        self::assertIsString($json, self::VECTORS . ' was not read');
        $cases = json_decode($json, true, 16, JSON_THROW_ON_ERROR)['cases'];
        foreach ($cases as $case) {
            yield json_encode($case['stored']) . ' (' . $case['writer'] . ')' => [
                $case['stored'], $case['values'], $case['everyone'], $case['normalized'],
            ];
        }
    }

    /**
     * @dataProvider storedValues
     * @param list<string> $values
     */
    public function testEachStoredSpellingIsReadAsTheRedirectsReadIt(
        string $stored,
        array $values,
        bool $everyone,
        string $normalized
    ): void {
        self::assertSame($values, Criterion::values($stored));
        self::assertSame($everyone, Criterion::matchesEveryone(Criterion::values($stored)));
        self::assertSame($normalized, Criterion::normalize($stored));
        self::assertSame($normalized, Criterion::normalize($normalized), 'a stored value is stored again unchanged');
        self::assertSame($everyone, Criterion::matchesEveryone(Criterion::values($normalized)), 'and reads as it did');
    }

    public function testACriterionIsDescribedAsTheRedirectsReadIt(): void
    {
        $types = ['country' => 'Country'];
        self::assertSame('any visitor', Criterion::describe('country', 'is', 'all', $types));
        self::assertSame('any visitor', Criterion::describe('country', 'is_not', 'United States(US), All', $types));
        $listed = Criterion::describe('country', 'is_not', 'United States(US), Canada(CA)', $types);
        self::assertSame('Country is not United States(US), Canada(CA)', $listed);
        self::assertSame('device is mobile', Criterion::describe('device', 'is', 'mobile'));
    }

    /**
     * Every reader of a criterion's value calls Criterion, and none spells
     * the every-visitor value itself: offrtr.php's own `'ALL'` is how the two
     * scripts came to differ. A file that names the criteria table and is not
     * listed fails until it is.
     */
    public function testEveryReaderOfACriterionValueGoesThroughCriterion(): void
    {
        $found = [];
        $problems = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            if (!str_contains($source, '202_rotator_rules_criteria') || $file === '202-config/Rotator/Criterion.php') {
                continue;
            }
            $found[] = $file;
            if ((self::READERS[$file] ?? null) !== 'reads') {
                continue;
            }
            if (!str_contains($source, 'Criterion::')) {
                $problems[] = "$file reads a criterion's value and does not call Criterion";
            }
            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                if (strcasecmp(substr($token[1], 1, -1), Criterion::EVERYONE) === 0) {
                    $problems[] = "$file:{$token[2]} spells {$token[1]}: "
                        . 'ask Criterion::isEveryone() or matchesEveryone()';
                }
            }
        }
        $listed = array_keys(self::READERS);
        sort($listed);
        sort($found);
        self::assertSame($listed, $found, 'Files that name the criteria table: list a new one in READERS');
        self::assertSame([], $problems);
    }
}
