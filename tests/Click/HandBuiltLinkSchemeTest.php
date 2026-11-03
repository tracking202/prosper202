<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * A link the operator pastes somewhere (a tracking link, a pixel, a postback
 * or notification URL) takes its scheme from TrackingBaseUrl, as the API's
 * does: the one the tracking domain is saved with, or else the one the
 * request arrived on. Get Links wrote `'http://' . getTrackingDomain() . …`,
 * so an install served over HTTPS handed out http:// links while
 * `p202 tracker get-url` handed out https:// ones for the same tracker; the
 * Postback page and API integrations chose the scheme from the request alone
 * and never read the saved one.
 *
 * Two scans over the served tree (SourceScan::phpFiles(), and the scripts
 * under 202-js/):
 *
 *  - a URL built on a hard-coded http://: a PHP string ending in `http://`
 *    that is concatenated (`'http://' . $host`), interpolated
 *    (`"http://$host"`, a heredoc), completed by a PHP block in HTML
 *    (`href="http://<?= $host ?>`), or a format string (`'http://%s/'`);
 *    in a script, `'http://' + host` or `` `http://${host}` ``;
 *  - a scheme chosen by hand in PHP: a ternary between http and https
 *    (`$secure ? 'https://' : 'http://'`), or http or https as a default
 *    (`$parsed['scheme'] ?? 'http'`).
 *
 * Each is allowed only where listed, with the reason its URL is not one of
 * those links; the lists only shrink. Not seen: a scheme assembled from parts
 * (`'http' . ($s ? 's' : '')`), one held in a variable an `if` sets, and a
 * script's ternary (p202-setup.js rebuilds the Postback page's snippets on
 * the protocol the operator picks under Advanced, starting from the page's).
 */
final class HandBuiltLinkSchemeTest extends TestCase
{
    /** @var array<string, array{int, string}> repo-relative file => count, why */
    private const HARD_CODED_HTTP = [
        '202-config/formatting.php' => [1, "clean_url() (WordPress's): a URL typed without a scheme, read as http"],
        'tracking202/ajax/click_history_row.php' => [1, "a click's referer stored without a scheme, shown in Spy"],
    ];

    /** @var array<string, array{int, string}> repo-relative file => count, why */
    private const SCHEME_CHOICES = [
        '202-config/Click/TrackingBaseUrl.php' => [2, 'the rule itself: build() and requestOrigin()'],
        '202-config/DataEngine/SelfCall.php' => [1, "the cron's call to the listener's own address, on its scheme"],
        'tracking202/static/landing.php' => [
            1,
            "the landing-page script calls back on the scheme the visitor's browser loaded it over"
                . ' (mixed content otherwise)',
        ],
        'api/v3/Controllers/TrackersController.php' => [
            1,
            "a landing page's own URL keeps the scheme it was saved with; one saved as //host has none to keep",
        ],
        'tracking202/ajax/generate_tracking_link.php' => [1, 'the same landing-page URL, as Get Links shows it'],
    ];

    /**
     * @return list<array{int, string, int}|string> tokens without whitespace or comments
     */
    private static function tokens(string $source): array
    {
        return array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }

    /** A quoted string's value, or null for any other token. */
    private static function literal(mixed $token): ?string
    {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return substr($token[1], 1, -1);
    }

    private static function endsInHttp(string $text): bool
    {
        return preg_match('~http://$~iD', $text) === 1;
    }

    /**
     * The lines of PHP that build a URL on a hard-coded http://.
     *
     * @return list<int>
     */
    public static function hardCodedHttp(string $source): array
    {
        $tokens = self::tokens($source);
        $lines = [];
        $opens = [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO];
        $interpolations = [T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            $literal = self::literal($token);
            $built = ($literal !== null && self::endsInHttp($literal) && $next === '.')
                || ($literal !== null && preg_match('~http://%~i', $literal) === 1)
                || ($token[0] === T_ENCAPSED_AND_WHITESPACE && self::endsInHttp($token[1])
                    && is_array($next) && in_array($next[0], $interpolations, true))
                || ($token[0] === T_INLINE_HTML && self::endsInHttp($token[1])
                    && is_array($next) && in_array($next[0], $opens, true));
            if ($built) {
                $lines[] = $token[2] + ($token[0] === T_INLINE_HTML ? substr_count($token[1], "\n") : 0);
            }
            if ($token[0] === T_INLINE_HTML) {
                foreach (self::scriptLines($token[1]) as $line) {
                    $lines[] = $token[2] + $line - 1;
                }
            }
        }

        return $lines;
    }

    /**
     * The lines of a script (or of a page's inline script) that build a URL
     * on a hard-coded http://, counted from 1.
     *
     * @return list<int>
     */
    public static function scriptLines(string $script): array
    {
        $lines = [];
        $pattern = '~([\'"])http://\1\s*\+|`http://\$\{~i';
        foreach (explode("\n", $script) as $n => $line) {
            if (preg_match($pattern, $line) === 1) {
                $lines[] = $n + 1;
            }
        }

        return $lines;
    }

    /**
     * The lines of PHP that choose a scheme by hand.
     *
     * @return list<int>
     */
    public static function schemeChoices(string $source): array
    {
        $tokens = self::tokens($source);
        $scheme = static function (mixed $token): ?string {
            $value = self::literal($token);
            if ($value === null) {
                return null;
            }
            $value = strtolower($value);

            return in_array($value, ['http', 'http://', 'https', 'https://'], true) ? rtrim($value, ':/') : null;
        };
        $lines = [];
        foreach ($tokens as $i => $token) {
            if ($token === '?') {
                $then = $scheme($tokens[$i + 1] ?? null);
                $else = ($tokens[$i + 2] ?? null) === ':' ? $scheme($tokens[$i + 3] ?? null) : null;
                if ($then !== null && $else !== null && $then !== $else) {
                    $lines[] = $tokens[$i + 1][2];
                }
                continue;
            }
            $default = is_array($token) && $token[0] === T_COALESCE;
            $elvis = $token === ':' && ($tokens[$i - 1] ?? null) === '?';
            if (($default || $elvis) && $scheme($tokens[$i + 1] ?? null) !== null) {
                $lines[] = $tokens[$i + 1][2];
            }
        }

        return $lines;
    }

    /**
     * @param callable(string): list<int> $scan
     * @param array<string, array{int, string}> $allowed
     * @return array{list<string>, list<string>, int}
     */
    private static function sweep(iterable $files, callable $scan, array $allowed): array
    {
        $unlisted = [];
        $miscounted = [];
        $seen = 0;
        foreach ($files as $path => $source) {
            $lines = $scan($source);
            if ($lines === []) {
                continue;
            }
            $seen += count($lines);
            if (!isset($allowed[$path])) {
                $unlisted[] = $path . ' line ' . implode(', ', $lines);
            } elseif ($allowed[$path][0] !== count($lines)) {
                $miscounted[] = $path . ': ' . count($lines) . ' (lines ' . implode(', ', $lines) . '), '
                    . $allowed[$path][0] . ' listed';
            }
        }

        return [$unlisted, $miscounted, $seen];
    }

    /** @return array<string, string> the scripts under 202-js/, less the vendored and minified ones */
    private static function scripts(): array
    {
        $root = SourceScan::repoRoot();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            SourceScan::tree($root . '/202-js'),
            static fn (\SplFileInfo $f): bool => $f->getFilename() !== 'vendor'
        ));
        $scripts = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'js' || str_ends_with($file->getFilename(), '.min.js')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                throw new \RuntimeException('Could not read ' . $file->getPathname());
            }
            $scripts[substr($file->getPathname(), strlen($root) + 1)] = $source;
        }
        ksort($scripts);

        return $scripts;
    }

    public function testNoLinkIsBuiltOnAHardCodedHttpScheme(): void
    {
        [$unlisted, $miscounted, $seen] = self::sweep(
            SourceScan::phpFiles(),
            [self::class, 'hardCodedHttp'],
            self::HARD_CODED_HTTP
        );
        self::assertSame([], $unlisted, "A URL is built on a hard-coded http://:\n  " . implode("\n  ", $unlisted)
            . "\nBuild a link the operator pastes on p202TrackingBaseForResponse() (the API's base,"
            . ' TrackingBaseLookup);'
            . ' anything else goes in HARD_CODED_HTTP with why it is not such a link');
        self::assertSame([], $miscounted, 'a listed file gained or lost one: route it, or update the list');
        $listed = array_sum(array_column(self::HARD_CODED_HTTP, 0));
        self::assertSame($listed, $seen, 'a listed file no longer builds one: take it off the list');

        [$unlisted] = self::sweep(self::scripts(), [self::class, 'scriptLines'], []);
        self::assertGreaterThanOrEqual(5, count(self::scripts()), 'the scan reads the scripts');
        self::assertSame([], $unlisted, 'A script builds a URL on a hard-coded http://');
    }

    public function testEverySchemeChosenByHandIsListed(): void
    {
        [$unlisted, $miscounted, $seen] = self::sweep(
            SourceScan::phpFiles(),
            [self::class, 'schemeChoices'],
            self::SCHEME_CHOICES
        );
        self::assertSame([], $unlisted, "A scheme is chosen outside TrackingBaseUrl:\n  " . implode("\n  ", $unlisted)
            . "\nA link the operator pastes takes the saved scheme too: p202TrackingBaseForResponse(); anything else"
            . ' goes in SCHEME_CHOICES with why its URL is not such a link');
        self::assertSame([], $miscounted, 'a listed file gained or lost one: route it, or update the list');
        $listed = array_sum(array_column(self::SCHEME_CHOICES, 0));
        self::assertSame($listed, $seen, 'a listed file no longer chooses one: take it off the list');
    }

    /** @return iterable<string, array{string, int}> */
    public static function hardCodedShapes(): iterable
    {
        yield 'concatenated' => ['<?php $u = \'http://\' . getTrackingDomain() . "/x";', 1];
        yield 'double quotes, another case' => ['<?php $u = "HTTP://" . $host;', 1];
        yield 'a prefix before the scheme' => ['<?php $a = \'<a href="http://\' . $host . \'">\';', 1];
        yield 'interpolated' => ['<?php $u = "http://$host/x";', 1];
        yield 'interpolated in braces' => ['<?php $u = "http://{$host}/x";', 1];
        yield 'a heredoc' => ["<?php \$u = <<<EOT\nhttp://{\$host}/x\nEOT;\n", 1];
        yield 'a format string' => ['<?php $u = sprintf(\'http://%s/x\', $host);', 1];
        yield 'completed by a block in HTML' => ["<p>\n<a href=\"http://<?php echo \$host; ?>/x\">", 1];
        yield 'a script in a page' => ["<script>\nvar u = 'http://' + host;\n</script>", 1];
        yield 'a whole URL is not a builder' => ['<?php $u = \'http://p.example\'; header("location: http://x/");', 0];
        yield 'https is not http' => ['<?php $u = \'https://\' . $host;', 0];
        yield 'a comparison is not a builder' => ['<?php str_starts_with($u, \'http://\');', 0];
        yield 'a comment is not a builder' => ["<?php // 'http://' . \$host\n", 0];
    }

    /** @dataProvider hardCodedShapes */
    public function testTheShapesAHardCodedSchemeTakes(string $source, int $expected): void
    {
        self::assertCount($expected, self::hardCodedHttp($source));
    }

    /** @return iterable<string, array{string, int}> */
    public static function scriptShapes(): iterable
    {
        yield 'concatenated' => ["var u = 'http://' + host;", 1];
        yield 'double quotes' => ['var u = "http://" + host;', 1];
        yield 'a template' => ['var u = `http://${host}/x`;', 1];
        yield 'chosen by the operator' => ["var u = (secure ? 'https' : 'http') + '://' + root;", 0];
        yield 'https' => ["var u = 'https://' + host;", 0];
    }

    /** @dataProvider scriptShapes */
    public function testTheShapesAScriptTakes(string $script, int $expected): void
    {
        self::assertCount($expected, self::scriptLines($script));
    }

    /** @return iterable<string, array{string, int}> */
    public static function choiceShapes(): iterable
    {
        yield 'a ternary of schemes' => ['<?php $s = $secure ? \'https://\' : \'http://\';', 1];
        yield 'the other way round, bare' => ['<?php $s = $plain ? "http" : "https";', 1];
        yield 'a default' => ['<?php $s = ($p[\'scheme\'] ?? \'http\') . \'://\';', 1];
        yield 'a short ternary' => ['<?php $s = $p ?: \'https\';', 1];
        yield 'one scheme both ways is no choice' => ['<?php $s = $x ? \'https\' : \'https\';', 0];
        yield 'a list of schemes is not a choice' => ['<?php in_array($s, [\'http\', \'https\'], true);', 0];
        yield 'a comparison is not a choice' => ['<?php $ok = $s === \'https\';', 0];
    }

    /** @dataProvider choiceShapes */
    public function testTheShapesASchemeChoiceTakes(string $source, int $expected): void
    {
        self::assertCount($expected, self::schemeChoices($source));
    }
}
