<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * Connection is the one place a transaction begins or ends.
 *
 * mysqli's begin_transaction() inside an open transaction commits it
 * (CLAUDE.md #13). Connection::transaction() refuses to begin inside one it
 * opened, and joinTransaction() refuses outside one; neither can see a
 * transaction opened anywhere else, nor stop a commit or rollback made
 * behind its back. So no served file outside 202-config/Database/
 * Connection.php begins, ends or turns off autocommit, in any spelling this
 * scan reads:
 *
 *  - a call of begin_transaction, autocommit, commit, rollback, savepoint,
 *    release_savepoint or PDO's beginTransaction as a method (`->`, `?->`,
 *    `::`), or of a mysqli_* function of those (`name(`, `\name(`,
 *    `namespace\name(`), in any case, first-class callables included;
 *  - a string naming one of them, other than an array key: what a callable
 *    array, call_user_func() or a variable method name would call;
 *  - a member call through a variable or an expression (`->$m()`,
 *    `->{...}()`, `::$m()`), whose name the scan cannot read: refused
 *    whatever it calls (the served tree has none);
 *  - SQL that does it (START TRANSACTION, BEGIN [WORK], COMMIT, ROLLBACK,
 *    SAVEPOINT, RELEASE SAVEPOINT, SET … autocommit, XA START or BEGIN, and
 *    LOCK TABLES and UNLOCK TABLES, which commit an open transaction), in a
 *    string read as PHP assembles it (AssembledStrings: across `.`,
 *    interpolation and heredocs), one statement at a time; and a statement
 *    whose verb is built at runtime ahead of TRANSACTION, WORK or SAVEPOINT,
 *    or a SET whose variable is.
 *
 * Not seen: a name or statement built at runtime other than as above (by a
 * call such as sprintf(), or as a literal joined after a variable), or read
 * from outside the source; nor DDL, which commits an open transaction too
 * (the installer and the upgrade run theirs outside any). Each allowed
 * boundary says why it is not Connection's, and the list only shrinks.
 */
final class TransactionsBeginOnlyInConnectionTest extends TestCase
{
    private const CONNECTION = '202-config/Database/Connection.php';

    private const METHODS = [
        'begin_transaction', 'begintransaction', 'autocommit', 'commit', 'rollback', 'savepoint', 'release_savepoint',
    ];

    private const FUNCTIONS = [
        'mysqli_begin_transaction', 'mysqli_autocommit', 'mysqli_commit', 'mysqli_rollback', 'mysqli_savepoint',
        'mysqli_release_savepoint',
    ];

    /** file => what the scan reports there, each needed for the reason given */
    private const ALLOWED = [
        // The database error page ends its caller's transaction before it
        // records the error, and tells Connection (transactionsEnded()).
        '202-config/mysql-error-args.php' => ['->autocommit()', '->rollback()'],
        // The rule that checks these calls' results names them.
        '202-config/PHPStan/Rules/UncheckedTransactionBoundaryRule.php' => [
            "'autocommit'", "'begin_transaction'", "'commit'",
            "'mysqli_autocommit'", "'mysqli_begin_transaction'", "'mysqli_commit'",
        ],
    ];

    public function testOnlyConnectionBeginsOrEndsATransaction(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            if ($file === self::CONNECTION) {
                continue;
            }
            $hits = self::scan($source);
            if ($hits !== []) {
                $found[$file] = $hits;
            }
        }

        $unlisted = [];
        foreach ($found as $file => $hits) {
            $allowed = self::ALLOWED[$file] ?? [];
            foreach ($hits as [$line, $what]) {
                if (!in_array($what, $allowed, true)) {
                    $unlisted[] = "$file:$line $what";
                }
            }
        }
        self::assertSame([], $unlisted, 'A transaction boundary outside Connection. Run the work with'
            . ' Connection::transaction(), or in the caller\'s with joinTransaction(); a raw begin_transaction()'
            . ' inside one of its transactions commits it, and a raw commit() or rollback() ends it behind its back.');

        $stale = [];
        foreach (self::ALLOWED as $file => $allowed) {
            $whats = array_column($found[$file] ?? [], 1);
            foreach ($allowed as $what) {
                if (!in_array($what, $whats, true)) {
                    $stale[] = "$file $what";
                }
            }
        }
        self::assertSame([], $stale, 'These allowed boundaries are gone; take them off the list.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function spellings(): iterable
    {
        yield 'method' => ['$db->begin_transaction();', '->begin_transaction()'];
        yield 'nullsafe method' => ['$this->db?->commit();', '->commit()'];
        yield 'static-looking method' => ['mysqli::rollback($db);', '->rollback()'];
        yield 'any case' => ['$db->BEGIN_TRANSACTION();', '->begin_transaction()'];
        yield 'autocommit off' => ['$db->autocommit(false);', '->autocommit()'];
        yield 'savepoint' => ['$db->savepoint("a");', '->savepoint()'];
        yield 'release savepoint' => ['$db->release_savepoint("a");', '->release_savepoint()'];
        yield 'PDO begin' => ['$pdo->beginTransaction();', '->begintransaction()'];
        yield 'first-class callable' => ['$f = $db->commit(...);', '->commit()'];
        yield 'function' => ['mysqli_begin_transaction($db);', 'mysqli_begin_transaction()'];
        yield 'qualified function' => ['\\mysqli_autocommit($db, false);', 'mysqli_autocommit()'];
        yield 'namespace-relative function' => ['namespace\\mysqli_commit($db);', 'mysqli_commit()'];
        yield 'function, any case' => ['MySQLi_Rollback($db);', 'mysqli_rollback()'];
        yield 'callable array' => ['call_user_func([$db, \'begin_transaction\']);', "'begin_transaction'"];
        yield 'callable string' => ['call_user_func("mysqli_commit", $db);', "'mysqli_commit'"];
        yield 'name in a variable' => ['$m = \'rollback\';', "'rollback'"];
        yield 'name assembled' => ['$m = \'com\' . \'mit\';', "'commit'"];
        yield 'variable method' => ['$db->$m();', '->$…()'];
        yield 'expression method' => ['$db->{$m}();', '->$…()'];
        yield 'variable static method' => ['mysqli::$m($db);', '->$…()'];
        yield 'expression method naming a boundary\'s start' => ['$db->{\'comm\' . $tail}();', '->$…()'];
        yield 'start transaction' => ['$db->query("START TRANSACTION");', 'SQL START TRANSACTION'];
        yield 'start transaction, options' => [
            '$db->query(\'start transaction with consistent snapshot\');', 'SQL START TRANSACTION',
        ];
        yield 'begin' => ['$db->query(\'BEGIN\');', 'SQL BEGIN'];
        yield 'begin work' => ['$db->query("begin work;");', 'SQL BEGIN'];
        yield 'commit, a bare name' => ['$db->real_query(\'COMMIT\');', "'commit'"];
        yield 'commit and chain' => ['$db->query(\'COMMIT WORK AND NO CHAIN\');', 'SQL COMMIT'];
        yield 'rollback to savepoint' => ['$db->query("ROLLBACK TO SAVEPOINT $name");', 'SQL ROLLBACK'];
        yield 'savepoint sql' => ['$db->query(\'SAVEPOINT a\');', 'SQL SAVEPOINT'];
        yield 'release savepoint sql' => ['$db->query(\'RELEASE SAVEPOINT a\');', 'SQL RELEASE SAVEPOINT'];
        yield 'set autocommit' => ['$db->query(\'SET autocommit = 0\');', 'SQL SET autocommit'];
        yield 'set session autocommit' => ['$db->query("SET SESSION AUTOCOMMIT=0");', 'SQL SET autocommit'];
        yield 'set @@autocommit' => ['$db->query(\'SET @@session.autocommit := 0\');', 'SQL SET autocommit'];
        yield 'set with others' => ['$db->query("SET sql_mode = \'\', autocommit = 0");', 'SQL SET autocommit'];
        yield 'xa start' => ['$db->query("XA START \'x\'");', 'SQL XA'];
        yield 'lock tables' => ['$db->query(\'LOCK TABLES 202_clicks WRITE\');', 'SQL LOCK TABLES'];
        yield 'unlock tables' => ['$db->query(\'UNLOCK TABLES\');', 'SQL UNLOCK TABLES'];
        yield 'second statement' => [
            '$db->multi_query(\'SELECT 1; START TRANSACTION; SELECT 2\');', 'SQL START TRANSACTION',
        ];
        yield 'after a comment' => ['$db->query("/* open */ START TRANSACTION");', 'SQL START TRANSACTION'];
        yield 'assembled sql' => ['$db->query(\'START \' . \'TRANSACTION\');', 'SQL START TRANSACTION'];
        yield 'heredoc' => ["\$db->query(<<<SQL\n    START TRANSACTION\n    SQL);", 'SQL START TRANSACTION'];
        yield 'nowdoc' => ["\$db->query(<<<'SQL'\n    COMMIT WORK\n    SQL);", 'SQL COMMIT'];
        yield 'heredoc, a bare name' => ["\$db->query(<<<SQL\n    ROLLBACK\n    SQL);", "'rollback'"];
        yield 'class constant' => ["final class K { const BEGIN = 'BEGIN'; }", 'SQL BEGIN'];
        yield 'verb built at runtime' => ['$db->query($verb . \' TRANSACTION\');', 'SQL built-verb TRANSACTION'];
        yield 'set of a variable built at runtime' => ['$db->query("SET {$name} = 0");', 'SQL SET built-variable'];
    }

    /** @dataProvider spellings */
    public function testTheScanReadsEverySpelling(string $code, string $what): void
    {
        self::assertSame([[2, $what]], self::scan("<?php\n" . $code), $code);
    }

    /** @return iterable<string, array{string}> */
    public static function notBoundaries(): iterable
    {
        yield 'Connection' => ['$conn->transaction(fn () => 1); $conn->joinTransaction($f); $conn->inTransaction();'];
        yield 'a declaration' => ['final class F { public function commit(): bool { return true; } }'];
        yield 'a property' => ['$x = $db->autocommit;'];
        yield 'an array key' => ['$a = [\'commit\' => $sha, "rollback" => 1];'];
        yield 'prose' => ['$m = \'Commit failed\'; $n = "Rollback the change, then begin again"; $o = \'Begin here\';'];
        yield 'another setting' => ['$db->query("SET SESSION sql_mode = \'\'");'];
        yield 'a named setting' => ['$db->query("SET time_zone = \'+00:00\'");'];
        yield 'a word inside a statement' => ['$db->query("SELECT commit FROM t WHERE begin = 1");'];
        yield 'a comment' => ['// $db->begin_transaction(); START TRANSACTION' . "\n" . '/* $db->commit() */'];
        yield 'a function of another name' => ['mysqli_commit_all($db); my_rollback($db);'];
        yield 'an expression method no boundary starts with' => ['$this->{\'set\' . ucfirst($field)}($value);'];
    }

    /** @dataProvider notBoundaries */
    public function testTheScanReportsNothingElse(string $code): void
    {
        self::assertSame([], self::scan("<?php\n" . $code), $code);
    }

    /**
     * What the file does to a transaction, by line.
     *
     * @return list<array{int, string}>
     */
    private static function scan(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $hits = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            $prev = $tokens[$i - 1] ?? null;
            $member = is_array($prev)
                && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
            if ($member && (($t === '{') || self::is($t, T_VARIABLE))) {
                $after = $t === '{' ? self::closing($tokens, $i) + 1 : $i + 1;
                $readable = $t === '{' && self::namesNoBoundary($tokens[$i + 1] ?? null);
                if (($tokens[$after] ?? null) === '(' && !$readable) {
                    $hits[] = [self::line($tokens, $i), '->$…()'];
                }
                continue;
            }
            if (!is_array($t)) {
                continue;
            }
            $name = SourceScan::callableName($t[0], $t[1]);
            if ($name !== null && ($tokens[$i + 1] ?? null) === '(') {
                if ($member && $t[0] === T_STRING && in_array($name, self::METHODS, true)) {
                    $hits[] = [$t[2], '->' . $name . '()'];
                } elseif (!$member && in_array($name, self::FUNCTIONS, true) && !self::declares($prev)) {
                    $hits[] = [$t[2], $name . '()'];
                }
                continue;
            }
            $string = null;
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING && $prev !== '.') {
                $string = self::literalChain($tokens, $i);
            } elseif (
                $t[0] === T_START_HEREDOC
                && self::is($tokens[$i + 1] ?? null, T_ENCAPSED_AND_WHITESPACE)
                && self::is($tokens[$i + 2] ?? null, T_END_HEREDOC)
            ) {
                $string = [$tokens[$i + 1][1], $i + 3];
            }
            $isKey = $string !== null && self::is($tokens[$string[1]] ?? null, T_DOUBLE_ARROW);
            if ($string !== null && self::isAName($string[0]) && !$isKey) {
                $hits[] = [$t[2], "'" . strtolower(trim($string[0])) . "'"];
            }
        }
        foreach (AssembledStrings::of($source) as $string) {
            // A bare name is read above, where an array key can be told apart.
            if (self::isAName($string['text'])) {
                continue;
            }
            foreach (self::statements($string['text']) as $what) {
                $hits[] = [$string['line'], 'SQL ' . $what];
            }
        }
        usort($hits, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $hits;
    }

    /**
     * The transaction statements in one assembled string.
     *
     * @return list<string>
     */
    private static function statements(string $text): array
    {
        $text = (string) preg_replace(['~/\*.*?\*/~s', '~(?:--\s|#)[^\n]*~'], ' ', $text);
        $unread = preg_quote(AssembledStrings::UNREAD, '/');
        $rollback = '/^ROLLBACK(?: WORK)?(?:(?: AND(?: NO)? CHAIN)?(?: (?:NO )?RELEASE)?| TO (?:SAVEPOINT )?\S+)$/i';
        $found = [];
        foreach (explode(';', $text) as $statement) {
            $s = trim((string) preg_replace('/\s+/', ' ', $statement));
            $what = match (true) {
                preg_match('/^START TRANSACTION\b/i', $s) === 1 => 'START TRANSACTION',
                preg_match('/^BEGIN(?: WORK)?$/i', $s) === 1 => 'BEGIN',
                preg_match('/^COMMIT(?: WORK)?(?: AND(?: NO)? CHAIN)?(?: (?:NO )?RELEASE)?$/i', $s) === 1 => 'COMMIT',
                preg_match($rollback, $s) === 1 => 'ROLLBACK',
                preg_match('/^SAVEPOINT \S+$/i', $s) === 1 => 'SAVEPOINT',
                preg_match('/^RELEASE SAVEPOINT \S+$/i', $s) === 1 => 'RELEASE SAVEPOINT',
                preg_match('/^SET\b.*\bautocommit\s*:?=/i', $s) === 1 => 'SET autocommit',
                preg_match('/^SET (?:(?:SESSION|LOCAL|GLOBAL) |@@)?' . $unread . '/i', $s) === 1
                    => 'SET built-variable',
                preg_match('/^XA (?:START|BEGIN)\b/i', $s) === 1 => 'XA',
                preg_match('/^LOCK TABLES?\b/i', $s) === 1 => 'LOCK TABLES',
                preg_match('/^UNLOCK TABLES?$/i', $s) === 1 => 'UNLOCK TABLES',
                preg_match('/^(?:' . $unread . '|\s)\s*(?:TRANSACTION|WORK)\b/i', $statement) === 1
                    => 'built-verb TRANSACTION',
                default => null,
            };
            if ($what !== null) {
                $found[] = $what;
            }
        }

        return $found;
    }

    /**
     * Whether the first token of a `->{...}` name is a literal that no
     * boundary method's name starts with (`$this->{'set' . $field}()`).
     */
    private static function namesNoBoundary(mixed $first): bool
    {
        if (!self::is($first, T_CONSTANT_ENCAPSED_STRING)) {
            return false;
        }
        $prefix = strtolower(stripcslashes(substr($first[1], 1, -1)));
        foreach (self::METHODS as $method) {
            if (str_starts_with($method, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /** Whether a string is the name of a boundary method or function. */
    private static function isAName(string $text): bool
    {
        $name = strtolower(trim($text));

        return in_array($name, self::METHODS, true) || in_array($name, self::FUNCTIONS, true);
    }

    private static function is(mixed $token, int $id): bool
    {
        return is_array($token) && $token[0] === $id;
    }

    /** Whether the token before a name makes it a declaration, not a call. */
    private static function declares(mixed $prev): bool
    {
        return is_array($prev) && in_array($prev[0], [T_FUNCTION, T_NEW, T_CONST], true);
    }

    /**
     * A literal and the literals `.` joins to it: their text and the index
     * after the last.
     *
     * @param list<mixed> $tokens
     * @return array{string, int}
     */
    private static function literalChain(array $tokens, int $i): array
    {
        $text = '';
        while (is_array($tokens[$i] ?? null) && $tokens[$i][0] === T_CONSTANT_ENCAPSED_STRING) {
            $text .= stripcslashes(substr($tokens[$i][1], 1, -1));
            if (($tokens[$i + 1] ?? null) !== '.' || !self::is($tokens[$i + 2] ?? null, T_CONSTANT_ENCAPSED_STRING)) {
                return [$text, $i + 1];
            }
            $i += 2;
        }

        return [$text, $i];
    }

    /** @param list<mixed> $tokens */
    private static function closing(array $tokens, int $open): int
    {
        $depth = 0;
        for ($j = $open, $n = count($tokens); $j < $n; $j++) {
            $u = $tokens[$j];
            if ($u === '{' || (is_array($u) && in_array($u[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($u === '}') {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return $n;
    }

    /** @param list<mixed> $tokens */
    private static function line(array $tokens, int $i): int
    {
        for (; $i >= 0; $i--) {
            if (is_array($tokens[$i])) {
                return $tokens[$i][2];
            }
        }

        return 0;
    }
}
