<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * A read of one click's row, by its id or its public id, is not cached,
 * anywhere in the served tree. memcache_mysql_fetch_assoc() keeps a row three minutes under
 * its SQL, and a click's row changes under it: a redirector re-click keeps
 * the click's public id and rewrites where it goes, so cl.php sent the
 * visitor to the offer before while the click recorded the new one
 * (measured with memcached running), and off.php and offrtr.php rewrite the
 * rows they read. Each entry was per click, so it saved nothing but a reload.
 *
 * Read by the scan below: the SQL a call is handed (its variable's last
 * assignment above the call with every `.=` after it, or the string itself)
 * names a click's id when it holds click_id_public, `click_id =` or
 * `click_id IN`; such a call passes
 * 0, caching off. A cached call whose SQL the scan cannot find is refused by
 * name.
 */
final class ClickKeyedReadsAreUncachedTest extends TestCase
{
    /** The reads the scan finds today: a floor, so a scan that sees none fails. */
    private const KNOWN = [
        'tracking202/redirect/cl.php' => 1,
        'tracking202/redirect/off.php' => 1,
        'tracking202/redirect/offrtr.php' => 2,
        'tracking202/redirect/pci.php' => 1,
    ];

    public function testEveryReadOfOneClickIsUncached(): void
    {
        $found = [];
        $problems = [];
        // The whole served tree: a page that acts on a click is not always a click script.
        foreach (SourceScan::phpFiles() as $file => $source) {
            foreach (self::reads($source) as [$line, $keyed, $cached]) {
                if ($keyed === null) {
                    if ($cached) {
                        $problems[] = "$file:$line: a cached read whose SQL the scan cannot find";
                    }
                    continue;
                }
                if (!$keyed) {
                    continue;
                }
                $found[$file] = ($found[$file] ?? 0) + 1;
                if ($cached) {
                    $problems[] = "$file:$line reads one click's row through the cache";
                }
            }
        }
        ksort($found);

        self::assertSame(
            [],
            $problems,
            "Pass 0 (caching off) to a read of one click's row:\n  " . implode("\n  ", $problems)
        );
        self::assertSame(self::KNOWN, $found, 'the reads of one click the scan finds; list a new one');
    }

    /** @return iterable<string, array{string, list<array{int, ?bool, bool}>}> */
    public static function spellings(): iterable
    {
        yield 'cached, by public id' => [
            "<?php \$sql = \"SELECT a FROM t WHERE click_id_public='\" . \$p . \"'\";\n"
                . "\$r = memcache_mysql_fetch_assoc(\$db, \$sql);",
            [[2, true, true]],
        ];
        yield 'uncached, by id' => [
            "<?php \$sql = \"SELECT a FROM t WHERE c.click_id='\" . \$i . \"'\";\n"
                . "\$r = memcache_mysql_fetch_assoc(\$db, \$sql, 0);",
            [[2, true, false]],
        ];
        yield 'a list, cached' => [
            "<?php \$sql = 'SELECT a FROM t WHERE click_id = 5';\n"
                . "\$r = foreach_memcache_mysql_fetch_assoc(\$db, \$sql);",
            [[2, true, true]],
        ];
        yield 'the last assignment counts' => [
            "<?php \$sql = 'SELECT a FROM t WHERE click_id = 5';\n\$sql = 'SELECT b FROM u';\n"
                . "\$r = memcache_mysql_fetch_assoc(\$db, \$sql);",
            [[3, false, true]],
        ];
        yield 'a string handed in' => [
            "<?php \$r = memcache_mysql_fetch_assoc(\$db, \"SELECT a FROM t WHERE click_id_public='1'\");",
            [[1, true, true]],
        ];
        yield 'another table' => [
            "<?php \$sql = 'SELECT a FROM 202_trackers WHERE tracker_id_public = 1';\n"
                . "\$r = memcache_mysql_fetch_assoc(\$db, \$sql);",
            [[2, false, true]],
        ];
        yield 'SQL the scan cannot find' => ['<?php $r = memcache_mysql_fetch_assoc($db, build());', [[1, null, true]]];
        yield 'the older form, cached' => [
            "<?php \$sql = 'SELECT a WHERE click_id_public = 1';\n\$r = memcache_mysql_fetch_assoc(\$sql);",
            [[2, true, true]],
        ];
        yield 'the older form, uncached' => [
            "<?php \$sql = 'SELECT a WHERE click_id_public = 1';\n\$r = memcache_mysql_fetch_assoc(\$sql, 0);",
            [[2, true, false]],
        ];
        yield 'appended to' => [
            "<?php \$sql = 'SELECT a FROM t';\n\$sql .= ' WHERE click_id = 5';\n"
                . "\$r = memcache_mysql_fetch_assoc(\$db, \$sql);",
            [[3, true, true]],
        ];
        yield 'by a list of ids' => [
            "<?php \$sql = 'SELECT a FROM t WHERE click_id IN (1, 2)';\n"
                . "\$r = memcache_mysql_fetch_assoc(\$db, \$sql);",
            [[2, true, true]],
        ];
        yield 'appended to a start the scan cannot find' => [
            "<?php function f(\$db, \$sql) {\n\$sql .= ' WHERE a = 1';\n"
                . "return memcache_mysql_fetch_assoc(\$db, \$sql); }",
            [[3, null, true]],
        ];
        yield 'caching off by a variable' => [
            "<?php \$sql = 'SELECT a WHERE click_id = 1';\n\$r = memcache_mysql_fetch_assoc(\$db, \$sql, \$off);",
            [[2, true, true]],
        ];
    }

    /**
     * @dataProvider spellings
     * @param list<array{int, ?bool, bool}> $expected
     */
    public function testTheScanReadsEverySpelling(string $source, array $expected): void
    {
        self::assertSame($expected, self::reads($source));
    }

    /**
     * Each cached-read call in a source: its line, whether its SQL names one
     * click (null: the SQL could not be found), and whether it caches (any
     * third argument but 0 does).
     *
     * @return list<array{int, ?bool, bool}>
     */
    private static function reads(string $source): array
    {
        $code = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
        $out = [];
        foreach ($code as $i => $t) {
            $call = $t->is(T_STRING)
                && in_array($t->text, ['memcache_mysql_fetch_assoc', 'foreach_memcache_mysql_fetch_assoc'], true)
                && ($code[$i + 1] ?? null)?->text === '('
                && !($code[$i - 1] ?? null)?->is(T_FUNCTION);
            if (!$call) {
                continue;
            }
            $arguments = self::arguments($code, $i + 1);
            // ($db, $sql, $allowCaching) or the older ($sql, $allowCaching)
            $offset = count($arguments[0]) === 1 && $arguments[0][0]->text === '$db' ? 1 : 0;
            $sql = $arguments[$offset] ?? [];
            $caching = $arguments[$offset + 1] ?? null;
            $cached = $caching === null || array_map(static fn (\PhpToken $a): string => $a->text, $caching) !== ['0'];
            $text = null;
            if (count($sql) === 1 && $sql[0]->is(T_VARIABLE)) {
                $text = self::lastAssignment($code, $i, $sql[0]->text);
            } elseif ($sql !== [] && $sql[0]->is([T_CONSTANT_ENCAPSED_STRING, T_START_HEREDOC, '"'])) {
                $text = implode(' ', array_map(static fn (\PhpToken $a): string => $a->text, $sql));
            }
            $keyed = $text === null ? null : preg_match('/click_id_public|\bclick_id\s*(?:=|IN\b)/i', $text) === 1;
            $out[] = [$t->line, $keyed, $cached];
        }

        return $out;
    }

    /**
     * The tokens of each argument of the call whose '(' is at $open.
     *
     * @param list<\PhpToken> $code
     * @return list<list<\PhpToken>>
     */
    private static function arguments(array $code, int $open): array
    {
        $arguments = [[]];
        $depth = 0;
        for ($j = $open, $n = count($code); $j < $n; $j++) {
            $text = $code[$j]->text;
            if (in_array($text, ['(', '[', '{'], true) || $code[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $arguments[] = [];
                continue;
            }
            $arguments[count($arguments) - 1][] = $code[$j];
        }

        return $arguments;
    }

    /**
     * The source of $variable's last plain assignment above $before and of
     * every `.=` after it, each to its statement's end; null when there is no
     * plain assignment (appends alone are not the whole statement).
     *
     * @param list<\PhpToken> $code
     */
    private static function lastAssignment(array $code, int $before, string $variable): ?string
    {
        $sources = [];
        for ($j = $before - 1; $j >= 0; $j--) {
            $operator = $code[$j + 1] ?? null;
            if (!$code[$j]->is(T_VARIABLE) || $code[$j]->text !== $variable || $operator === null) {
                continue;
            }
            $plain = $operator->text === '=';
            if (!$plain && !$operator->is(T_CONCAT_EQUAL)) {
                continue;
            }
            $parts = [];
            for ($k = $j + 2, $n = count($code); $k < $n && $code[$k]->text !== ';'; $k++) {
                $parts[] = $code[$k]->text;
            }
            array_unshift($sources, implode(' ', $parts));
            if ($plain) {
                return implode(' ', $sources);
            }
        }

        return null;
    }
}
