<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every script of this install that the code names in a string — a URL the
 * Integrations page tells you to paste into JVZoo, an endpoint the API lists,
 * a page a link opens — is a file in the tree.
 *
 * The Integrations page and GET /system/integrations named jvzoo.php, zpn.php,
 * slack.php and paykickstart.php under tracking202/static/ for as long as
 * none of the four existed, so every notification sent to them was a 404 and
 * nothing said so.
 *
 * It reads string literals and inline HTML, not comments, so a comment about
 * a script that was removed is not a reference.
 */
final class ReferencedScriptsExistTest extends TestCase
{
    /**
     * A script path relative to the install, or to the web root (`/202-account/…`); not one inside a
     * longer path or after a host (`https://click202.com/tracking202/…` is another server's).
     */
    private const PATH = '#(?<![A-Za-z0-9_.:-])(?<![A-Za-z0-9_.:-]/)/?'
        . '((?:tracking202|202-account|202-config|202-cronjobs|api)/[A-Za-z0-9_./-]*?\.php)\b#';

    /** The tokens a script's name can be written in: strings, and HTML outside PHP. */
    private const TEXT = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML];

    /**
     * @param array<string, string> $files repo-relative path => source
     * @return array{int, array<string, list<string>>} references read, and each missing script with where it is named
     */
    public static function missing(array $files, string $root): array
    {
        $read = 0;
        $missing = [];
        foreach ($files as $path => $source) {
            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || !in_array($token[0], self::TEXT, true)) {
                    continue;
                }
                if (preg_match_all(self::PATH, $token[1], $m) === 0) {
                    continue;
                }
                foreach ($m[1] as $script) {
                    $read++;
                    if (!is_file($root . '/' . $script)) {
                        $missing[$script][] = $path . ':' . $token[2];
                    }
                }
            }
        }

        return [$read, $missing];
    }

    public function testEveryScriptTheCodeNamesExists(): void
    {
        [$read, $missing] = self::missing(SourceScan::phpFiles(), SourceScan::repoRoot());

        self::assertGreaterThan(300, $read, 'the scan read the references (one that reads none passes vacuously)');
        $lines = [];
        foreach ($missing as $script => $where) {
            $lines[] = $script . ' (named at ' . implode(', ', $where) . ')';
        }
        self::assertSame([], $lines, "These scripts are named but not in the tree:\n  " . implode("\n  ", $lines));
    }

    public function testTheScanReadsStringsAndNotComments(): void
    {
        $files = ['x.php' => "<?php\n"
            . "// tracking202/static/gone-comment.php\n"
            . "\$a = 'tracking202/static/gone-single.php';\n"
            . "\$b = \"{\$base}tracking202/static/gone-double.php\";\n"
            . "?>\n<a href=\"/202-account/gone-html.php\">x</a>\n"
            . "<?php \$c = 'tracking202/static/cb202.php';\n"
            . "\$d = 'https://click202.com/tracking202/redirect/gone-remote.php';\n"
            . "\$e = 'vendor/x/api/gone-nested.php';\n"];
        [$read, $missing] = self::missing($files, SourceScan::repoRoot());

        self::assertSame(4, $read);
        self::assertSame(
            ['tracking202/static/gone-single.php', 'tracking202/static/gone-double.php', '202-account/gone-html.php'],
            array_keys($missing)
        );
    }
}
