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
 * the last. That builder went with the classic shell (U8); the page reads
 * ReportSummaryForm's list for all four (p202_overview_groupings()), and
 * ReportSummaryForm runs every level's query whichever list offered it.
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
     * Group Overview's four "Group by" selectors draw their options from
     * ReportSummaryForm's list — the class that runs the query — in its
     * order, and from nothing else.
     */
    public function testThePageOffersTheReportsOwnList(): void
    {
        require_once $this->root . '/202-config/functions-ui.php';
        require_once $this->root . '/202-config/functions-ui-overview.php';
        self::assertSame(
            array_map('strval', \ReportSummaryForm::getDetailArray()),
            array_map('strval', array_keys(p202_overview_groupings()))
        );
    }
}
