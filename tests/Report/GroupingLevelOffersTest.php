<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * Every place that offers Group Overview's grouping levels offers the same
 * ones, and every level offered is one the report can run.
 *
 * The level lists are two copies (ReportBasicForm's and ReportSummaryForm's
 * private $DETAIL_LEVEL_ARRAY), and the classic advanced builder drew its
 * first three selectors from one and its fourth from the other — so the
 * Goal / source level, added to one copy, could be picked at every depth but
 * the last. The v2 page reads ReportSummaryForm's list for all four
 * (p202_overview_groupings()), and ReportSummaryForm runs every level's query
 * whichever list offered it.
 */
final class GroupingLevelOffersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/202-config/ReportSummaryForm.class.php';
    }

    public function testBothCopiesOfTheLevelListAreTheSameList(): void
    {
        self::assertSame(\ReportSummaryForm::getDetailArray(), \ReportBasicForm::getDetailArray());
        self::assertContains(\ReportBasicForm::DETAIL_LEVEL_GOAL_SOURCE, \ReportBasicForm::getDetailArray());
        self::assertContains(\ReportBasicForm::DETAIL_LEVEL_TRANSACTIONS, \ReportBasicForm::getDetailArray());
    }

    public function testEveryOfferedLevelHasALabelAGroupKeyAndAForm(): void
    {
        foreach (\ReportSummaryForm::getDetailArray() as $id) {
            self::assertNotSame('Unknown', \ReportBasicForm::translateDetailLevelById($id), "level $id has a label");
            self::assertNotSame('', (string) \ReportSummaryForm::translateDetailKeyById($id), "level $id has a group key");
            $class = (string) \ReportSummaryForm::translateDetailFunctionById($id);
            self::assertTrue($class !== '' && class_exists($class), "level $id has a row form ($class)");
        }
    }

    /**
     * The classic builder's four "Group By" selectors (display_calendar())
     * each draw their options from ReportSummaryForm's list — the class that
     * runs the query — and from nothing else.
     */
    public function testEveryClassicGroupingSelectorOffersTheReportsOwnList(): void
    {
        $source = (string) file_get_contents($this->root . '/202-config/functions-tracking202.php');
        $n = preg_match_all('~<select\b[^>]*\bname="details\[\]"[^>]*>(.*?)</select>~s', $source, $selects);
        self::assertSame(4, $n, 'the classic builder has four grouping selectors');
        foreach ($selects[1] as $i => $body) {
            $level = $i + 1;
            self::assertSame(1, preg_match_all('/getDetailArray\s*\(/', $body), "selector $level draws one level list");
            self::assertSame(1, preg_match('/\bforeach\s*\(\s*(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*getDetailArray\s*\(\s*\)\s+as\s+\$detail_item\s*\)/', $body, $m),
                "selector $level iterates a level list as \$detail_item");
            self::assertSame('ReportSummaryForm', ltrim($m[1], '\\'), "selector $level offers ReportSummaryForm's levels");
        }
    }
}
