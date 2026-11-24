<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\AdClickIdColumns;
use Prosper202\Click\ClickRecord;
use Tests\Support\SourceScan;

/**
 * 202_google's key is (click_id, gclid), so a click can have more than one
 * row, and a join to it repeats the click once for each: counted twice in
 * the reports, two pixels fired, two lines in the Visitors download, a token
 * filled from whichever row came first. A click's values there are read one
 * per click with AdClickIdColumns::sql() (and utm()).
 *
 * Every place the served tree names the table is one of: a write
 * (INSERT INTO), its definition (CREATE TABLE, ALTER TABLE), or a read of
 * that table alone (FROM 202_google [AS x] [FORCE INDEX (…)] WHERE …) that
 * is not a derived table; anything else is listed in NAMED with why. The
 * question is asked of what PHP assembles: each statement's string literals,
 * heredocs and markup joined across `.`, and TableRegistry::GOOGLE read as
 * the name. A name built another way (a variable) is not seen.
 */
final class GoogleRowsAreNotJoinedTest extends TestCase
{
    /**
     * file => [how many of its namings are none of the accepted shapes, why],
     * held exactly.
     *
     * @var array<string, array{int, string}>
     */
    private const NAMED = [
        '202-config/Database/Schema/TableRegistry.php' => [1, 'the name itself (TableRegistry::GOOGLE, read as the name)'],
        '202-config/Database/Tables/TrackingTables.php' => [1, 'google(): the name its definition is registered under'],
        '202-config/Click/ClickRetention.php' => [1, 'TABLES: its rows are deleted with their click'],
        '202-config/Click/MysqlClickRepository.php' => [1, 'the error that names the columns the upgrade has not added'],
        '202-config/Click/AdClickIdIndex.php' => [1, 'backfillSql(): one index row for each id of each of a click\'s'
            . ' rows, which INSERT IGNORE folds; a second row\'s ids are meant to be read'],
    ];

    private const NAME = '/(?<![A-Za-z0-9_])202_google(?![A-Za-z0-9_])/i';

    public function testTheServedTreeNamesTheTableOnlyInTheAcceptedShapes(): void
    {
        $counts = [];
        $unlisted = [];
        $seen = 0;
        foreach (SourceScan::phpFiles() as $file => $source) {
            foreach (self::namings($source) as [$line, $accepted, $context]) {
                $seen++;
                if ($accepted) {
                    continue;
                }
                $counts[$file] = ($counts[$file] ?? 0) + 1;
                if (!isset(self::NAMED[$file])) {
                    $unlisted[] = "$file:$line: …{$context}…";
                }
            }
        }

        self::assertGreaterThanOrEqual(10, $seen, 'the scan finds the places that name 202_google');
        self::assertSame(
            [],
            $unlisted,
            'A statement joins 202_google, or names it in a shape that is not a write, its definition or a'
            . ' read of it alone: a click with two rows there is repeated. Read its values with'
            . ' AdClickIdColumns::sql() or utm(), or list the file in NAMED with why.'
        );
        $listed = array_map(static fn (array $entry): int => $entry[0], self::NAMED);
        ksort($listed);
        ksort($counts);
        self::assertSame($listed, $counts, 'NAMED holds each file\'s count exactly: lower it when one goes');
    }

    /** @return iterable<string, array{string}> */
    public static function joins(): iterable
    {
        yield 'LEFT JOIN' => ['<?php $s = "SELECT c.click_id FROM 202_clicks AS c LEFT JOIN 202_google AS g ON g.click_id = c.click_id WHERE 1";'];
        yield 'LEFT OUTER JOIN, lower case' => ['<?php $s = "select 1 from 202_clicks c left outer join 202_google g on (c.click_id = g.click_id)";'];
        yield 'INNER JOIN' => ['<?php $s = "SELECT 1 FROM 202_clicks c INNER JOIN 202_google g ON g.click_id = c.click_id WHERE 1";'];
        yield 'bare JOIN, no alias' => ['<?php $s = "SELECT 1 FROM 202_clicks c JOIN 202_google USING (click_id) WHERE 1";'];
        yield 'STRAIGHT_JOIN' => ['<?php $s = "SELECT 1 FROM 202_clicks c STRAIGHT_JOIN 202_google g ON g.click_id = c.click_id";'];
        yield 'backticks' => ['<?php $s = "SELECT 1 FROM 202_clicks AS 2c LEFT JOIN `202_google` AS 2g USING (`click_id`)";'];
        yield 'upper case name' => ['<?php $s = "SELECT 1 FROM 202_clicks c LEFT JOIN 202_GOOGLE g ON g.click_id = c.click_id";'];
        yield 'a comma join after' => ['<?php $s = "SELECT 1 FROM 202_clicks AS c, 202_google AS g WHERE g.click_id = c.click_id";'];
        yield 'a comma join before' => ['<?php $s = "SELECT 1 FROM 202_google AS g, 202_clicks AS c WHERE g.click_id = c.click_id";'];
        yield 'the driving table of a join' => ['<?php $s = "SELECT 1 FROM 202_google AS g JOIN 202_clicks AS c ON c.click_id = g.click_id WHERE 1";'];
        yield 'a joined derived table' => ['<?php $s = "SELECT 1 FROM 202_clicks c LEFT JOIN (SELECT click_id, gclid FROM 202_google WHERE 1) AS g ON g.click_id = c.click_id";'];
        yield 'a derived table in parentheses' => ['<?php $s = "SELECT 1 FROM 202_clicks c LEFT JOIN ((SELECT click_id FROM 202_google AS x WHERE 1)) AS g ON 1";'];
        yield 'a derived table after a comma' => ['<?php $s = "SELECT 1 FROM 202_clicks c, (SELECT click_id FROM 202_google WHERE 1) AS g WHERE g.click_id = c.click_id";'];
        yield 'a derived table driving' => ['<?php $s = "SELECT 1 FROM (SELECT click_id FROM 202_google WHERE 1) AS g JOIN 202_clicks c ON 1";'];
        yield 'joined across .' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c LEFT JOIN 202_' . 'google AS g ON g.click_id = c.click_id';"];
        yield 'across . and quotes' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c LEFT JOIN ' . \"202_google\" . ' AS g ON 1';"];
        yield 'across lines' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c'\n    . ' LEFT JOIN 202_google AS g'\n    . ' ON g.click_id = c.click_id';"];
        yield 'interpolated' => ['<?php $s = "SELECT 1 FROM 202_clicks c LEFT JOIN 202_google AS g ON g.click_id = $id";'];
        yield 'a heredoc' => ["<?php \$s = <<<SQL\nSELECT 1 FROM 202_clicks c\nLEFT JOIN 202_google AS g ON g.click_id = {\$id}\nSQL;"];
        yield 'a nowdoc' => ["<?php \$s = <<<'SQL'\nSELECT 1 FROM 202_clicks c\nLEFT OUTER JOIN 202_google AS 2gg ON (c.click_id = 2gg.click_id)\nSQL;"];
        yield 'TableRegistry::GOOGLE' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c LEFT JOIN ' . TableRegistry::GOOGLE . ' AS g ON 1';"];
        yield 'TableRegistry::GOOGLE, qualified' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c JOIN `' . \\Prosper202\\Database\\Schema\\TableRegistry::GOOGLE . '` g ON 1';"];
        yield 'a read of every row' => ['<?php $s = "SELECT click_id, gclid FROM 202_google";'];
        yield 'markup' => ["<?php ?>\nSELECT 1 FROM 202_clicks c LEFT JOIN 202_google g ON 1\n"];
    }

    /** @dataProvider joins */
    public function testTheScanRefusesEachSpellingOfAJoin(string $source): void
    {
        $namings = self::namings($source);
        self::assertCount(1, $namings, 'the scan sees the one naming');
        self::assertFalse($namings[0][1], 'refused: ' . $namings[0][2]);
    }

    /** @return iterable<string, array{string}> */
    public static function reads(): iterable
    {
        yield 'a read of the click\'s rows' => ["<?php \$s = 'SELECT MAX(gclid) AS gclid FROM 202_google WHERE click_id = ?';"];
        yield 'with an alias and an index' => ['<?php $s = "SELECT 1 FROM `202_google` AS g FORCE INDEX (PRIMARY) WHERE g.click_id = 1";'];
        yield 'a scalar subquery in a select list' => ['<?php $s = "SELECT c.click_id, (SELECT MAX(g.gclid) FROM 202_google AS g WHERE g.click_id = c.click_id) AS gclid FROM 202_clicks c";'];
        yield 'EXISTS' => ['<?php $s = "SELECT 1 FROM 202_clicks c JOIN 202_clicks_record cr ON 1 WHERE EXISTS (SELECT 1 FROM 202_google AS g WHERE g.click_id = c.click_id)";'];
        yield 'IN' => ['<?php $s = "SELECT 1 FROM 202_clicks c WHERE c.click_id IN (SELECT g.click_id FROM 202_google g WHERE g.gclid = ?)";'];
        yield 'the writer' => ["<?php \$s = 'INSERT INTO 202_google (click_id, gclid) VALUES (?, ?)';"];
        yield 'INSERT IGNORE' => ["<?php \$s = 'INSERT IGNORE INTO `202_google` (click_id) VALUES (1)';"];
        yield 'its definition' => ["<?php \$s = 'CREATE TABLE IF NOT EXISTS `' . TableRegistry::GOOGLE . '` (click_id bigint)';"];
        yield 'ALTER TABLE' => ["<?php \$s = 'ALTER TABLE 202_google MODIFY gclid VARCHAR(150)';"];
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $id) {
            yield "sql('$id')" => ['<?php $s = ' . var_export('SELECT ' . AdClickIdColumns::sql($id, 'c.click_id') . ' AS x FROM 202_clicks AS c LEFT JOIN 202_clicks_site cs ON 1', true) . ';'];
        }
        foreach (array_keys(AdClickIdColumns::UTM_IDS) as $name) {
            yield "utm('$name')" => ['<?php $s = ' . var_export('SELECT ' . AdClickIdColumns::utm($name, 'c.click_id') . ' AS x FROM 202_clicks AS c', true) . ';'];
        }
    }

    /** @dataProvider reads */
    public function testTheScanAcceptsAReadOfTheTableAlone(string $source): void
    {
        $namings = self::namings($source);
        self::assertCount(1, $namings, 'the scan sees the one naming');
        self::assertTrue($namings[0][1], 'accepted: ' . $namings[0][2]);
    }

    public function testACommentNamesNothing(): void
    {
        self::assertSame([], self::namings("<?php\n// LEFT JOIN 202_google AS g\n/* JOIN 202_google */\n\$a = 1;"));
    }

    /** sql() and utm() refuse what is not a column of 202_google a click keeps, before it reaches the SQL. */
    public function testSqlAndUtmNameOnlyTheColumnsAClickKeeps(): void
    {
        foreach (AdClickIdColumns::UTM_IDS as $name => $id) {
            self::assertStringContainsString("MAX(g_ids.$id)", AdClickIdColumns::sql($id, 'c.click_id'));
            self::assertStringContainsString("FROM 202_$name AS utm_v", AdClickIdColumns::utm($name, 'c.click_id'));
        }
        foreach ([['click_id', 'c.click_id'], ['utm_source', 'c.click_id'], ['gclid', 'c.click_id) OR (1']] as [$id, $column]) {
            try {
                AdClickIdColumns::sql($id, $column);
                self::fail("sql('$id', '$column') was accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($id === 'gclid' ? 'is not a column' : 'is not an ad click id', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('utm_source_id is not a UTM value a click keeps');
        AdClickIdColumns::utm('utm_source_id', 'c.click_id');
    }

    /**
     * Each naming of the table in a source: its line, whether it is one of
     * the accepted shapes, and the text around it.
     *
     * @return list<array{int, bool, string}>
     */
    private static function namings(string $source): array
    {
        $namings = [];
        foreach (self::statements($source) as [$line, $text]) {
            $text = str_replace('`', '', $text);
            if (preg_match_all(self::NAME, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($matches[0] as [$name, $at]) {
                $namings[] = [
                    $line,
                    self::accepted($text, $at, strlen($name)),
                    preg_replace('/\s+/', ' ', substr($text, max(0, $at - 60), 120 + strlen($name))),
                ];
            }
        }

        return $namings;
    }

    private static function accepted(string $text, int $at, int $length): bool
    {
        $before = substr($text, 0, $at);
        $after = substr($text, $at + $length);
        if (preg_match('/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE)\s*\z/i', $before) === 1) {
            return true;
        }
        if (preg_match('/\bFROM\s*\z/i', $before) !== 1) {
            return false;
        }
        $alone = '/\A(?:\s+(?:AS\s+)?(?!(?:WHERE|FORCE|USE|IGNORE|JOIN|LEFT|RIGHT|INNER|STRAIGHT_JOIN|CROSS|NATURAL)\b)[A-Za-z0-9_]+)?'
            . '(?:\s+(?:FORCE|USE)\s+INDEX\s*\([^()]*\))?\s+WHERE\b/i';
        if (preg_match($alone, $after) !== 1) {
            return false;
        }

        return !self::inDerivedTable($before);
    }

    /** Whether the parentheses that hold the read are a table of an outer FROM or JOIN. */
    private static function inDerivedTable(string $before): bool
    {
        $open = self::unclosedParen($before, strlen($before));
        while ($open !== null) {
            $head = rtrim(substr($before, 0, $open));
            if (str_ends_with($head, '(')) {
                // ((SELECT …)): the outer parenthesis decides.
                $open = strlen($head) - 1;
                continue;
            }
            if (preg_match('/\b(?:JOIN|FROM)\z/i', $head) === 1) {
                return true;
            }
            if (!str_ends_with($head, ',')) {
                return false;
            }
            // After a comma: a select list's, or a FROM list's.
            return self::nearestClause($head) === 'FROM';
        }

        return false;
    }

    /** The offset of the innermost `(` before $end that is not closed before it. */
    private static function unclosedParen(string $text, int $end): ?int
    {
        $depth = 0;
        for ($i = $end - 1; $i >= 0; $i--) {
            if ($text[$i] === ')') {
                $depth++;
            } elseif ($text[$i] === '(') {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            }
        }

        return null;
    }

    /** The clause keyword nearest before the end of $text at its own depth, JOIN read as FROM. */
    private static function nearestClause(string $text): ?string
    {
        $depth = 0;
        $words = preg_split('/(\(|\)|\s+|,)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        for ($i = count($words) - 1; $i >= 0; $i--) {
            $word = strtoupper($words[$i]);
            if ($word === ')') {
                $depth++;
            } elseif ($word === '(') {
                if ($depth === 0) {
                    return null;
                }
                $depth--;
            } elseif ($depth === 0 && in_array($word, ['SELECT', 'FROM', 'JOIN', 'STRAIGHT_JOIN', 'WHERE', 'ON', 'HAVING', 'BY', 'SET', 'VALUES', 'USING'], true)) {
                return in_array($word, ['JOIN', 'STRAIGHT_JOIN'], true) ? 'FROM' : $word;
            }
        }

        return null;
    }

    /**
     * The statements of a source, each as its first line and the text PHP
     * assembles from its string literals, heredocs and markup: pieces joined
     * by `.` alone are joined as one, others with a space between, and
     * TableRegistry::GOOGLE is the table's name.
     *
     * @return list<array{int, string}>
     */
    private static function statements(string $source): array
    {
        $statements = [];
        $text = '';
        $line = null;
        $glued = false;
        $close = static function () use (&$statements, &$text, &$line, &$glued): void {
            if ($text !== '') {
                $statements[] = [(int) $line, $text];
            }
            $text = '';
            $line = null;
            $glued = false;
        };
        $piece = static function (string $value, int $at) use (&$text, &$line, &$glued): void {
            $line ??= $at;
            $text .= ($glued ? '' : ' ') . $value;
            $glued = true;
        };
        $tokens = token_get_all($source);
        $interpolations = 0;
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                if ($token === '}' && $interpolations > 0) {
                    $interpolations--;
                    $glued = false;
                } elseif ($token === ';' || $token === '{' || $token === '}') {
                    $close();
                } elseif ($token !== '.' && $token !== '"') {
                    $glued = false;
                }
                continue;
            }
            [$id, $value, $at] = $token;
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_START_HEREDOC, T_END_HEREDOC], true)) {
                continue;
            }
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $interpolations++;
                $glued = false;
                continue;
            }
            if ($id === T_INLINE_HTML) {
                $close();
                $statements[] = [$at, $value];
                continue;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $piece(substr($value, 1, -1), $at);
                continue;
            }
            if ($id === T_ENCAPSED_AND_WHITESPACE) {
                $piece($value, $at);
                continue;
            }
            if (
                $id === T_STRING && $value === 'GOOGLE'
                && is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_DOUBLE_COLON
                && is_array($tokens[$i - 2] ?? null) && str_ends_with($tokens[$i - 2][1], 'TableRegistry')
            ) {
                $piece('202_google', $at);
                continue;
            }
            // The class and the `::` before a constant leave the glue as it was, for GOOGLE above.
            if ($id === T_DOUBLE_COLON || str_ends_with('\\' . $value, '\\TableRegistry')) {
                continue;
            }
            $glued = false;
        }
        $close();

        return $statements;
    }
}
