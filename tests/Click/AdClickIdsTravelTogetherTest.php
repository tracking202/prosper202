<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Tests\Support\SourceScan;

/**
 * Every statement of the served tree that names the gclid names every other
 * ad click id a click keeps (ClickRecord::AD_CLICK_IDS), or its file lists
 * it in APART with why.
 *
 * The msclkid and fbclid were captured once: their tables, tokens and token
 * reads are still there, and the writer that filled the tables went with
 * the dead code it sat in, so [[msclkid]] and [[fbclid]] have filled with
 * nothing since, with no failure anywhere. A click id is carried by many
 * lists (the request's defaults, the writer, the tokens, the token reads,
 * the landing page script, the API's columns, the Visitors download), and
 * an id missing from one is silent in every direction. The gclid is in all
 * of them, so a list that names it is one the others belong in: adding a
 * name to AD_CLICK_IDS makes this report every list it is not in.
 *
 * A statement is the tokens between two `;`, `{` or `}`; a page's inline
 * markup (the landing page's script) is read chunk by chunk between its PHP
 * tags. Only string literals and markup are read, so a comment that names
 * the ids satisfies nothing, and a statement that builds the name another
 * way is not seen.
 */
final class AdClickIdsTravelTogetherTest extends TestCase
{
    /**
     * file => [how many of its statements name the gclid alone, why]. The
     * count is held exactly.
     *
     * @var array<string, array{int, string}>
     */
    private const APART = [
        'api/v3/Apps/Android/InstallClassifier.php' => [2, "the Play install referrer's gclid, not a click's"],
        'api/v3/Apps/Android/InstallIntake.php' => [3, "the Play install referrer's gclid, on 202_app_installs"],
        'api/v3/Apps/Android/ReferrerParser.php' => [1, "the Play install referrer's fields"],
        'api/v3/Controllers/AppInstallsController.php' => [2, "GET /apps/{id}/installs: the install referrer's gclid"],
        '202-config/Database/Tables/AppTables.php' => [1, "202_app_installs.gclid, the install referrer's"],
        '202-config/functions-upgrade.php' => [2, 'the rungs that created 202_google and widened its gclid: frozen,'
            . ' and the 1.9.79 rung reconciles the table to its definition'],
        '202-config/connect2.php' => [2, 'replaceTrackerPlaceholders() reads the ids 1.9.79 added apart'
            . ' (AdClickIdColumns::of()); getPrePopVars() stops the gclid from being passed on, and the'
            . ' others are passed on as they were before 1.9.79'],
        '202-config/static-endpoint-helpers.php' => [1, 'p202PostbackClickSql(): gpb.php and upx.php read the ids'
            . ' 1.9.79 added apart (AdClickIdColumns::of())'],
        '202-config/Conversion/TrafficSourcePixels.php' => [1, 'clickTokens() reads the ids 1.9.79 added apart'
            . ' (AdClickIdColumns::of())'],
        '202-config/Click/MysqlClickRepository.php' => [1, "writeGoogleRow(): the row a database the 1.9.79"
            . " upgrade has not reached takes"],
        'tracking202/static/gpb.php' => [1, "the gclid's token value, read with the click; the others are read"
            . ' apart (AdClickIdColumns::of())'],
        'tracking202/static/upx.php' => [1, 'as gpb.php'],
    ];

    public function testEveryStatementThatNamesTheGclidNamesEveryAdClickId(): void
    {
        $others = array_values(array_diff(array_keys(ClickRecord::AD_CLICK_IDS), ['gclid']));
        self::assertNotSame([], $others, 'AD_CLICK_IDS names the ids beside the gclid');

        $unlisted = [];
        $counts = [];
        $seen = 0;
        foreach (SourceScan::phpFiles() as $file => $source) {
            foreach (self::sites($source) as [$line, $text]) {
                if (!self::names($text, 'gclid')) {
                    continue;
                }
                $seen++;
                $missing = array_values(
                    array_filter($others, static fn (string $id): bool => !self::names($text, $id))
                );
                if ($missing === []) {
                    continue;
                }
                $counts[$file] = ($counts[$file] ?? 0) + 1;
                if (!isset(self::APART[$file])) {
                    $unlisted[] = "$file:$line names the gclid and not " . implode(', ', $missing);
                }
            }
        }

        self::assertGreaterThanOrEqual(20, $seen, 'the scan finds the statements that name the gclid');
        self::assertSame(
            [],
            $unlisted,
            'A statement names the gclid and not every other ad click id a click keeps'
            . ' (ClickRecord::AD_CLICK_IDS). Carry them all, or list the file in APART with why.'
        );
        $listed = array_map(static fn (array $entry): int => $entry[0], self::APART);
        ksort($listed);
        ksort($counts);
        self::assertSame($listed, $counts, 'APART holds each file\'s count exactly: lower it when one goes');
    }

    /** Each id the click keeps is a 202_google column, and a token the URLs fill. */
    public function testEachIdIsAColumnAndAToken(): void
    {
        $google = \Prosper202\Database\Tables\TrackingTables::google()->createStatement;
        $pixels = (new \ReflectionClass(\Prosper202\Conversion\TrafficSourcePixels::class))->getConstant('TOKENS');
        self::assertIsArray($pixels);
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $id) {
            self::assertMatchesRegularExpression('/^\s*`' . $id . '`\s/m', $google, "202_google has no $id column");
            self::assertArrayHasKey($id, $pixels, "[[$id]] is not a token TrafficSourcePixels fills");
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function plantedSites(): iterable
    {
        yield 'a list of every id' => ["<?php \$ids = ['gclid', 'gbraid', 'wbraid', 'ttclid'];", true];
        yield 'a list that lacks one' => ["<?php \$ids = ['gclid', 'gbraid', 'ttclid'];", false];
        yield 'a statement across lines' => ["<?php f('gclid',\n 'gbraid',\n 'wbraid',\n 'ttclid');", true];
        yield 'SQL that lacks them' => ['<?php $sql = "SELECT g.gclid FROM 202_google AS g";', false];
        yield 'markup that lacks one' => ["<?php ?>\n\"gclid=\" + a, \"gbraid=\" + b, \"wbraid=\" + c,\n", false];
        yield 'markup that has them' => [
            "<?php ?>\n\"gclid=\" + a, \"gbraid=\" + b, \"wbraid=\" + c, \"ttclid=\" + d\n",
            true,
        ];
        yield 'a comment does not count' => ["<?php // gbraid wbraid ttclid\n\$a = 'gclid';", false];
        yield 'another statement does not count' => [
            "<?php \$a = 'gclid'; \$b = ['gbraid', 'wbraid', 'ttclid'];",
            false,
        ];
        yield 'an interpolation does not end the statement' => [
            "<?php \$a = \"gclid {\$x['y']} gbraid\" . 'wbraid ttclid';",
            true,
        ];
    }

    /** @dataProvider plantedSites */
    public function testTheScanReadsEachShape(string $source, bool $carriesAll): void
    {
        $named = array_filter(self::sites($source), static fn (array $site): bool => self::names($site[1], 'gclid'));
        self::assertCount(1, $named, 'one statement names the gclid');
        $text = array_values($named)[0][1];
        $all = array_reduce(
            array_keys(ClickRecord::AD_CLICK_IDS),
            static fn (bool $carry, string $id): bool => $carry && self::names($text, $id),
            true
        );
        self::assertSame($carriesAll, $all);
    }

    private static function names(string $text, string $id): bool
    {
        return preg_match('/(?<![A-Za-z0-9_])' . preg_quote($id, '/') . '(?![A-Za-z0-9_])/i', $text) === 1;
    }

    /**
     * The statements of a source, each as its first line and the text of its
     * string literals and markup.
     *
     * @return list<array{int, string}>
     */
    private static function sites(string $source): array
    {
        $sites = [];
        $text = '';
        $line = null;
        $close = static function () use (&$sites, &$text, &$line): void {
            if ($text !== '') {
                $sites[] = [(int) $line, $text];
            }
            $text = '';
            $line = null;
        };
        // The `}` that ends a string's {$interpolation} ends no statement.
        $interpolations = 0;
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                if ($token === '}' && $interpolations > 0) {
                    $interpolations--;
                } elseif ($token === ';' || $token === '{' || $token === '}') {
                    $close();
                }
                continue;
            }
            [$id, $value, $at] = $token;
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $interpolations++;
                continue;
            }
            if ($id === T_INLINE_HTML) {
                $close();
                $sites[] = [$at, $value];
                continue;
            }
            if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $line ??= $at;
                $text .= ' ' . $value;
            }
        }
        $close();

        return $sites;
    }
}
