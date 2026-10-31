<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Tests\Click\SingleClickReadsNameTheirVisitTest;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * A click names the redirector rule that matched and the redirect that sent
 * it by id (202_clicks_rotator, 202_dataengine), and the reports name them
 * through those ids. PUT /rotators/{id}/rules/{ruleId} deleted a rule's
 * redirects and inserted them again, so every one took a new id and the
 * clicks on it were listed under no redirect, while the set beside it
 * updated a redirect sent with its id in place.
 *
 * Every DELETE in the served tree whose FROM names 202_rotator_rules or
 * 202_rotator_rules_redirects is listed here by file and function, with why
 * it gives nothing a write keeps a new id: the rule or the redirector goes
 * with it, or it removes only what the write leaves out. A new one fails
 * until it is listed. Criteria are left out: nothing names a criterion by
 * its id.
 *
 * Read with AssembledStrings, as ClickRowsWrittenOnlyByTheWriterTest reads
 * its deletes. A DELETE whose table the scan cannot read is listed by its
 * text with the tables it reaches (UNREAD_TABLES). What this does not see: an
 * INSERT or REPLACE alone (a delete-and-reinsert needs the delete), and a
 * client that deletes rules over the API and posts them again (the sync
 * engine's re-sync does).
 */
final class RedirectRowsKeepTheirIdsTest extends TestCase
{
    private const TABLES = ['202_rotator_rules', '202_rotator_rules_redirects'];

    /** A DELETE's FROM, to the clause after it (ClickRowsWrittenOnlyByTheWriterTest's). */
    private const DELETE = '/\A\s*DELETE\b.*?\bFROM\s+(.+?)(?:\s+(?:WHERE|ORDER|LIMIT|USING)\b|\z)/is';

    /** Each table a reference list names: the first, and each after JOIN or a comma. */
    private const TABLE_REF = '/(?:\A|\b(?:STRAIGHT_)?JOIN\b|,)\s*\(?\s*(?:`?[\w\x00]+`?\.)?`?'
        . '((?:[\w\x00]|%(?:\d+\$)?s)+)/i';

    private const SITE_BYTES = 60;

    private const REDIRECTOR_REMOVED = 'removes the redirector, with every rule and redirect it has';

    /** @var array<string, array<string, array<string, string>>> file => function => statement => why */
    private const DELETES = [
        'api/v3/Controllers/RotatorsController.php' => [
            'delete' => [
                'DELETE FROM 202_rotator_rules_redirects WHERE rule_id IN (SELECT id FROM 202_rotator_rules WHERE'
                    . ' rotator_id = ?)' => self::REDIRECTOR_REMOVED,
                'DELETE FROM 202_rotator_rules WHERE rotator_id = ?' => self::REDIRECTOR_REMOVED,
            ],
            'writeRuleSet' => [
                'DELETE FROM 202_rotator_rules_redirects WHERE rule_id IN (…)' => 'the rules a set leaves out,'
                    . ' with their redirects',
                'DELETE FROM 202_rotator_rules WHERE rotator_id = ? AND id IN (…)' => 'as above',
            ],
            'removeEntriesBut' => [
                'DELETE FROM 202_rotator_rules_redirects WHERE rule_id = ?' => "a kept rule's redirects the write"
                    . ' leaves out: those it keeps are updated in place (writeRedirects()) and are the NOT IN it'
                    . ' appends',
            ],
            'deleteRule' => [
                'DELETE FROM 202_rotator_rules_redirects WHERE rule_id = ?' => 'removes the rule, with its redirects',
                'DELETE FROM 202_rotator_rules WHERE id = ? AND rotator_id = ?' => 'as above',
            ],
        ],
        'tracking202/ajax/rotator.php' => [
            '{main}' => [
                "DELETE FROM 202_rotator_rules_redirects WHERE id NOT IN (…) AND rule_id = '…'" => "Setup ›"
                    . " Redirectors' save: the rule's redirects the page did not post; one posted with its id is"
                    . ' updated in place',
                "DELETE FROM `202_rotator_rules` WHERE `id` NOT IN (…) AND rotator_id='…'" => 'the same save: the'
                    . ' rules the page did not post; one posted with its id is updated in place',
            ],
        ],
        'tracking202/setup/rotator.php' => [
            '{main}' => [
                'DELETE FROM 202_rotator_rules_redirects WHERE rule_id IN (SELECT id FROM 202_rotator_rules WHERE'
                    . ' rotator_id = ?)' => self::REDIRECTOR_REMOVED,
                'DELETE FROM 202_rotator_rules WHERE rotator_id = ?' => self::REDIRECTOR_REMOVED,
            ],
        ],
    ];

    /** @var array<string, array<string, string>> file => statement => the tables it reaches */
    private const UNREAD_TABLES = [
        '202-config/Attribution/AttributionStore.php' => [
            'DELETE FROM … WHERE conv_id = ?' => "a conversion's journey and credit tables",
        ],
        '202-config/Click/ClickRetention.php' => [
            'DELETE FROM `…` WHERE click_id IN (…)' => 'the click tables',
        ],
        '202-config/Crud/MysqlCrudRepository.php' => [
            'DELETE FROM … WHERE … = ? AND … = ?' => 'the setup table its TableConfig names, none a rule table',
        ],
        '202-config/DataEngine/ClickRollupSql.php' => [
            'DELETE de FROM 202_clicks AS 2c STRAIGHT_JOIN' => 'deleteEarlierVisits(): the rollup table its caller'
                . ' names',
            'DELETE de FROM 202_click_latest_visits AS lv STRAIGHT_JOIN' => 'deleteEarlierVisitsOfClick(), as'
                . ' above',
        ],
        '202-config/Ltv/MysqlCustomerCrmRepository.php' => [
            'DELETE FROM … WHERE customer_id = ? AND user_id = ?' => "a customer erasure's ERASED_TABLES",
        ],
        'api/v3/Apps/AppRetention.php' => [
            'DELETE FROM … WHERE … AND … < ? LIMIT …' => 'the app measurement tables its retention classes'
                . ' name',
        ],
        'api/v3/Controller.php' => [
            'DELETE FROM %s WHERE %s' => "the setup or app table a controller's tableName() names; RotatorsController"
                . ' is no Controller',
        ],
    ];

    public function testEveryDeleteOfARuleOrRedirectIsListed(): void
    {
        $problems = [];
        $seen = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (self::deletes($source) as [$line, $function, $statement, $readable]) {
                $listed = $readable
                    ? isset(self::DELETES[$path][$function][$statement])
                    : isset(self::UNREAD_TABLES[$path][$statement]);
                if ($listed) {
                    $seen[$path][$readable ? $function : ''][$statement] = true;
                    continue;
                }
                $problems[] = "$path:$line " . ($readable ? "$function(): " : '') . $statement;
            }
        }

        self::assertSame([], $problems, "Deletes of a redirector rule's or redirect's rows that are not listed:\n  "
            . implode("\n  ", $problems)
            . "\nA click and its report rows name its rule and redirect by id. A write that keeps one updates its row"
            . ' in place (RotatorsController::writeKeptEntries()); a delete removes only what is gone, and is listed'
            . ' in DELETES with why (a table the scan cannot read: UNREAD_TABLES, with the tables it reaches).');
        $stale = [];
        foreach (self::DELETES as $path => $functions) {
            foreach ($functions as $function => $statements) {
                foreach (array_keys($statements) as $statement) {
                    if (!isset($seen[$path][$function][$statement])) {
                        $stale[] = "$path $function(): $statement";
                    }
                }
            }
        }
        foreach (self::UNREAD_TABLES as $path => $statements) {
            foreach (array_keys($statements) as $statement) {
                if (!isset($seen[$path][''][$statement])) {
                    $stale[] = "$path: $statement";
                }
            }
        }
        self::assertSame([], $stale, 'listed deletes the scan no longer finds; drop them');
    }

    /** @dataProvider plantedDeletes */
    public function testTheScanReadsEachDeleteShape(string $code, string $function, string $statement): void
    {
        $found = array_map(static fn (array $d): array => [$d[1], $d[2]], self::deletes("<?php\n" . $code));

        self::assertSame([[$function, $statement]], $found, $code);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function plantedDeletes(): iterable
    {
        $in = static fn (string $body): string => "class C {\n    public function updateRule() {\n        $body\n"
            . "    }\n}";
        yield 'a rule\'s redirects, as PUT of one rule deleted them' => [
            $in('$db->prepare(\'DELETE FROM 202_rotator_rules_redirects WHERE rule_id = ?\');'),
            'updateRule',
            'DELETE FROM 202_rotator_rules_redirects WHERE rule_id = ?',
        ];
        yield 'in a closure, read as the method it is in' => [
            $in('$this->transaction(function () use ($db) {'
                . ' $db->query("DELETE FROM 202_rotator_rules WHERE id = 1"); });'),
            'updateRule',
            'DELETE FROM 202_rotator_rules WHERE id = 1',
        ];
        yield 'lower case, backticks, a database' => [
            "\$db->query('delete from `p202`.`202_rotator_rules_redirects` where rule_id = 1');",
            '{main}',
            'delete from `p202`.`202_rotator_rules_redirects` where rule_id = 1',
        ];
        yield 'an interpolated id, over lines' => [
            "\$sql = \"\n\tDELETE\n\tFROM 202_rotator_rules_redirects\n\tWHERE rule_id = '\$id'\";",
            '{main}',
            "DELETE FROM 202_rotator_rules_redirects WHERE rule_id = '…'",
        ];
        yield 'a joined table' => [
            '$db->query("DELETE rr FROM 202_rotators ro JOIN 202_rotator_rules_redirects rr ON rr.id = ro.id");',
            '{main}',
            'DELETE rr FROM 202_rotators ro JOIN 202_rotator_rules_redirects rr ON rr.id = ro.id',
        ];
        yield 'a constant' => [
            "use Prosper202\\Database\\Schema\\TableRegistry;\n"
                . "\$db->query('DELETE FROM ' . TableRegistry::ROTATOR_RULES_REDIRECTS . ' WHERE rule_id = 1');",
            '{main}',
            'DELETE FROM 202_rotator_rules_redirects WHERE rule_id = 1',
        ];
        yield 'a table read at runtime' => [
            '$db->query("DELETE FROM " . $table . " WHERE rule_id = 1");',
            '',
            'DELETE FROM … WHERE rule_id = 1',
        ];
    }

    /** @dataProvider notDeletes */
    public function testTheScanReadsNoDeleteIn(string $code): void
    {
        self::assertSame([], self::deletes("<?php\n" . $code), $code);
    }

    /** @return iterable<string, array{string}> */
    public static function notDeletes(): iterable
    {
        yield 'a read' => ['$db->query("SELECT id FROM 202_rotator_rules_redirects WHERE rule_id = 1");'];
        yield 'an update in place' => ['$db->query("UPDATE 202_rotator_rules_redirects SET weight = 1 WHERE id = 1");'];
        yield 'a criterion' => ['$db->query("DELETE FROM 202_rotator_rules_criteria WHERE rule_id = 1");'];
        yield 'another table' => ['$db->query("DELETE FROM 202_rotators WHERE id = 1");'];
        yield 'prose' => ['echo "Delete the rule from Setup first";'];
    }

    /**
     * Each DELETE in a source whose FROM names a rule table, or a table the
     * scan cannot read: its line, the named function it is in ({main}
     * outside any; '' for an unread table, which is listed by file), the
     * statement with white space folded and `…` for each part the scan
     * cannot read, and whether its table was read.
     *
     * @return list<array{int, string, string, bool}>
     */
    private static function deletes(string $source): array
    {
        $out = [];
        $ranges = null;
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            if (preg_match(self::DELETE, $text, $m) !== 1) {
                continue;
            }
            preg_match_all(self::TABLE_REF, $m[1], $tables);
            $named = false;
            $unread = false;
            foreach ($tables[1] as $table) {
                $unread = $unread || strpbrk($table, AssembledStrings::UNREAD . '%') !== false;
                $named = $named || in_array(strtolower($table), self::TABLES, true);
            }
            $statement = trim((string) preg_replace('/\s+/', ' ', $text), ' ');
            if ($named) {
                $ranges ??= SingleClickReadsNameTheirVisitTest::functionRanges($source);
                $function = SingleClickReadsNameTheirVisitTest::functionAt($ranges, $line);
                $out[] = [$line, $function, str_replace(AssembledStrings::UNREAD, '…', $statement), true];
            } elseif ($unread) {
                $out[] = [$line, '', self::cut($statement), false];
            }
        }

        return $out;
    }

    /** An unread statement as listed: cut after the last whole word within SITE_BYTES. */
    private static function cut(string $statement): string
    {
        if (strlen($statement) > self::SITE_BYTES) {
            $statement = (string) preg_replace('/ \S*$/', '', substr($statement, 0, self::SITE_BYTES + 1));
        }

        return str_replace(AssembledStrings::UNREAD, '…', $statement);
    }
}
