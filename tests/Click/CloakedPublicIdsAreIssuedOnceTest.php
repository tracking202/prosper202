<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;

/**
 * A cloaked click's public id is made in one place (P0ZN). Four scripts
 * made it as the click id with a random digit on each side, which named the
 * clicks next to it; each now asks PublicClickIdKey::forNewClick(). This
 * holds every assignment of a click's public id in the served tree to that,
 * or to a value already stored (a re-click's, the click the tracking
 * template recorded, a read of the row), and refuses the old construction
 * anywhere.
 *
 * It reads assignments to $click_id_public and $mysql['click_id_public'] by
 * those names: a public id built under another name and stored through
 * MysqlClickRepository is not seen.
 */
final class CloakedPublicIdsAreIssuedOnceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    private const WRITERS = [
        'tracking202/redirect/dl.php',
        'tracking202/redirect/rtr.php',
        'tracking202/static/record_simple.php',
        'tracking202/static/record_adv.php',
    ];

    /** What an assignment may hand a click's public id, besides forNewClick(), with why. */
    private const STORED = [
        // None: a click without cloaking has no public id.
        "''",
        // The value issued above it, carried into $mysql.
        '(string) $click_id_public',
        '$click_id_public',
        // The click the tracking template (pt.php) recorded for this visit, read back.
        '$rsParallelClick->clickIdPublic',
        '$raParallelClick->clickIdPublic',
        // off.php: the id stored on the visitor's click, read back to pass on.
        "\$db->real_escape_string((string)(\$click_row1['click_id_public'] ?? ''))",
    ];

    /** @return list<string> */
    private static function servedFiles(): array
    {
        $files = [];
        foreach (['tracking202', '202-config', 'api', '202-account', '202-Mobile'] as $dir) {
            $it = new \RecursiveIteratorIterator(\Tests\Support\SourceScan::tree(self::ROOT . $dir));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen(self::ROOT));
                }
            }
        }
        sort($files);
        self::assertGreaterThan(200, count($files), 'the served tree was not found');

        return $files;
    }

    public function testEachWriterIssuesTheIdThroughPublicClickIdKey(): void
    {
        foreach (self::WRITERS as $file) {
            $source = (string) file_get_contents(self::ROOT . $file);
            self::assertSame(1, substr_count($source, 'PublicClickIdKey::forNewClick($db, (int) $click_id)'), $file);
        }
    }

    public function testNoAssignmentOfAPublicIdBuildsOne(): void
    {
        $seen = 0;
        foreach (self::servedFiles() as $file) {
            $source = (string) file_get_contents(self::ROOT . $file);
            preg_match_all(
                "/(?:\\\$click_id_public|\\\$mysql\\['click_id_public'\\])\\s*=(?![=>])\\s*(.*?);/s",
                $source,
                $m
            );
            foreach ($m[1] as $rhs) {
                $seen++;
                $rhs = trim((string) preg_replace('/\s+/', ' ', $rhs));
                if (in_array($rhs, self::STORED, true)) {
                    continue;
                }
                // rtr.php: a re-click keeps the id its links were handed out with; a new click gets one.
                $reClickKeeps = preg_quote(
                    "\$reClick !== null && \$reClick['click_id_public'] !== ''"
                        . " && \$reClick['click_id_public'] !== '0' ? \$reClick['click_id_public'] : ",
                    '/'
                );
                $issues = preg_quote('\\Prosper202\\Click\\PublicClickIdKey::forNewClick($db, (int) $click_id)', '/');
                self::assertMatchesRegularExpression(
                    '/\A(?:' . $reClickKeeps . ')?' . $issues . '\z/D',
                    $rhs,
                    $file . ' assigns a click public id from something that is neither'
                        . ' PublicClickIdKey::forNewClick() nor stored: ' . $rhs
                );
            }
        }
        self::assertGreaterThanOrEqual(12, $seen, 'the assignments were not found');
    }

    public function testNothingBuildsAClickIdBetweenRandomDigits(): void
    {
        foreach (self::servedFiles() as $file) {
            $source = (string) file_get_contents(self::ROOT . $file);
            self::assertDoesNotMatchRegularExpression(
                '/(?:random_int|mt_rand|rand)\s*\([^)]*\)\s*\.\s*\$click_?id\b/i',
                $source,
                $file . ' builds a click public id from the click id, which names its neighbours'
            );
        }
    }
}
