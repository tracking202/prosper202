<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\Criterion;
use Prosper202\Rotator\IpCriterion;
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

    /** @return iterable<string, array{string, string, ?string, string}> type, typed value, refusal, stored */
    public static function typedValues(): iterable
    {
        yield 'an IP criterion of ALL' => ['ip', 'ALL', null, 'ALL'];
        yield 'an IP criterion of all, padded' => ['ip', ' all ', null, 'ALL'];
        yield 'an IP criterion of All' => ['ip', 'All', null, 'ALL'];
        yield 'an address beside ALL' => ['ip', '203.0.113.7, ALL', null, '203.0.113.7,ALL'];
        yield 'addresses, canonical' => ['ip', '2001:DB8::77, 198.51.100.88', null, '2001:db8::77,198.51.100.88'];
        yield 'not an address' => ['ip', '203.0.113.500', IpCriterion::refusal('203.0.113.500'), ''];
        yield 'a country beside ALL' => ['country', 'US, ALL', null, 'US,ALL'];
        yield 'a country list' => ['country', 'United States(US), Canada(CA)', null, 'United States(US),Canada(CA)'];
    }

    /**
     * What the Redirectors page's save refuses and stores for a typed value.
     * ALL matches every visitor whatever the type, so an IP criterion
     * holding it is not refused as no address (the API's check takes it the
     * same way, RotatorRuleSetIntegrationTest).
     *
     * @dataProvider typedValues
     */
    public function testATypedValueIsRefusedAndStoredAsItIsRead(
        string $type,
        string $typed,
        ?string $refusal,
        string $stored
    ): void {
        self::assertSame($refusal, Criterion::refusal($type, $typed));
        if ($refusal === null) {
            self::assertSame($stored, Criterion::stored($type, $typed));
        }
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
