<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * The report pages build no SQL list with GROUP_CONCAT and paste no text into
 * LIKE.
 *
 * Both shapes shipped in the pages' filters and both fail in silence: a
 * GROUP_CONCAT id list is cut at group_concat_max_len (1024 bytes on MySQL 8)
 * with a warning nobody reads, so the referer filter dropped every click of
 * the URLs past the cut and the report read as complete; and text pasted
 * into a LIKE pattern turns the visitor's % and _ into wildcards, so a
 * keyword filter of `50%` matched "500 off". The filters are subqueries now
 * (Prosper202\DataEngine\TextFilterSql, the API's ReportFilter); this keeps
 * the next report from bringing either back.
 *
 * Read from the PHP tokens of every file a report page runs: each string
 * piece of SQL (a literal, or the text between interpolations) is checked,
 * so `"... LIKE '%" . $x . "%'"` and `"... LIKE '%$x%'"` are both seen —
 * the piece ends with the LIKE's open quote and the pattern comes from
 * outside it. `LIKE ?` with a bound, escaped pattern is the way to write one.
 */
final class ReportFiltersBuildNoListsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /** What the report pages run: directories (every *.php under them) and files. */
    private const REPORT_CODE = [
        'tracking202/ajax',
        'tracking202/analyze',
        'tracking202/overview',
        'tracking202/visitors',
        'tracking202/spy',
        'tracking202/Report',
        '202-config/DataEngine',
        '202-config/class-dataengine.php',
        '202-config/ReportSummaryForm.class.php',
        '202-config/ReportBasicForm.class.php',
        '202-config/functions-tracking202.php',
        '202-config/functions-report-prefs.php',
        '202-config/functions-ui-overview.php',
    ];

    /** A piece of SQL that ends where a LIKE pattern's text is pasted in. */
    private const PASTED_LIKE = '/\bLIKE\s+(?:CONVERT\s*\(\s*_utf8\s*)?\\\\?[\'"]%?\s*$/i';

    /** @return list<string> */
    private static function files(): array
    {
        $files = [];
        foreach (self::REPORT_CODE as $path) {
            $full = self::ROOT . $path;
            if (is_file($full)) {
                $files[] = $path;
                continue;
            }
            self::assertDirectoryExists($full, "$path is listed as report code and is gone; update the list");
            $it = new \RecursiveIteratorIterator(\Tests\Support\SourceScan::tree($full));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen(self::ROOT));
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The string pieces of a file, with their lines: literals, and the text
     * between a double-quoted string's or heredoc's interpolations.
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function pieces(string $code): array
    {
        $out = [];
        foreach (\PhpToken::tokenize($code) as $token) {
            if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                $text = $token->text;
                if ($token->id === T_CONSTANT_ENCAPSED_STRING) {
                    // Drop the delimiters, so a piece that ENDS in an open
                    // quote is told from one whose closing quote is the PHP
                    // string's own.
                    $text = substr($text, 1, -1);
                }
                $out[] = [$text, $token->line];
            }
        }

        return $out;
    }

    /**
     * @return list<string> file:line — what
     */
    private static function violations(string $file, string $code): array
    {
        $found = [];
        foreach (self::pieces($code) as [$text, $line]) {
            if (preg_match('/\bgroup_concat\s*\(/i', $text) === 1) {
                $found[] = "$file:$line builds a list with GROUP_CONCAT, which the server cuts at group_concat_max_len without an error; select the rows (a join or a subquery)";
            }
            if (preg_match(self::PASTED_LIKE, $text) === 1) {
                $found[] = "$file:$line pastes text into a LIKE pattern, where its % and _ are wildcards; bind it escaped (ReportFilter, TextFilterSql)";
            }
        }

        return $found;
    }

    public function testNoReportPageBuildsAListOrPastesALikePattern(): void
    {
        $files = self::files();
        self::assertGreaterThan(40, count($files), 'the report code was found');
        $found = [];
        foreach ($files as $file) {
            $found = [...$found, ...self::violations($file, (string) file_get_contents(self::ROOT . $file))];
        }
        self::assertSame([], $found, implode("\n", $found));
    }

    /** @return iterable<string, array{string}> */
    public static function shapesTheCheckMustSee(): iterable
    {
        yield 'the referer id list' => ['<?php $sql = "SELECT GROUP_CONCAT(distinct 2de.click_referer_site_url_id) AS site_url_id FROM 202_dataengine";'];
        yield 'lower case, a space before the paren' => ['<?php $sql = \'SELECT group_concat (keyword_id) FROM 202_keywords\';'];
        yield 'concatenated contains pattern' => ['<?php $f = " AND 2k.keyword like \'%" . $kw . "%\'";'];
        yield 'interpolated contains pattern' => ['<?php $f = " AND 2k.keyword LIKE \'%$kw%\'";'];
        yield 'prefix pattern' => ['<?php $f = " AND 2i.ip_address LIKE \'" . $ip . "%\'";'];
        yield 'the CONVERT form' => ['<?php $f = " AND keyword LIKE CONVERT( _utf8 \'%" . $kw . "%\' USING utf8 )";'];
        yield 'a single-quoted string with an escaped quote' => ['<?php $f = \' AND k.keyword LIKE \\\'%\' . $kw . \'%\\\'\';'];
        yield 'a heredoc' => ["<?php \$f = <<<SQL\n AND su.site_url_address LIKE '%{\$ref}%'\nSQL;\n"];
    }

    /**
     * Each shape the pages had, planted: the check sees it.
     *
     * @dataProvider shapesTheCheckMustSee
     */
    public function testTheCheckSeesEveryShapeThePagesHad(string $code): void
    {
        self::assertNotSame([], self::violations('planted.php', $code));
    }

    /** @return iterable<string, array{string}> */
    public static function shapesThatAreFine(): iterable
    {
        yield 'a bound pattern' => ['<?php $sql = "SELECT 1 FROM 202_keywords k WHERE k.keyword LIKE ? ESCAPE \'!\'";'];
        yield 'a fixed pattern' => ['<?php $sql = "SELECT 1 FROM t WHERE name LIKE \'%fixed%\'";'];
        yield 'a word in a comment' => ['<?php // GROUP_CONCAT( is cut at 1024 bytes' . "\n"];
    }

    /** @dataProvider shapesThatAreFine */
    public function testTheCheckPassesWhatIsFine(string $code): void
    {
        self::assertSame([], self::violations('fine.php', $code));
    }
}
