<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;

/**
 * A URL that carries one click's id or public id — the offer URL with its
 * subid, a cl.php or pci.php link — is looked up with
 * findOrCreateClickSiteUrl(), which leaves the cache alone: through
 * findOrCreateSiteUrl() every click wrote an entry nothing read again. The
 * URLs a click shares with others (its referer, its landing page, the link
 * it followed: off.php's is go.php?acip=… for every click) keep the cached
 * lookup.
 *
 * Which URL a call is handed is a judgement the scan cannot make, so each
 * click script's lookups are listed whole, in order, by method and argument
 * as the tokens spell them (comments left out): a lookup added, removed,
 * moved to the other method or handed another variable fails until the
 * list says which kind of URL it is.
 */
final class ClickSiteUrlsAreUncachedTest extends TestCase
{
    private const CLICK = 'findOrCreateClickSiteUrl';
    private const SHARED = 'findOrCreateSiteUrl';

    /** file => its lookups in order: [method, argument] */
    private const CALLS = [
        'tracking202/redirect/dl.php' => [
            [self::SHARED, "\$_GET [ 't202ref' ]"],
            [self::SHARED, "\$referer_query [ 'url' ]"],
            [self::SHARED, "\$_SERVER [ 'HTTP_REFERER' ] ?? ''"],
            [self::SHARED, "\$referer_query [ 'url' ]"],
            [self::SHARED, "\$_SERVER [ 'HTTP_REFERER' ] ?? ''"],
            // dl.php?t202id=…: the link, the same for every click on it
            [self::SHARED, '$outbound_site_url'],
            [self::CLICK, '$cloaking_site_url'],
            [self::CLICK, '$redirect_site_url'],
        ],
        'tracking202/redirect/off.php' => [
            [self::SHARED, '$outbound_site_url'],
            [self::CLICK, '(string) $redirect_site_url'],
        ],
        'tracking202/redirect/offrtr.php' => [
            [self::SHARED, '$outbound_site_url'],
            [self::CLICK, '(string) $redirect_site_url'],
            [self::SHARED, '$outbound_site_url'],
            [self::CLICK, '(string) $redirect_site_url'],
        ],
        'tracking202/redirect/rtr.php' => [
            [self::SHARED, "\$referer_query [ 'url' ]"],
            [self::SHARED, "(string) ( \$_SERVER [ 'HTTP_REFERER' ] ?? '' )"],
            [self::SHARED, '$outbound_site_url'],
            [self::CLICK, '$cloaking_site_url'],
            [self::CLICK, '(string) $redirect_site_url'],
        ],
        'tracking202/static/record_adv.php' => [
            [self::SHARED, '$_referer_site_url'],
            [self::SHARED, '$landing_site_url'],
        ],
        'tracking202/static/record_simple.php' => [
            [self::SHARED, '$_referer_site_url'],
            [self::SHARED, '$landing_site_url'],
            // pci.php?pci=<the click's public id>
            [self::CLICK, '$outbound_site_url'],
            [self::CLICK, '$cloaking_site_url'],
            [self::CLICK, '$redirect_site_url'],
        ],
    ];

    public function testEachClickScriptsLookupsAreTheListedOnes(): void
    {
        $root = dirname(__DIR__, 2);
        $files = glob("$root/tracking202/{redirect,static}/*.php", GLOB_BRACE) ?: [];
        self::assertGreaterThan(20, count($files));
        $found = [];
        foreach ($files as $path) {
            $calls = self::lookups((string) file_get_contents($path));
            if ($calls !== []) {
                $found[substr($path, strlen($root) + 1)] = $calls;
            }
        }
        ksort($found);
        $listed = self::CALLS;
        ksort($listed);

        self::assertSame($listed, $found);
    }

    /** @return iterable<string, array{string, list<array{string, string}>}> */
    public static function spellings(): iterable
    {
        yield 'a call' => ['<?php $id = $r->findOrCreateSiteUrl($url);', [[self::SHARED, '$url']]];
        yield 'nested parentheses' => [
            '<?php $id = $r->findOrCreateClickSiteUrl((string) ($a ?? \'\'));',
            [[self::CLICK, "(string) ( \$a ?? '' )"]],
        ];
        yield 'not ended by its statement' => [
            '<?php $id = $r->findOrCreateSiteUrl($u) ?: 0;',
            [[self::SHARED, '$u']],
        ];
        yield 'a comment inside' => ['<?php $r->findOrCreateSiteUrl(/* x */ $u);', [[self::SHARED, '$u']]];
        yield 'a static call' => ['<?php X::findOrCreateSiteUrl($u);', [[self::SHARED, '$u']]];
        yield 'named in a comment' => ['<?php // $r->findOrCreateSiteUrl($u);' . "\n", []];
        yield 'named in a string' => ['<?php $s = "findOrCreateSiteUrl($u)";', []];
        yield 'a callable by name' => [
            '<?php $f = [$r, \'findOrCreateSiteUrl\'];',
            [['callable', "'findOrCreateSiteUrl'"]],
        ];
        yield 'a dynamic member call' => ['<?php $r->{$m}($u);', [['dynamic', '$m']]];
        yield 'a dynamic property' => ['<?php $v = $r->{\'x\'};', []];
    }

    /**
     * @dataProvider spellings
     * @param list<array{string, string}> $expected
     */
    public function testTheScanReadsEverySpelling(string $source, array $expected): void
    {
        self::assertSame($expected, self::lookups($source));
    }

    /**
     * Each lookup in a source: [method, argument], the argument's tokens
     * joined by single spaces; a string naming either method (a callable)
     * and a dynamic member call are listed as such, so a call the scan
     * cannot follow fails rather than passing unseen.
     *
     * @return list<array{string, string}>
     */
    private static function lookups(string $source): array
    {
        $code = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
        $out = [];
        foreach ($code as $i => $t) {
            $named = in_array(trim($t->text, '\'"'), [self::CLICK, self::SHARED], true);
            if ($t->is(T_CONSTANT_ENCAPSED_STRING) && $named) {
                $out[] = ['callable', $t->text];
                continue;
            }
            if ($t->text === '{' && ($code[$i - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                // A member named at runtime: a call when its brace is followed by one.
                for ($j = $i, $depth = 0, $n = count($code); $j < $n; $j++) {
                    $depth += match ($code[$j]->text) {
                        '{' => 1,
                        '}' => -1,
                        default => 0,
                    };
                    if ($depth === 0) {
                        break;
                    }
                }
                if (($code[$j + 1] ?? null)?->text === '(') {
                    $out[] = ['dynamic', $code[$i + 1]->text ?? ''];
                }
                continue;
            }
            $call = $t->is(T_STRING) && $named && ($code[$i + 1] ?? null)?->text === '(';
            if (!$call) {
                continue;
            }
            $depth = 0;
            $argument = [];
            for ($j = $i + 1, $n = count($code); $j < $n; $j++) {
                $depth += match ($code[$j]->text) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                if ($depth === 0) {
                    break;
                }
                if ($j > $i + 1) {
                    $argument[] = $code[$j]->text;
                }
            }
            $out[] = [$t->text, implode(' ', $argument)];
        }

        return $out;
    }
}
