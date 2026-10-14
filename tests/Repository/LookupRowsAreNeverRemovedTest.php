<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * A lookup's cache keeps a value's id for 30 days (MemcacheIdCache). That is
 * safe only while a row, once made, keeps its id and its value: a deleted
 * row leaves cached ids naming nothing, a truncated table gives its ids to
 * other values, and an updated row answers for a value it no longer holds.
 * No served code removes or changes a lookup row; the frozen upgrade rungs
 * that did are listed. A statement whose table is built at run time is
 * listed per file, with what names its tables: a new one is read before it
 * is listed (CLAUDE.md #20).
 *
 * Read from the strings as PHP assembles them: DELETE … FROM, TRUNCATE,
 * DROP TABLE, RENAME TABLE, and UPDATE with a SET in the same string. Not
 * seen: a statement split across separate strings.
 */
final class LookupRowsAreNeverRemovedTest extends TestCase
{
    private const REMOVAL = '/\b(?:DELETE\b(?:\s+(?:LOW_PRIORITY|QUICK|IGNORE))*\s+FROM'
        . '|TRUNCATE(?:\s+TABLE)?|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|RENAME\s+TABLE'
        . '|UPDATE(?:\s+LOW_PRIORITY)?(?:\s+IGNORE)?(?=\s+\S+[^;]*?\bSET\b))'
        . '\s+`?((?:[\w\x00]|%(?:\d+\$)?s)+)/i';

    /** file => lookup tables its statements remove or change, with why that is allowed */
    private const FROZEN = [
        '202-config/functions-upgrade.php' => [
            'tables' => [
                '202_browsers', '202_locations_city', '202_locations_country', '202_locations_region', '202_platforms',
            ],
            'why' => 'the 1.8.2 rung, frozen (CLAUDE.md #33): it rebuilt the location tables and emptied'
                . ' browsers and platforms',
        ],
    ];

    /** file => [statements whose table is built at run time, what names their tables] */
    private const UNREAD = [
        '202-config/Attribution/AttributionStore.php' => [1, 'the three attribution tables, by name'],
        '202-config/Click/ClickRetention.php' => [1, 'ClickRetention::TABLES, the click tables'],
        '202-config/Conversion/Ledger/MysqlConversionLedger.php' => [2, '202_clicks and 202_clicks_spy, by name'],
        '202-config/Crud/MysqlCrudRepository.php' => [3, 'its TableConfig (LookupTablesWrittenOnlyByRepositoriesTest'
            . '::testTheUnreadWritesReachNoLookupTable)'],
        '202-config/Ltv/MysqlCustomerCrmRepository.php' => [2, 'the customer tables it lists, by name'],
        '202-config/Update/CpcUpdate.php' => [1, '202_clicks, with joins appended'],
        'api/v3/Apps/AppRetention.php' => [1, 'its retention classes\' tables, the app event tables'],
        'api/v3/Controller.php' => [4, 'each CRUD controller\'s tableName() (LookupTablesWrittenOnlyByRepositoriesTest'
            . '::testTheUnreadWritesReachNoLookupTable)'],
    ];

    public function testNoServedCodeRemovesOrChangesALookupRow(): void
    {
        $problems = [];
        $frozen = [];
        $unread = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (self::removals($source) as [$line, $table]) {
                if ($table === '?') {
                    $unread[$path] = ($unread[$path] ?? 0) + 1;
                } elseif (in_array($table, LookupTablesWrittenOnlyByRepositoriesTest::LOOKUP_TABLES, true)) {
                    if (in_array($table, self::FROZEN[$path]['tables'] ?? [], true)) {
                        $frozen[$path][$table] = true;
                    } else {
                        $problems[] = "$path:$line removes or changes rows of $table";
                    }
                }
            }
        }

        self::assertSame([], $problems, "A lookup row is removed or changed; its cached id would outlive it:\n  "
            . implode("\n  ", $problems));
        foreach (self::FROZEN as $path => ['tables' => $tables]) {
            $found = array_keys($frozen[$path] ?? []);
            sort($found);
            self::assertSame($tables, $found, "$path: the listed tables are the ones it touches");
        }
        $listed = array_map(static fn (array $entry): int => $entry[0], self::UNREAD);
        ksort($unread);
        ksort($listed);
        self::assertSame($listed, $unread, 'A removal or change whose table is built at run time, unlisted or gone:'
            . ' read what names its tables, then list it with that');
    }

    /** @return array<string, array{string, ?string}> */
    public static function shapes(): array
    {
        return [
            'DELETE' => ['DELETE FROM 202_keywords WHERE keyword_id = ?', '202_keywords'],
            'DELETE, backquoted, with options' => ['delete low_priority quick from `202_ips` where 1', '202_ips'],
            'TRUNCATE' => ['TRUNCATE 202_site_urls', '202_site_urls'],
            'TRUNCATE TABLE' => ['TRUNCATE TABLE 202_browsers;', '202_browsers'],
            'DROP TABLE IF EXISTS' => ['DROP TABLE IF EXISTS 202_tracking_c1', '202_tracking_c1'],
            'RENAME TABLE' => ['RENAME TABLE 202_ips_v6 TO old_ips_v6', '202_ips_v6'],
            'UPDATE' => ['UPDATE 202_device_models SET device_type = 2 WHERE device_id = ?', '202_device_models'],
            'UPDATE IGNORE' => ['UPDATE IGNORE `202_platforms` SET platform_name = ?', '202_platforms'],
            'a table built at run time' => ["DELETE FROM \x00 WHERE id = ?", '?'],
            'a sprintf() table' => ['UPDATE %s SET %s = ? WHERE %s = ?', '?'],
            'another table' => ['DELETE FROM 202_clicks WHERE click_id = ?', '202_clicks'],
            'a longer name' => ['DELETE FROM 202_keywords_archive', '202_keywords_archive'],
            'prose' => ['edit it with p202 goal update 12', null],
            'an INSERT' => ['INSERT INTO 202_keywords SET keyword = ?', null],
        ];
    }

    /** @dataProvider shapes */
    public function testTheScanReadsEachShape(string $sql, ?string $table): void
    {
        $found = self::tablesIn($sql);
        self::assertSame($table === null ? [] : [$table], $found, $sql);
    }

    /** @return list<array{int, string}> line and table ('?' when built at run time) of each statement */
    private static function removals(string $source): array
    {
        $out = [];
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            foreach (self::tablesIn($text) as $table) {
                $out[] = [$line, $table];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function tablesIn(string $text): array
    {
        SourceScan::countMatches(self::REMOVAL, $text, 'a string', $matches);
        $tables = [];
        foreach ($matches[1] ?? [] as $name) {
            $built = str_contains($name, AssembledStrings::UNREAD) || str_contains($name, '%');
            $tables[] = $built ? '?' : strtolower($name);
        }

        return $tables;
    }
}
