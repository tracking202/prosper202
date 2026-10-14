<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\RuntimeWriteTables;
use Tests\Support\SourceScan;

/**
 * The lookup tables a click's ids point into — keywords, c1-c4, the UTM
 * values, the tracking variables and their sets, the locations, addresses,
 * site URLs, browsers, platforms and device models — are written by the
 * repositories in 202-config/Repository/Mysql/ and nothing else in the
 * served tree.
 *
 * Three implementations wrote them (connect2.php's INDEXES, connect.php's
 * INDEXES and the repositories), and the copies drifted apart: one stored a
 * quote with the backslash it had escaped twice, one sent an IPv6 address's
 * packed bytes into its SQL unescaped (a 0x22 byte broke the statement, a
 * 0x5c corrupted the address, so every click from it made a new row), one
 * cut a value where the others did not. Each fix landed in one copy. The
 * device tables had two (connect2.php's PLATFORMS and the repository), and
 * neither stored what the other did: one never cut a name, the other gave
 * every device model type 1.
 *
 * Read from the strings as PHP assembles them (AssembledStrings): every
 * INSERT or REPLACE into one of these tables outside the repositories fails,
 * unless OTHER_WRITERS lists it with why; and a write whose table the scan
 * cannot read (a name built at runtime, wholly or in part) is listed in
 * UNREAD_WRITERS site by site, with the tables it can reach, none of them a
 * lookup table, rather than taken to write none (CLAUDE.md #20). Listing a
 * file vouched for every such write in it, so a second one there passed
 * unseen: each listed site answers for one INSERT, and another in the same
 * file fails as one anywhere else. Both lists only shrink. What this cannot
 * see: a write that is not an SQL string in PHP, an INSERT into a table it
 * cannot read that does not begin its string (that reads as prose, "could
 * not insert into " . $table), and an UPDATE or DELETE of these tables
 * (neither makes a row an id can point to).
 */
final class LookupTablesWrittenOnlyByRepositoriesTest extends TestCase
{
    private const HOME = '202-config/Repository/Mysql/';

    public const LOOKUP_TABLES = [
        '202_keywords', '202_tracking_c1', '202_tracking_c2', '202_tracking_c3', '202_tracking_c4',
        '202_utm_source', '202_utm_medium', '202_utm_campaign', '202_utm_term', '202_utm_content',
        '202_custom_variables', '202_variable_sets', '202_variable_sets2',
        '202_locations_country', '202_locations_city', '202_locations_region', '202_locations_isp',
        '202_ips', '202_ips_v6', '202_site_domains', '202_site_urls',
        '202_browsers', '202_platforms', '202_device_models',
    ];

    /** @var array<string, array<string, string>> file => lookup table => why */
    private const OTHER_WRITERS = [
        '202-config/functions-upgrade.php' => [
            '202_variable_sets2' => 'the 1.9.30-era upgrade rungs that filled the set map from 202_variable_sets'
                . ' when the table was added: a tagged version\'s step is frozen (CLAUDE.md #33)',
            '202_browsers' => 'the 1.1.2 to 1.2.0 rung\'s seed of three browser ids: frozen (CLAUDE.md #33)',
            '202_platforms' => 'the 1.6.2 to 1.7 rung\'s seed of the Mobile and Tablet platforms: frozen',
        ],
    ];

    /**
     * file => each INSERT there whose table the scan cannot read, by its
     * text (siteText()) => what decides its table. One entry answers for
     * one INSERT: a second with the same text needs a second entry, which
     * a key cannot hold, so it fails.
     *
     * @var array<string, array<string, string>>
     */
    private const UNREAD_WRITERS = [
        '202-config/DataEngine/ClickRollupSql.php' => [
            'insert into …(…) SELECT … FROM 202_clicks AS 2c LEFT OUTER'
                => 'insertSelect(): the rollup table its caller names',
        ],
        '202-config/class-dataengine.php' => [
            'INSERT INTO … (…,encode) VALUES … ON DUPLICATE KEY UPDATE'
                => 'doSummary(): 202_dataengine or 202_dataengine_new',
        ],
        'api/v3/Controller.php' => [
            'INSERT INTO %s (%s) VALUES (%s)' => 'the table each CRUD controller names in tableName()',
        ],
        '202-config/Crud/MysqlCrudRepository.php' => [
            'INSERT INTO … (…) VALUES (…)' => 'the table its TableConfig names',
        ],
    ];

    /** How many writes (sites, tables) the two lists hold. Lower it when one goes. */
    private const CEILING = 7;

    /** How much of an unread INSERT's text names it in UNREAD_WRITERS, in bytes (a part unread is one). */
    private const SITE_BYTES = 60;

    /**
     * The keyword and the table after it, past a database name: a run of
     * name characters, unread parts (AssembledStrings::UNREAD) and sprintf()
     * conversions, so a name built in part at runtime ("202_tracking_c" .
     * $i, "202_utm_{$k}") is read whole and reads as unread, not as the
     * prefix it starts with.
     */
    private const INSERT = '/\b(?:INSERT|REPLACE)\b(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*'
        . '(?:\s+INTO)?\s*(?:`?[\w\x00]+`?\.)?`?((?:[\w\x00]|%(?:\d+\$)?s)+)/i';

    public function testOnlyTheRepositoriesInsertIntoTheLookupTables(): void
    {
        $problems = [];
        $seen = [];
        $unread = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (str_starts_with($path, self::HOME)) {
                continue;
            }
            foreach (self::inserts($source) as [$line, $table, $site]) {
                if ($table === '?') {
                    $unread[$path][] = [$line, $site];
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
        [$unreadProblems, $stale] = self::unreadSites($unread, self::UNREAD_WRITERS);
        $problems = array_merge($problems, $unreadProblems);

        self::assertSame([], $problems, "A lookup table is written outside the repositories:\n  "
            . implode("\n  ", $problems)
            . "\nLook the id up through LookupRepositoryFactory::location(\$db), ::tracking(\$db)"
            . " or ::device(\$db) (findOrCreate*()). A write whose table is built at runtime is listed in"
            . ' UNREAD_WRITERS, by its text, with the tables it can reach (testTheUnreadWritesReachNoLookupTable).');

        foreach (self::OTHER_WRITERS as $path => $tables) {
            foreach (array_keys($tables) as $table) {
                if (!isset($seen[$path][$table])) {
                    $stale[] = "$path  $table";
                }
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
        $count = 0;
        foreach (self::UNREAD_WRITERS as $sites) {
            $count += count($sites);
        }
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
        // A name finished at runtime is not the prefix it starts with, which
        // read as another table (202_tracking_c, 202_utm_) or as no INSERT.
        yield 'a prefix, joined' => ['<?php $db->query("INSERT INTO 202_tracking_c" . $i . " SET c1 = 1");', ['?']];
        yield 'a prefix, interpolated' => ['<?php $db->query("INSERT INTO 202_utm_{$k} SET a = 1");', ['?']];
        yield 'a prefix, backticked' => ['<?php $db->query("INSERT INTO `202_tracking_c$i` SET a = 1");', ['?']];
        yield 'the bare prefix' => ['<?php $db->query("INSERT INTO 202_" . $t . " SET a = 1");', ['?']];
        yield 'a name and a suffix' => ['<?php $db->query("INSERT INTO 202_keywords" . $s . " SET a = 1");', ['?']];
        yield 'a name, then its columns' => ['<?php $q = "INSERT INTO 202_keywords(keyword) VALUES (?)";', [
            '202_keywords',
        ]];
        yield 'a database\'s table' => ['<?php $q = "INSERT INTO `p202`.`202_ips` SET ip_address = ?";', ['202_ips']];
        yield 'a table of a database named at runtime' => [
            '<?php $q = "INSERT INTO {$name}.202_site_urls SET a = 1";',
            ['202_site_urls'],
        ];
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

    /** @return iterable<string, array{string, string}> source, the text its one unread INSERT is listed by */
    public static function unreadSiteTexts(): iterable
    {
        yield 'interpolated, over lines' => [
            "<?php \$q = \"INSERT  INTO\n  {\$t} SET a = 1\";",
            'INSERT INTO … SET a = 1',
        ];
        yield 'joined' => [
            "<?php \$q = 'INSERT INTO ' . \$t . ' (' . \$c . ') VALUES (?)';",
            'INSERT INTO … (…) VALUES (?)',
        ];
        yield 'sprintf' => [
            "<?php \$q = sprintf('INSERT INTO %s (a) VALUES (%s)', \$t, \$v);",
            'INSERT INTO %s (a) VALUES (%s)',
        ];
        yield 'cut' => [
            "<?php \$q = 'INSERT INTO ' . \$t . ' (a, b, c, d, e, f, g, h, i, j, k, l, m, n, o, p, q) VALUES (?)';",
            'INSERT INTO … (a, b, c, d, e, f, g, h, i, j, k, l, m, n, o,',
        ];
    }

    /** @dataProvider unreadSiteTexts */
    public function testAnUnreadInsertIsListedByItsText(string $source, string $site): void
    {
        self::assertSame([[1, '?', $site]], self::inserts($source));
    }

    /**
     * @return iterable<string, array{array<string, list<array{int, string}>>, list<string>, list<string>}>
     *         found, the problems, the listed sites not found
     */
    public static function unreadSiteMatches(): iterable
    {
        yield 'the listed one' => [['a.php' => [[3, 'INSERT INTO … SET x = 1']]], [], []];
        yield 'a second, with the same text' => [
            ['a.php' => [[3, 'INSERT INTO … SET x = 1'], [9, 'INSERT INTO … SET x = 1']]],
            ['a.php:9 inserts into a table the scan cannot read: INSERT INTO … SET x = 1'
                . ' (UNREAD_WRITERS lists 1 such INSERT in a.php; the scan finds 2)'],
            [],
        ];
        yield 'a second, with another text' => [
            ['a.php' => [[3, 'INSERT INTO … SET x = 1'], [9, 'INSERT INTO … SET y = 2']]],
            ['a.php:9 inserts into a table the scan cannot read: INSERT INTO … SET y = 2'
                . ' (UNREAD_WRITERS lists 1 such INSERT in a.php; the scan finds 2)'],
            [],
        ];
        yield 'the listed one changed' => [
            ['a.php' => [[3, 'INSERT INTO … SET y = 2']]],
            ['a.php:3 inserts into a table the scan cannot read: INSERT INTO … SET y = 2'
                . ' (UNREAD_WRITERS lists 1 such INSERT in a.php; the scan finds 1)'],
            ['a.php  INSERT INTO … SET x = 1'],
        ];
        yield 'another file' => [
            ['a.php' => [[3, 'INSERT INTO … SET x = 1']], 'b.php' => [[4, 'INSERT INTO … SET x = 1']]],
            ['b.php:4 inserts into a table the scan cannot read: INSERT INTO … SET x = 1'],
            [],
        ];
        yield 'gone' => [[], [], ['a.php  INSERT INTO … SET x = 1']];
    }

    /**
     * Against one listed site, `INSERT INTO … SET x = 1` in a.php.
     *
     * @dataProvider unreadSiteMatches
     * @param array<string, list<array{int, string}>> $found
     * @param list<string> $problems
     * @param list<string> $stale
     */
    public function testEachListedSiteAnswersForOneInsert(array $found, array $problems, array $stale): void
    {
        self::assertSame(
            [$problems, $stale],
            self::unreadSites($found, ['a.php' => ['INSERT INTO … SET x = 1' => 'why']])
        );
    }

    /**
     * The unread INSERTs found that UNREAD_WRITERS does not answer for, and
     * the sites it lists that were not found. Each listed site answers for
     * one INSERT in its file, in the order found.
     *
     * @param array<string, list<array{int, string}>> $found path => [line, site text]
     * @param array<string, array<string, string>> $listed UNREAD_WRITERS' shape
     * @return array{list<string>, list<string>}
     */
    private static function unreadSites(array $found, array $listed): array
    {
        $problems = [];
        foreach ($found as $path => $sites) {
            $left = $listed[$path] ?? [];
            foreach ($sites as [$line, $site]) {
                if (array_key_exists($site, $left)) {
                    unset($left[$site]);
                    continue;
                }
                $problems[] = "$path:$line inserts into a table the scan cannot read: $site" . (isset($listed[$path])
                    ? ' (UNREAD_WRITERS lists ' . count($listed[$path]) . " such INSERT in $path;"
                        . ' the scan finds ' . count($sites) . ')'
                    : '');
            }
        }
        $stale = [];
        foreach ($listed as $path => $sites) {
            $foundHere = array_column($found[$path] ?? [], 1);
            foreach (array_keys($sites) as $site) {
                if (!in_array($site, $foundHere, true)) {
                    $stale[] = "$path  $site";
                }
            }
        }

        return [$problems, $stale];
    }

    /**
     * Each INSERT or REPLACE in a source: its line, its table, lower-cased,
     * or '?' when the scan cannot read it, and for '?' the text it is listed
     * by (siteText()). A table the scan cannot read after a keyword inside a
     * string is prose ("could not insert " . $what); a name that is not a
     * 202_ table (`insert into the …`) is not one of these.
     *
     * @return list<array{int, string, string}>
     */
    private static function inserts(string $source): array
    {
        $out = [];
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            if (preg_match_all(self::INSERT, $text, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($ms as $m) {
                $table = $m[1][0];
                if (strpbrk($table, AssembledStrings::UNREAD . '%') === false) {
                    if (preg_match('/^202_\w+$/i', $table) === 1) {
                        $out[] = [$line, strtolower($table), ''];
                    }
                } elseif (preg_match('/^\s*$/', substr($text, 0, $m[0][1])) === 1) {
                    $out[] = [$line, '?', self::siteText(substr($text, $m[0][1]))];
                }
            }
        }

        return $out;
    }

    /**
     * An unread INSERT as UNREAD_WRITERS lists it: from its keyword, white
     * space folded, cut after the last whole word within SITE_BYTES, each
     * part the scan cannot read as `…`.
     */
    private static function siteText(string $statement): string
    {
        $folded = trim((string) preg_replace('/\s+/', ' ', $statement));
        if (strlen($folded) > self::SITE_BYTES) {
            $folded = (string) preg_replace('/ \S*$/', '', substr($folded, 0, self::SITE_BYTES + 1));
        }

        return str_replace(AssembledStrings::UNREAD, '…', $folded);
    }
}
