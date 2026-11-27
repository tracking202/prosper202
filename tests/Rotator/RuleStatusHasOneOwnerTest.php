<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\RuleStatus;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * `202_rotator_rules.status` is written in one file and read one way
 * (RuleStatus). The redirects, the setup page, the notices and the API each
 * decided for themselves what NULL meant, and the page's answer (active)
 * was not the redirects' (passed by): saving the page switched the rule on
 * (CLAUDE.md #25).
 *
 * Held here, over the served tree:
 * - every INSERT, REPLACE or UPDATE of the table is in OWNER, where each
 *   names status as a bound value taken from the enum (`->stored()`), so no
 *   rule is created with no status and none is written a literal;
 * - no SQL naming the table compares status itself: the condition comes
 *   from RuleStatus::matchesTrafficSql();
 * - the files that read a rule's stored status (READERS) do not cast it,
 *   compare it or test it for presence: they ask RuleStatus.
 *
 * Does not see: a write whose table name is built at runtime (the generic
 * writers ClickRowsWrittenOnlyByTheWriterTest lists, none of which is given
 * this table); a status copied to a variable before it is compared; a
 * reader in a file READERS does not name (ReportsController answers the
 * stored value as it is); JavaScript and Go, which send the page's word and
 * 0/1 and are held by the surface test below.
 */
final class RuleStatusHasOneOwnerTest extends TestCase
{
    private const TABLE = '202_rotator_rules';
    private const OWNER = 'api/v3/Controllers/RotatorsController.php';

    private const READERS = [
        self::OWNER,
        '202-config/Rotator/NeverMatches.php',
        '202-config/Rotator/RuleOrder.php',
        '202-config/Rotator/SaveNotices.php',
        'api/v3/Support/SyncEngine.php',
        'tracking202/setup/rotator.php',
    ];

    private const WRITE = '/\b(?:(?:INSERT|REPLACE)\b(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*(?:\s+INTO)?\s+[`\w.]*?'
        . '|UPDATE\b[^;]*?)\b' . self::TABLE . '\b/is';

    /** Something done to a status column in SQL other than giving it a bound value. */
    private const SQL_READ = '/\bstatus`?\s*(?:=|<|>|!|\b(?:IS|IN|NOT|LIKE|BETWEEN)\b)|\b(?:IFNULL|COALESCE|NULLIF|IF|ISNULL)\s*\(\s*(?:\w+\.)?`?status\b/i';

    private const STATUS_KEY = '(?:\$\w+|\))(?:\s*\[[^\]]*\])*\s*\[\s*[\'"]status[\'"]\s*\]';

    public function testOnlyTheOwnerWritesARule(): void
    {
        $writers = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (self::writes($source) !== []) {
                $writers[] = $path;
            }
        }

        self::assertSame([self::OWNER], $writers, 'A redirector rule is written outside RotatorsController, which plans its'
            . ' status with RuleStatus under the redirector\'s lock. Write it through the controller.');
    }

    public function testEveryWriteInTheOwnerBindsTheEnumsValue(): void
    {
        $problems = self::ownerProblems(SourceScan::phpFiles()[self::OWNER]);

        self::assertSame([], $problems, implode("\n", $problems));
    }

    public function testNoSqlComparesTheStatusItself(): void
    {
        $problems = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (self::sqlReads($source) as $line) {
                $problems[] = "$path:$line";
            }
        }

        self::assertSame([], $problems, 'SQL that reads a rule\'s status for itself; take the condition from'
            . " RuleStatus::matchesTrafficSql():\n  " . implode("\n  ", $problems));

        $asked = [];
        foreach (AssembledStrings::of(SourceScan::phpFiles()['202-config/Rotator/RuleOrder.php']) as $string) {
            foreach ($string['calls'] as $call) {
                $asked[] = $call['class'] . '::' . $call['method'] . (str_contains($string['text'], self::TABLE) ? '' : ' elsewhere');
            }
        }
        self::assertContains(RuleStatus::class . '::matchesTrafficSql', $asked, 'the redirects\' read asks the enum');
    }

    public function testNoReaderInterpretsTheStoredStatusItself(): void
    {
        $files = SourceScan::phpFiles();
        $problems = [];
        foreach (self::READERS as $path) {
            self::assertArrayHasKey($path, $files, "$path is gone: drop it from READERS");
            self::assertStringContainsString('RuleStatus::', $files[$path], "$path no longer asks RuleStatus: drop it from READERS");
            foreach (self::ownReadings($files[$path]) as $line) {
                $problems[] = "$path:$line";
            }
        }

        self::assertSame([], $problems, "A rule's stored status read without RuleStatus (tryOfStored(), storedMatchesTraffic()):\n  "
            . implode("\n  ", $problems));
    }

    /** What a client may send is what the enum creates a rule in, on every surface that lists it. */
    public function testTheSurfacesListTheValuesTheEnumTakes(): void
    {
        $sendable = array_map(static fn (RuleStatus $s): ?int => $s->stored(), RuleStatus::initial());
        sort($sendable);
        self::assertSame([0, 1], $sendable);
        $answered = array_map(static fn (RuleStatus $s): ?int => $s->stored(), RuleStatus::cases());

        $root = SourceScan::repoRoot();
        $spec = (string) file_get_contents($root . '/docs/openapi.yaml');
        self::assertSame($sendable, self::specStatusEnum($spec, 'RotatorRuleCreate'), 'docs/openapi.yaml RotatorRuleCreate.status');
        self::assertEqualsCanonicalizing($answered, self::specStatusEnum($spec, 'RotatorRule'), 'docs/openapi.yaml RotatorRule.status');

        $go = (string) file_get_contents($root . '/go-cli/cmd/enum_flags.go');
        self::assertSame(1, preg_match('/^var binaryValues = \[\]string\{([^}]*)\}/m', $go, $m), 'go-cli binaryValues');
        self::assertSame(array_map('strval', $sendable), array_map(static fn (string $v): string => trim($v, ' "'), explode(',', $m[1])));
        $cli = (string) file_get_contents($root . '/go-cli/cmd/rotator.go');
        foreach (['rotatorRuleCreateCmd', 'rotatorRuleUpdateCmd'] as $command) {
            self::assertStringContainsString(
                'enumFlag(' . $command . ', "status", newEnum(binaryValues))',
                $cli,
                "p202 rotator's --status on $command takes the values the API does"
            );
        }
    }

    /** @return list<int|null> */
    private static function specStatusEnum(string $spec, string $schema): array
    {
        $start = strpos($spec, "\n    $schema:\n");
        self::assertNotFalse($start, "schema $schema is not in docs/openapi.yaml");
        $rest = substr($spec, $start + 1);
        $end = preg_match('/\n    [A-Z]\w*:\n/', $rest, $m, PREG_OFFSET_CAPTURE, 1) === 1 ? $m[0][1] : strlen($rest);
        self::assertSame(
            1,
            preg_match('/^        status:\n(?:          .*\n)*?          enum: \[([^\]]*)\]/m', substr($rest, 0, $end), $found),
            "$schema.status lists no enum"
        );

        return array_map(static fn (string $v): ?int => trim($v) === 'null' ? null : (int) trim($v), explode(',', $found[1]));
    }

    /**
     * @dataProvider plantedWrites
     */
    public function testTheScanReadsEachWriteShape(string $code): void
    {
        self::assertCount(1, self::writes("<?php\n" . $code), $code);
    }

    /** @return iterable<string, array{string}> */
    public static function plantedWrites(): iterable
    {
        yield 'an insert' => ['$db->query("INSERT INTO 202_rotator_rules (rotator_id, rule_name) VALUES (1, \'x\')");'];
        yield 'insert set, backticks, lower case' => ["\$db->query('insert into `202_rotator_rules` set rule_name = 1');"];
        yield 'insert ignore, database' => ['$sql = "INSERT IGNORE INTO p202.202_rotator_rules (id) VALUES (1)";'];
        yield 'replace' => ['$sql = "REPLACE INTO 202_rotator_rules (id, status) VALUES (1, 1)";'];
        yield 'an update' => ['$db->query("UPDATE 202_rotator_rules SET status = 1 WHERE id = 5");'];
        yield 'an update over lines' => ["\$sql = \"\n\tUPDATE\n\t\t202_rotator_rules\n\tSET\n\t\tstatus = '1'\";"];
        yield 'a joined update' => ['$sql = "UPDATE 202_rotators AS r JOIN 202_rotator_rules AS ru ON (ru.rotator_id = r.id) SET ru.status = 1";'];
        yield 'joined across a dot' => ['$sql = "UPDATE " . "202_rotator_rules" . " SET status = NULL";'];
        yield 'a heredoc' => ["\$sql = <<<SQL\nUPDATE 202_rotator_rules SET status = 0\nSQL;\n"];
        yield 'the registry constant' => ['$sql = "UPDATE " . \Prosper202\Database\Schema\TableRegistry::ROTATOR_RULES . " SET status = 1";'];
    }

    public function testTheScanPassesByTheOtherTablesAndReads(): void
    {
        foreach ([
            '$db->query("UPDATE 202_rotator_rules_criteria SET value = ? WHERE id = ?");',
            '$db->query("INSERT INTO 202_rotator_rules_redirects (rule_id) VALUES (?)");',
            '$db->query("SELECT id FROM 202_rotator_rules WHERE rotator_id = ?");',
            '$db->query("DELETE FROM 202_rotator_rules WHERE rotator_id = ?");',
            '$db->query("UPDATE 202_rotators SET name = ? WHERE id = ?");',
        ] as $code) {
            self::assertSame([], self::writes("<?php\n" . $code), $code);
        }
    }

    /**
     * @dataProvider plantedOwnerWrites
     */
    public function testTheOwnerCheckRefusesEachWriteThatIsNotTheEnums(string $code, string $problem): void
    {
        $problems = self::ownerProblems("<?php\n" . $code, 1);

        self::assertCount(1, $problems, $code . "\n" . implode("\n", $problems));
        self::assertStringContainsString($problem, $problems[0]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function plantedOwnerWrites(): iterable
    {
        $bound = "\n\$this->bind(\$stmt, 'i', \$rule['status']->stored());";
        yield 'an insert that names no status' => [
            '$stmt = $this->prepare("INSERT INTO 202_rotator_rules (rotator_id, rule_name) VALUES (?, ?)");' . $bound,
            'creates a rule with no status',
        ];
        yield 'an insert with a literal' => [
            '$stmt = $this->prepare("INSERT INTO 202_rotator_rules (rotator_id, status) VALUES (?, 1)");' . $bound,
            'writes a value that is not bound',
        ];
        yield 'an update with a literal' => [
            '$stmt = $this->prepare("UPDATE 202_rotator_rules SET rule_name = ?, status = 1 WHERE id = ?");' . $bound,
            'gives status something other than a bound value',
        ];
        yield 'an update to NULL' => [
            '$stmt = $this->prepare("UPDATE 202_rotator_rules SET status = NULL WHERE id = ?");' . $bound,
            'gives status something other than a bound value',
        ];
        yield 'an update with a from-state of its own' => [
            '$stmt = $this->prepare("UPDATE 202_rotator_rules SET status = ? WHERE id = ? AND status IS NULL");' . $bound,
            'reads status in its WHERE',
        ];
        yield 'a bind of a value that is not the enum\'s' => [
            '$stmt = $this->prepare("UPDATE 202_rotator_rules SET status = ? WHERE id = ?");'
                . "\n\$this->bind(\$stmt, 'ii', \$rule['status'], \$id);",
            'binds a status that is not RuleStatus::stored()',
        ];
        yield 'no bind at all' => [
            '$stmt = $this->prepare("UPDATE 202_rotator_rules SET status = ? WHERE id = ?");' . "\n\$stmt->execute();",
            'binds a status that is not RuleStatus::stored()',
        ];
    }

    /**
     * @dataProvider plantedSqlReads
     */
    public function testTheScanReadsEachSqlComparison(string $code): void
    {
        self::assertCount(1, self::sqlReads("<?php\n" . $code), $code);
    }

    /** @return iterable<string, array{string}> */
    public static function plantedSqlReads(): iterable
    {
        yield 'the old read' => ['$sql = "SELECT id FROM 202_rotator_rules AS ru WHERE ru.status = \'1\'";'];
        yield 'not zero' => ['$sql = "SELECT id FROM 202_rotator_rules WHERE status != 0";'];
        yield 'not equal' => ['$sql = "SELECT id FROM 202_rotator_rules WHERE status <> 0";'];
        yield 'null test' => ['$sql = "SELECT id FROM `202_rotator_rules` WHERE `status` IS NULL";'];
        yield 'a list' => ['$sql = "SELECT id FROM 202_rotator_rules ru WHERE ru.status IN (1)";'];
        yield 'a fallback' => ['$sql = "SELECT COALESCE(ru.status, 1) AS status FROM 202_rotator_rules ru";'];
        yield 'ifnull' => ['$sql = "SELECT id FROM 202_rotator_rules WHERE IFNULL(status, 1) = 1";'];
        yield 'a join condition' => ['$sql = "SELECT 1 FROM 202_rotators r JOIN 202_rotator_rules ru ON (ru.rotator_id = r.id AND ru.status = 1)";'];
        yield 'a bound comparison' => ['$sql = "SELECT id FROM 202_rotator_rules WHERE status = ?";'];
        yield 'in a write\'s WHERE' => ['$sql = "UPDATE 202_rotator_rules SET position = 1 WHERE status = 1";'];
        yield 'joined across a dot' => ['$sql = "SELECT id FROM 202_rotator_rules" . " WHERE status = 1";'];
    }

    public function testTheSqlScanPassesByAWritesSetAndOtherTables(): void
    {
        foreach ([
            '$sql = "UPDATE 202_rotator_rules SET rule_name = ?, status = ? WHERE id = ?";',
            '$sql = "SELECT ru.id, ru.status FROM 202_rotator_rules AS ru WHERE ru.rotator_id = ?";',
            '$sql = "SELECT id FROM 202_exports WHERE status = ?";',
            '$sql = "SELECT id FROM 202_rotator_rules_criteria WHERE statement = ?";',
        ] as $code) {
            self::assertSame([], self::sqlReads("<?php\n" . $code), $code);
        }
    }

    /**
     * @dataProvider plantedReadings
     */
    public function testTheScanReadsEachOwnReading(string $code): void
    {
        self::assertCount(1, self::ownReadings("<?php\n" . $code), $code);
    }

    /** @return iterable<string, array{string}> */
    public static function plantedReadings(): iterable
    {
        yield 'the page\'s' => ['$inactive = isset($rule[\'status\']) && (string) $rule[\'status\'] === \'0\';'];
        yield 'the notices\'' => ['$active = (int) $status[\'status\'] === 1;'];
        yield 'the warnings\'' => ['$active = (string) ($rule[\'status\'] ?? \'\') === \'1\';'];
        yield 'a loose comparison' => ['if ($rule["status"] == 1) { }'];
        yield 'not equal' => ['if ($rule[\'status\'] != 0) { }'];
        yield 'the literal first' => ['if (\'1\' === $rule[\'status\']) { }'];
        yield 'null test' => ['$none = $held[\'status\'] === null;'];
        yield 'a nested row' => ['$on = $rules[$i][\'status\'] === 1;'];
        yield 'truthiness' => ['if ($rule[\'status\']) { }'];
        yield 'negated' => ['if (!$rule[\'status\']) { }'];
        yield 'a ternary' => ['$word = $rule[\'status\'] ? \'active\' : \'inactive\';'];
        yield 'and' => ['$on = $rule[\'status\'] && $x;'];
        yield 'after and' => ['$on = $x && $rule[\'status\'];'];
        yield 'empty()' => ['$off = empty($rule[\'status\']);'];
        yield 'intval()' => ['$n = intval($rule[\'status\']);'];
        yield 'is_null()' => ['$n = is_null($rule[\'status\']);'];
        yield 'in_array()' => ['$on = in_array($rule[\'status\'], [1, \'1\'], true);'];
        yield 'a match' => ['$w = match ($rule[\'status\']) { 1 => \'a\', default => \'b\' };'];
        yield 'a switch' => ['switch ($rule[\'status\']) { case 1: break; }'];
        yield 'a fallback then a comparison' => ['$on = ($rule[\'status\'] ?? 1) === 1;'];
        yield 'a call\'s result' => ['$on = $this->rule()[\'status\'] === 1;'];
    }

    public function testTheReadingScanPassesByTheEnumAndTheWire(): void
    {
        foreach ([
            '$active = RuleStatus::storedMatchesTraffic($rule[\'status\'] ?? null);',
            '$from = RuleStatus::tryOfStored($held[\'status\']);',
            'if ($rule[\'status\'] !== RuleStatus::tryOfStored($held[\'status\'])) { }',
            '$status = self::plannedStatus($rule[\'status\'] ?? null, $held);',
            '$this->bind($stmt, \'i\', $rule[\'status\']->stored());',
            '$out = [\'status\' => $rule[\'status\']->stored()];',
            '$fields = [\'rule_name\', \'splittest\', \'status\'];',
            '$out = [\'status\' => $error->remoteStatus];',
        ] as $code) {
            self::assertSame([], self::ownReadings("<?php\n" . $code), $code);
        }
    }

    /** @return list<array{line: int, text: string}> each INSERT, REPLACE or UPDATE of the rules table */
    private static function writes(string $source): array
    {
        $out = [];
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            if (preg_match(self::WRITE, $text) === 1) {
                $out[] = ['line' => $line, 'text' => $text];
            }
        }

        return $out;
    }

    /**
     * @return list<string> what is wrong with the owner's writes; $floor is how many it must hold
     */
    private static function ownerProblems(string $source, int $floor = 4): array
    {
        $problems = [];
        $lines = explode("\n", $source);
        $writes = self::writes($source);
        if (count($writes) < $floor) {
            $problems[] = 'the scan found ' . count($writes) . ' writes of ' . self::TABLE . ', fewer than the ' . $floor . ' the owner holds';
        }
        foreach ($writes as ['line' => $line, 'text' => $text]) {
            $at = self::OWNER . ':' . $line;
            if (preg_match('/\A\s*UPDATE\b/i', $text) === 1) {
                $set = preg_split('/\bWHERE\b/i', (string) preg_replace('/\A.*?\bSET\b/is', '', $text), 2);
                if (preg_match('/\bstatus\b/i', $set[0]) === 1 && preg_match('/\bstatus`?\s*=\s*\?(?=\s*(?:,|\z))/i', $set[0]) !== 1) {
                    $problems[] = "$at gives status something other than a bound value";
                    continue;
                }
                if (preg_match('/\bstatus\b/i', $set[1] ?? '') === 1) {
                    $problems[] = "$at reads status in its WHERE: the redirector's lock is the guard, and every move is the enum's";
                    continue;
                }
                if (preg_match('/\bstatus\b/i', $set[0]) !== 1) {
                    continue;
                }
            } elseif (preg_match('/\(([^)]*)\)\s*VALUES\s*\(([^)]*)\)/i', $text, $m) !== 1 || preg_match('/\bstatus\b/i', $m[1]) !== 1) {
                $problems[] = "$at creates a rule with no status (or in a form the scan cannot read: name the columns and VALUES)";
                continue;
            } elseif (preg_match('/\A\s*\?(?:\s*,\s*\?)*\s*\z/', $m[2]) !== 1) {
                $problems[] = "$at writes a value that is not bound";
                continue;
            }
            // The statement's own bind: the first one after it, to its semicolon.
            $after = implode("\n", array_slice($lines, $line - 1));
            if (
                preg_match('/->bind\s*\((.*?)\)\s*;/s', $after, $bind) !== 1
                || preg_match('/\[\s*\'status\'\s*\]\s*->\s*stored\s*\(\s*\)/', $bind[1]) !== 1
                || substr_count(substr($after, 0, (int) strpos($after, $bind[0])), '->prepare(') > 1
            ) {
                $problems[] = "$at binds a status that is not RuleStatus::stored()";
            }
        }

        return $problems;
    }

    /** @return list<int> the lines of SQL that names the rules table and reads its status for itself */
    private static function sqlReads(string $source): array
    {
        $out = [];
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            if (preg_match('/\b' . self::TABLE . '\b/i', $text) !== 1) {
                continue;
            }
            // A write's SET gives status a value; its WHERE is read like any other.
            $read = (string) preg_replace('/\bSET\b.*?(?=\bWHERE\b|\z)/is', '', $text);
            if (preg_match(self::SQL_READ, $read) === 1) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @return list<int> the lines that cast, compare or test a `['status']` rather than ask RuleStatus */
    private static function ownReadings(string $source): array
    {
        $key = self::STATUS_KEY;
        $shapes = [
            // (int) $rule['status'], (string) ($rule['status'] ?? '')
            '/\((?:int|integer|string|bool|boolean|float)\)\s*\(*\s*' . $key . '/',
            // $rule['status'] === …, ($rule['status'] ?? 1) == …; not against the enum
            '/' . $key . '\s*(?:\?\?[^;,]*?)?\)*\s*(?:===?|!==?|<>|<|>)(?!=?\s*RuleStatus::)/',
            // … === $rule['status']
            '/(?:===?|!==?|<>)\s*\(*\s*' . $key . '(?!\s*->)/',
            '/\b(?:isset|empty|intval|boolval|strval|is_null|is_numeric|in_array|match|switch|if|elseif|while)\s*\(\s*!?\s*' . $key . '\s*[,)]/',
            // truthiness
            '/' . $key . '\s*(?:\?(?!\?)|&&|\|\||\band\b|\bor\b)/i',
            '/(?:&&|\|\||!)\s*' . $key . '\s*(?:[;)]|&&|\|\|)/',
        ];
        $out = [];
        foreach (explode("\n", $source) as $n => $line) {
            foreach ($shapes as $shape) {
                if (preg_match($shape, $line) === 1) {
                    $out[] = $n + 1;
                    break;
                }
            }
        }

        return $out;
    }
}
