<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * A page that draws figures the spend ledger may have costed (DataEngine,
 * Group Overview, the Publishers report) says so beside them: every served
 * file under tracking202/ that reads one of those calls
 * p202_spend_ledger_note() or p202_spend_ledger_text(), is a spreadsheet
 * download (`*_download.php`), or is listed here with the reason it has
 * nowhere to say it.
 */
final class SpendLedgerNoteShownTest extends TestCase
{
    /** file => why it does not say it */
    private const array SILENT = [
        'tracking202/ajax/charts.php' => 'redraws the Overview chart inside account_overview.php,'
            . ' which says it for the same window',
    ];

    public function testEveryPageReadingLedgerCostedFiguresSaysHowFarTheSpendReaches(): void
    {
        $root = dirname(__DIR__, 2);
        $files = new \RecursiveIteratorIterator(SourceScan::tree($root . '/tracking202'));
        $readers = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = substr($file->getPathname(), strlen($root) + 1);
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/->(getReportData|getChart|getQuery)\s*\(|PublishersReport::read\s*\(/', $source) !== 1) {
                continue;
            }
            $readers[] = $path;
            if (isset(self::SILENT[$path]) || str_ends_with($path, '_download.php')) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/\bp202_spend_ledger_(note|text)\s*\(/',
                $source,
                "$path draws ledger-costed figures without the spend ledger note"
            );
        }
        self::assertContains('tracking202/ajax/account_overview.php', $readers, 'the scan found the pages');
        foreach (array_keys(self::SILENT) as $path) {
            self::assertContains($path, $readers, "$path is listed but reads none of these");
        }
    }
}
