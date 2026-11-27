<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * 202_destination_deliveries is written by DeliveryRows and nothing else, and
 * every UPDATE there names the status it expects in its own WHERE.
 *
 * Held, over every PHP file outside tests/:
 *  - a file that names the table (the literal, or TableRegistry's constant)
 *    is the owner or is listed in READERS with how many times it names it,
 *    so a new mention anywhere is read by a person;
 *  - outside the owner, no PHP statement that names the table holds an
 *    INSERT, REPLACE or UPDATE;
 *  - in a file that names the table, no SQL compares or sets `status` with a
 *    literal typed in the string: the value comes from DeliveryStatus;
 *  - in the owner, every UPDATE's WHERE names `status = ?` or the from-states
 *    DeliveryStatus::whereMayMoveTo() gives.
 *
 * Does not see: a statement whose table name arrives in a variable or is
 * built from pieces (the mention count is the floor for that: the name has
 * to be written somewhere); a statement assembled across several PHP
 * statements; whether the value bound to `status = ?` is the right state;
 * whether the affected-row count is read; a status compared in PHP rather
 * than in SQL; and files outside SourceScan's walk (Go, shell).
 */
final class DeliveryRowsWrittenOnlyByTheOwnerTest extends TestCase
{
    private const OWNER = '202-config/Destinations/DeliveryRows.php';

    /** @var array<string, array{0: int, 1: string}> file => how many times it names the table, and what for */
    private const READERS = [
        '202-config/Database/Schema/TableRegistry.php' => [2, 'the name'],
        '202-config/Database/Tables/ConversionTables.php' => [2, 'the table definition'],
        '202-config/Destinations/DestinationQueue.php' => [5, 'reads: the due rows, a backfill\'s already-queued check, health(), log(), realTestSent()'],
        '202-config/Destinations/DestinationStore.php' => [1, 'reads: waiting(), the latest wait only the operator ends'],
        '202-config/User/UserDataPurge.php' => [1, 'deletes a purged account\'s rows, whatever their status'],
        'api/v3/Controllers/DestinationsController.php' => [3, 'reads: a delete\'s preview count and the delivery log'],
    ];

    private const UPDATES_IN_THE_OWNER = 10;
    private const UPDATES_THAT_SET_STATUS = 5;

    private const TABLE = '202_destination_deliveries';
    private const CONSTANT = 'DESTINATION_DELIVERIES';
    private const WRITE = '/\b(?:INSERT|REPLACE)\b(?:\s+(?:IGNORE|LOW_PRIORITY|DELAYED|HIGH_PRIORITY))*\s+INTO\b|(?<!FOR )\bUPDATE\b/i';
    private const LITERAL_STATUS = '/\bstatus`?\s*(?:=|<>|!=|(?:NOT\s+)?IN\s*\()\s*\\\\?[\'"]/i';

    public function testTheTreeHoldsTheRule(): void
    {
        $files = SourceScan::phpFiles();
        self::assertArrayHasKey(self::OWNER, $files);
        self::assertSame([], self::findings($files, self::OWNER, self::READERS));
        foreach (array_keys(self::READERS) as $reader) {
            self::assertArrayHasKey($reader, $files, $reader . ' is listed and gone: take it off the list');
        }
    }

    public function testTheOwnersUpdatesAreAllRead(): void
    {
        $updates = self::updates((string) file_get_contents(SourceScan::repoRoot() . '/' . self::OWNER));
        self::assertCount(self::UPDATES_IN_THE_OWNER, $updates, 'the scan reads every UPDATE of the owner; change the number with the code');
        $setting = array_filter($updates, static fn (array $u): bool => $u['sets']);
        self::assertCount(self::UPDATES_THAT_SET_STATUS, $setting, 'finish, resendOne, resendAll, release, cancelForDeletedDestination');
    }

    public function testAnotherFileThatWritesTheTableIsRefused(): void
    {
        foreach ([
            'the literal name' => '<?php $c->prepareWrite("UPDATE 202_destination_deliveries SET attempts = 0 WHERE delivery_id = ?");',
            'the constant' => '<?php $c->prepareWrite(\'UPDATE `\' . TableRegistry::DESTINATION_DELIVERIES . \'` SET attempts = 0 WHERE delivery_id = ?\');',
            'an insert' => '<?php $c->query(\'INSERT INTO 202_destination_deliveries (user_id) VALUES (1)\');',
            'insert ignore' => '<?php $c->query("insert  ignore into `202_destination_deliveries` (user_id) VALUES (1)");',
            'replace' => '<?php $c->query("REPLACE INTO 202_destination_deliveries (user_id) VALUES (1)");',
            'a heredoc' => "<?php \$c->query(<<<SQL\nUPDATE 202_destination_deliveries SET attempts = 0\nSQL);",
        ] as $spelling => $source) {
            $found = implode("\n", self::findings(['202-config/Elsewhere.php' => $source], self::OWNER, ['202-config/Elsewhere.php' => [1, 'planted']]));
            self::assertStringContainsString('writes 202_destination_deliveries outside', $found, $spelling);
        }
    }

    public function testAFileThatNamesTheTableUnlistedOrMoreOftenThanListedIsRefused(): void
    {
        $read = '<?php $c->prepareRead("SELECT 1 FROM 202_destination_deliveries WHERE delivery_id = ? FOR UPDATE");';
        self::assertSame([], self::findings(['a.php' => $read], self::OWNER, ['a.php' => [1, 'planted']]), 'a locking read is a read');
        self::assertStringContainsString('is not listed', implode("\n", self::findings(['a.php' => $read], self::OWNER, [])));
        self::assertStringContainsString('names the table 2 times, listed for 1', implode("\n", self::findings(['a.php' => $read . ' $t = TableRegistry::DESTINATION_DELIVERIES;'], self::OWNER, ['a.php' => [1, 'planted']])));
        self::assertSame([], self::findings(['a.php' => '<?php // 202_destination_deliveries, in a comment'], self::OWNER, []), 'a comment names nothing');
    }

    public function testAStatusTypedIntoTheSqlIsRefused(): void
    {
        foreach ([
            'compared' => '"SELECT 1 FROM 202_destination_deliveries WHERE status = \'pending\'"',
            'a state no case names' => '"SELECT 1 FROM 202_destination_deliveries WHERE status = \'done\'"',
            'in a list' => '"SELECT 1 FROM 202_destination_deliveries WHERE q.status IN (\'failed\', \'withheld\')"',
            'not equal' => '"SELECT 1 FROM 202_destination_deliveries WHERE `status` <> \'sent\'"',
            'single-quoted PHP' => '\'SELECT 1 FROM 202_destination_deliveries WHERE status = \\\'pending\\\'\'',
            'in another string of the file' => '"SELECT 1 FROM 202_destination_deliveries" . $w, " AND status=\'pending\'"',
        ] as $spelling => $sql) {
            $found = implode("\n", self::findings(['a.php' => '<?php $c->prepareRead(' . $sql . ');'], self::OWNER, ['a.php' => [1, 'planted']]));
            self::assertStringContainsString('types a status into its SQL', $found, $spelling);
        }
        $bound = '<?php $c->prepareRead("SELECT 1 FROM 202_destination_deliveries WHERE status = ? AND kind = \'conversion\' AND status IN (" . $list . ")");';
        self::assertSame([], self::findings(['a.php' => $bound], self::OWNER, ['a.php' => [1, 'planted']]), 'bound, or given by the enum');
    }

    public function testAnOwnersUpdateWithNoStatusInItsWhereIsRefused(): void
    {
        $owner = static fn (string $body): array => [self::OWNER => '<?php final class DeliveryRows { private const REQUEUE = \' SET status = ?, attempts = 0\'; function f() { ' . $body . ' } }'];
        foreach ([
            'sets the status by key alone' => '$c->prepareWrite(\'UPDATE \' . self::TABLE . \' SET status = ?, sent_at = ? WHERE delivery_id = ?\');',
            'through the constant' => '$c->prepareWrite(\'UPDATE \' . self::TABLE . self::REQUEUE . \' WHERE delivery_id = ?\');',
            'a lease write by key alone' => '$c->prepareWrite(\'UPDATE \' . self::TABLE . \' SET attempts = ? WHERE delivery_id = ?\');',
            'no WHERE' => '$c->prepareWrite(\'UPDATE \' . self::TABLE . \' SET status = ?\');',
            'a $from nothing gave' => '$c->prepareWrite(\'UPDATE \' . self::TABLE . \' SET status = ? WHERE delivery_id = ? AND \' . $from[\'sql\']);',
            'status only in the SET' => '$c->prepareWrite(\'UPDATE \' . self::TABLE . \' SET attempts = 1, status = ? WHERE delivery_id = ?\');',
        ] as $spelling => $body) {
            self::assertStringContainsString('does not name the status it expects', implode("\n", self::findings($owner($body), self::OWNER, [])), $spelling);
        }
        foreach ([
            'one state' => '$c->prepareWrite(\'UPDATE \' . self::TABLE . \' SET status = ? WHERE delivery_id = ? AND status = ?\');',
            'the table\'s from-states' => '$from = DeliveryStatus::whereMayMoveTo($to, \'status\'); $c->prepareWrite(\'UPDATE \' . self::TABLE . self::REQUEUE . \' WHERE delivery_id = ? AND \' . $from[\'sql\']);',
        ] as $spelling => $body) {
            self::assertSame([], self::findings($owner($body), self::OWNER, []), $spelling);
        }
    }

    /**
     * @param array<string, string> $files path => source
     * @param array<string, array{0: int, 1: string}> $readers
     * @return list<string>
     */
    private static function findings(array $files, string $owner, array $readers): array
    {
        $found = [];
        foreach ($files as $path => $source) {
            if ($path !== $owner && !str_contains($source, self::TABLE) && !str_contains($source, self::CONSTANT)) {
                continue;
            }
            $statements = self::statements($source);
            $mentions = 0;
            foreach ($statements as $statement) {
                $mentions += $statement['mentions'];
            }
            if ($path !== $owner && $mentions === 0) {
                continue;
            }
            foreach ($statements as $statement) {
                foreach ($statement['strings'] as $string) {
                    if (preg_match(self::LITERAL_STATUS, $string) === 1) {
                        $found[] = $path . ':' . $statement['line'] . ' types a status into its SQL; take it from DeliveryStatus (->value bound, or ->quoted()).';
                    }
                }
            }
            if ($path === $owner) {
                foreach (self::updates($source) as $update) {
                    if (!$update['guarded']) {
                        $found[] = $path . ':' . $update['line'] . ' is an UPDATE that does not name the status it expects in its WHERE.';
                    }
                }
                continue;
            }
            if (!isset($readers[$path])) {
                $found[] = $path . ' names ' . self::TABLE . ' and is not listed: its writes belong in ' . $owner . ', and a reader is listed with why.';
            } elseif ($readers[$path][0] !== $mentions) {
                $found[] = $path . ' names the table ' . $mentions . ' times, listed for ' . $readers[$path][0] . ': read the new statement, then change the number.';
            }
            foreach ($statements as $statement) {
                if ($statement['mentions'] > 0 && preg_match(self::WRITE, $statement['sql']) === 1) {
                    $found[] = $path . ':' . $statement['line'] . ' writes ' . self::TABLE . ' outside ' . $owner . '.';
                }
            }
        }

        return $found;
    }

    /**
     * The file's PHP statements (the tokens between two `;`), each with the
     * strings it holds, those strings joined with what stands between them,
     * and how many times it names the table.
     *
     * @return list<array{line: int, strings: list<string>, sql: string, mentions: int, start: int}>
     */
    private static function statements(string $source): array
    {
        $constants = self::stringConstants($source);
        $statements = [];
        $new = static fn (int $line, int $start): array => ['line' => $line, 'strings' => [], 'sql' => '', 'mentions' => 0, 'start' => $start];
        $current = null;
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG])) {
                continue;
            }
            $current ??= $new($token->line, $token->pos);
            if ($token->text === ';') {
                $statements[] = $current;
                $current = null;
                continue;
            }
            if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML])) {
                // Without the PHP quotes, which would read as the SQL's.
                $text = $token->is(T_CONSTANT_ENCAPSED_STRING) ? substr($token->text, 1, -1) : $token->text;
                $current['strings'][] = $text;
                $current['sql'] .= $text;
                $current['mentions'] += substr_count($token->text, self::TABLE);
                continue;
            }
            if ($token->is(T_STRING) && $token->text === self::CONSTANT) {
                $current['mentions']++;
            }
            // A constant of the file that is one string reads as that string.
            $current['sql'] .= $token->is(T_STRING) && isset($constants[$token->text]) ? $constants[$token->text] : $token->text;
        }
        if ($current !== null) {
            $statements[] = $current;
        }

        return $statements;
    }

    /** @return array<string, string> the file's `const NAME = '…';` */
    private static function stringConstants(string $source): array
    {
        preg_match_all('/\bconst\s+(\w+)\s*=\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*;/', $source, $m, PREG_SET_ORDER);
        $constants = [];
        foreach ($m as [, $name, $value]) {
            $constants[$name] = $value;
        }

        return $constants;
    }

    /**
     * Every UPDATE of the owner: whether it sets the status, and whether its
     * WHERE names `status = ?` or a $from the same function took from
     * whereMayMoveTo().
     *
     * @return list<array{line: int, sets: bool, guarded: bool}>
     */
    private static function updates(string $source): array
    {
        $updates = [];
        foreach (self::statements($source) as $statement) {
            if (preg_match('/\bUPDATE\b(.*?)\bSET\b(.*)\z/is', $statement['sql'], $m) !== 1) {
                continue;
            }
            $where = preg_split('/\bWHERE\b/i', $m[2]);
            $after = is_array($where) && count($where) > 1 ? (string) end($where) : '';
            $function = strrpos(substr($source, 0, $statement['start']), 'function ');
            $before = substr($source, (int) $function, $statement['start'] - (int) $function);
            $updates[] = [
                'line' => $statement['line'],
                'sets' => preg_match('/(?:\A|,)\s*status\s*=/i', (string) (is_array($where) ? $where[0] : $m[2])) === 1,
                'guarded' => preg_match('/\bstatus\s*=\s*\?/i', $after) === 1
                    || (str_contains($after, '$from[sql]') && str_contains($before, '$from = DeliveryStatus::whereMayMoveTo(')),
            ];
        }

        return $updates;
    }
}
