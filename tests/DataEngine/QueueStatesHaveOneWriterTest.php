<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\DirtyHourState;
use Prosper202\DataEngine\RebuildWindowState;
use Prosper202\Lifecycle\State;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * The state of a rebuild window (202_dataengine_job) and of a dirty hour
 * (202_dirty_hours) is two flag columns each. One class writes each table
 * (RebuildWindows, DirtyHours), and every state change it makes is a move
 * the table's enum allows, with the from-state in the statement's WHERE.
 *
 * Six writers set the flags with statements of their own: one enqueue used
 * INSERT IGNORE, which left a rolled-up row as it was; the pass marked a
 * row by its id whatever a writer had done to it since; and the roll-up of
 * an hour claimed, marked and released whichever window had its bounds.
 *
 * Held, over every served PHP file, by the strings PHP assembles
 * (AssembledStrings):
 *  - outside the owner, no INSERT, REPLACE, UPDATE or DELETE names the table;
 *  - outside the owner, no string names the table and one of its flags,
 *    so no reader re-derives the state (the schema's CREATE and ALTER apart);
 *  - in the owner, each UPDATE is `SET <a state> WHERE [keys AND] <a state
 *    that may become it>`, in the enum's own spelling; each INSERT writes an
 *    initial state, and an upsert one every state may become; each DELETE
 *    names a state rows are deleted from.
 *
 * Does not see: a statement whose table is built at runtime (`"UPDATE $t"`,
 * sprintf); a statement that does not start its string, or whose table is
 * in a string appended with `.=`, unless that string names a flag; a flag
 * condition in a string that does not name the table (a join fragment); SQL
 * outside PHP;
 * a PHP comparison of a fetched flag ($row['processed']) with no SQL beside
 * it; whether the caller reads the count a statement answers.
 * RebuildWindowsIntegrationTest and DirtyHoursQueueIntegrationTest hold the
 * enums' SQL to their ofRow() on a server.
 */
final class QueueStatesHaveOneWriterTest extends TestCase
{
    /**
     * `deleted`: the states a row may be deleted from.
     *
     * @var array<string, array{owner: string, flags: list<string>, states: class-string<State>, deleted: list<string>}>
     */
    private const QUEUES = [
        '202_dataengine_job' => [
            'owner' => '202-config/DataEngine/RebuildWindows.php',
            'flags' => ['processing', 'processed'],
            'states' => RebuildWindowState::class,
            'deleted' => [],
        ],
        '202_dirty_hours' => [
            'owner' => '202-config/DataEngine/DirtyHours.php',
            'flags' => ['processed', 'deleted'],
            'states' => DirtyHourState::class,
            // A range rolled up; one queued again meanwhile is Queued, and stays.
            'deleted' => ['taken'],
        ],
    ];

    /** An UPDATE needs no SET in its string: `$sql = "UPDATE t"; $sql .= " SET …";` is two strings. */
    private const WRITE = '/\A\s*(?:(?:INSERT|REPLACE)\b.*?\bINTO|UPDATE|DELETE\b.*?\bFROM)\b/is';
    private const SCHEMA = '/\A\s*(?:CREATE\s+TABLE|ALTER\s+TABLE)\b/i';
    private const KEYS = '(?:\w+ = \?(?: AND \w+ = \?)* AND )?';

    public function testNothingButTheOwnerWritesAQueueOrSpellsItsFlags(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            array_push($found, ...self::outsideFindings($path, $source));
        }
        self::assertSame([], $found, "A queue's state is written or re-derived outside its owner:\n  "
            . implode("\n  ", $found)
            . "\nQueue through DirtyHours or RebuildWindows, and read a row's state with the enum's ofRow().");
    }

    public function testEveryStatementOfAnOwnerIsAMoveItsEnumAllows(): void
    {
        foreach (self::QUEUES as $table => ['owner' => $owner]) {
            $source = (string) file_get_contents(SourceScan::repoRoot() . '/' . $owner);
            self::assertSame([], self::ownerFindings($table, $source), $owner);
        }
    }

    /** The scan reads the owners' statements, so an empty finding is not a scan that read nothing. */
    public function testTheScanReadsTheOwnersStatements(): void
    {
        $kinds = [
            '202_dataengine_job' => ['INSERT' => 1, 'UPDATE' => 3],
            '202_dirty_hours' => ['INSERT' => 1, 'UPDATE' => 2, 'DELETE' => 1],
        ];
        foreach (self::QUEUES as $table => ['owner' => $owner]) {
            $source = (string) file_get_contents(SourceScan::repoRoot() . '/' . $owner);
            $seen = [];
            foreach (array_unique(self::writes($table, $source)) as $statement) {
                $kind = strtoupper((string) strtok($statement, ' '));
                $seen[$kind] = ($seen[$kind] ?? 0) + 1;
            }
            self::assertSame($kinds[$table], $seen, $owner);
        }
    }

    /**
     * @dataProvider plantedOutside
     */
    public function testTheScanRefusesOutsideTheOwner(string $code, string $expected): void
    {
        self::assertSame(['planted.php:2 ' . $expected], self::outsideFindings('planted.php', "<?php\n" . $code), $code);
    }

    /** @return iterable<string, array{string, string}> */
    public static function plantedOutside(): iterable
    {
        yield 'the enqueue that left a rolled-up row' => [
            '$c->prepareWrite(\'INSERT IGNORE INTO 202_dirty_hours SET user_id = ?\');',
            'writes 202_dirty_hours',
        ];
        yield 'the mark by id' => [
            '$db->query("UPDATE 202_dirty_hours set processed=\'1\', deleted=\'1\' where id=" . $row[\'id\']);',
            'writes 202_dirty_hours',
        ];
        yield 'the sweep' => ['$db->query("DELETE FROM 202_dirty_hours where deleted=1");', 'writes 202_dirty_hours'];
        yield 'a replace, lower case, backticks' => [
            '$db->query("replace into `202_dirty_hours` (user_id) values (1)");',
            'writes 202_dirty_hours',
        ];
        yield 'the unguarded claim, joined across lines' => [
            '$sql = "UPDATE 202_dataengine_job SET processing = \'1\' WHERE time_from =\'" . $from' . "\n"
                . '    . "\' AND time_to = \'" . $to . "\'";',
            'writes 202_dataengine_job',
        ];
        yield 'a window queued' => [
            '$sql = "INSERT INTO 202_dataengine_job SET time_from = \'" . $a . "\', time_to = \'" . $b . "\'";',
            'writes 202_dataengine_job',
        ];
        yield 'the table by its constant' => [
            'use Prosper202\Database\Schema\TableRegistry; $db->query("DELETE FROM " . TableRegistry::DATAENGINE_JOB);',
            'writes 202_dataengine_job',
        ];
        yield 'a heredoc' => [
            "\$sql = <<<SQL\nUPDATE 202_dataengine_job SET x = 1\nSQL;\n",
            'writes 202_dataengine_job',
        ];
        yield 'a joined update' => [
            '$db->query("UPDATE 202_users u JOIN 202_dirty_hours d ON d.user_id = u.user_id SET d.user_id = 0");',
            'writes 202_dirty_hours',
        ];
        yield 'an update continued with .=' => [
            '$sql = "UPDATE 202_dirty_hours"; $sql .= " SET user_id = 0";',
            'writes 202_dirty_hours',
        ];
        yield 'a reader counting by a flag' => [
            '$db->query(\'SELECT COUNT(*) as cnt FROM 202_dirty_hours WHERE processed = 0\');',
            'reads 202_dirty_hours by its flag processed',
        ];
        yield 'a reader summing a flag' => [
            '$q = "SELECT count(*) as total, sum(processed) as done FROM 202_dataengine_job";',
            'reads 202_dataengine_job by its flag processed',
        ];
        yield 'a reader skipping claimed windows' => [
            '$q = "SELECT time_to FROM 202_dataengine_job WHERE `processing` != \'1\'";',
            'reads 202_dataengine_job by its flag processing',
        ];
    }

    /**
     * @dataProvider notFindings
     */
    public function testTheScanPasses(string $code): void
    {
        self::assertSame([], self::outsideFindings('planted.php', "<?php\n" . $code), $code);
    }

    /** @return iterable<string, array{string}> */
    public static function notFindings(): iterable
    {
        yield 'a read that names no flag' => ['$db->query("SELECT COUNT(*) FROM 202_dirty_hours WHERE user_id = 5");'];
        yield 'the rollup table, whose name the job table\'s begins with' => [
            '$db->query("DELETE FROM 202_dataengine WHERE click_id = 5 AND processed = 1");',
        ];
        yield 'the schema' => ['$sql = "CREATE TABLE IF NOT EXISTS `202_dataengine_job` (`processed` tinyint(1))";'];
        yield 'another table\'s flag' => ['$db->query("UPDATE 202_conversion_logs SET deleted = 1 WHERE conv_id = 5");'];
        yield 'prose' => ['echo "delete the rows of 202_dirty_hours by hand";'];
    }

    /**
     * @dataProvider plantedInAnOwner
     */
    public function testTheScanRefusesInAnOwner(string $table, string $from, string $to, string $expected): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/' . self::QUEUES[$table]['owner']);
        self::assertSame(1, substr_count($source, $from), 'the plant found its place: ' . $from);

        $found = array_values(array_unique(self::ownerFindings($table, str_replace($from, $to, $source))));

        self::assertSame([$expected], $found);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function plantedInAnOwner(): iterable
    {
        $dirty = '202_dirty_hours';
        $job = '202_dataengine_job';
        yield 'the take with no from-state' => [
            $dirty,
            "\n        . ' WHERE id = ? AND ' . DirtyHourState::IS_QUEUED;",
            "\n        . ' WHERE id = ?';",
            'no move DirtyHourState allows: UPDATE 202_dirty_hours SET processed = 1, deleted = 1 WHERE id = ?',
        ];
        yield 'a take of a range already taken' => [
            $dirty,
            "\n        . ' WHERE id = ? AND ' . DirtyHourState::IS_QUEUED;",
            "\n        . ' WHERE id = ? AND ' . DirtyHourState::IS_TAKEN;",
            'no move DirtyHourState allows: UPDATE 202_dirty_hours SET processed = 1, deleted = 1 WHERE id = ? AND'
                . ' processed <> 0',
        ];
        yield 'flags no state is written with' => [
            $dirty,
            "'UPDATE 202_dirty_hours SET ' . DirtyHourState::SET_TAKEN",
            "'UPDATE 202_dirty_hours SET processed = 1, deleted = 0'",
            'no move DirtyHourState allows: UPDATE 202_dirty_hours SET processed = 1, deleted = 0 WHERE id = ? AND'
                . ' processed = 0',
        ];
        yield 'the finish by id alone, which lost a range queued again' => [
            $dirty,
            "'DELETE FROM 202_dirty_hours WHERE id = ? AND ' . DirtyHourState::IS_TAKEN",
            "'DELETE FROM 202_dirty_hours WHERE id = ?'",
            'deletes rows of no state they may be deleted from: DELETE FROM 202_dirty_hours WHERE id = ?',
        ];
        yield 'a delete of queued ranges' => [
            $dirty,
            "'DELETE FROM 202_dirty_hours WHERE id = ? AND ' . DirtyHourState::IS_TAKEN",
            "'DELETE FROM 202_dirty_hours WHERE id = ? AND ' . DirtyHourState::IS_QUEUED",
            'deletes rows of no state they may be deleted from: DELETE FROM 202_dirty_hours WHERE id = ? AND'
                . ' processed = 0',
        ];
        yield 'an enqueue that leaves a taken row taken' => [
            $dirty,
            "private const QUEUE = 'INSERT INTO 202_dirty_hours (",
            "private const QUEUE = 'INSERT IGNORE INTO 202_dirty_hours (",
            'an INSERT that does not queue: INSERT IGNORE INTO 202_dirty_hours',
        ];
        yield 'an upsert that does not queue again' => [
            $dirty,
            "' ON DUPLICATE KEY UPDATE processed = 0, deleted = 0'",
            "' ON DUPLICATE KEY UPDATE user_id = user_id'",
            'an INSERT that does not queue: INSERT INTO 202_dirty_hours',
        ];
        yield 'an upsert that queues with other flags than the enum\'s' => [
            $dirty,
            "' ON DUPLICATE KEY UPDATE processed = 0, deleted = 0'",
            "' ON DUPLICATE KEY UPDATE processed = 0'",
            'an INSERT that does not queue: INSERT INTO 202_dirty_hours',
        ];
        yield 'a replace' => [
            $dirty,
            "private const QUEUE = 'INSERT INTO 202_dirty_hours (",
            "private const QUEUE = 'REPLACE INTO 202_dirty_hours (",
            'an INSERT that does not queue: REPLACE INTO 202_dirty_hours',
        ];
        yield 'the claim with no from-state' => [
            $job,
            "' WHERE time_from = ? AND time_to = ? AND ' . RebuildWindowState::IS_PENDING",
            "' WHERE time_from = ? AND time_to = ?'",
            'no move RebuildWindowState allows: UPDATE 202_dataengine_job SET processing = 1, processed = 0 WHERE'
                . ' time_from = ? AND time_to = ?',
        ];
        yield 'a claim over another run\'s' => [
            $job,
            "' WHERE time_from = ? AND time_to = ? AND ' . RebuildWindowState::IS_PENDING",
            "' WHERE time_from = ? AND time_to = ? AND processed = 0'",
            'no move RebuildWindowState allows: UPDATE 202_dataengine_job SET processing = 1, processed = 0 WHERE'
                . ' time_from = ? AND time_to = ? AND processed = 0',
        ];
        yield 'a finished window queued again' => [
            $job,
            "private const RELEASE = 'UPDATE 202_dataengine_job SET ' . RebuildWindowState::SET_PENDING\n"
                . "        . ' WHERE time_from = ? AND time_to = ? AND ' . RebuildWindowState::IS_PROCESSING;",
            "private const RELEASE = 'UPDATE 202_dataengine_job SET ' . RebuildWindowState::SET_PENDING\n"
                . "        . ' WHERE time_from = ? AND time_to = ? AND ' . RebuildWindowState::IS_COMPLETED;",
            'no move RebuildWindowState allows: UPDATE 202_dataengine_job SET processing = 0, processed = 0 WHERE'
                . ' time_from = ? AND time_to = ? AND processed <> 0',
        ];
        yield 'a window queued already claimed' => [
            $job,
            "        . RebuildWindowState::SET_PENDING;",
            "        . RebuildWindowState::SET_PROCESSING;",
            'an INSERT that does not queue: INSERT INTO 202_dataengine_job',
        ];
        yield 'a window deleted' => [
            $job,
            "    public function __construct(",
            "    private const DROP = 'DELETE FROM 202_dataengine_job WHERE ' . RebuildWindowState::IS_COMPLETED;\n\n"
                . "    public function __construct(",
            'deletes rows of no state they may be deleted from: DELETE FROM 202_dataengine_job WHERE processed <> 0',
        ];
    }

    /**
     * What $source, a file that is not a queue's owner, does to a queue.
     *
     * @return list<string>
     */
    private static function outsideFindings(string $path, string $source): array
    {
        $found = [];
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            foreach (self::QUEUES as $table => ['owner' => $owner, 'flags' => $flags]) {
                if ($path === $owner || !self::names($table, $text)) {
                    continue;
                }
                if (preg_match(self::WRITE, $text) === 1) {
                    $found[] = "$path:$line writes $table";
                    continue;
                }
                if (preg_match(self::SCHEMA, $text) === 1) {
                    continue;
                }
                foreach ($flags as $flag) {
                    if (preg_match('/\b' . $flag . '\b/i', $text) === 1) {
                        $found[] = "$path:$line reads $table by its flag $flag";
                        break;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * What is wrong with the statements $source, a queue's owner, writes its table with.
     *
     * @return list<string>
     */
    private static function ownerFindings(string $table, string $source): array
    {
        $states = self::QUEUES[$table]['states'];
        $quote = static fn (string $sql): string => preg_quote($sql, '/');
        $moves = [];
        $deletes = [];
        $queues = [];
        foreach ($states::cases() as $from) {
            foreach ($from->next() as $to) {
                $moves[] = '/\AUPDATE ' . $table . ' SET ' . $quote($to->assignment()) . ' WHERE ' . self::KEYS
                    . $quote($from->condition()) . '\z/';
            }
            if (in_array($from->value, self::QUEUES[$table]['deleted'], true)) {
                $deletes[] = '/\ADELETE FROM ' . $table . ' WHERE ' . self::KEYS . $quote($from->condition()) . '\z/';
            }
        }
        foreach ($states::initial() as $initial) {
            // A new row in an initial state, by its flags or by their defaults.
            $queues[] = '/\AINSERT INTO ' . $table . ' SET (?:\w+ = \?, )*' . $quote($initial->assignment()) . '\z/';
            if ($states::sources($initial) === array_values(array_filter(
                $states::cases(),
                static fn (State $s): bool => $s !== $initial
            ))) {
                $queues[] = '/\AINSERT INTO ' . $table . ' \((?:(?!' . implode('|', self::QUEUES[$table]['flags'])
                    . ')\w+(?:, )?)+\) VALUES %s ON DUPLICATE KEY UPDATE ' . $quote($initial->assignment()) . '\z/';
            }
        }

        $found = [];
        foreach (self::writes($table, $source) as $statement) {
            $shown = substr(str_replace(AssembledStrings::UNREAD, '…', $statement), 0, 120);
            $kind = strtoupper((string) strtok($statement, ' '));
            [$allowed, $problem] = match ($kind) {
                'UPDATE' => [$moves, 'no move ' . substr($states, (int) strrpos($states, '\\') + 1) . ' allows: '],
                'DELETE' => [$deletes, 'deletes rows of no state they may be deleted from: '],
                default => [$queues, 'an INSERT that does not queue: '],
            };
            foreach ($allowed as $pattern) {
                if (preg_match($pattern, $statement) === 1) {
                    continue 2;
                }
            }
            if ($kind !== 'UPDATE' && $kind !== 'DELETE' && preg_match('/\A.*?\bINTO \S+/', $shown, $head) === 1) {
                $shown = $head[0];
            }
            $found[] = $problem . $shown;
        }

        return $found;
    }

    /**
     * The statements in $source that write $table, white space folded.
     *
     * @return list<string>
     */
    private static function writes(string $table, string $source): array
    {
        $writes = [];
        foreach (AssembledStrings::of($source) as ['text' => $text]) {
            if (self::names($table, $text) && preg_match(self::WRITE, $text) === 1) {
                $writes[] = trim((string) preg_replace('/\s+/', ' ', $text));
            }
        }

        return $writes;
    }

    private static function names(string $table, string $text): bool
    {
        return preg_match('/(?<![\w])' . $table . '(?![\w])/i', $text) === 1;
    }
}
