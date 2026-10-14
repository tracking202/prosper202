<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * The four click scripts read a keyword from the referring page's q and from
 * the parameter a t202var_ keyword names. Either can be a list (q[]=1,
 * x[]=1), and a list reaching findOrCreateKeyword(string) is a TypeError:
 * the click is lost and the visitor gets a 500. rtr.php took a list as no
 * keyword; dl.php, record_simple.php and record_adv.php did not (measured:
 * dl.php answered 500 to both). The scripts also disagreed on what an empty
 * q is ('0' was no keyword on the landing pages and a keyword elsewhere).
 *
 * Each assignment is the first statement of the block its own condition
 * opens, and that condition is exactly the one below: a condition that only
 * contains it (`… || true`) or an is_string() somewhere above is not one.
 */
final class KeywordSourcesAreStringsTest extends TestCase
{
    private const SCRIPTS = [
        'tracking202/redirect/dl.php',
        'tracking202/redirect/rtr.php',
        'tracking202/static/record_simple.php',
        'tracking202/static/record_adv.php',
    ];

    /** source => [the read, the condition that guards its assignment] */
    private const SOURCES = [
        'referer q' => [
            '$referer_query[\'q\']',
            'is_string($referer_query[\'q\'] ?? null) && $referer_query[\'q\'] !== \'\'',
        ],
        't202var' => ['$_GET[$t202var]', 'is_string($_GET[$t202var] ?? null)'],
    ];

    public function testEachKeywordSourceIsReadOnlyAsAString(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::SCRIPTS as $script) {
            $source = file_get_contents($root . '/' . $script);
            self::assertIsString($source, $script);
            foreach (self::SOURCES as $name => [$read, $guard]) {
                $assignment = '/\$keyword\s*=\s*(?:\(string\)\s*)?' . preg_quote($read, '/') . '\s*;/';
                $count = preg_match_all($assignment, $source, $found, PREG_OFFSET_CAPTURE);
                self::assertGreaterThan(0, $count, "$script assigns the $name keyword");
                foreach ($found[0] as [, $offset]) {
                    self::assertSame(
                        $guard,
                        self::guardingCondition($source, $offset),
                        "$script assigns the $name keyword under another condition"
                    );
                }
            }
        }
    }

    /** @return array<string, array{string, ?string}> */
    public static function shapes(): array
    {
        $assign = '$keyword = $referer_query[\'q\'];';

        return [
            'guarded' => ["if (is_string(\$q ?? null)) {\n\t$assign", 'is_string($q ?? null)'],
            'guarded, a comment after the brace' => ["if (a(b)) { // c\n\t$assign", 'a(b)'],
            'an elseif' => ["} elseif (x) {\n\t$assign", 'x'],
            'a statement between' => ["if (x) {\n\t\$y = 1;\n\t$assign", null],
            'after the block closed' => ["if (x) {\n\t\$y = 1;\n}\n$assign", null],
            'no condition at all' => [$assign, null],
        ];
    }

    /** @dataProvider shapes */
    public function testTheConditionIsReadOnlyWhereItOpensTheAssignmentsBlock(string $code, ?string $expected): void
    {
        $offset = strpos($code, '$keyword');
        self::assertIsInt($offset);
        self::assertSame($expected, self::guardingCondition($code, $offset));
    }

    /**
     * The condition of the `if`/`elseif` whose block the statement at
     * $offset opens, whitespace folded; null when anything but the brace
     * (and a line comment) stands between the condition and the statement.
     */
    private static function guardingCondition(string $source, int $offset): ?string
    {
        $before = substr($source, 0, $offset);
        $if = strrpos($before, 'if (');
        if ($if === false) {
            return null;
        }
        $open = $if + 3;
        $depth = 0;
        for ($i = $open; $i < $offset; $i++) {
            if ($source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ')' && --$depth === 0) {
                $between = substr($source, $i + 1, $offset - $i - 1);
                if (preg_match('/\A\s*\{\s*(?:\/\/[^\n]*\n\s*)?\z/', $between) !== 1) {
                    return null;
                }

                return (string) preg_replace('/\s+/', ' ', trim(substr($source, $open + 1, $i - $open - 1)));
            }
        }

        return null;
    }
}
