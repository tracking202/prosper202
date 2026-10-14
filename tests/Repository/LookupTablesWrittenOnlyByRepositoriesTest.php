<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\RuntimeWriteTables;
use Tests\Support\SourceScan;

/**
 * The lookup tables a click's ids point into — keywords, c1-c4, the UTM
 * values, the tracking variables and their sets, the locations, addresses
 * and site URLs — are written by the repositories in
 * 202-config/Repository/Mysql/ and nothing else in the served tree.
 *
 * Three implementations wrote them (connect2.php's INDEXES, connect.php's
 * INDEXES and the repositories), and the copies drifted apart: one stored a
 * quote with the backslash it had escaped twice, one sent an IPv6 address's
 * packed bytes into its SQL unescaped (a 0x22 byte broke the statement, a
 * 0x5c corrupted the address, so every click from it made a new row), one
 * cut a value where the others did not. Each fix landed in one copy.
 *
 * Read from the strings as PHP assembles them (AssembledStrings): every
 * INSERT or REPLACE into one of these tables outside the repositories fails,
 * unless OTHER_WRITERS lists it with why; and a write whose table the scan
 * cannot read is listed in UNREAD_WRITERS with the tables it can reach, none
 * of them a lookup table, rather than taken to write none (CLAUDE.md #20).
 * Both lists only shrink. What this cannot see: a write that is not an SQL
 * string in PHP, and an UPDATE or DELETE of these tables (neither makes a
 * row an id can point to).
 */
final class LookupTablesWrittenOnlyByRepositoriesTest extends TestCase
{
    private const HOME = '202-config/Repository/Mysql/';

    private const LOOKUP_TABLES = [
        '202_keywords', '202_tracking_c1', '202_tracking_c2', '202_tracking_c3', '202_tracking_c4',
        '202_utm_source', '202_utm_medium', '202_utm_campaign', '202_utm_term', '202_utm_content',
        '202_custom_variables', '202_variable_sets', '202_variable_sets2',
        '202_locations_country', '202_locations_city', '202_locations_region', '202_locations_isp',
        '202_ips', '202_ips_v6', '202_site_domains', '202_site_urls',
    ];

    /** @var array<string, array<string, string>> file => lookup table => why */
    private const OTHER_WRITERS = [
        '202-config/functions-upgrade.php' => [
            '202_variable_sets2' => 'the 1.9.30-era upgrade rungs that filled the set map from 202_variable_sets'
                . ' when the table was added: a tagged version\'s step is frozen (CLAUDE.md #33)',
        ],
    ];

    /** @var array<string, string> file => what decides its table */
    private const UNREAD_WRITERS = [
        '202-config/DataEngine/ClickRollupSql.php' => 'insertSelect(): the rollup table its caller names',
        '202-config/class-dataengine.php' => 'doSummary(): 202_dataengine or 202_dataengine_new',
        'api/v3/Controller.php' => 'the table each CRUD controller names in tableName()',
        '202-config/Crud/MysqlCrudRepository.php' => 'the table its TableConfig names',
    ];

    /** How many writers the two lists hold. Lower it when one goes. */
    private const CEILING = 5;

    private const INSERT = '/\b(?:INSERT|REPLACE)\b(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*'
        . '(?:\s+INTO)?\s*(?:`?(202_\w+)`?|`?(\x00|%(?:\d+\$)?s)`?)/i';

    public function testOnlyTheRepositoriesInsertIntoTheLookupTables(): void
    {
        $problems = [];
        $seen = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (str_starts_with($path, self::HOME)) {
                continue;
            }
            foreach (self::inserts($source) as [$line, $table]) {
                if ($table === '?') {
                    if (isset(self::UNREAD_WRITERS[$path])) {
                        $seen[$path]['?'] = true;
                    } else {
                        $problems[] = "$path:$line inserts into a table the scan cannot read";
                    }
                    continue;
                }
                if (!in_array($table, self::LOOKUP_TABLES, true)) {
                    continue;
                }
                if (isset(self::OTHER_WRITERS[$path][$table])) {
                    $seen[$path][$table] = true;
                    continue;
                }
                $problems[] = "$path:$line inserts into $table";
            }
        }

        self::assertSame([], $problems, "A lookup table is written outside the repositories:\n  "
            . implode("\n  ", $problems)
            . "\nLook the id up through LookupRepositoryFactory::location(\$db) or ::tracking(\$db)"
            . " (findOrCreate*()). A write whose table is built at runtime is listed in UNREAD_WRITERS"
            . ' with the tables it can reach.');

        $stale = [];
        foreach (self::OTHER_WRITERS as $path => $tables) {
            foreach (array_keys($tables) as $table) {
                if (!isset($seen[$path][$table])) {
                    $stale[] = "$path  $table";
                }
            }
        }
        foreach (array_keys(self::UNREAD_WRITERS) as $path) {
            if (!isset($seen[$path]['?'])) {
                $stale[] = "$path  (unread table)";
            }
        }
        self::assertSame(
            [],
            $stale,
            "Listed writers the scan no longer finds; drop them:\n  " . implode("\n  ", $stale)
        );
    }

    public function testTheListsOnlyShrink(): void
    {
        $count = count(self::UNREAD_WRITERS);
        foreach (self::OTHER_WRITERS as $tables) {
            $count += count($tables);
        }

        self::assertLessThanOrEqual(self::CEILING, $count, 'a writer was added; see the class docblock');
        self::assertSame(self::CEILING, $count, 'one went: lower CEILING to ' . $count . ' so it cannot return unseen');
    }

    public function testTheUnreadWritesReachNoLookupTable(): void
    {
        $reach = [
            '202-config/DataEngine/ClickRollupSql.php' => ['202_dataengine', '202_dataengine_new'],
            '202-config/class-dataengine.php' => ['202_dataengine', '202_dataengine_new'],
            'api/v3/Controller.php' => RuntimeWriteTables::controllers(),
            '202-config/Crud/MysqlCrudRepository.php' => RuntimeWriteTables::tableConfigs(),
        ];
        self::assertSame(array_keys(self::UNREAD_WRITERS), array_keys($reach), 'every unread writer is read');
        self::assertGreaterThanOrEqual(8, count($reach['api/v3/Controller.php']), 'every CRUD controller is read');
        self::assertGreaterThanOrEqual(5, count($reach['202-config/Crud/MysqlCrudRepository.php']));

        foreach ($reach as $path => $tables) {
            self::assertSame([], array_values(array_intersect($tables, self::LOOKUP_TABLES)), $path);
        }
    }

    public function testEveryLookupTableIsInTheSchema(): void
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
            array_values(array_diff(self::LOOKUP_TABLES, $defined)),
            'a name the scan looks for is wrong'
        );
    }

    /** @return iterable<string, array{string, list<string>}> source, tables the scan must report */
    public static function spellings(): iterable
    {
        yield 'a SET clause' => [
            "<?php \$db->query(\"INSERT INTO 202_keywords SET keyword='\" . \$k . \"'\");",
            ['202_keywords'],
        ];
        yield 'a column list, backticked' => [
            '<?php $q = "insert ignore into `202_variable_sets2` (`variable_set_id`, `variables`) values (1, 2)";',
            ['202_variable_sets2'],
        ];
        yield 'REPLACE' => ['<?php $q = "REPLACE INTO 202_tracking_c1 SET c1 = ?";', ['202_tracking_c1']];
        yield 'over lines' => ["<?php \$q = 'INSERT\n    INTO\n 202_ips\n SET ip_address = ?';", ['202_ips']];
        yield 'a table by its constant' => [
            "<?php use Prosper202\\Database\\Schema\\TableRegistry;\n"
                . "\$q = 'INSERT INTO ' . TableRegistry::SITE_URLS . ' SET site_url_address = ?';",
            ['202_site_urls'],
        ];
        yield 'a heredoc' => ["<?php \$q = <<<SQL\nINSERT INTO 202_locations_city SET city_name = '{\$c}'\nSQL;", [
            '202_locations_city',
        ]];
        yield 'inside a call\'s arguments' => [
            "<?php \$s = \$this->conn->prepareWrite('INSERT INTO 202_ips_v6 SET ip_address = ?');",
            ['202_ips_v6'],
        ];
        yield 'a table read from a variable' => ["<?php \$db->query('INSERT INTO ' . \$table . ' SET c1 = 1');", ['?']];
        yield 'a table interpolated' => ['<?php $db->query("INSERT INTO {$t} SET a = 1");', ['?']];
        yield 'a table by sprintf' => ["<?php \$q = sprintf('INSERT INTO %s (a) VALUES (?)', \$t);", ['?']];
        yield 'another table' => ['<?php $q = "INSERT INTO 202_clicks SET click_id = 1";', ['202_clicks']];
    }

    /**
     * @dataProvider spellings
     * @param list<string> $expected
     */
    public function testTheScanReadsEverySpelling(string $source, array $expected): void
    {
        self::assertSame($expected, array_column(self::inserts($source), 1));
    }

    /** @return iterable<string, array{string}> */
    public static function notInserts(): iterable
    {
        yield 'a read' => ['<?php $q = "SELECT ip_id FROM 202_ips WHERE ip_address = ?";'];
        yield 'an update' => ['<?php $q = "UPDATE 202_site_urls SET site_url_address = ?";'];
        yield 'a delete' => ['<?php $q = "DELETE FROM 202_keywords WHERE keyword_id = 1";'];
        yield 'prose' => ["<?php \$m = 'Failed to insert into ' . \$table . ': ' . \$e;"];
        yield 'a comment' => ['<?php // INSERT INTO 202_ips SET ip_address = ?' . "\n"];
    }

    /** @dataProvider notInserts */
    public function testTheScanLeavesOtherStatementsAlone(string $source): void
    {
        self::assertSame([], self::inserts($source));
    }

    /**
     * Each INSERT or REPLACE in a source: its line and its table, lower-cased,
     * or '?' when the scan cannot read it. A table the scan cannot read after
     * a keyword inside a string is prose ("could not insert " . $what).
     *
     * @return list<array{int, string}>
     */
    private static function inserts(string $source): array
    {
        $out = [];
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            if (preg_match_all(self::INSERT, $text, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($ms as $m) {
                $named = ($m[1][1] ?? -1) >= 0 && $m[1][0] !== '';
                if ($named) {
                    $out[] = [$line, strtolower($m[1][0])];
                } elseif (preg_match('/^\s*$/', substr($text, 0, $m[0][1])) === 1) {
                    $out[] = [$line, '?'];
                }
            }
        }

        return $out;
    }
}
