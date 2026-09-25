<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;
use Tests\Support\SqlLiteralText;

/**
 * Goals are recorded through the conversion ledger, never beside it
 * (plan §2.1, §5.5), and their outcomes are read through one method.
 *
 * Three invariants over the whole tree:
 *
 * 1. Only the ledger inserts conversion rows: an INSERT (or REPLACE) into
 *    202_conversion_logs appears in MysqlConversionRepository (record()) and
 *    MysqlConversionLedger (the legacy baseline) and nowhere else. The goal
 *    engine writes its conversions through recordInTransaction(); a second
 *    writer would bypass the dedupe key, the click recompute and the MTA
 *    outbox that make the click's value explainable.
 * 2. Only the engine writes a subject's goal state: INSERT, UPDATE or
 *    DELETE of 202_goal_outcomes, _progress, _events and _subjects appears
 *    in GoalEngine, and — deletes only — in UserDataPurge.
 * 3. Every read of 202_goal_outcomes goes through
 *    MysqlGoalRepository::liveOutcomes()/countLiveOutcomes(), whose filter
 *    always carries `superseded_at IS NULL`. The one other reader is the
 *    engine's revive lookup, which must see retired rows by design.
 *
 * The SQL is read from string literals (SqlLiteralText); a target table
 * built entirely at runtime reads as `?` and is refused by name for the
 * statements this test is about, rather than passed.
 */
final class GoalWritersTest extends TestCase
{
    private const LEDGER_INSERTERS = [
        '202-config/Conversion/MysqlConversionRepository.php',
        '202-config/Conversion/Ledger/MysqlConversionLedger.php',
    ];

    private const STATE_TABLES = '202_goal_(?:outcomes|progress|events|subjects)';

    private const STATE_WRITERS = [
        '202-config/Goals/GoalEngine.php' => 'any',
        '202-config/User/UserDataPurge.php' => 'delete',
    ];

    private const OUTCOME_READERS = [
        '202-config/Goals/MysqlGoalRepository.php' => 2, // liveOutcomes(), countLiveOutcomes()
        '202-config/Goals/GoalEngine.php' => 1,          // the revive lookup
        '202-config/User/UserDataPurge.php' => 1,        // the purge's DELETE … FROM
    ];

    /** @return array<string, string> relative path => SQL text */
    private static function tree(): array
    {
        static $tree = null;
        if ($tree !== null) {
            return $tree;
        }
        $root = dirname(__DIR__, 2);
        $tree = [];
        foreach (SqlLiteralText::phpFiles($root) as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            $tree[substr($path, strlen($root) + 1)] = SqlLiteralText::of($source);
        }

        return $tree;
    }

    /** @return list<string> */
    public static function ledgerInserts(string $sql): array
    {
        preg_match_all('/\b(?:INSERT|REPLACE)\b[^;]{0,40}?\bINTO\s+`?(202_conversion_logs|\?)`?[\s(]/i', $sql, $m);

        return $m[1];
    }

    /** @return list<string> the verbs that write a goal state table */
    public static function stateWrites(string $sql): array
    {
        $verbs = [];
        $patterns = [
            'insert' => '/\b(?:INSERT|REPLACE)\b[^;]{0,40}?\bINTO\s+`?' . self::STATE_TABLES . '`?\b/i',
            'update' => '/\bUPDATE\s+`?' . self::STATE_TABLES . '`?\b/i',
            'delete' => '/\bDELETE\b[^;]{0,40}?\bFROM\s+`?' . self::STATE_TABLES . '`?\b/i',
        ];
        foreach ($patterns as $verb => $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                $verbs[] = $verb;
            }
        }

        return $verbs;
    }

    public static function outcomeReads(string $sql): int
    {
        return preg_match_all('/\b(?:FROM|JOIN)\s+`?202_goal_outcomes`?\b/i', $sql);
    }

    public function testTheWalkSawTheTree(): void
    {
        self::assertGreaterThan(300, count(self::tree()), 'the file walk found too few PHP files to be the whole tree');
    }

    public function testOnlyTheLedgerInsertsConversionRows(): void
    {
        $offenders = [];
        foreach (self::tree() as $relative => $sql) {
            foreach (self::ledgerInserts($sql) as $target) {
                if ($target === '202_conversion_logs' && in_array($relative, self::LEDGER_INSERTERS, true)) {
                    continue;
                }
                if ($target === '?' && !str_contains($sql, '202_conversion_logs')) {
                    continue; // a runtime target in a file that never names the ledger
                }
                $offenders[] = $relative . ' (' . $target . ')';
            }
        }
        self::assertSame([], $offenders, 'Conversion rows are inserted by MysqlConversionRepository::record()/recordInTransaction() only; '
            . 'goal conversions go through GoalEngine, which calls it.');
        foreach (self::LEDGER_INSERTERS as $relative) {
            self::assertContains('202_conversion_logs', self::ledgerInserts(self::tree()[$relative] ?? ''), $relative . ' no longer inserts: fix the list');
        }
    }

    public function testNoGenericWriterCanBePointedAtTheLedger(): void
    {
        // The two writers whose table comes from data, not a literal.
        $config = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/Crud/TableConfig.php');
        self::assertStringNotContainsString('202_conversion_logs', $config);
        foreach (glob(dirname(__DIR__, 2) . '/api/v3/Controllers/*.php') ?: [] as $controller) {
            $source = (string) file_get_contents($controller);
            self::assertDoesNotMatchRegularExpression("/function tableName\\(\\)[^}]*'202_(conversion_logs|goal_\\w+)'/s", $source, basename($controller));
        }
    }

    public function testOnlyTheEngineWritesASubjectsGoalState(): void
    {
        $offenders = [];
        foreach (self::tree() as $relative => $sql) {
            $verbs = self::stateWrites($sql);
            if ($verbs === []) {
                continue;
            }
            $allowed = self::STATE_WRITERS[$relative] ?? null;
            if ($allowed === 'any' || ($allowed === 'delete' && $verbs === ['delete'])) {
                continue;
            }
            $offenders[] = $relative . ' (' . implode(', ', $verbs) . ')';
        }
        self::assertSame([], $offenders, 'goal events, progress, subjects and outcomes are written by GoalEngine only');
        self::assertSame(['insert', 'update', 'delete'], self::stateWrites(self::tree()['202-config/Goals/GoalEngine.php']));
    }

    public function testEveryReadOfOutcomesGoesThroughLiveOutcomes(): void
    {
        $found = [];
        foreach (self::tree() as $relative => $sql) {
            $reads = self::outcomeReads($sql);
            if ($reads > 0) {
                $found[$relative] = $reads;
            }
        }
        ksort($found);
        $expected = self::OUTCOME_READERS;
        ksort($expected);
        self::assertSame($expected, $found, 'a new reader of 202_goal_outcomes must call MysqlGoalRepository::liveOutcomes(), '
            . 'which never returns a retired row; reading the table directly counts an outcome twice after a replay or a re-evaluation');

        $repo = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/Goals/MysqlGoalRepository.php');
        self::assertMatchesRegularExpression("/function outcomeFilter\\(.*?\\\$where = \\['user_id = \\?', 'superseded_at IS NULL'\\];/s", $repo,
            'the one filter every outcome read uses starts from the live rows');
        self::assertSame(2, substr_count($repo, 'self::outcomeFilter('), 'both readers use the filter');
    }

    /** @dataProvider plantedLedgerInserts */
    public function testEveryPlantedLedgerInsertIsSeen(string $code): void
    {
        self::assertNotSame([], self::ledgerInserts(SqlLiteralText::of("<?php\n" . $code)), $code);
    }

    /** @return iterable<string, array{string}> */
    public static function plantedLedgerInserts(): iterable
    {
        yield 'plain' => ['$db->query("INSERT INTO 202_conversion_logs (click_id) VALUES (1)");'];
        yield 'backticks, lowercase' => ["\$db->query('insert into `202_conversion_logs` set click_id = 1');"];
        yield 'ignore' => ['$db->query("INSERT IGNORE INTO 202_conversion_logs (click_id) VALUES (1)");'];
        yield 'replace' => ['$db->query("REPLACE INTO 202_conversion_logs (click_id) VALUES (1)");'];
        yield 'split across .=' => ['$sql = "INSERT INTO"; $sql .= " 202_conversion_logs (click_id) VALUES (1)"; $db->query($sql);'];
        yield 'runtime table beside the name' => ['$t = "202_conversion_logs"; $db->query("INSERT INTO " . $t . " (click_id) VALUES (1)");'];
    }

    /** @dataProvider plantedStateWrites */
    public function testEveryPlantedStateWriteIsSeen(string $code, string $verb): void
    {
        self::assertContains($verb, self::stateWrites(SqlLiteralText::of("<?php\n" . $code)), $code);
    }

    /** @return iterable<string, array{string, string}> */
    public static function plantedStateWrites(): iterable
    {
        yield 'insert an outcome' => ['$db->query("INSERT INTO 202_goal_outcomes (goal_id) VALUES (1)");', 'insert'];
        yield 'retire an outcome' => ['$db->query("UPDATE `202_goal_outcomes` SET superseded_at = 1");', 'update'];
        yield 'bump progress' => ['$db->query("update 202_goal_progress set `count` = `count` + 1");', 'update'];
        yield 'drop events' => ['$db->query("DELETE FROM 202_goal_events WHERE subject_id = 1");', 'delete'];
        yield 'aliased delete' => ['$db->query("DELETE s FROM 202_goal_subjects s WHERE s.user_id = 1");', 'delete'];
    }

    /** @dataProvider plantedOutcomeReads */
    public function testEveryPlantedOutcomeReadIsSeen(string $code): void
    {
        self::assertSame(1, self::outcomeReads(SqlLiteralText::of("<?php\n" . $code)), $code);
    }

    /** @return iterable<string, array{string}> */
    public static function plantedOutcomeReads(): iterable
    {
        yield 'select' => ['$db->query("SELECT COUNT(*) FROM 202_goal_outcomes WHERE goal_id = 1");'];
        yield 'join' => ['$db->query("SELECT g.name FROM 202_goals g JOIN `202_goal_outcomes` o ON o.goal_id = g.goal_id");'];
        yield 'split' => ['$sql = "SELECT * FROM"; $sql .= " 202_goal_outcomes"; $db->query($sql);'];
    }
}
