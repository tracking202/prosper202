<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * The click path never compares a public id column with a quoted value. The
 * columns are integers, so MySQL casts the text first: `12abc` named record
 * 12 and `1e3` record 1000, and a value the request sent reached another
 * record than the one it spelled. The ids are read exactly (PublicId,
 * ClickId) and compared as numbers: bound, or an int put in the statement.
 *
 * Read per string literal of the click path's files: a column ending in
 * `_public` or named `public_id`, then `=`, then a quote. It does not follow
 * a value into a variable first (`$sql = "… = '" . $id . "'"` is seen,
 * `$col . "='"` is not), and it reads only the scripts and classes below.
 */
final class PublicIdsAreComparedAsNumbersTest extends TestCase
{
    private const CLICK_PATH = [
        'tracking202/redirect/',
        'tracking202/static/',
        '202-config/Click/',
        '202-config/connect2.php',
        '202-config/static-endpoint-helpers.php',
    ];

    private const QUOTED_COMPARE = '/\b\w*(?:_public|public_id)\s*=\s*\\\\?[\'"]/i';

    public function testNoPublicIdIsComparedAsText(): void
    {
        $found = [];
        $scanned = 0;
        foreach (SourceScan::phpFiles() as $file => $source) {
            if (!self::onTheClickPath($file)) {
                continue;
            }
            $scanned++;
            foreach (self::quotedCompares($source) as $line) {
                $found[] = "$file:$line";
            }
        }

        self::assertGreaterThan(30, $scanned, 'the click path\'s files were read');
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
        yield 'bound' => ["<?php \$s = 'SELECT 1 FROM t WHERE click_id_public = ?';", []];
        yield 'an int in the statement' => ["<?php \$s = \"SELECT 1 FROM t WHERE click_id_public=\" . \$p;", []];
        yield 'a PHP variable, not SQL' => ["<?php \$click_id_public = '';", []];
        yield 'another column' => ["<?php \$s = \"SELECT 1 FROM t WHERE click_id='\" . \$p . \"'\";", []];
    }

    /**
     * @dataProvider spellings
     * @param list<int> $lines
     */
    public function testTheScanReadsEverySpellingItClaims(string $php, array $lines): void
    {
        self::assertSame($lines, self::quotedCompares($php));
    }

    private static function onTheClickPath(string $file): bool
    {
        foreach (self::CLICK_PATH as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> the lines of the string literals that compare a public id with a quote */
    private static function quotedCompares(string $source): array
    {
        $lines = [];
        foreach (token_get_all($source) as $token) {
            if (
                !is_array($token)
                || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            ) {
                continue;
            }
            // A quoted literal's own quotes are PHP's, not the statement's.
            $text = $token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1];
            $offset = 0;
            while (preg_match(self::QUOTED_COMPARE, $text, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $lines[] = $token[2] + substr_count(substr($text, 0, $m[0][1]), "\n");
                $offset = $m[0][1] + strlen($m[0][0]);
            }
        }

        return $lines;
    }
}
