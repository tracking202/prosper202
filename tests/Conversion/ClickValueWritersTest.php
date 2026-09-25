<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;

/**
 * A click's lead flag and value are a cache of its conversion ledger rows,
 * and only MysqlConversionLedger::recompute() may write them.
 *
 * Before the ledger, six places set click_lead or click_payout directly:
 * the static endpoints' click update, the repository's own click update, the
 * revenue upload, the subid upload, both subid-clearing pages, and a dead
 * currency helper. Each one wrote a value its rows could not explain. This
 * test walks every PHP file in the tree and fails on any UPDATE (or ON
 * DUPLICATE KEY UPDATE) whose SET clause assigns either column, outside the
 * one file allowed to.
 *
 * How it reads SQL, and what it therefore cannot see: the SQL is rebuilt
 * from the file's string literals in token order, with every non-string
 * token between two literals read as a placeholder. So a statement split
 * across concatenation (`"UPDATE " . $table . " SET click_lead = 1"`),
 * across `.=` statements (`$sql = "UPDATE x SET"; $sql .= " click_payout = 1";`)
 * or written in a heredoc is read whole. A column name that is itself built
 * at runtime (`"SET " . $col . " = 1"`) is not: that shape is refused by name
 * below rather than passed, because a scanner that cannot see a construct
 * must not answer "no such construct" (CLAUDE.md error pattern #20).
 */
final class ClickValueWritersTest extends TestCase
{
    private const RUNTIME = 'an assignment to a column built at runtime (cannot be read): ';

    private const ALLOWED = [
        '202-config/Conversion/Ledger/MysqlConversionLedger.php',
    ];

    /**
     * Files whose UPDATE target or column is built at runtime, so the scan
     * cannot read them, with why none of them can write a click's value.
     * Each reason is checked where it can be.
     */
    private const RUNTIME_TARGETS = [
        '202-config/Crud/MysqlCrudRepository.php' => 'updates only the tables TableConfig lists (asserted below)',
        '202-config/Ltv/MysqlCustomerCrmRepository.php' => 'a customer merge repoints the literal customer_id column of a literal table list',
        '202-config/DataEngine/ClickRollupSql.php' => 'upserts the report rollup (202_dataengine or its _new staging copy), which is derived from 202_clicks, never 202_clicks itself',
        '202-config/class-dataengine.php' => 'upserts the report rollup tables, as above',
    ];

    private const SKIP_DIRS = ['vendor', 'tests', '.git', '.claude', 'node_modules', 'go-cli', 'sdk', 'documentation', 'docs'];

    public function testOnlyTheLedgerWritesAClicksLeadOrValue(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $scanned = 0;
        foreach ($this->phpFiles($root) as $path) {
            $relative = substr($path, strlen($root) + 1);
            $source = file_get_contents($path);
            $this->assertIsString($source, $relative . ' could not be read');
            $scanned++;
            foreach (self::writesOfClickValue($source) as $hit) {
                if (in_array($relative, self::ALLOWED, true)) {
                    continue;
                }
                if (isset(self::RUNTIME_TARGETS[$relative]) && str_starts_with($hit, self::RUNTIME)) {
                    continue;
                }
                $offenders[] = $relative . ': ' . $hit;
            }
        }

        // Not vacuous: the walk has to have seen the tree.
        $this->assertGreaterThan(300, $scanned, 'the file walk found too few PHP files to be the whole tree');
        $this->assertSame(
            [],
            $offenders,
            "click_lead and click_payout are derived from the click's ledger rows by "
            . 'MysqlConversionLedger::recompute(); nothing else may write them. Record or delete a '
            . "conversion through MysqlConversionRepository instead:\n" . implode("\n", $offenders)
        );
    }

    public function testTheGenericCrudRepositoryServesNoClicksTable(): void
    {
        $config = file_get_contents(dirname(__DIR__, 2) . '/202-config/Crud/TableConfig.php');
        $this->assertIsString($config);
        preg_match_all("/table:\\s*'([^']+)'/", $config, $m);
        $this->assertNotSame([], $m[1], 'TableConfig names no tables, so this check read nothing');
        foreach ($m[1] as $table) {
            $this->assertStringStartsNotWith('202_clicks', $table, 'MysqlCrudRepository may update ' . $table
                . ', and its SET columns come from the request; it must not serve a clicks table');
        }
    }

    public function testTheAllowedWriterIsStillWhereTheListSays(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::ALLOWED as $relative) {
            $source = file_get_contents($root . '/' . $relative);
            $this->assertIsString($source, $relative . ' is on the allow-list but does not exist');
            $this->assertNotSame([], self::writesOfClickValue($source), $relative . ' is allowed to write the click value but no longer does');
        }
    }

    /**
     * Every shape a writer has been, or could plausibly be, spelled in. Each
     * must be seen.
     *
     * @dataProvider plantedWriters
     */
    public function testEveryPlantedShapeIsSeen(string $code): void
    {
        $this->assertNotSame([], self::writesOfClickValue("<?php\n" . $code), 'not seen: ' . $code);
    }

    /** @return iterable<string, array{string}> */
    public static function plantedWriters(): iterable
    {
        yield 'one literal' => ['$db->query("UPDATE 202_clicks SET click_lead = 1 WHERE click_id = 5");'];
        yield 'single-quoted, backticks' => ["\$db->query('UPDATE `202_clicks` SET `click_payout`=\\'3\\' WHERE click_id=1');"];
        yield 'lowercase' => ['$db->query("update 202_clicks set click_lead=0 where click_id=1");'];
        yield 'second column in the list' => ['$db->query("UPDATE 202_clicks SET click_filtered = 0, click_payout = 2 WHERE click_id = 1");'];
        yield 'table in a variable' => ['$db->query("UPDATE " . $table . " SET click_lead = 1 WHERE click_id = ?");'];
        yield 'value interpolated' => ['$db->query("UPDATE 202_clicks_spy SET click_payout=\'$p\' WHERE click_id=1");'];
        yield 'built across .=' => ['$sql = "UPDATE `202_clicks` SET"; $sql .= " `click_payout`=\'" . $p . "\' "; $sql .= "WHERE click_id=1"; $db->query($sql);'];
        yield 'heredoc' => ["\$sql = <<<SQL\nUPDATE 202_clicks\nSET click_lead = 1\nWHERE click_id = 1\nSQL;\n"];
        yield 'multi-table update' => ['$db->query("UPDATE 202_clicks AS c INNER JOIN 202_aff_campaigns AS a ON a.id = c.aff_campaign_id SET c.click_lead=0 WHERE c.user_id=1");'];
        yield 'upsert' => ['$db->query("INSERT INTO 202_clicks (click_id) VALUES (1) ON DUPLICATE KEY UPDATE click_payout = 3");'];
        yield 'column built at runtime' => ['$db->query("UPDATE 202_clicks SET " . $column . " = 1 WHERE click_id = 1");'];
        yield 'table and column built at runtime' => ['$db->query("UPDATE {$table} SET {$column} = ? WHERE id = ?");'];
        yield 'seed without the lead guard' => ['$db->query("UPDATE 202_clicks SET aff_campaign_id=1, click_payout=2 WHERE click_id=5");'];
        yield 'seed guarded on another column' => ['$db->query("UPDATE 202_clicks SET click_payout=2 WHERE click_id=5 AND click_filtered = 0");'];
        yield 'a guard that also sets the lead' => ['$db->query("UPDATE 202_clicks SET click_payout=2, click_lead=1 WHERE click_id=5 AND click_lead = 0");'];
    }

    /**
     * Reads of the columns are not writes.
     *
     * @dataProvider plantedReads
     */
    public function testReadsAreNotWrites(string $code): void
    {
        $this->assertSame([], self::writesOfClickValue("<?php\n" . $code), 'read reported as a write: ' . $code);
    }

    /** @return iterable<string, array{string}> */
    public static function plantedReads(): iterable
    {
        yield 'select' => ['$db->query("SELECT click_lead, click_payout FROM 202_clicks WHERE click_lead = 1");'];
        yield 'update of another column filtered on the lead' => ['$db->query("UPDATE 202_clicks SET click_filtered = 0 WHERE click_lead = 1 AND click_payout = 0");'];
        yield 'insert of a new click' => ['$db->query("INSERT INTO 202_clicks SET click_id = 1, click_payout = 2, click_lead = 0");'];
        yield 'comment' => ["// UPDATE 202_clicks SET click_lead = 1\n\$x = 1;"];
        yield 'another table with a runtime column' => ['$db->query("UPDATE 202_rotators SET " . $col . " = 1 WHERE id = 1");'];
        yield 'routing seed on a click that has not converted' => ['$db->query("UPDATE 202_clicks AS c SET c.aff_campaign_id=\'1\', c.click_payout=\'2\' WHERE c.click_id=\'5\' AND c.click_lead = 0");'];
    }

    /**
     * @return list<string> One description per write of click_lead or click_payout.
     */
    public static function writesOfClickValue(string $source): array
    {
        $text = '';
        $tokens = token_get_all($source);
        foreach ($tokens as $i => $token) {
            if (is_array($token)) {
                [$id, $value] = $token;
                if ($id === T_CONSTANT_ENCAPSED_STRING) {
                    $text .= stripcslashes(substr($value, 1, -1));
                } elseif ($id === T_ENCAPSED_AND_WHITESPACE) {
                    $text .= $value;
                } elseif ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_WHITESPACE || $id === T_OPEN_TAG) {
                    continue;
                } else {
                    $text .= ' ? ';
                }
            } elseif ($token === ';') {
                // `$sql .= "..."` continues the statement the previous one
                // started, so it is read as part of it.
                $text .= self::continuesWithConcatAssign($tokens, $i) ? ' ' : ' ; ';
            } elseif ($token !== '.' && $token !== '"') {
                $text .= ' ? ';
            }
        }

        $hits = [];
        $patterns = [
            '/\bUPDATE\b(?<target>[^;]{0,400}?)\bSET\b(?<set>.{0,2000}?)(?<where>(?:\bWHERE\b|\bORDER\s+BY\b|\bLIMIT\b)[^;]{0,2000}|(?= ; )|$)/is',
            '/\bINSERT\b[^;]{0,40}?\bINTO\b(?<target>[^;(]{0,200}?)[(\s][^;]{0,4000}?\bON\s+DUPLICATE\s+KEY\s+UPDATE\b(?<set>.{0,2000}?)(?<where>(?= ; )|$)/is',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === false) {
                throw new \RuntimeException('the scan pattern failed: ' . preg_last_error_msg());
            }
            foreach ($matches as $m) {
                $target = $m['target'];
                $set = $m['set'];
                $where = $m['where'];
                $shown = trim((string) preg_replace('/\s+/', ' ', substr($m[0], 0, 160)));

                // A literal target that is not a clicks table cannot hold
                // the columns; an unknown one (a placeholder) might.
                $clicksTarget = preg_match('/\b202_clicks(_spy)?\b/i', $target) === 1
                    || str_contains($target, '?')
                    || $target === '';
                if (!$clicksTarget) {
                    continue;
                }

                $setsLead = preg_match('/\bclick_lead`?\s*=(?!=)/i', $set) === 1;
                $setsPayout = preg_match('/\bclick_payout`?\s*=(?!=)/i', $set) === 1;
                if ($setsLead || $setsPayout) {
                    // The one other writer the ledger allows: routing a click
                    // that has not converted to an offer seeds its payout.
                    $guarded = !$setsLead && preg_match("/\bclick_lead`?\s*=\s*'?0'?(?![0-9])/i", $where) === 1;
                    if (!$guarded) {
                        $hits[] = $shown;
                    }
                } elseif (preg_match('/\bSET\s*\?|,\s*\?\s*=|^\s*\?\s*=/i', 'SET' . $set) === 1) {
                    $hits[] = self::RUNTIME . $shown;
                }
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function continuesWithConcatAssign(array $tokens, int $at): bool
    {
        $seen = [];
        for ($j = $at + 1, $n = count($tokens); $j < $n && count($seen) < 2; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $seen[] = $t;
        }

        return count($seen) === 2 && is_array($seen[0]) && $seen[0][0] === T_VARIABLE
            && is_array($seen[1]) && $seen[1][0] === T_CONCAT_EQUAL;
    }

    /**
     * @return iterable<string>
     */
    private function phpFiles(string $root): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file) use ($root): bool {
                    if ($file->isDir()) {
                        return !in_array($file->getFilename(), self::SKIP_DIRS, true)
                            || dirname($file->getPathname()) !== $root;
                    }
                    return $file->getExtension() === 'php';
                }
            )
        );
        foreach ($iterator as $file) {
            yield $file->getPathname();
        }
    }
}
