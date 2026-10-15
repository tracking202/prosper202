<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Repository\LookupTablesWrittenOnlyByRepositoriesTest;
use Tests\Support\SourceScan;

/**
 * A click's rows are created by MysqlClickRepository and nothing else in the
 * served tree.
 *
 * Three writers created them: the repository (direct links and landing
 * pages), RotatorClickWriter (the redirector) and connect2.php's insert*()
 * functions, which nothing called any more. The redirector's REPLACE reset
 * every column of a re-clicked click it did not name, and the repository's
 * INSERT stored a direct link's empty public id as '', which a strict
 * sql_mode refused: each writer had its own faults, fixed in one place.
 *
 * Read with the lookup tables' scan (LookupTablesWrittenOnlyByRepositoriesTest::
 * inserts()): every INSERT or REPLACE into a click table outside the writer
 * fails unless OTHER_WRITERS lists it with why; the INSERTs whose table it
 * cannot read are the ones that test lists, and none of them reaches a click
 * table. An UPDATE or DELETE of a click's rows (a conversion, a re-click of
 * an offer, retention) is not seen: it makes no click.
 */
final class ClickRowsWrittenOnlyByTheWriterTest extends TestCase
{
    private const WRITER = '202-config/Click/MysqlClickRepository.php';

    public const CLICK_TABLES = [
        '202_clicks_counter', '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking',
        '202_clicks_variable', '202_google', '202_bing', '202_facebook', '202_clicks_rotator',
        '202_clicks_record', '202_clicks_site',
    ];

    /** @var array<string, array<string, string>> file => click table => why */
    private const OTHER_WRITERS = [
        '202-config/Ltv/MysqlCustomerRepository.php' => [
            '202_clicks_tracking' => 'stampClickCustomer(): a click already recorded takes its customer, and one'
                . ' recorded before tracking rows were written gets the row, marked for the rollup',
        ],
        'tracking202/redirect/offrtr.php' => [
            '202_clicks_rotator' => 'a landing page click leaving through an offer rotator takes the route it'
                . ' leaves by; every column of the row is named, so the REPLACE resets none it did not set',
        ],
    ];

    /** How many writes OTHER_WRITERS holds. Lower it when one goes. */
    private const CEILING = 2;

    public function testOnlyTheWriterInsertsAClicksRows(): void
    {
        $problems = [];
        $seen = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if ($path === self::WRITER) {
                continue;
            }
            foreach (LookupTablesWrittenOnlyByRepositoriesTest::inserts($source) as [$line, $table]) {
                if (!in_array($table, self::CLICK_TABLES, true)) {
                    continue;
                }
                if (isset(self::OTHER_WRITERS[$path][$table])) {
                    $seen[$path][$table] = true;
                    continue;
                }
                $problems[] = "$path:$line inserts into $table";
            }
        }

        self::assertSame([], $problems, "A click's rows are written outside MysqlClickRepository:\n  "
            . implode("\n  ", $problems)
            . "\nBuild the click with ClickRecordBuilder and write it with recordClick() or recordReClick().");
        $stale = [];
        foreach (self::OTHER_WRITERS as $path => $tables) {
            foreach (array_keys($tables) as $table) {
                if (!isset($seen[$path][$table])) {
                    $stale[] = "$path  $table";
                }
            }
        }
        self::assertSame([], $stale, 'listed writers the scan no longer finds; drop them');
    }

    public function testTheListOnlyShrinks(): void
    {
        $count = array_sum(array_map('count', self::OTHER_WRITERS));

        self::assertLessThanOrEqual(self::CEILING, $count, 'a writer was added; see the class docblock');
        self::assertSame(self::CEILING, $count, 'one went: lower CEILING to ' . $count);
    }

    /** The writer is found by the scan, so an empty finding above is not a scan that read nothing. */
    public function testTheScanFindsTheWritersOwnInserts(): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/' . self::WRITER);
        $tables = array_unique(array_column(LookupTablesWrittenOnlyByRepositoriesTest::inserts($source), 1));
        sort($tables);
        $expected = array_values(array_diff(self::CLICK_TABLES, ['202_bing', '202_facebook']));
        sort($expected);

        self::assertSame($expected, $tables);
    }

    public function testEveryClickTableIsInTheSchema(): void
    {
        $defined = [];
        foreach (glob(SourceScan::repoRoot() . '/202-config/Database/Tables/*Tables.php') ?: [] as $file) {
            $class = 'Prosper202\\Database\\Tables\\' . basename($file, '.php');
            foreach ($class::getDefinitions() as $definition) {
                $defined[] = $definition->tableName;
            }
        }

        self::assertSame(
            [],
            array_values(array_diff(self::CLICK_TABLES, $defined)),
            'a name the scan looks for is wrong'
        );
    }
}
