<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * The click scripts read four values the visitor's request decides: the
 * referring page's q and url, the parameter a t202var_ keyword names, and
 * t202ref. The keyword's two (q and t202var_) are read by
 * ClickRequest::keyword() alone, which ClickRequestTest holds to strings;
 * the scripts read the other two themselves. Any of them can be a list (q[]=1, url[]=1, x[]=1, t202ref[]=1).
 * A list reaching a lookup that takes a string is a TypeError: the click is
 * lost, on dl.php after its redirect was sent (measured: a referer's url[]
 * and q[], and a t202var_ list, each left no click), and a list cast with
 * (string) was stored as "Array". The scripts also disagreed on an empty
 * value ('0' was nothing on the landing pages and a value elsewhere).
 *
 * Every use of each value outside a condition sits in a block whose own
 * condition is exactly the one below: a condition that only contains it
 * (`… || true`), an is_string() further up, or a use after the block closed
 * is not one.
 */
final class ClickRequestValuesAreStringsTest extends TestCase
{
    private const SCRIPTS = [
        'tracking202/redirect/dl.php',
        'tracking202/redirect/rtr.php',
        'tracking202/static/record_simple.php',
        'tracking202/static/record_adv.php',
    ];

    /** source => [the read, the condition of every block that uses it, scripts that never read it] */
    private const SOURCES = [
        'referer q' => [
            '$referer_query[\'q\']',
            'is_string($referer_query[\'q\'] ?? null) && $referer_query[\'q\'] !== \'\'',
            self::SCRIPTS,
        ],
        'referer url' => [
            '$referer_query[\'url\']',
            'is_string($referer_query[\'url\'] ?? null) && $referer_query[\'url\'] !== \'\'',
            [],
        ],
        't202var' => ['$_GET[$t202var]', 'is_string($_GET[$t202var] ?? null)', self::SCRIPTS],
        't202ref' => [
            '$_GET[\'t202ref\']',
            'is_string($_GET[\'t202ref\'] ?? null) && $_GET[\'t202ref\'] !== \'\'',
            ['tracking202/redirect/rtr.php'],
        ],
    ];

    public function testEveryUseOfARequestValueIsUnderItsStringGuard(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::SCRIPTS as $script) {
            $source = file_get_contents($root . '/' . $script);
            self::assertIsString($source, $script);
            foreach (self::SOURCES as $name => [$read, $guard, $unread]) {
                $uses = 0;
                $offset = 0;
                while (($at = strpos($source, $read, $offset)) !== false) {
                    $offset = $at + strlen($read);
                    if (self::inCondition($source, $at)) {
                        continue;
                    }
                    $uses++;
                    self::assertSame(
                        $guard,
                        self::enclosingCondition($source, $at),
                        "$script uses the $name at byte $at under another condition"
                    );
                }
                if (in_array($script, $unread, true)) {
                    self::assertSame(0, $uses, "$script reads the $name; list it");
                } else {
                    self::assertGreaterThan(0, $uses, "$script reads the $name");
                }
            }
        }
    }

    /** Where ClickRequest::keyword() reads; none of them is read by a script itself, in any quoting. */
    private const KEYWORD_SOURCES = [
        '$_GET' => [
            'OVKEY', 'OVRAW', 't202kw', 'target_passthrough', 'keyword', 'search_word', 'query', 'encquery', 'terms',
            'rdata', 'qs', 'wd', 'text', 'szukaj', 'qt', 'k', 'words',
        ],
        '$referer_query' => ['q'],
    ];

    public function testEveryScriptReadsItsKeywordThroughTheOneReader(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::SCRIPTS as $script) {
            $source = (string) file_get_contents($root . '/' . $script);
            self::assertSame(1, substr_count($source, 'ClickRequest::keyword($_GET, $referer_query, '), $script);
            self::assertSame([], self::keywordSourceReads($source), "$script reads a keyword source itself");
            self::assertStringNotContainsString('t202var_', $source, "$script resolves a t202var_ keyword itself");
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function keywordSourceSpellings(): iterable
    {
        yield 'single quotes' => ["<?php \$k = \$_GET['t202kw'] ?? '';", ["\$_GET['t202kw']"]];
        yield 'double quotes' => ['<?php $k = $_GET["OVKEY"];', ['$_GET["OVKEY"]']];
        yield 'the request' => ["<?php \$k = \$_REQUEST['keyword'];", ["\$_REQUEST['keyword']"]];
        yield 'the lower-cased copy' => ["<?php \$k = \$_lGET['words'];", ["\$_lGET['words']"]];
        yield 'spaced' => ["<?php \$k = \$referer_query [ 'q' ];", ["\$referer_query['q']"]];
        yield 'another parameter' => ["<?php \$k = \$_GET['c1'];", []];
        yield 'the referer\'s url' => ["<?php \$k = \$referer_query['url'];", []];
    }

    /**
     * @dataProvider keywordSourceSpellings
     * @param list<string> $expected
     */
    public function testTheKeywordSourceScanReadsEverySpelling(string $source, array $expected): void
    {
        self::assertSame($expected, self::keywordSourceReads($source));
    }

    /**
     * Each read of a keyword source in a source, by its tokens: the request
     * ($_GET, $_REQUEST, dl.php's lower-cased $_lGET) or the referring page's
     * query, indexed by a source's name in either quoting.
     *
     * @return list<string>
     */
    private static function keywordSourceReads(string $source): array
    {
        $code = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
        $arrays = [
            '$_GET' => '$_GET', '$_REQUEST' => '$_GET', '$_lGET' => '$_GET', '$referer_query' => '$referer_query',
        ];
        $found = [];
        foreach ($code as $i => $t) {
            $from = $arrays[$t->text] ?? null;
            if (!$t->is(T_VARIABLE) || $from === null || ($code[$i + 1] ?? null)?->text !== '[') {
                continue;
            }
            $key = $code[$i + 2] ?? null;
            $source = $key !== null && $key->is(T_CONSTANT_ENCAPSED_STRING)
                && in_array(substr($key->text, 1, -1), self::KEYWORD_SOURCES[$from], true);
            if ($source) {
                $found[] = $t->text . '[' . $key->text . ']';
            }
        }

        return $found;
    }

    /** @return array<string, array{string, ?string}> */
    public static function shapes(): array
    {
        $use = '$keyword = $v;';

        return [
            'first in the block' => ["if (is_string(\$v ?? null)) {\n\t$use", 'is_string($v ?? null)'],
            'a comment after the brace' => ["if (a(b)) { // c\n\t$use", 'a(b)'],
            'after another statement in the block' => ["if (x) {\n\t\$y = f(1);\n\t$use", 'x'],
            'an elseif' => ["} elseif (x) {\n\t$use", 'x'],
            'in a nested block' => ["if (x) {\n\tif (y) {\n\t\t$use", 'y'],
            'after a nested block closed' => ["if (x) {\n\tif (y) {\n\t}\n\t$use", 'x'],
            'after the block closed' => ["if (x) {\n\t\$y = 1;\n}\n$use", null],
            'an else' => ["if (x) {\n} else {\n\t$use", null],
            'no block' => [$use, null],
        ];
    }

    /** @dataProvider shapes */
    public function testTheConditionReadIsTheOneOfTheEnclosingBlock(string $code, ?string $expected): void
    {
        $at = strpos($code, '$v;');
        self::assertIsInt($at);
        self::assertSame($expected, self::enclosingCondition($code, $at));
    }

    public function testAUseInsideAConditionIsNotAUse(): void
    {
        $code = 'if (is_string($v ?? null) && f($v) !== \'\') { $k = g($v); }';
        $uses = [];
        $offset = 0;
        while (($at = strpos($code, '$v', $offset)) !== false) {
            $uses[] = self::inCondition($code, $at);
            $offset = $at + 2;
        }
        self::assertSame([true, true, false], $uses);
    }

    /**
     * The condition of the `if`/`elseif` whose block holds $offset, whitespace
     * folded; null when that block is no `if`'s (an else, a loop, none).
     */
    private static function enclosingCondition(string $source, int $offset): ?string
    {
        $depth = 0;
        for ($i = $offset - 1; $i >= 0; $i--) {
            if ($source[$i] === '}') {
                $depth++;
            } elseif ($source[$i] === '{') {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }
                $head = rtrim(substr($source, 0, $i));
                if (!str_ends_with($head, ')')) {
                    return null;
                }
                $close = strlen($head) - 1;
                $parens = 0;
                for ($j = $close; $j >= 0; $j--) {
                    if ($head[$j] === ')') {
                        $parens++;
                    } elseif ($head[$j] === '(' && --$parens === 0) {
                        if (preg_match('/(?:^|[^\w$])(?:else)?if\s*\z/', substr($head, 0, $j)) !== 1) {
                            return null;
                        }

                        return (string) preg_replace('/\s+/', ' ', trim(substr($head, $j + 1, $close - $j - 1)));
                    }
                }

                return null;
            }
        }

        return null;
    }

    /** Whether $offset sits inside the parentheses of an `if`/`elseif` condition. */
    private static function inCondition(string $source, int $offset): bool
    {
        $depth = 0;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $c = $source[$i];
            if ($depth === 0 && ($c === ';' || $c === '{' || $c === '}')) {
                return false;
            }
            if ($c === ')') {
                $depth++;
            } elseif ($c === '(') {
                if ($depth > 0) {
                    $depth--;
                } elseif (preg_match('/(?:^|[^\w$])(?:else)?if\s*\z/', substr($source, 0, $i)) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
