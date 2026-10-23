<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * A statement that counts 202_clicks rows counts a click once, at its latest
 * visit.
 *
 * A redirector re-click gives the click another 202_clicks row, so SUM,
 * COUNT or AVG over the table's rows counts the click at each visit: its
 * clicks and cost, and once it converts its leads and income. The reports
 * that count the table were moved to LatestVisit::isLatest(), the condition
 * that keeps a click's latest visit, one by one, and nothing held the next
 * one to it. Every statement that counts rows of a bare 202_clicks has
 * isLatest() ANDed into it for each one it reads, or its function is listed
 * in COUNTS_EVERY_VISIT with how many such statements it makes and why each
 * is right; the list only shrinks.
 *
 * Read per statement as PHP assembles it (AssembledStrings), with the tables
 * read as SingleClickReadsNameTheirVisitTest reads them (tablesRead(),
 * isConjunct()). A statement counts rows when it reads a bare 202_clicks,
 * not a DELETE's target, calls SUM, COUNT or AVG anywhere in its text, and
 * is not a read of one click, which that test holds. MIN and MAX are not
 * counts: another visit does not change them by its number. COUNT(DISTINCT
 * click_id) is read as a count, the loud direction.
 *
 * What it does not read: a condition held in a variable or appended in
 * another statement, which reads as missing (most of the listed sites);
 * a count added to a fragment in another statement (ClickRollupSql's FROM
 * clauses); a table named at runtime; and SQL not spelled in PHP strings. A
 * statement is keyed by the function it is in, as the sibling keys it: a
 * closure is the function around it, and code outside any named function,
 * or in one whose name is a keyword (`list`), is {main}.
 */
final class ClickRowCountsTakeTheLatestVisitTest extends TestCase
{
    /**
     * file::function => [how many statements it makes that count 202_clicks
     * rows with no isLatest() ANDed for each, why each counts a click once
     * anyway or counts its visits on purpose]. The count is held exactly.
     */
    private const COUNTS_EVERY_VISIT = [
        '202-config/Attribution/AttributionReports.php::creditsBy' => [1, 'isLatest(\'c\') is held in $latest and'
            . ' interpolated after AND.'],
        '202-config/Attribution/AttributionReports.php::assistsBy' => [1, 'isLatest(\'c\') is held in $latest and'
            . ' interpolated after AND.'],
        '202-config/Attribution/AttributionRollup.php::buildHours' => [4, 'Each of the four holds isLatest(\'c\') in'
            . ' $latest, interpolated after AND.'],
        '202-config/Click/ClickRetention.php::{main}' => [1, 'COUNT_EXPIRED: COUNT(DISTINCT c.click_id) of the clicks'
            . ' every visit of which is before the cutoff, each once.'],
        '202-config/Click/ClickRetention.php::countOlderThan' => [2, 'COUNT_EXPIRED, each click once; and the rows of'
            . ' each click table the expired clicks own, which the deletion removes, every visit\'s on purpose.'],
        '202-config/Click/ClickRetention.php::clicksOlderThan' => [1, 'COUNT_EXPIRED, each click once.'],
        '202-config/Click/ReClickBackfill.php::reClickedClicks' => [1, 'Finds the re-clicked clicks by counting each'
            . ' click\'s visits (HAVING COUNT(*) > 1): it counts visits on purpose.'],
        '202-config/Ltv/MysqlLtvRepository.php::breakdown' => [1, 'The spend sum\'s isLatest(\'sc\') is held in'
            . ' $spendWhere.'],
        'api/legacy/functions.php::reportQuery' => [1, 'Each group\'s figures append isLatest(\'2c\'), held in'
            . ' $latest, in a later statement.'],
        'api/v3/Apps/Android/InstallReport.php::installGroups' => [2, 'The WHERE is buildFilters()\' list, whose'
            . ' second condition is isLatest(\'c\'), joined with AND.'],
        'api/v3/Apps/Android/InstallReport.php::goalGroups' => [1, 'The WHERE is buildFilters()\' list, whose'
            . ' second condition is isLatest(\'c\'), joined with AND.'],
        'api/v3/Apps/Android/InstallReport.php::totals' => [2, 'The WHERE is buildFilters()\' list, whose second'
            . ' condition is isLatest(\'c\'), joined with AND.'],
        'api/v3/Controllers/AdministrationController.php::retentionState' => [1, 'COUNT(DISTINCT click_id): each'
            . ' click once.'],
        'api/v3/Controllers/ClicksController.php::{main}' => [1, 'list() (a keyword, so read as {main}): GET /clicks'
            . ' counts the rows it lists, and it lists each visit, as documented.'],
    ];

    /** The list only shrinks: raise this only with a reason a reviewer accepts. */
    private const CEILING = 14;

    private const COUNTS = '/(?<![\w\x00`.])(?:SUM|COUNT|AVG)\s*\(/i';

    /** @var array<string, list<array{line: int, function: string, sql: string}>>|null */
    private static ?array $tree = null;

    public function testEveryCountOfClickRowsTakesTheLatestVisitOrIsListed(): void
    {
        $unlisted = [];
        foreach (self::tree() as $path => $counts) {
            foreach ($counts as $count) {
                if (!isset(self::COUNTS_EVERY_VISIT[$path . '::' . $count['function']])) {
                    $unlisted[] = $path . ':' . $count['line'] . ' (' . $count['function'] . '): ' . $count['sql'];
                }
            }
        }

        self::assertSame(
            [],
            $unlisted,
            "A statement that counts 202_clicks rows counts a re-clicked click at each of its visits. AND"
                . " LatestVisit::isLatest() into it for each 202_clicks it reads, or list its function in"
                . " COUNTS_EVERY_VISIT with why each visit should count:\n" . implode("\n", $unlisted)
        );
    }

    public function testEachListedFunctionMakesTheCountsItWeighs(): void
    {
        $made = [];
        foreach (self::tree() as $path => $counts) {
            foreach ($counts as $count) {
                $site = $path . '::' . $count['function'];
                $made[$site] = ($made[$site] ?? 0) + 1;
            }
        }
        $wrong = [];
        foreach (self::COUNTS_EVERY_VISIT as $site => [$listed]) {
            if (($made[$site] ?? 0) !== $listed) {
                $wrong[] = $site . ': listed ' . $listed . ', makes ' . ($made[$site] ?? 0);
            }
        }

        self::assertSame(
            [],
            $wrong,
            "Each listed function's count is held exactly: one that makes fewer, or none, comes off the list or"
                . " down, and one that makes more lists its new statement with why:\n" . implode("\n", $wrong)
        );
    }

    public function testTheListOnlyShrinks(): void
    {
        self::assertLessThanOrEqual(self::CEILING, count(self::COUNTS_EVERY_VISIT));
    }

    /**
     * @dataProvider spellings
     * @param list<string> $expected "line function" for each statement the scan reports
     */
    public function testTheScanReadsEachSpelling(string $source, array $expected): void
    {
        self::assertSame($expected, array_map(
            static fn (array $count): string => $count['line'] . ' ' . $count['function'],
            self::counts($source)
        ));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function spellings(): iterable
    {
        $use = "<?php\nuse Prosper202\\Click\\LatestVisit;\n";
        $reported = static fn (string $code): array => [$use . $code, ['3 {main}']];
        $none = static fn (string $code): array => [$use . $code, []];
        $q = static fn (string $sql): string => "\$s = '" . $sql . "';";
        $latest = static fn (string $head, string $alias, string $tail = ''): string => "\$s = '" . $head
            . "' . LatestVisit::isLatest('" . $alias . "')" . ($tail === '' ? '' : " . '" . $tail . "'") . ';';

        yield 'a count' => $reported($q('SELECT COUNT(*) FROM 202_clicks WHERE user_id = ?'));
        yield 'a sum, aliased' => $reported($q('SELECT SUM(c.click_cpc) FROM 202_clicks AS c WHERE c.user_id = ?'));
        yield 'an average, lower case'
            => $reported($q('select avg(c.click_cpc) from 202_clicks c where c.user_id = ?'));
        yield 'a space before the parenthesis' => $reported($q('SELECT COUNT (*) FROM 202_clicks'));
        yield 'backticks and a database' => $reported($q('SELECT SUM(c.click_cpc) FROM `p202`.`202_clicks` AS c'));
        yield 'joined' => $reported($q('SELECT COUNT(*) FROM 202_clicks_tracking t JOIN 202_clicks c'
            . ' ON c.click_id = t.click_id WHERE t.customer_id = ?'));
        yield 'in a subquery' => $reported($q('SELECT x.n FROM (SELECT COUNT(*) AS n FROM 202_clicks c'
            . ' WHERE c.user_id = ?) AS x'));
        yield 'counted distinct' => $reported($q('SELECT COUNT(DISTINCT click_id) FROM 202_clicks'));
        yield 'an insert from a count' => $reported($q('INSERT INTO t (n) SELECT COUNT(*) FROM 202_clicks c'
            . ' WHERE c.user_id = ?'));
        yield 'held in a variable (not read)' => [
            $use . "\$latest = LatestVisit::isLatest('c');\n"
                . "\$s = \"SELECT SUM(c.click_cpc) FROM 202_clicks c WHERE {\$latest}\";",
            ['4 {main}'],
        ];

        yield 'the latest visit' => $none($latest('SELECT COUNT(*) FROM 202_clicks c WHERE c.user_id = ? AND ', 'c'));
        yield 'the latest visit, right after WHERE' => $none(
            $latest('SELECT SUM(c.click_cpc) FROM 202_clicks c WHERE ', 'c', ' AND c.user_id = ?')
        );
        yield 'the latest visit in the ON' => $none($latest(
            'SELECT COUNT(*) FROM 202_clicks_tracking t JOIN 202_clicks c ON c.click_id = t.click_id AND ',
            'c'
        ));
        yield 'each of two read, each latest' => $none(
            "\$s = 'SELECT COUNT(*) FROM 202_clicks a JOIN 202_clicks b ON b.user_id = a.user_id WHERE '"
            . " . LatestVisit::isLatest('a') . ' AND ' . LatestVisit::isLatest('b');"
        );
        yield 'one of two read latest' => [$use . "\$s = 'SELECT COUNT(*) FROM 202_clicks a JOIN 202_clicks b"
            . " ON b.user_id = a.user_id WHERE ' . LatestVisit::isLatest('a');", ['3 {main}']];
        yield 'the latest visit of another alias' => $reported(
            $latest('SELECT COUNT(*) FROM 202_clicks c JOIN 202_clicks_record r USING (click_id) WHERE ', 'r')
        );
        yield 'the latest visit or another condition' => $reported(
            $latest('SELECT COUNT(*) FROM 202_clicks c WHERE ', 'c', ' OR c.click_lead = 1')
        );
        yield 'the latest visit under NOT' => $reported(
            $latest('SELECT COUNT(*) FROM 202_clicks c WHERE c.user_id = ? AND NOT ', 'c')
        );
        yield 'isLatest of another class of that name' => [
            "<?php\n\$s = 'SELECT COUNT(*) FROM 202_clicks c WHERE ' . \\Other\\LatestVisit::isLatest('c');",
            ['2 {main}'],
        ];

        yield 'the first and last time' => $none($q('SELECT MIN(click_time), MAX(click_time) FROM 202_clicks'));
        yield 'no count' => $none($q('SELECT c.click_id FROM 202_clicks c WHERE c.user_id = ?'));
        yield 'a column, not a count' => $none($q('SELECT c.account_count FROM 202_clicks c WHERE c.user_id = ?'));
        yield 'a function that ends in a count\'s name' => $none($q('SELECT DISCOUNT(c.click_cpc) FROM 202_clicks c'));
        yield 'another click table' => $none($q('SELECT COUNT(*) FROM 202_clicks_advance WHERE keyword_id = ?'));
        yield 'one click (SingleClickReadsNameTheirVisitTest\'s)' => $none(
            $q('SELECT COUNT(*) FROM 202_clicks WHERE click_id = ?')
        );
        yield 'a delete\'s target' => $none($q('DELETE FROM 202_clicks WHERE click_id IN (SELECT click_id FROM t'
            . ' GROUP BY click_id HAVING COUNT(*) > 1)'));

        yield 'in a function' => [
            "<?php\nfunction totals(): string\n{\n    return 'SELECT SUM(click_cpc) FROM 202_clicks';\n}\n",
            ['4 totals'],
        ];
        yield 'in a closure, the function around it' => ["<?php\nfunction outer(): void\n{\n    \$f = function () {\n"
            . "        return 'SELECT COUNT(*) FROM 202_clicks';\n    };\n}\n", ['5 outer']];
    }

    /**
     * Each statement in $source that counts 202_clicks rows without
     * isLatest() ANDed for each 202_clicks it reads: its line, the function it
     * is in, and its text.
     *
     * @return list<array{line: int, function: string, sql: string}>
     */
    public static function counts(string $source): array
    {
        $found = [];
        $functions = null;
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text, 'calls' => $calls]) {
            if (preg_match(self::COUNTS, $text) !== 1) {
                continue;
            }
            $clicks = SingleClickReadsNameTheirVisitTest::tablesRead($text)['clicks'];
            if ($clicks === [] || SingleClickReadsNameTheirVisitTest::readsOneClick($text, $calls)) {
                continue;
            }
            $latest = [];
            foreach ($calls as $call) {
                if (
                    strtolower($call['class']) === 'prosper202\click\latestvisit'
                    && strtolower($call['method']) === 'islatest'
                    && SingleClickReadsNameTheirVisitTest::isConjunct($text, $call['offset'])
                ) {
                    $latest[] = $call['args'][0] ?? null;
                }
            }
            if (array_diff($clicks, $latest) === []) {
                continue;
            }
            $functions ??= SingleClickReadsNameTheirVisitTest::functionRanges($source);
            $found[] = [
                'line' => $line,
                'function' => SingleClickReadsNameTheirVisitTest::functionAt($functions, $line),
                'sql' => SingleClickReadsNameTheirVisitTest::folded($text),
            ];
        }

        return $found;
    }

    /** @return array<string, list<array{line: int, function: string, sql: string}>> */
    private static function tree(): array
    {
        if (self::$tree === null) {
            self::$tree = [];
            foreach (SourceScan::phpFiles() as $path => $source) {
                $counts = self::counts($source);
                if ($counts !== []) {
                    self::$tree[$path] = $counts;
                }
            }
        }

        return self::$tree;
    }
}
