<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Repository\LookupTablesWrittenOnlyByRepositoriesTest;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * A click's rows are created by MysqlClickRepository and nothing else in the
 * served tree, and rewritten by it or by a writer listed with why.
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
 * table.
 *
 * Rewrites are read the same way (rewrites()): every UPDATE, and every DELETE
 * whose FROM names a click table, outside the writer fails unless
 * OTHER_REWRITERS lists it. off.php, offrtr.php, lp.php and pci.php rewrote a
 * click the visitor named by a cookie or a public id, and offrtr.php and
 * lp.php wrote any account's (CLAUDE.md #27): a click leaving its landing
 * page goes through recordExit(), which takes the account and refuses another
 * account's click. Each listed rewriter scopes its own by the account or acts
 * for the click's own. A statement whose table the scan cannot read is listed
 * by its text (`…` for each part it cannot read), and the list says which
 * tables it reaches. A DELETE is read by every table its FROM names, so one
 * that deletes another table's rows joined to a click reads as a rewrite.
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
    ];

    /** How many writes OTHER_WRITERS holds. Lower it when one goes. */
    private const CEILING = 1;

    /**
     * file => click table, or the statement as rewrites() reads it when it
     * cannot read the table => why
     *
     * @var array<string, array<string, string>>
     */
    private const OTHER_REWRITERS = [
        '202-config/Conversion/Ledger/MysqlConversionLedger.php' => [
            'UPDATE … SET click_lead = 1, click_payout = ? WHERE click_id' => 'recompute(): 202_clicks and'
                . ' 202_clicks_spy, the lead and value of a click it has locked, from its conversion rows',
            'UPDATE … SET click_lead = 0 WHERE click_id = ?' => 'recompute(), as above',
        ],
        '202-config/static-endpoint-helpers.php' => [
            '202_clicks' => "p202ApplyConversionClickSide(): a converting click's cost and filter flag, inside its"
                . ' conversion, for the click the conversion is recorded on',
            '202_clicks_spy' => "p202ApplyConversionClickSide(), the click's 202_clicks_spy copy",
        ],
        '202-config/Update/CpcUpdate.php' => [
            '202_clicks' => "Update CPC: the cost on every visit of an account's clicks, WHERE user_id = ?, chosen by"
                . ' their first visit through a scope joined on the account (the page and POST /api/v3/clicks/cpc)',
            '202_clicks_spy' => 'Update CPC: the 202_clicks_spy copies of the same clicks, WHERE user_id = ?',
        ],
        '202-config/Update/SubidBatch.php' => [
            '202_clicks' => "a subid upload's click filter flag, WHERE click_id = ? AND user_id = ?",
            '202_clicks_spy' => 'as for 202_clicks',
        ],
        '202-config/Click/ClickRetention.php' => [
            'DELETE FROM `…` WHERE click_id IN (…)' => "click-data retention: every click table, for an account's"
                . ' expired clicks',
        ],
        '202-config/DataEngine/ClickRollupSql.php' => [
            'DELETE de FROM 202_clicks AS 2c STRAIGHT_JOIN' => 'deleteEarlierVisits(): deletes `de` alone, the'
                . ' rollup table its caller names, at a click\'s earlier visits, which it reads from 202_clicks and'
                . ' 202_click_latest_visits',
            '202_clicks' => 'deleteEarlierVisits(), as above: 202_clicks is joined, not written',
        ],
        '202-config/User/UserDataPurge.php' => [
            '202_clicks' => "deletes a purged account's identity observations, joined to its clicks; no click row",
        ],
        '202-cronjobs/index.php' => [
            '202_clicks_spy' => 'ages every click out of the Spy view after a day',
        ],
        '202-config/Ltv/MysqlCustomerCrmRepository.php' => [
            'UPDATE … SET … = ? WHERE … = ?' => "a customer merge: 202_clicks_tracking.customer_id among a literal"
                . ' table list, from one of the account\'s customers to another',
            'DELETE FROM … WHERE customer_id = ? AND user_id = ?' => "a customer erasure's ERASED_TABLES, none a"
                . ' click table',
        ],
        '202-config/Attribution/AttributionStore.php' => [
            'DELETE FROM … WHERE conv_id = ?' => "a conversion's journey and credit tables",
        ],
        '202-config/Crud/MysqlCrudRepository.php' => [
            'UPDATE … SET … WHERE … = ? AND … = ?' => 'the setup tables TableConfig names, none a click table'
                . ' (ClickValueWritersTest)',
            'DELETE FROM … WHERE … = ? AND … = ?' => 'as above',
            'UPDATE … SET … = 1 WHERE … = ? AND … = ?' => 'as above',
        ],
        'api/v3/Controller.php' => [
            'UPDATE %s SET %s = ? WHERE %s = ?' => "the setup or app table a controller's tableName() names",
            'UPDATE %s SET %s WHERE %s' => 'as above',
            'UPDATE %s SET %s = 1 WHERE %s' => 'as above',
            'DELETE FROM %s WHERE %s' => 'as above',
        ],
        'api/v3/Apps/AppRetention.php' => [
            'DELETE FROM … WHERE … AND … < ? LIMIT' => 'the app measurement tables its retention classes name',
        ],
    ];

    /** How many rewrites OTHER_REWRITERS holds. Lower it when one goes. */
    private const REWRITE_CEILING = 24;

    /** An UPDATE's table references, to SET. */
    private const UPDATE = '/\A\s*UPDATE\b(?:\s+(?:LOW_PRIORITY|IGNORE))*\s+(.+?)\s+SET\b/is';

    /** A DELETE's FROM, to the clause after it. */
    private const DELETE = '/\A\s*DELETE\b.*?\bFROM\s+(.+?)(?:\s+(?:WHERE|ORDER|LIMIT|USING)\b|\z)/is';

    /** Each table a reference list names: the first, and each after JOIN or a comma. */
    private const TABLE_REF = '/(?:\A|\b(?:STRAIGHT_)?JOIN\b|,)\s*\(?\s*(?:`?[\w\x00]+`?\.)?`?((?:[\w\x00]|%(?:\d+\$)?s)+)/i';

    private const SITE_BYTES = 60;

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

    public function testOnlyTheWriterRewritesAClick(): void
    {
        $problems = [];
        $seen = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if ($path === self::WRITER) {
                continue;
            }
            foreach (self::rewrites($source) as [$line, $what]) {
                if (isset(self::OTHER_REWRITERS[$path][$what])) {
                    $seen[$path][$what] = true;
                    continue;
                }
                $problems[] = "$path:$line rewrites $what";
            }
        }

        self::assertSame([], $problems, "A click's rows are rewritten outside MysqlClickRepository:\n  "
            . implode("\n  ", $problems)
            . "\nA click leaving its landing page goes through recordExit(), which refuses another account's"
            . ' click; any other rewrite is listed with why its clicks are the account\'s own.');
        $stale = [];
        foreach (self::OTHER_REWRITERS as $path => $sites) {
            foreach (array_keys($sites) as $what) {
                if (!isset($seen[$path][$what])) {
                    $stale[] = "$path  $what";
                }
            }
        }
        self::assertSame([], $stale, 'listed rewriters the scan no longer finds; drop them');
    }

    public function testTheRewriteListOnlyShrinks(): void
    {
        $count = array_sum(array_map('count', self::OTHER_REWRITERS));

        self::assertLessThanOrEqual(self::REWRITE_CEILING, $count, 'a rewriter was added; see the class docblock');
        self::assertSame(self::REWRITE_CEILING, $count, 'one went: lower REWRITE_CEILING to ' . $count);
    }

    /** @dataProvider plantedRewrites */
    public function testTheScanReadsEachRewriteShape(string $code, string $expected): void
    {
        self::assertSame([$expected], array_column(self::rewrites("<?php\n" . $code), 1), $code);
    }

    /** @return iterable<string, array{string, string}> */
    public static function plantedRewrites(): iterable
    {
        yield 'one literal' => [
            '$db->query("UPDATE 202_clicks_record SET click_out = 1 WHERE click_id = 5");',
            '202_clicks_record',
        ];
        yield 'lower case, backticks, database' => [
            "\$db->query('update `p202`.`202_clicks_site` set x = 1');",
            '202_clicks_site',
        ];
        yield 'over lines, as the scripts wrote them' => [
            "\$sql = \"\n\tUPDATE\n\t\t202_clicks_rotator\n\tSET\n\t\trule_id = 1\";",
            '202_clicks_rotator',
        ];
        yield 'a joined table' => [
            '$db->query("UPDATE 202_offers AS o LEFT JOIN 202_clicks_spy AS s ON (s.id = o.id) SET s.x = 1");',
            '202_clicks_spy',
        ];
        yield 'a comma join' => ['$db->query("UPDATE 202_offers o, 202_clicks c SET c.x = 1");', '202_clicks'];
        yield 'low priority' => [
            '$db->query("UPDATE LOW_PRIORITY 202_clicks_tracking SET c1_id = 0");',
            '202_clicks_tracking',
        ];
        yield 'a heredoc' => [
            "\$sql = <<<SQL\nUPDATE 202_clicks_advance SET keyword_id = 0\nSQL;\n",
            '202_clicks_advance',
        ];
        yield 'a delete' => ['$db->query("DELETE FROM 202_clicks_record WHERE click_id = 5");', '202_clicks_record'];
        yield 'a straight join' => [
            '$db->query("DELETE o FROM 202_offers o STRAIGHT_JOIN 202_google g ON g.click_id = o.id");',
            '202_google',
        ];
        yield 'a multi-table delete' => [
            '$db->query("DELETE o FROM 202_offers o JOIN 202_google g ON g.click_id = o.id");',
            '202_google',
        ];
        yield 'a table read at runtime' => [
            '$db->query("UPDATE " . $table . " SET click_out = 1 WHERE click_id = ?");',
            'UPDATE … SET click_out = 1 WHERE click_id = ?',
        ];
        yield 'a sprintf table' => [
            '$db->query(sprintf("DELETE FROM %s WHERE click_id = 1", $t));',
            'DELETE FROM %s WHERE click_id = 1',
        ];
    }

    /** @dataProvider notRewrites */
    public function testTheScanReadsNoRewriteInto(string $code): void
    {
        self::assertSame([], self::rewrites("<?php\n" . $code), $code);
    }

    /** @return iterable<string, array{string}> */
    public static function notRewrites(): iterable
    {
        yield 'a read' => ['$db->query("SELECT click_out FROM 202_clicks_record WHERE click_id = 1");'];
        yield 'an upsert, read as an insert' => [
            '$db->query("INSERT INTO 202_clicks_record (click_id) VALUES (1) ON DUPLICATE KEY UPDATE click_out = 1");',
        ];
        yield 'another table' => ['$db->query("UPDATE 202_aff_campaigns SET aff_campaign_payout = 1");'];
        yield 'prose' => ['echo "Edit it with p202 goal update " . $id . ".";'];
        yield 'prose starting with the word' => ['echo "update " . $name . " first";'];
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

    /**
     * Each UPDATE, and each DELETE whose FROM names a click table, in a
     * source: its line, and the click table, or the statement as listed
     * (white space folded, `…` for a part the scan cannot read, cut after the
     * last whole word within SITE_BYTES) when it cannot read the table. A
     * statement is read where a string starts with it.
     *
     * @return list<array{int, string}>
     */
    public static function rewrites(string $source): array
    {
        $out = [];
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            foreach ([self::UPDATE, self::DELETE] as $statement) {
                if (preg_match($statement, $text, $m) !== 1) {
                    continue;
                }
                preg_match_all(self::TABLE_REF, $m[1], $tables);
                $named = [];
                foreach ($tables[1] as $table) {
                    if (strpbrk($table, AssembledStrings::UNREAD . '%') !== false) {
                        $named[self::siteText($text)] = true;
                    } elseif (in_array(strtolower($table), self::CLICK_TABLES, true)) {
                        $named[strtolower($table)] = true;
                    }
                }
                foreach (array_keys($named) as $what) {
                    $out[] = [$line, (string) $what];
                }
            }
        }

        return $out;
    }

    private static function siteText(string $statement): string
    {
        $folded = trim((string) preg_replace('/\s+/', ' ', $statement));
        if (strlen($folded) > self::SITE_BYTES) {
            $folded = (string) preg_replace('/ \S*$/', '', substr($folded, 0, self::SITE_BYTES + 1));
        }

        return str_replace(AssembledStrings::UNREAD, '…', $folded);
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
