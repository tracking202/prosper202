<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Prosper202\Notifications\NotificationStatus;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;
use Tests\Support\SqlJoinScan;

/**
 * A notification's state (202_notification_pending.status) is written by
 * NotificationOutbox alone, and only by a move NotificationStatus holds.
 *
 * The worker marked a row `sent`, `failed` or `pending` again with `WHERE
 * notification_id = ?`, so a row cancelled while its send was in flight was
 * put back in the queue and sent after the correction that had replaced it.
 *
 * Per statement, in every PHP file outside tests/ (SqlJoinScan's string
 * chains: literals, heredocs, `.`, interpolation, sprintf(); a class
 * constant holding the table's name read as its value):
 *
 * - an INSERT or REPLACE into the table, or an UPDATE naming it whose SET
 *   list assigns `status`, is in NotificationOutbox.php;
 * - there, the UPDATE sets `status = ?` and its WHERE has a top-level
 *   conjunct naming the state it leaves: `status = ?`, or the `['sql']` of
 *   a variable assigned from NotificationStatus::whereMayMoveTo() in the
 *   file. An OR at the top of the WHERE is refused. The INSERT quotes no
 *   literal and its ON DUPLICATE KEY UPDATE leaves `status` alone;
 * - in any statement naming the table, `status` is compared (=, <>, !=,
 *   <=>, [NOT] IN) with `?` or with a NotificationStatus case's value,
 *   never with a quoted literal;
 * - in a file naming the table, no PHP comparison (===, !==, ==, !=) has a
 *   string literal that is a state's value for an operand.
 *
 * What it does not see: a statement finished by a later one (`$sql .= …`,
 * read as two chains: the SET half is still found, and then refused for
 * having no WHERE); a table name built at runtime; that the `?` bound for a
 * state is a case's value, or the case the move means (move() and insert()
 * ask the enum, and NotificationStatusIntegrationTest runs them); SQL
 * outside PHP (the live scripts' fixtures); a PHP comparison in a file that
 * does not name the table, or one spelled without a comparison operator
 * (in_array(), match, a switch); and the Postbacks sent tab's labels, which
 * NotificationStatusSurfacesTest holds.
 */
final class NotificationStatusWrittenOnlyByTheOutboxTest extends TestCase
{
    private const TABLE = '202_notification_pending';
    private const OWNER = '202-config/Notifications/NotificationOutbox.php';
    private const COMPARES = ['=', '<>', '!=', '<=>'];

    /**
     * What is wrong with one PHP source, as "line N: why"; and the state
     * writes read in it, as "line N: INSERT|UPDATE".
     *
     * @return array{findings: list<string>, writes: list<string>}
     */
    public static function scan(string $source, bool $owner): array
    {
        $findings = [];
        $writes = [];
        $named = false;
        foreach (SqlJoinScan::chains($source) as $chain) {
            foreach ($chain['holes'] as $index => $hole) {
                $value = AssembledStrings::constantAt($source, trim($hole, '{} '), $chain['start']);
                if (is_string($value) && preg_match('/^[A-Za-z0-9_]+$/', $value) === 1) {
                    $chain['text'] = str_replace("\x01{$index}\x02", $value, $chain['text']);
                }
            }
            $t = SqlJoinScan::sqlTokens($chain['text'], $chain['holes']);
            $namesTable = false;
            foreach ($t as $tok) {
                if (in_array($tok['k'], ['word', 'name'], true) && strtolower($tok['v']) === self::TABLE) {
                    $namesTable = true;
                }
            }
            if (!$namesTable) {
                continue;
            }
            $named = true;
            $at = 'line ' . $chain['line'] . ': ';
            foreach (self::literalComparisons($t) as $why) {
                $findings[] = $at . $why;
            }
            $verb = $t[0]['k'] === 'word' ? $t[0]['u'] : '';
            if ($verb === 'INSERT' || $verb === 'REPLACE') {
                if (!self::insertsIntoTheTable($t)) {
                    continue;
                }
                $writes[] = $at . 'INSERT';
                if (!$owner) {
                    $findings[] = $at . 'creates a notification outside NotificationOutbox';
                    continue;
                }
                if ($verb === 'REPLACE') {
                    $findings[] = $at . 'REPLACE rewrites a row whatever state it is in';
                }
                foreach ($t as $i => $tok) {
                    if ($tok['k'] === 'str') {
                        $findings[] = $at . 'the INSERT quotes a literal (' . $tok['v'] . '): a state is bound';
                    }
                    if (self::isWord($tok, 'DUPLICATE')) {
                        foreach (array_slice($t, $i) as $after) {
                            if (self::isWord($after, 'STATUS')) {
                                $findings[] = $at . 'the INSERT rewrites the status of a row that exists';
                            }
                        }
                    }
                }
                continue;
            }
            if ($verb !== 'UPDATE') {
                continue;
            }
            $set = self::topLevel($t, 'SET', 0);
            if ($set === null) {
                continue;
            }
            $where = self::topLevel($t, 'WHERE', $set);
            $assigned = self::statusAssignment($t, $set + 1, $where ?? count($t));
            if ($assigned === null) {
                continue;
            }
            $writes[] = $at . 'UPDATE';
            if (!$owner) {
                $findings[] = $at . 'sets a notification\'s status outside NotificationOutbox';
                continue;
            }
            if (($t[$assigned]['k'] ?? '') !== 'param') {
                $findings[] = $at . 'sets status to something other than a bound state';
            }
            if ($where === null) {
                $findings[] = $at . 'sets status with no WHERE the scan can read';
                continue;
            }
            $why = self::fromStateProblem($source, array_slice($t, $where + 1));
            if ($why !== null) {
                $findings[] = $at . $why;
            }
        }
        if ($named) {
            foreach (self::literalPhpComparisons($source) as $why) {
                $findings[] = $why;
            }
        }

        return ['findings' => $findings, 'writes' => $writes];
    }

    /** @param array{k: string, v: string, u: string}|null $tok */
    private static function isWord(?array $tok, string $word): bool
    {
        return $tok !== null && $tok['k'] === 'word' && $tok['u'] === $word;
    }

    /** @param list<array{k: string, v: string, u: string}> $t */
    private static function insertsIntoTheTable(array $t): bool
    {
        foreach ($t as $i => $tok) {
            if (self::isWord($tok, 'INTO')) {
                $target = $t[$i + 1] ?? null;

                return $target !== null && strtolower($target['v']) === self::TABLE;
            }
        }
        // INSERT with no INTO, or REPLACE <table>: the table is named, so it is read as the target.
        return true;
    }

    /**
     * The index of a word outside every parenthesis, from $from; null when there is none.
     *
     * @param list<array{k: string, v: string, u: string}> $t
     */
    private static function topLevel(array $t, string $word, int $from): ?int
    {
        $depth = 0;
        foreach ($t as $i => $tok) {
            if ($tok['k'] === 'punct' && $tok['v'] === '(') {
                $depth++;
            } elseif ($tok['k'] === 'punct' && $tok['v'] === ')') {
                $depth--;
            } elseif ($i >= $from && $depth === 0 && self::isWord($tok, $word)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The index of the value a SET list assigns to `status` (`status = …`,
     * `n.status = …`, `` `status` = … ``), or null when it assigns none.
     *
     * @param list<array{k: string, v: string, u: string}> $t
     */
    private static function statusAssignment(array $t, int $from, int $to): ?int
    {
        $depth = 0;
        $starts = true;
        for ($i = $from; $i < $to; $i++) {
            $tok = $t[$i];
            if ($tok['k'] === 'punct' && $tok['v'] === '(') {
                $depth++;
            } elseif ($tok['k'] === 'punct' && $tok['v'] === ')') {
                $depth--;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($tok['k'] === 'punct' && $tok['v'] === ',') {
                $starts = true;
                continue;
            }
            if ($starts && self::isColumn($tok) && ($t[$i + 1]['v'] ?? '') === '=') {
                return $i + 2;
            }
            // `n.status`: the alias and its dot do not end the column's turn.
            $starts = $starts && (($tok['k'] === 'punct' && $tok['v'] === '.') || (in_array($tok['k'], ['word', 'name'], true) && ($t[$i + 1]['v'] ?? '') === '.'));
        }

        return null;
    }

    /** @param array{k: string, v: string, u: string} $tok */
    private static function isColumn(array $tok): bool
    {
        return in_array($tok['k'], ['word', 'name'], true) && strtoupper($tok['v']) === 'STATUS';
    }

    /**
     * Why a state-changing UPDATE's WHERE names no state it leaves; null when it does.
     *
     * @param list<array{k: string, v: string, u: string}> $condition
     */
    private static function fromStateProblem(string $source, array $condition): ?string
    {
        $conjuncts = [[]];
        $depth = 0;
        foreach ($condition as $tok) {
            if ($tok['k'] === 'punct' && $tok['v'] === '(') {
                $depth++;
            } elseif ($tok['k'] === 'punct' && $tok['v'] === ')') {
                $depth--;
            }
            if ($depth === 0 && ($tok['k'] === 'word' && in_array($tok['u'], ['OR', 'XOR'], true) || $tok['v'] === '||')) {
                return 'an OR at the top of the WHERE: the state it leaves does not bound the write';
            }
            if ($depth === 0 && self::isWord($tok, 'AND')) {
                $conjuncts[] = [];
                continue;
            }
            $conjuncts[count($conjuncts) - 1][] = $tok;
        }
        foreach ($conjuncts as $c) {
            // [alias .] status = ?
            if (count($c) >= 3 && self::isColumn($c[count($c) - 3]) && $c[count($c) - 2]['v'] === '=' && $c[count($c) - 1]['k'] === 'param'
                && (count($c) === 3 || (count($c) === 5 && $c[1]['v'] === '.'))) {
                return null;
            }
            if (count($c) === 1 && $c[0]['k'] === 'hole' && preg_match('/^\$(\w+)\[\'sql\'\]$/', $c[0]['v'], $m) === 1
                && preg_match('/\$' . $m[1] . '\s*=\s*NotificationStatus::whereMayMoveTo\(\s*NotificationStatus::\w+\s*,\s*\'status\'\s*\)\s*;/', $source) === 1) {
                return null;
            }
        }

        return 'sets status without naming the state it leaves in its WHERE (`status = ?`, or NotificationStatus::whereMayMoveTo())';
    }

    /**
     * Each comparison of `status` with a quoted literal.
     *
     * @param list<array{k: string, v: string, u: string}> $t
     * @return list<string>
     */
    private static function literalComparisons(array $t): array
    {
        $out = [];
        $check = static function (array $tok) use (&$out): void {
            if ($tok['k'] !== 'str') {
                return;
            }
            if (preg_match('/^\{?(?:\\\\?Prosper202\\\\Notifications\\\\)?NotificationStatus::(\w+)->value\}?$/', $tok['u'], $m) === 1
                && defined(NotificationStatus::class . '::' . $m[1])) {
                return;
            }
            $out[] = 'compares status with the literal \'' . ($tok['u'] !== '' ? $tok['u'] : $tok['v']) . '\': bind the state, or name its case';
        };
        foreach ($t as $i => $tok) {
            if (!self::isColumn($tok)) {
                continue;
            }
            $next = $t[$i + 1] ?? null;
            $prev = $t[$i - 1] ?? null;
            if ($prev !== null && $prev['k'] === 'punct' && $prev['v'] === '.') {
                $prev = $t[$i - 3] ?? null;
            }
            if ($next !== null && in_array($next['v'], self::COMPARES, true) && isset($t[$i + 2])) {
                $check($t[$i + 2]);
            }
            if ($prev !== null && in_array($prev['v'], self::COMPARES, true)) {
                $before = $t[$i - 2] ?? null;
                if (($t[$i - 1]['v'] ?? '') === '.') {
                    $before = $t[$i - 4] ?? null;
                }
                if ($before !== null) {
                    $check($before);
                }
            }
            $in = self::isWord($next, 'NOT') ? $i + 2 : $i + 1;
            if (self::isWord($t[$in] ?? null, 'IN') && ($t[$in + 1]['v'] ?? '') === '(') {
                for ($j = $in + 2; isset($t[$j]) && $t[$j]['v'] !== ')'; $j++) {
                    $check($t[$j]);
                }
            }
        }

        return $out;
    }

    /**
     * Each PHP comparison with a string literal that is a state's value.
     *
     * @return list<string>
     */
    private static function literalPhpComparisons(string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
        $compare = [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL];
        $out = [];
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_CONSTANT_ENCAPSED_STRING) || NotificationStatus::tryFrom(substr($token->text, 1, -1)) === null) {
                continue;
            }
            if ((isset($tokens[$i - 1]) && $tokens[$i - 1]->is($compare)) || (isset($tokens[$i + 1]) && $tokens[$i + 1]->is($compare))) {
                $out[] = 'line ' . $token->line . ': compares with the literal ' . $token->text . ' in PHP: name the NotificationStatus case';
            }
        }

        return $out;
    }

    public function testOnlyTheOutboxWritesAStateAndEveryWriteNamesTheStateItLeaves(): void
    {
        $wrong = [];
        $writes = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!str_contains($source, self::TABLE) && !str_contains($source, 'NOTIFICATION_PENDING')) {
                continue;
            }
            $read = self::scan($source, $path === self::OWNER);
            foreach ($read['findings'] as $finding) {
                $wrong[] = "  $path $finding";
            }
            foreach ($read['writes'] as $write) {
                $writes[$path][] = preg_replace('/^line \d+: /', '', $write);
            }
        }
        self::assertSame([], $wrong, "A notification's state is NotificationStatus's, written by NotificationOutbox:\n" . implode("\n", $wrong));
        // The floor: the scan reads the writes that exist, so "none elsewhere" is not "none read".
        // insert(); and move(), cancelUnsentReached(), the revival's restore and the worker's sent.
        self::assertSame([self::OWNER => ['INSERT' => 1, 'UPDATE' => 4]], array_map(
            static function (array $w): array {
                $counts = array_count_values($w);
                ksort($counts);

                return $counts;
            },
            $writes
        ));
    }

    /** @return iterable<string, array{string, bool, string}> PHP statements, whether in the owner, and the refusal ('' for none) */
    public static function shapes(): iterable
    {
        $t = self::TABLE;
        $ok = "\$this->c->prepareWrite('UPDATE $t SET status = ?, last_error = ? WHERE notification_id = ? AND status = ? AND attempts = ?');";

        yield 'the owner, from one bound state' => [$ok, true, ''];
        yield 'the owner, qualified' => ["\$c->p('UPDATE $t n SET n.status = ? WHERE n.notification_id = ? AND n.status = ?');", true, ''];
        yield 'the owner, from the enum\'s sources' => [
            "\$from = NotificationStatus::whereMayMoveTo(NotificationStatus::Sent, 'status');\n"
            . "\$c->p('UPDATE $t SET status = ?, sent_at = ? WHERE notification_id = ? AND ' . \$from['sql']);", true, '',
        ];
        yield 'the owner, the claim (no state written)' => ["\$c->p('UPDATE $t SET attempts = ? WHERE notification_id = ?');", true, ''];
        yield 'the owner, an insert' => ["\$c->p('INSERT INTO $t (user_id, status) VALUES (?, ?) ON DUPLICATE KEY UPDATE notification_id = notification_id');", true, ''];
        yield 'a read with the case named' => ["\$c->p(\"SELECT 1 FROM $t WHERE status = '\" . NotificationStatus::Pending->value . \"'\");", false, ''];
        yield 'a read with the state bound' => ["\$c->p('SELECT 1 FROM $t WHERE status = ? AND status NOT IN (?, ?)');", false, ''];
        yield 'a delete elsewhere' => ["\$c->p('DELETE FROM $t WHERE user_id = ?');", false, ''];
        yield 'another table\'s status' => ["\$c->p('UPDATE 202_destination_deliveries SET status = \\'sent\\' WHERE delivery_id = ?');", false, ''];

        yield 'a second writer' => [$ok, false, 'outside NotificationOutbox'];
        yield 'a second writer, the table by its constant' => [
            "\$c->p('UPDATE ' . \\Prosper202\\Database\\Schema\\TableRegistry::NOTIFICATION_PENDING . ' SET status = ? WHERE notification_id = ? AND status = ?');",
            false, 'outside NotificationOutbox',
        ];
        yield 'a second writer, interpolated' => ["\$id = 1; \$c->p(\"UPDATE `$t` SET `status` = ? WHERE notification_id = {\$id} AND status = ?\");", false, 'outside NotificationOutbox'];
        yield 'a second writer, a heredoc' => ["\$c->p(<<<SQL\n    UPDATE $t\n    SET last_error = NULL, status = ?\n    WHERE notification_id = ?\n    SQL);", false, 'outside NotificationOutbox'];
        yield 'a second writer, sprintf' => ["\$c->p(sprintf('UPDATE $t SET status = ? WHERE notification_id = %d AND status = ?', \$id));", false, 'outside NotificationOutbox'];
        yield 'a second writer, joined' => ["\$c->p('UPDATE 202_conversion_logs c JOIN $t n ON n.conv_id = c.conv_id SET n.status = ? WHERE c.conv_id = ?');", false, 'outside NotificationOutbox'];
        yield 'an insert elsewhere' => ["\$c->p('INSERT INTO $t (user_id, status) VALUES (?, ?)');", false, 'creates a notification outside'];
        yield 'an insert elsewhere, SET form' => ["\$c->p('INSERT IGNORE INTO $t SET user_id = ?, status = ?');", false, 'creates a notification outside'];
        yield 'a replace elsewhere' => ["\$c->p('REPLACE INTO $t (user_id, status) VALUES (?, ?)');", false, 'creates a notification outside'];

        yield 'no state left' => ["\$c->p('UPDATE $t SET status = ?, last_error = ? WHERE notification_id = ?');", true, 'without naming the state it leaves'];
        yield 'the state left under an OR' => ["\$c->p('UPDATE $t SET status = ? WHERE notification_id = ? AND status = ? OR attempts = 0');", true, 'an OR at the top'];
        yield 'the state left inside a disjunction' => ["\$c->p('UPDATE $t SET status = ? WHERE notification_id = ? AND (status = ? OR attempts = 0)');", true, 'without naming the state it leaves'];
        yield 'the state left only in a subquery' => ["\$c->p('UPDATE $t SET status = ? WHERE notification_id IN (SELECT notification_id FROM $t WHERE status = ?)');", true, 'without naming the state it leaves'];
        yield 'a state it is not in' => ["\$c->p('UPDATE $t SET status = ? WHERE notification_id = ? AND status <> ?');", true, 'without naming the state it leaves'];
        yield 'no WHERE' => ["\$c->p('UPDATE $t SET status = ?');", true, 'no WHERE'];
        yield 'a WHERE finished later' => ["\$sql = 'UPDATE $t SET status = ?'; \$sql .= ' WHERE notification_id = ? AND status = ?';", true, 'no WHERE'];
        yield 'sources that are not the enum\'s' => ["\$from = ['sql' => '1 = 1']; \$c->p('UPDATE $t SET status = ? WHERE notification_id = ? AND ' . \$from['sql']);", true, 'without naming the state it leaves'];
        yield 'the enum\'s sources for another column' => [
            "\$from = NotificationStatus::whereMayMoveTo(NotificationStatus::Sent, 'kind');\n\$c->p('UPDATE $t SET status = ? WHERE notification_id = ? AND ' . \$from['sql']);",
            true, 'without naming the state it leaves',
        ];
        yield 'a literal state set' => ["\$c->p(\"UPDATE $t SET status = 'sent' WHERE notification_id = ? AND status = ?\");", true, 'other than a bound state'];
        yield 'a state set from a variable' => ["\$c->p(\"UPDATE $t SET status = '\$status' WHERE notification_id = ? AND status = ?\");", true, 'other than a bound state'];
        yield 'a literal state left' => ["\$c->p(\"UPDATE $t SET status = ? WHERE notification_id = ? AND status = 'pending'\");", true, 'the literal \'pending\''];
        yield 'an insert that quotes its state' => ["\$c->p(\"INSERT INTO $t (user_id, status) VALUES (?, 'pending')\");", true, 'the INSERT quotes a literal'];
        yield 'an insert that rewrites a row' => ["\$c->p('INSERT INTO $t (user_id, status) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)');", true, 'rewrites the status'];
        yield 'a replace in the owner' => ["\$c->p('REPLACE INTO $t (user_id, status) VALUES (?, ?)');", true, 'REPLACE rewrites'];

        yield 'a state no case names' => ["\$c->p(\"SELECT 1 FROM $t WHERE status = 'done'\");", false, 'the literal \'done\''];
        yield 'a literal in a list' => ["\$c->p(\"SELECT 1 FROM $t n WHERE n.status NOT IN ('cancelled', ?)\");", false, 'the literal \'cancelled\''];
        yield 'a literal, the other way round' => ["\$c->p(\"SELECT 1 FROM $t WHERE 'sent' = status\");", false, 'the literal \'sent\''];
        yield 'a literal, unequal' => ["\$c->p(\"SELECT SUM(n.status <> 'cancelled') FROM $t n\");", false, 'the literal \'cancelled\''];
        yield 'a case that does not exist' => ["\$c->p(\"SELECT 1 FROM $t WHERE status = '\" . NotificationStatus::Done->value . \"'\");", false, 'NotificationStatus::Done->value'];
        yield 'a variable in quotes' => ["\$c->p(\"SELECT 1 FROM $t WHERE status = '{\$status}'\");", false, 'the literal \'{$status}\''];
        yield 'a PHP comparison' => ["\$c->p('SELECT status FROM $t'); if (\$row['status'] === 'pending') { return; }", false, 'in PHP'];
        yield 'a PHP comparison, loose and reversed' => ["\$c->p('SELECT status FROM $t'); if ('withheld' != \$row['status']) { return; }", false, 'in PHP'];
    }

    /** @dataProvider shapes */
    public function testTheScanReadsEveryShapeItClaims(string $statements, bool $owner, string $refusal): void
    {
        $source = "<?php\nfinal class Planted {\n    public function f(\$c, \$row): void {\n        $statements\n    }\n}\n";
        $findings = self::scan($source, $owner)['findings'];
        if ($refusal === '') {
            self::assertSame([], $findings);

            return;
        }
        self::assertNotSame([], $findings, 'the shape was read as clean');
        self::assertStringContainsString($refusal, implode("\n", $findings));
    }
}
