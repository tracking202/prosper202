<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Report\AnalyzeDownload;
use Prosper202\Report\TsvCell;
use Tests\Support\SourceScan;

/**
 * TsvCell is the one cell of every tab-separated download. This holds the
 * cell to one line, one column and no formula, and the tree to writing the
 * field separator nowhere else: a download that joins its cells with a tab
 * of its own is one whose cells nothing cleans.
 */
final class TsvCellTest extends TestCase
{
    /**
     * Files that write a tab that is not a download's field separator, with why.
     * The list only shrinks.
     */
    private const OTHER_TABS = [
        '202-config/functions-standalone-ui.php' => 'indents the page head\'s asset tags',
        '202-config/connect2.php' => 'the access log line, whose fields it cleans of tabs and line breaks itself',
        '202-config/Http/ForwardedHeader.php' => 'skips the spaces and tabs a Forwarded header holds between its parts',
    ];

    public function testATabOrALineBreakBecomesOneSpace(): void
    {
        self::assertSame('a b c d', TsvCell::text("a\tb\nc\r\n\td"));
        self::assertSame(' lead', TsvCell::text("\tlead"));
        self::assertSame("''", TsvCell::text("''"));
    }

    public function testAFormulaIsWrittenAsText(): void
    {
        foreach (['=1+1', '+cmd|calc', '-2+3', '@SUM(A1)', '=HYPERLINK("x")', '- Facebook', "=\t1"] as $value) {
            $cell = TsvCell::text($value);
            self::assertSame("'", $cell[0], $value . ' is written as text');
            self::assertStringNotContainsString("\t", $cell);
        }
    }

    public function testANumberStaysANumber(): void
    {
        foreach (['-12.50', '+3', '-100%', '12.5%', '-1,234.56', '-1e5', '0', '-0.10'] as $value) {
            self::assertSame($value, TsvCell::text($value));
        }
        self::assertSame('-5', TsvCell::text(-5));
        self::assertSame('-0.5', TsvCell::text(-0.5));
    }

    public function testNoneAndFlags(): void
    {
        self::assertSame('', TsvCell::text(null));
        self::assertSame('1', TsvCell::text(true));
        self::assertSame('0', TsvCell::text(false));
    }

    public function testARowIsOneLineOfItsCells(): void
    {
        self::assertSame("a b\t'=x\t\t3\n", TsvCell::row(["a\nb", '=x', null, 3]));
        self::assertSame("a\tb", TsvCell::line(['a', 'b']));
    }

    public function testTheGroupOverviewCellIsTsvCell(): void
    {
        require_once SourceScan::repoRoot() . '/202-config/ReportBasicForm.class.php';
        ob_start();
        try {
            \ReportBasicForm::echoCell("=a\tb\nc");
            \ReportBasicForm::echoCell(-4);
        } finally {
            $out = (string) ob_get_clean();
        }
        self::assertSame("'=a b c\t-4\t", $out);
    }

    public function testAnAnalyzeDownloadRowIsTsvCell(): void
    {
        $figures = ['clicks' => "1\t2", 'roi' => '-50', 'net' => '=x'];
        $line = AnalyzeDownload::row(" -- a\tb\nc", $figures, ['EUR', "USD\n1"]);

        self::assertSame(
            " -- a b c\t1 2\t\t\t\t\t\t\t\t\t\t'=x\t-50\tEUR\tUSD 1\n",
            $line
        );
        self::assertSame(14, substr_count($line, "\t"), 'a first cell, twelve figures and two money columns');
        self::assertSame(
            count(explode("\t", AnalyzeDownload::header("=a\tb"))),
            count(explode("\t", $line)),
            'the header has the row\'s columns'
        );
        self::assertStringStartsWith("'=a b\tClicks\t", AnalyzeDownload::header("=a\tb"));
    }

    public function testTheAnalyzeDownloadsWriteThroughTheClass(): void
    {
        $source = SourceScan::phpFiles()['202-config/class-dataengine.php'];
        self::assertSame(2, substr_count($source, 'AnalyzeDownload::header('), 'the report and variables headers');
        self::assertSame(4, substr_count($source, 'AnalyzeDownload::row('), 'the report row and three variable rows');
    }

    public function testNoOtherFileWritesTheFieldSeparator(): void
    {
        $found = [];
        $listed = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            if ($file === '202-config/Report/TsvCell.php') {
                continue;
            }
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $token[1] === '"\t"') {
                    if (isset(self::OTHER_TABS[$file])) {
                        $listed[$file] = true;
                    } else {
                        $found[] = $file . ':' . $token[2];
                    }
                }
            }
        }

        self::assertSame(
            [],
            $found,
            'a tab-separated download writes its cells with Prosper202\Report\TsvCell (row(), line(), or text() and '
            . 'TsvCell::SEPARATOR), so a tab or a line break a visitor sent cannot move a column. A tab that is not a '
            . 'download\'s separator is listed in OTHER_TABS with why.'
        );
        $expected = array_keys(self::OTHER_TABS);
        $seen = array_keys($listed);
        sort($expected);
        sort($seen);
        self::assertSame($expected, $seen, 'a file listed in OTHER_TABS still writes a tab');
    }
}
