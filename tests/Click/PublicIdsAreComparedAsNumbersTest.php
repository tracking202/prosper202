<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * No PHP file of the application compares a public id column with a quoted
 * value. The columns are integers, so MySQL casts the text first: `12abc`
 * named record 12 and `1e3` record 1000, and a value the request sent
 * reached another record than the one it spelled. The ids are read exactly
 * (PublicId, ClickId) and compared as numbers: bound, or an int put in the
 * statement.
 *
 * Read per string literal of every file SourceScan::phpFiles() gives (tests
 * excluded): a column ending in `_public` or named `public_id`, bare or in
 * backticks, then a comparison (`=`, `!=`, `<>`, `<=>`, `IN (`, `LIKE`, with
 * or without BINARY), then a quote. It does not follow a value into a
 * variable first (`$sql = "… = '" . $id . "'"` is seen, `$col . "='"` is
 * not). One text column has such a name (export_session_id_public), and
 * nothing compares it; a statement that does binds it.
 *
 * An `=` that assigns is not a comparison: the column right after SET, or
 * after SET and `column = value` items each ended by a comma. That is read
 * from the literals of the same PHP statement, any other PHP token (an array
 * key's literal included) standing for a value it cannot see. Whatever the
 * reading cannot place — an item it cannot read, a SET in an earlier
 * statement — is a comparison: the loud direction.
 */
final class PublicIdsAreComparedAsNumbersTest extends TestCase
{
    private const QUOTED_COMPARE = '/\b\w*(?:_public|public_id)`?\s*(?<op><=>|[!<>]?=|<>|\bIN\s*\(|\bLIKE\b)\s*'
        . '(?:BINARY\s+)?\\\\?[\'"]/i';

    /** Stands in, in a statement's SQL, for a PHP token that is not a literal of it. */
    private const UNREAD = "\x00";

    private const SQL_TOKEN = '/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`|\w+|\S/s';

    public function testNoPublicIdIsComparedAsText(): void
    {
        $found = [];
        $scanned = 0;
        foreach (SourceScan::phpFiles() as $file => $source) {
            $scanned++;
            foreach (self::quotedCompares($source) as $line) {
                $found[] = "$file:$line";
            }
        }

        self::assertGreaterThan(500, $scanned, 'the application\'s files were read, not the click path\'s alone');
        self::assertSame(
            [],
            $found,
            "Compare a public id as a number, read with PublicId::parse() or ClickId::parse():\n  "
                . implode("\n  ", $found),
        );
    }

    /** @return iterable<string, array{string, list<int>}> */
    public static function spellings(): iterable
    {
        yield 'concatenated, single quotes' => [
            "<?php \$s = \"SELECT 1 FROM t WHERE click_id_public='\" . \$p . \"'\";",
            [1],
        ];
        yield 'spaced' => [
            "<?php \$s = \"SELECT 1 FROM t WHERE x.landing_page_id_public = '\" . \$p . \"'\";",
            [1],
        ];
        yield 'interpolated' => ["<?php \$s = \"SELECT 1 FROM t WHERE tracker_id_public='{\$p}'\";", [1]];
        yield 'public_id' => ["<?php \$s = \"SELECT 1 FROM 202_rotators WHERE public_id='\" . \$p . \"'\";", [1]];
        yield 'double quotes in a single-quoted string' => [
            "<?php \$s = 'SELECT 1 FROM t WHERE aff_campaign_id_public=\"' . \$p . '\"';",
            [1],
        ];
        yield 'heredoc, third line' => ["<?php \$s = <<<SQL\nSELECT 1\nWHERE click_id_public = '\$p'\nSQL;\n", [3]];
        yield 'upper case' => ["<?php \$s = \"SELECT 1 FROM t WHERE CLICK_ID_PUBLIC='\" . \$p . \"'\";", [1]];
        yield 'not equal' => ["<?php \$s = \"SELECT 1 FROM t WHERE click_id_public != '\" . \$p . \"'\";", [1]];
        yield 'not equal, SQL spelling' => ["<?php \$s = \"SELECT 1 FROM t WHERE click_id_public<>'0'\";", [1]];
        yield 'null-safe' => ["<?php \$s = \"SELECT 1 FROM t WHERE click_id_public <=> '\" . \$p . \"'\";", [1]];
        yield 'a list' => ["<?php \$s = \"SELECT 1 FROM t WHERE click_id_public IN ('\" . \$p . \"')\";", [1]];
        yield 'binary' => ["<?php \$s = \"SELECT 1 FROM t WHERE click_id_public = BINARY '\" . \$p . \"'\";", [1]];
        yield 'like' => ["<?php \$s = \"SELECT 1 FROM t WHERE click_id_public LIKE '\" . \$p . \"'\";", [1]];
        yield 'in backticks' => ["<?php \$s = \"SELECT 1 FROM t WHERE `click_id_public` = '\" . \$p . \"'\";", [1]];
        yield 'in backticks, qualified' => [
            "<?php \$s = \"SELECT 1 FROM t WHERE `t`.`tracker_id_public`='\" . \$p . \"'\";",
            [1],
        ];
        yield 'a list of ints' => ["<?php \$s = 'SELECT 1 FROM t WHERE click_id_public IN (' . \$ids . ')';", []];
        yield 'bound' => ["<?php \$s = 'SELECT 1 FROM t WHERE click_id_public = ?';", []];
        yield 'an int in the statement' => ["<?php \$s = \"SELECT 1 FROM t WHERE click_id_public=\" . \$p;", []];
        yield 'a PHP variable, not SQL' => ["<?php \$click_id_public = '';", []];
        yield 'another column' => ["<?php \$s = \"SELECT 1 FROM t WHERE click_id='\" . \$p . \"'\";", []];

        // An `=` that assigns.
        yield 'assigned after SET' => [
            "<?php f(\"UPDATE 202_rotators SET public_id = '\" . g(1, 9) . \"' WHERE id = '\" . \$id . \"'\");",
            [],
        ];
        yield 'assigned after SET, qualified, in backticks' => [
            "<?php \$s = \"UPDATE t SET `t`.`tracker_id_public`='\" . \$p . \"'\";",
            [],
        ];
        yield 'assigned in a SET list, over lines' => [
            "<?php \$s = \"INSERT INTO t\n SET `a`='\" . \$m['a'] . \"',\n b = NOW(), c = -1.5, d = NULL,\n"
                . " `tracker_id_public`='0'\";",
            [],
        ];
        yield 'assigned after a value read from an array' => [
            "<?php \$s = \"UPDATE t SET a = \" . \$m['a'] . \", click_id_public = '0'\";",
            [],
        ];
        yield 'only = assigns' => ["<?php \$s = \"UPDATE t SET click_id_public <=> '\" . \$p . \"'\";", [1]];
        yield 'assigned, then compared' => [
            "<?php \$s = \"UPDATE t SET click_id_public = '1' WHERE click_id_public = '\" . \$p . \"'\";",
            [1],
        ];
        yield 'compared inside a SET value' => [
            "<?php \$s = \"UPDATE t SET a = '1' AND click_id_public = '\" . \$p . \"'\";",
            [1],
        ];
        yield 'compared in a subquery inside a SET value' => [
            "<?php \$s = \"UPDATE t SET a = (SELECT 1 FROM u WHERE click_id_public = '\" . \$p . \"')\";",
            [1],
        ];
        yield 'compared in a function inside a SET value' => [
            "<?php \$s = \"UPDATE t SET a = IF(click_id_public = '\" . \$p . \"', 1, 0)\";",
            [1],
        ];
        yield 'compared in a select list, after a comma' => [
            "<?php \$s = \"SELECT a, click_id_public = '\" . \$p . \"' AS f FROM t\";",
            [1],
        ];
        yield 'a SET item it cannot read' => [
            "<?php \$s = \"UPDATE t SET \" . \$cols . \" click_id_public = '\" . \$p . \"'\";",
            [1],
        ];
        yield 'a SET value it cannot read' => [
            "<?php \$s = \"UPDATE t SET a = b, click_id_public = '\" . \$p . \"'\";",
            [1],
        ];
        yield 'SET in an earlier statement' => [
            "<?php \$s = 'UPDATE t SET '; \$s .= \"click_id_public = '\" . \$p . \"'\";",
            [1],
        ];
    }

    /**
     * @dataProvider spellings
     * @param list<int> $lines
     */
    public function testTheScanReadsEverySpellingItClaims(string $php, array $lines): void
    {
        self::assertSame($lines, self::quotedCompares($php));
    }

    /** @return list<int> the lines of the string literals that compare a public id with a quote */
    private static function quotedCompares(string $source): array
    {
        $lines = [];
        $statement = '';
        $previous = null;
        foreach (token_get_all($source) as $token) {
            $layout = !is_array($token)
                ? in_array($token, ['.', '"'], true)
                : in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_START_HEREDOC, T_END_HEREDOC], true);
            if ($layout) {
                continue;
            }
            $literal = is_array($token)
                && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true);
            if (!$literal) {
                $statement = $token === ';' ? '' : $statement . self::UNREAD;
                $previous = $token;
                continue;
            }
            // A quoted literal's own quotes are PHP's, not the statement's.
            $text = $token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1];
            $singleQuoted = $token[0] === T_CONSTANT_ENCAPSED_STRING && $token[1][0] === "'";
            $offset = 0;
            while (preg_match(self::QUOTED_COMPARE, $text, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $offset = $m[0][1] + strlen($m[0][0]);
                $before = $statement . self::unescaped(substr($text, 0, $m[0][1]), $singleQuoted);
                if ($m['op'][0] === '=' && self::assignedInASetList($before)) {
                    continue;
                }
                $lines[] = $token[2] + substr_count(substr($text, 0, $m[0][1]), "\n");
            }
            // An array key is a PHP value, not a part of the statement.
            $statement .= $previous === '[' ? self::UNREAD : self::unescaped($text, $singleQuoted);
            $previous = $token;
        }

        return $lines;
    }

    private static function unescaped(string $text, bool $singleQuoted): string
    {
        return $singleQuoted ? str_replace(['\\\\', "\\'"], ['\\', "'"], $text) : stripcslashes($text);
    }

    /**
     * Whether the column $before ends at is assigned: the last SET at the
     * column's own depth, then nothing but `column = value` items each ended
     * by a comma.
     */
    private static function assignedInASetList(string $before): bool
    {
        // The column's qualifier and opening backtick are the column's.
        $before = (string) preg_replace('/(?:`?\w+`?\.)?`?$/D', '', $before);
        preg_match_all(self::SQL_TOKEN, $before, $m);
        $depths = [[]];
        foreach ($m[0] as $t) {
            if ($t === '(') {
                $depths[] = [];
            } elseif ($t === ')') {
                if (count($depths) === 1) {
                    return false;
                }
                array_pop($depths);
                $depths[array_key_last($depths)][] = '()';
            } else {
                $depths[array_key_last($depths)][] = $t;
            }
        }
        $tokens = $depths[array_key_last($depths)];
        $set = null;
        foreach ($tokens as $i => $t) {
            if (strcasecmp($t, 'SET') === 0) {
                $set = $i;
            }
        }
        if ($set === null) {
            return false;
        }
        $items = [[]];
        foreach (array_slice($tokens, $set + 1) as $t) {
            if ($t === ',') {
                $items[] = [];
            } else {
                $items[array_key_last($items)][] = $t;
            }
        }
        if (array_pop($items) !== []) {
            return false;
        }
        foreach ($items as $item) {
            if (!self::isAnAssignment($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `column = value` or `table.column = value`, the value made only of
     * literals, numbers, signs, NULL, DEFAULT, function calls, parenthesized
     * groups and values it cannot see.
     *
     * @param list<string> $item
     */
    private static function isAnAssignment(array $item): bool
    {
        $name = '/^(?:\w+|`[^`]*`)$/D';
        $at = 0;
        if (preg_match($name, $item[$at] ?? '') !== 1) {
            return false;
        }
        if (($item[$at + 1] ?? null) === '.') {
            $at += 2;
            if (preg_match($name, $item[$at] ?? '') !== 1) {
                return false;
            }
        }
        if (($item[$at + 1] ?? null) !== '=') {
            return false;
        }
        $value = array_slice($item, $at + 2);
        if ($value === []) {
            return false;
        }
        foreach ($value as $k => $t) {
            $readable = in_array($t[0], ["'", '"'], true)
                || in_array($t, [self::UNREAD, '()', '-', '+', '.'], true)
                || ctype_digit($t)
                || in_array(strtoupper($t), ['NULL', 'DEFAULT'], true)
                || (preg_match('/^\w+$/D', $t) === 1 && ($value[$k + 1] ?? null) === '()');
            if (!$readable) {
                return false;
            }
        }

        return true;
    }
}
