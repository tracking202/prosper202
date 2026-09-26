<?php

declare(strict_types=1);

namespace Tests\Analyze;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;
use Tracking202\Analyze\AnalyzeReportController;

/**
 * The thirteen Analyze report pages that share AnalyzeReportController and
 * templates/report.php: that each page is wired to the report it names, that
 * the pure pieces the template depends on hold, and that the one option the
 * pages added to p202_data_table() renders as the sort control it stands for.
 *
 * What only a running instance can say — the same rows and totals as the
 * classic fragment, the filters taking effect, the downloads — is measured
 * against a live install (tests/browser/specs/analyze-reports.spec.js, and
 * the comparison recorded in the U3 report).
 */
final class AnalyzeReportPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 2);
        require_once $root . '/202-config/functions-ui.php';
        require_once $root . '/tracking202/analyze/AnalyzeReportController.php';
    }

    public function testEveryReportPageHandsItsOwnReportToTheController(): void
    {
        $root = dirname(__DIR__, 2) . '/tracking202/analyze/';
        $pages = [];
        foreach (AnalyzeReportController::REPORTS as $type => $report) {
            $file = $root . $report['page'];
            self::assertFileExists($file, "$type has a page");
            $source = (string) file_get_contents($file);
            $calls = preg_match_all("/new \\\\Tracking202\\\\Analyze\\\\AnalyzeReportController\\('([a-z]+)'\\)\\)->handleRequest\\(\\);/", $source, $m);
            self::assertSame(1, $calls, $report['page'] . ' runs the controller exactly once');
            self::assertSame($type, $m[1][0], $report['page'] . " asks for the $type report");
            self::assertFileExists($root . $report['download'], "$type's download exists");
            $pages[] = $report['page'];
        }
        self::assertCount(13, array_unique($pages), 'thirteen reports, thirteen pages');
    }

    public function testEveryAnalyzeReportInTheSubMenuIsOnePageOrTheTwoWithTheirOwnController(): void
    {
        $menu = (string) file_get_contents(dirname(__DIR__, 2) . '/tracking202/_config/sub-menu.php');
        preg_match_all("#'tracking202/analyze/([a-z_]+\\.php)'#", $menu, $m);
        $known = array_column(AnalyzeReportController::REPORTS, 'page');
        foreach ($m[1] as $page) {
            if (in_array($page, ['ltv.php', 'mobile_apps.php'], true)) {
                continue;
            }
            self::assertContains($page, $known, "the Analyze strip's $page is one of the shared report pages");
        }
    }

    public function testTheStoredWindowIsWhatTheRangePickerShows(): void
    {
        $from = mktime(0, 0, 0, 9, 1, 2026);
        $to = mktime(23, 59, 59, 9, 10, 2026);
        self::assertSame(
            ['range' => 'last7', 'from' => '2026-09-01', 'to' => '2026-09-10'],
            AnalyzeReportController::windowOf(['from' => $from, 'to' => $to, 'user_pref_time_predefined' => 'last7'])
        );
        self::assertSame(
            ['range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-10'],
            AnalyzeReportController::windowOf(['from' => $from, 'to' => $to, 'user_pref_time_predefined' => ''])
        );
        self::assertSame(
            ['range' => 'custom', 'from' => '', 'to' => ''],
            AnalyzeReportController::windowOf(['from' => 0, 'to' => 0, 'user_pref_time_predefined' => '']),
            'a custom window never set shows empty dates, not 1970'
        );
    }

    public function testTheVariableReportIsFlattenedTheWayTheClassicTableWalkedIt(): void
    {
        $value = static fn (string $v, string $clicks): array => ['variable_value' => $v, 'clicks' => $clicks, 'variable_name' => 'placement', 'ppc_network_name' => 'Google Ads'];
        $totals = ['total_clicks' => '9'];
        // DataEngine::doVariableReport(): totals first, then one entry per
        // traffic source keyed by its id, then the totals again.
        $data = [
            0 => $totals,
            1 => [
                0 => $value('a', '4'),
                'variables' => [
                    7 => [0 => $value('a', '4'), 'values' => [$value('a', '4'), $value('b', '5')]],
                ],
            ],
            2 => $totals,
        ];

        $report = AnalyzeReportController::variableReport($data);
        self::assertSame($totals, $report['totals']);
        self::assertCount(1, $report['groups'], 'the leading totals entry is not a group');
        self::assertSame('Google Ads', $report['groups'][0]['source']);
        self::assertSame('placement', $report['groups'][0]['variable']);
        self::assertSame(['a', 'b'], array_column($report['groups'][0]['values'], 'variable_value'));

        self::assertSame(['groups' => [], 'totals' => $totals], AnalyzeReportController::variableReport([0 => $totals, 1 => $totals]), 'no variables: no groups');
    }

    public function testAServerSortedColumnIsALinkAndSaysWhichColumnTheRowsAreIn(): void
    {
        $html = p202_data_table(
            [
                ['key' => 'k', 'label' => 'Keyword', 'sort' => 'text'],
                ['key' => 'c', 'label' => 'Clicks', 'num' => true, 'href' => '/r?order=sort_breakdown_clicks+desc&x=<y>'],
                ['key' => 'l', 'label' => 'Leads', 'num' => true, 'href' => '/r?order=sort_breakdown_leads+asc'],
            ],
            [['k' => 'a', 'c' => '1', 'l' => '2']],
            ['sorted' => ['key' => 'l', 'dir' => 'descending']]
        );
        $x = $this->xpath($html);

        self::assertFalse($x->query('//table')->item(0)->hasAttribute('data-p202-sort'), 'a server-sorted table is not also sorted in the browser');
        self::assertSame(0, $x->query('//th[1]/a')->length, 'a column with no link has no sort control');
        $link = $x->query('//th[2]/a[@class="p202-sort"]');
        self::assertSame(1, $link->length, 'the heading is the sort control');
        self::assertSame('/r?order=sort_breakdown_clicks+desc&x=<y>', $link->item(0)->getAttribute('href'), 'the link is escaped once and survives the round trip');
        self::assertSame('Clicks', trim($link->item(0)->textContent));
        self::assertSame('descending', $x->query('//th[3]')->item(0)->getAttribute('aria-sort'), 'and the current order is on its column');

        $sortable = $this->xpath(p202_data_table(
            [['key' => 'c', 'label' => 'Clicks', 'num' => true, 'href' => '/r?order=x']],
            [['c' => '1']],
            ['sortable' => true]
        ));
        self::assertSame(0, $sortable->query('//a')->length, 'a table sorted in the browser ignores the link: one sort per table');
        self::assertSame(1, $sortable->query('//th/button[@class="p202-sort"]')->length);
    }

    public function testTheTemplateCarriesNoScriptOfItsOwn(): void
    {
        // The page is server-rendered: the classic one's loadContent() call
        // and tracking-report.js are gone, and nothing else took their place.
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/tracking202/analyze/templates/report.php');
        self::assertStringNotContainsString('<script', $template);
        self::assertStringContainsString("template_top((string) \$info['title'], ['ui' => 'v2'])", $template);
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();
        return new DOMXPath($doc);
    }
}
