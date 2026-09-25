<?php

declare(strict_types=1);

namespace Tests\Report;

use Prosper202\DataEngine\ReportView;
use Tests\TestCase;

/**
 * Every read of the report filters goes through ReportView::apply(), and
 * every request a v2 report page makes later carries its view.
 *
 * A view only works if nothing between the page and the query reads the
 * stored row around it: one reader that does — a fragment's own SELECT, a
 * download's — draws the other tab's filters again, silently. So this walks
 * the tree rather than trusting a list:
 *
 *  - every statement under 202-config/ that selects a report column (or *)
 *    from 202_users_pref, and every such statement in a file that installs a
 *    view (p202_report_view_begin()), is matched by a ReportView::apply() in
 *    the same file — counted, so a second read added beside a first is seen;
 *  - every URL a page hands a view to (p202_report_view_url(), and the
 *    overview pages' `fragment` and `download`) is a file that installs it.
 *
 * What it cannot see: a read built somewhere the scan does not look (a
 * string assembled across functions), and an apply() that is present but
 * not on the row the read returned. ReportViewTest pins apply()'s own
 * behaviour; the browser pass in overview-visitors-spy.spec.js drives the
 * two-tab case end to end.
 */
final class ReportViewReadersTest extends TestCase
{
    /**
     * Code under 202-config/ that reads the row but draws no report: schema,
     * install and upgrade code, and the user repository behind the API's
     * preferences endpoint, whose job is to say what is stored.
     */
    private const NOT_READERS = ['202-config/Database/', '202-config/migrations/', '202-config/install.php', '202-config/functions-upgrade.php', '202-config/connect2.php', '202-config/User/MysqlUserRepository.php'];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/202-config/functions-ui.php';
        require_once $this->root . '/202-config/functions-report-prefs.php';
    }

    public function testTheViewCarriesEveryColumnTheFilterWriterStores(): void
    {
        $columns = ['user_pref_time_predefined', 'user_pref_time_from', 'user_pref_time_to'];
        foreach (p202_report_pref_fields() as $field) {
            $columns[] = $field['column'];
        }
        sort($columns);
        $view = ReportView::COLUMNS;
        sort($view);
        self::assertSame($columns, $view, 'a column a page writes but a view cannot carry would be read from the stored row, which another tab writes');
    }

    public function testEveryReadOfTheReportFiltersGoesThroughTheView(): void
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/202-config', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($this->root) + 1);
            }
        }
        foreach ($this->phpFiles(['tracking202/Report']) as $file) {
            $files[] = $file;
        }
        foreach ($this->filesInstallingAView() as $file) {
            $files[] = $file;
        }

        $checked = [];
        foreach (array_unique($files) as $file) {
            foreach (self::NOT_READERS as $skip) {
                if (str_starts_with($file, $skip)) {
                    continue 2;
                }
            }
            $source = (string) file_get_contents($this->root . '/' . $file);
            $reads = $this->reportReads($source);
            if ($reads === 0) {
                continue;
            }
            $checked[] = $file;
            $applies = preg_match_all('/\bReportView::apply\(/', $source);
            self::assertGreaterThanOrEqual($reads, $applies, "$file reads the report filters from 202_users_pref $reads time(s) but passes a row through ReportView::apply() $applies time(s); a read around the view draws another tab's filters");
        }
        // The scan must find the readers it exists for, or it proves nothing.
        foreach (['202-config/functions-tracking202.php', '202-config/class-dataengine.php', '202-config/functions-report-prefs.php', 'tracking202/ajax/account_overview.php', 'tracking202/overview/group_overview_download.php', 'tracking202/Report/ReportPrefsStore.php', 'tracking202/analyze/keywords_download.php'] as $reader) {
            self::assertContains($reader, $checked, "the scan sees $reader's read of the report filters");
        }
    }

    public function testEveryRequestAPageHandsAViewInstallsIt(): void
    {
        $targets = [];
        foreach ($this->phpFiles(['tracking202', '202-config']) as $file) {
            $source = (string) file_get_contents($this->root . '/' . $file);
            preg_match_all("/p202_report_view_url\\(\\s*\\\$base\\s*\\.\\s*'([^']+)'/", $source, $m);
            preg_match_all("/'(?:fragment|download)'\\s*=>\\s*\\\$base\\s*\\.\\s*'([^']+)'/", $source, $n);
            foreach (array_merge($m[1], $n[1]) as $url) {
                $targets[$url] = $file;
            }
        }
        self::assertNotEmpty($targets, 'the pages hand views to fragments and downloads');
        foreach ($targets as $url => $from) {
            $path = strtok($url, '?');
            $file = str_ends_with($path, '/') ? $path . 'index.php' : $path;
            self::assertFileExists($this->root . '/' . $file, "$from hands a view to $url");
            self::assertMatchesRegularExpression('/\bp202_report_view_begin\(/', (string) file_get_contents($this->root . '/' . $file),
                "$file is handed a view by $from but never installs it, so it draws whatever the stored filters say by then");
        }
    }

    public function testEveryAnalyzeDownloadInstallsTheViewItsPageHandsIt(): void
    {
        require_once $this->root . '/tracking202/analyze/AnalyzeReportController.php';
        $controller = (string) file_get_contents($this->root . '/tracking202/analyze/AnalyzeReportController.php');
        self::assertMatchesRegularExpression("/'downloadUrl' => p202_report_view_url\\(/", $controller, 'the report page hands its download the view it drew');
        foreach (\Tracking202\Analyze\AnalyzeReportController::REPORTS as $type => $report) {
            $file = 'tracking202/analyze/' . $report['download'];
            self::assertFileExists($this->root . '/' . $file);
            self::assertMatchesRegularExpression('/\bp202_report_view_begin\(/', (string) file_get_contents($this->root . '/' . $file),
                "$file ($type) is handed a view but never installs it, so it exports whatever the stored filters say by then");
        }
    }

    public function testAnOverviewPageHandsItsViewToItsFragmentAndItsDownload(): void
    {
        require_once $this->root . '/202-config/functions-ui-overview.php';
        $html = p202_overview_page([
            'id' => 'v', 'title' => 'T', 'desc' => 'D', 'icon' => 'bi-x', 'action' => '/p/', 'panel' => 'P',
            'fragment' => '/f.php?spy=1', 'download' => '/d/', 'names' => ['user_pref_show'], 'lists' => [], 'base' => '/',
            'state' => ['values' => ['user_pref_show' => 'real'], 'errors' => [], 'decided' => [], 'range' => 'today', 'from' => '2026-09-25', 'to' => '2026-09-25',
                'view' => 'range=today&user_pref_show=real'],
        ]);
        $view = 'view=' . rawurlencode('range=today&user_pref_show=real');
        self::assertStringContainsString('data-p202-report="/f.php?spy=1&amp;' . $view . '"', $html, 'the fragment is asked for under the page\'s view');
        self::assertStringContainsString('href="/d/?' . $view . '"', $html, 'the download exports it');
    }

    /** @return list<string> */
    private function filesInstallingAView(): array
    {
        $files = [];
        foreach ($this->phpFiles(['tracking202', '202-config']) as $file) {
            if (preg_match('/\bp202_report_view_begin\(/', (string) file_get_contents($this->root . '/' . $file)) === 1
                && !str_ends_with($file, 'functions-report-prefs.php')) {
                $files[] = $file;
            }
        }
        self::assertNotEmpty($files);
        return $files;
    }

    /**
     * How many statements in this source read a report column (or every
     * column) from 202_users_pref.
     */
    private function reportReads(string $source): int
    {
        $reads = 0;
        if (preg_match_all('/\b(?:FROM|JOIN)\s+`?202_users_pref`?\b/i', $source, $m, PREG_OFFSET_CAPTURE) === 0) {
            return 0;
        }
        foreach ($m[0] as [$match, $offset]) {
            $before = substr($source, max(0, $offset - 1200), min(1200, $offset));
            $select = strripos($before, 'SELECT');
            if ($select === false) {
                continue;
            }
            $list = substr($before, $select + 6);
            // An UPDATE ... or INSERT ... SELECT is not a read of the row.
            if (preg_match('/\b(UPDATE|INSERT|DELETE)\b/i', $list) === 1) {
                continue;
            }
            $reads += $this->namesAReportColumn($list) ? 1 : 0;
        }
        return $reads;
    }

    private function namesAReportColumn(string $selectList): bool
    {
        if (preg_match('/(^|[\s,.])\*/', $selectList) === 1) {
            return true;
        }
        foreach (ReportView::COLUMNS as $column) {
            if (preg_match('/\b' . preg_quote($column, '/') . '\b/', $selectList) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $dirs
     * @return list<string>
     */
    private function phpFiles(array $dirs): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen($this->root) + 1);
                }
            }
        }
        sort($files);
        return $files;
    }
}
