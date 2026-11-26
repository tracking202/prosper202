<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\Criterion;
use Prosper202\Rotator\IpCriterion;
use Prosper202\Rotator\IpRange;
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
        'api/v3/Controllers/RotatorsController.php' => 'reads',
        '202-config/Database/Schema/TableRegistry.php' => 'names the table; reads no value',
        '202-config/functions-upgrade.php' => 'creates the table; reads no value',
        '202-config/Rotator/CriterionRepair.php' => 'reads',
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
        yield 'a country as its code' => ['country', 'us, Canada(CA)', null, 'United States(US),Canada(CA)'];
        yield 'a country as its name' => ['country', 'united kingdom', null, 'United Kingdom(GB)'];
        yield 'a country nobody knows' => ['country', 'Narnia', null, 'Narnia'];
        yield 'a device in capitals' => ['device', 'Mobile, TABLET', null, 'mobile,tablet'];
        yield 'IP ranges' => ['iprange', '203.0.113.0/24, 192.0.2.*', null, '203.0.113.0/24,192.0.2.*'];
        yield 'not an IP range' => ['iprange', '203.0.113.0/24, 198.51.100.0/99', IpRange::refusal('198.51.100.0/99'), ''];
        yield 'eu, as 1.9.56 stored it' => ['eu', 'true', null, 'true'];
        yield 'eu Unknown in any case' => ['eu', 'unknown', null, 'unknown'];
        yield 'eu maybe' => ['eu', 'maybe', '"maybe" never matches a eu rule: use true or false or Unknown.', ''];
        yield 'fraud' => ['fraud', 'TRUE', null, 'TRUE'];
        yield 'fraud unknown' => ['fraud', 'Unknown', '"Unknown" never matches a fraud rule: use true or false.', ''];
        yield 'visitor, as 1.9.56 stored it' => ['visitor', 'filtered', null, 'filtered'];
        yield 'visitor bot' => ['visitor', 'bot', '"bot" never matches a visitor rule: use filtered or unique.', ''];
        yield 'a referer pattern' => ['referer', 'search\\.example', null, 'search\\.example'];
        yield 'a c1 value' => ['c1', 'spring, summer', null, 'spring,summer'];
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
     * The visitor's ISP is compared as a stored value can hold it: values()
     * splits on commas, so only the part before the first comma can match.
     */
    public function testTheVisitorsIspIsReadAsAStoredValueCanHoldIt(): void
    {
        $stored = Criterion::values('Comcast Cable Communications, LLC');
        self::assertContains(Criterion::visitorIsp('Comcast Cable Communications, LLC'), $stored);
        self::assertSame('Verizon Wireless', Criterion::visitorIsp('Verizon Wireless'));
        self::assertSame('Foo', Criterion::visitorIsp(' Foo , Inc'));
        self::assertSame('Unknown ISP/Carrier', Criterion::visitorIsp('Unknown ISP/Carrier'));
    }

    /**
     * The name a click records for an ISP (isp_name keeps 50 characters)
     * and Analyze › ISP lists, which a rule copied from it holds.
     */
    public function testTheRecordedIspIsTheVisitorsIspAsIspNameKeepsIt(): void
    {
        self::assertSame('Comcast Cable Communications', Criterion::recordedIsp('Comcast Cable Communications, LLC'));
        self::assertSame('Verizon Wireless', Criterion::recordedIsp(' Verizon Wireless '));
        self::assertSame(str_repeat('x', 50), Criterion::recordedIsp(str_repeat('x', 60)));
        self::assertSame(str_repeat('x', 49), Criterion::recordedIsp(str_repeat('x', 49) . ' Broadband'), 'a cut ending on a space');
        self::assertSame(str_repeat('é', 50), Criterion::recordedIsp(str_repeat('é', 55)), 'cut in characters, as the column counts');
    }

    /**
     * rtr.php compared an ISP criterion with the first part of the visitor's
     * ISP and offrtr.php with the whole of it, so one redirector sent one
     * visitor two ways; the click recorders stored the first part untrimmed,
     * so Analyze › ISP listed a name a rule could not hold. Every reader now
     * takes it from Criterion::visitorIsp(), but landing.php, which hands
     * the page the visitor's ISP as getIspData() answers it and records
     * nothing.
     */
    public function testEveryReaderOfTheVisitorsIspGoesThroughCriterion(): void
    {
        $exempt = [
            'tracking202/static/landing.php' => "hands the page getIspData()'s answer; records no ISP",
        ];
        $expected = [
            'tracking202/redirect/rtr.php', 'tracking202/redirect/offrtr.php', 'tracking202/redirect/dl.php',
            'tracking202/static/record_simple.php', 'tracking202/static/record_adv.php',
        ];
        $found = [];
        $problems = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            if (stripos($source, 'getIspData') === false || isset($exempt[$file])) {
                continue;
            }
            foreach (self::ispCalls($source) as [$line, $wrapped]) {
                $found[$file] = true;
                if (!$wrapped) {
                    $problems[] = "$file:$line reads getIspData() without Criterion::visitorIsp((string) ...)";
                }
            }
        }
        $found = array_keys($found);
        sort($found);
        sort($expected);
        self::assertSame($expected, $found, 'Files that read the visitor\'s ISP');
        self::assertSame([], $problems);
    }

    /**
     * Each call of getIspData() in $source, by its line, and whether it is
     * the argument of `Criterion::visitorIsp((string) ...)`. A name after
     * `function`, `->`, `::` or `new` is no call of it; a comment is no
     * token.
     *
     * @return list<array{int, bool}>
     */
    private static function ispCalls(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $text = static fn (int $i): string => isset($tokens[$i]) ? (is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]) : '';
        $id = static fn (int $i): ?int => isset($tokens[$i]) && is_array($tokens[$i]) ? $tokens[$i][0] : null;
        $calls = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || SourceScan::callableName($token[0], $token[1]) !== 'getispdata') {
                continue;
            }
            if (in_array($id($i - 1), [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)
                || $text($i + 1) !== '(') {
                continue;
            }
            $wrapped = $id($i - 1) === T_STRING_CAST
                && $text($i - 2) === '('
                && strcasecmp($text($i - 3), 'visitorIsp') === 0
                && $id($i - 4) === T_DOUBLE_COLON
                && preg_match('/(?:^|\\\\)Criterion$/Di', $text($i - 5)) === 1;
            $calls[] = [$token[2], $wrapped];
        }

        return $calls;
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
