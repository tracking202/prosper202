<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\User\RememberMeKeys;
use Prosper202\User\UserDataPurge;
use Tests\Support\SourceScan;

/**
 * Every writer that leaves a user unable to act deletes their remember-me
 * keys, and every sign-out deletes the key of the browser signing out.
 *
 * A remember-me key (202_auth_keys) outlived what ended its sign-in: the
 * sign-out cleared the cookie and kept the row, so a copy of the cookie went
 * on signing in for up to 14 days; switching a user off kept every row, so a
 * remembered browser that made no request while the user was off signed back
 * in once they were on again. Held here:
 *
 *  - The writers. Every statement in the served tree that writes 202_users'
 *    user_active or user_deleted, or whose SET list is built at run time and
 *    so can write either (and any REPLACE INTO or ON DUPLICATE KEY UPDATE of
 *    202_users), is found by reading the SQL as PHP assembles it, and the
 *    named function that holds it calls RememberMeKeys::revokeAll() — or is
 *    listed in REVOKED_OTHERWISE with how. The set is pinned in WRITERS, so a
 *    new writer is seen. An INSERT makes a user, who has no key yet.
 *  - The sign-outs. Only AUTH destroys a session or sets the remember_me
 *    cookie, and the pages that sign a browser out call AUTH::signOut().
 *
 * RememberMeKeysRevokedIntegrationTest runs each writer and the sign-out
 * against a database, the revocation in the writer's transaction included.
 *
 * What this does not see: a writer that holds users somewhere else than
 * 202_users (InMemoryUserRepository, a test double, holds no keys); SQL a
 * builder assembles from pieces that are not PHP strings at the statement
 * (a table name from a constant: none writes 202_users that way); and
 * whether the revocation runs on every path through the function, which the
 * integration test executes.
 */
final class RememberMeKeysRevokedTest extends TestCase
{
    /** Every writer that can leave a user unable to act, path::function. */
    private const WRITERS = [
        '202-config/User/MysqlUserRepository.php::update',
        '202-config/User/UserDataPurge.php::deleteUser',
        '202-config/functions-account-ui.php::p202_account_save_user',
        'api/v3/Controllers/UsersController.php::update',
    ];

    /** Writers that revoke the keys other than by calling revokeAll(), each with how. */
    private const REVOKED_OTHERWISE = [
        '202-config/User/UserDataPurge.php::deleteUser' => 'the delete cascade runs RememberMeKeys::REVOKE_ALL'
            . ' among its ACCESS_STATEMENTS, in the transaction that marks the user deleted'
            . ' (testTheDeleteCascadeRevokesTheKeys)',
    ];

    /** The pages that sign a browser out. */
    private const SIGN_OUTS = ['202-account/signout.php', '202-account/account.php'];

    public function testEveryWriterThatLeavesAUserUnableToActRevokesTheirKeys(): void
    {
        $files = SourceScan::phpFiles();
        self::assertGreaterThan(500, count($files), 'the walk read the served tree');
        $found = [];
        $unrevoked = [];
        foreach ($files as $path => $source) {
            foreach (self::writers($source) as [$function, $revokes, $line]) {
                $key = "$path::$function";
                $found[$key] = true;
                if (!$revokes && !array_key_exists($key, self::REVOKED_OTHERWISE)) {
                    $unrevoked[] = "$key (line $line)";
                }
            }
        }
        $found = array_keys($found);
        sort($found);
        self::assertSame([], $unrevoked, "These write user_active or user_deleted, or a SET list built at run time,"
            . " and do not delete the user's remember-me keys:\n  " . implode("\n  ", $unrevoked)
            . "\nCall Prosper202\\User\\RememberMeKeys::revokeAll() in the same transaction when the write leaves the"
            . ' user unable to act (UserStanding::isActive()).');
        self::assertSame(self::WRITERS, $found, 'The writers of user_active and user_deleted changed: a new one revokes'
            . ' the keys and goes in WRITERS, and one that no longer writes them leaves it.');
    }

    public function testTheDeleteCascadeRevokesTheKeys(): void
    {
        self::assertContains(RememberMeKeys::REVOKE_ALL, UserDataPurge::statements());
        self::assertSame('DELETE FROM 202_auth_keys WHERE user_id = ?', RememberMeKeys::REVOKE_ALL);
    }

    public function testEverySignOutGoesThroughAuth(): void
    {
        $files = SourceScan::phpFiles();
        $destroyers = [];
        $cookieSetters = [];
        foreach ($files as $path => $source) {
            if (self::calls($source, 'session_destroy') !== []) {
                $destroyers[] = $path;
            }
            if (self::setsTheRememberMeCookie($source)) {
                $cookieSetters[] = $path;
            }
        }
        self::assertSame(
            ['202-config/functions-auth.php'],
            $destroyers,
            "a session is destroyed only by AUTH::signOut(), which deletes the browser's remember-me key with it"
        );
        self::assertSame(
            ['202-config/functions-auth.php'],
            $cookieSetters,
            'the remember_me cookie is set and cleared only by AUTH'
        );
        foreach (self::SIGN_OUTS as $path) {
            self::assertTrue(
                self::callsAuthSignOut($files[$path] ?? ''),
                "$path signs a browser out through AUTH::signOut()"
            );
        }
    }

    /**
     * What the writer scan finds, executed on planted sources: [found, revokes].
     *
     * @dataProvider writerShapes
     * @param list<array{bool}> $want one entry per writer the source holds: whether it revokes
     */
    public function testTheWriterScanReadsTheSqlAsPhpAssemblesIt(string $code, array $want): void
    {
        $got = array_map(static fn (array $w): bool => $w[1], self::writers("<?php\n" . $code . "\n"));
        self::assertSame($want, $got, $code);
    }

    /** @return array<string, array{string, list<bool>}> */
    public static function writerShapes(): array
    {
        $revoke = ' \\Prosper202\\User\\RememberMeKeys::revokeAll($conn, $id);';
        $wrap = static fn (string $body): string => 'function f($conn, $id, $sets) { ' . $body . ' }';
        $switchOff = "\$sql = 'UPDATE 202_users SET user_active = 0 WHERE user_id = ?';";

        return [
            'another column' => [$wrap("\$sql = 'UPDATE 202_users SET user_email = ? WHERE user_id = ?';"), []],
            'another column, a value concatenated' => [
                $wrap("\$sql = \"UPDATE `202_users` SET user_dash_email='\" . \$email . \"' WHERE user_id = 1\";"),
                [],
            ],
            'another table' => [$wrap("\$sql = 'UPDATE 202_users_pref SET user_active = 0';"), []],
            'an insert' => [$wrap("\$sql = 'INSERT INTO 202_users (user_name, user_active) VALUES (?, 0)';"), []],
            'user_active, not revoking' => [$wrap($switchOff), [false]],
            'user_active, revoking' => [$wrap($switchOff . $revoke), [true]],
            'user_deleted' => [
                $wrap("\$sql = 'UPDATE `202_users` SET `user_deleted` = 1 WHERE user_id = ?';"),
                [false],
            ],
            'lower case' => [$wrap("\$sql = 'update 202_users set user_active=0 where user_id=1';"), [false]],
            'across literals' => [
                $wrap("\$sql = 'UPDATE 202_users SET user_email = ?,' . ' user_active = ? WHERE user_id = ?';"),
                [false],
            ],
            'a SET list built at run time' => [
                $wrap("\$sql = 'UPDATE 202_users SET ' . implode(', ', \$sets) . ' WHERE user_id = ?';"),
                [false],
            ],
            'a SET list built at run time, revoking' => [
                $wrap("\$sql = 'UPDATE 202_users SET ' . implode(', ', \$sets) . ' WHERE user_id = ?';" . $revoke),
                [true],
            ],
            'a column after a fixed one, built at run time' => [
                $wrap("\$sql = 'UPDATE 202_users SET user_email = ?, ' . \$more . ' WHERE user_id = ?';"),
                [false],
            ],
            'interpolated column' => [
                $wrap("\$sql = \"UPDATE 202_users SET {\$column} = 0 WHERE user_id = 1\";"),
                [false],
            ],
            'a heredoc' => [
                $wrap("\$sql = <<<SQL\nUPDATE 202_users SET user_active = 0 WHERE user_id = \$id\nSQL;"),
                [false],
            ],
            'replace into' => [$wrap("\$sql = 'REPLACE INTO 202_users SET user_id = 1';"), [false]],
            'on duplicate key update' => [
                $wrap("\$sql = 'INSERT INTO 202_users (user_id) VALUES (1) ON DUPLICATE KEY UPDATE user_active = 0';"),
                [false],
            ],
            'revoking in a closure inside the function' => [
                $wrap("\$conn->transaction(function () use (\$conn, \$id) { " . $switchOff . $revoke . ' });'),
                [true],
            ],
            'the revocation in another function' => [
                $wrap($switchOff) . ' function g($conn, $id) {' . $revoke . ' }',
                [false],
            ],
            'another class of the name' => [
                $wrap($switchOff . ' MyRememberMeKeys::revokeAll($conn, $id);'),
                [false],
            ],
            'a qualified name elsewhere' => [
                $wrap($switchOff . ' Other\\RememberMeKeys::revokeAll($conn, $id);'),
                [false],
            ],
            'the short name, imported' => [
                "use Prosper202\\User\\RememberMeKeys;\n"
                    . $wrap($switchOff . ' RememberMeKeys::revokeAll($conn, $id);'),
                [true],
            ],
            'the short name, not imported, in another namespace' => [
                "namespace Api;\n" . $wrap($switchOff . ' RememberMeKeys::revokeAll($conn, $id);'),
                [false],
            ],
            'the short name, in its own namespace' => [
                "namespace Prosper202\\User;\n" . $wrap($switchOff . ' RememberMeKeys::revokeAll($conn, $id);'),
                [true],
            ],
            'another method of the name' => [
                $wrap($switchOff . ' $keys->revokeAll($conn, $id);'),
                [false],
            ],
            'the call in a comment' => [
                $wrap($switchOff . ' /* RememberMeKeys::revokeAll($conn, $id); */'),
                [false],
            ],
        ];
    }

    /**
     * What the sign-out scans find, executed.
     *
     * @dataProvider signOutShapes
     */
    public function testTheSignOutScansReadCallsNotNames(
        string $code,
        bool $destroys,
        bool $setsCookie,
        bool $signsOut
    ): void {
        $source = "<?php\n" . $code . "\n";
        self::assertSame(
            [$destroys, $setsCookie, $signsOut],
            [
                self::calls($source, 'session_destroy') !== [],
                self::setsTheRememberMeCookie($source),
                self::callsAuthSignOut($source),
            ],
            $code
        );
    }

    /** @return array<string, array{string, bool, bool, bool}> */
    public static function signOutShapes(): array
    {
        return [
            'nothing' => ['echo 1;', false, false, false],
            'session_destroy()' => ['session_destroy();', true, false, false],
            'fully qualified' => ['\\session_destroy();', true, false, false],
            'in another case' => ['Session_Destroy();', true, false, false],
            'a method of the name' => ['$s->session_destroy();', false, false, false],
            'the cookie cleared' => ["setcookie('remember_me', '', ['expires' => 1]);", false, true, false],
            'the cookie in double quotes' => ['setcookie("remember_me", "");', false, true, false],
            'another cookie' => ["setcookie('p202vid', 'x');", false, false, false],
            'setrawcookie' => ["setrawcookie('remember_me', '');", false, true, false],
            'AUTH::signOut()' => ['AUTH::signOut();', false, false, true],
            'another class' => ['MyAUTH::signOut();', false, false, false],
            'the name only' => ["\$f = 'AUTH::signOut';", false, false, false],
        ];
    }

    /**
     * The writers a source holds: [function, whether that function revokes, line].
     *
     * @return list<array{string, bool, int}>
     */
    private static function writers(string $source): array
    {
        $t = self::tokens($source);
        $functions = self::namedFunctions($t);
        $writers = [];
        $seen = [];
        foreach ($t as $i => $x) {
            if (!is_array($x) || !in_array($x[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if (preg_match('/\b(?:UPDATE|REPLACE|INSERT)\b/i', $x[1]) !== 1 || stripos($x[1], '202_users') === false) {
                continue;
            }
            $start = self::stringStart($t, $i);
            if (isset($seen[$start])) {
                continue;
            }
            $seen[$start] = true;
            if (!self::writesStanding(self::assemble($t, $start))) {
                continue;
            }
            $scope = null;
            foreach ($functions as [$name, $open, $close]) {
                if ($i > $open && $i < $close && ($scope === null || $open > $scope[1])) {
                    $scope = [$name, $open, $close];
                }
            }
            [$name, $open, $close] = $scope ?? ['(file)', 0, count($t)];
            $writers[] = [$name, self::revokesBetween($t, $open, $close, $source), $x[2]];
        }

        return $writers;
    }

    /**
     * Whether assembled SQL (run-time pieces as \0) writes user_active or
     * user_deleted of 202_users, or a SET list it cannot read.
     */
    private static function writesStanding(string $sql): bool
    {
        if (preg_match('/\bREPLACE\s+INTO\s+`?202_users`?(?![\w])/i', $sql) === 1) {
            return true;
        }
        $clauses = [];
        $update = '/\bUPDATE\s+`?202_users`?(?![\w])(?:\s+(?:AS\s+)?`?\w+`?)?\s+SET\b(.*?)(?:\bWHERE\b|$)/is';
        if (preg_match_all($update, $sql, $m) > 0) {
            $clauses = $m[1];
        }
        if (
            preg_match('/\bINSERT\s+INTO\s+`?202_users`?(?![\w])/i', $sql) === 1
            && preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b(.*)$/is', $sql, $d) === 1
        ) {
            $clauses[] = $d[1];
        }
        foreach ($clauses as $clause) {
            // A run-time piece where a column goes: first in the list, or after a comma.
            if (preg_match('/(?:^|,)[\s`]*\x00/', $clause) === 1) {
                return true;
            }
            if (preg_match('/(?:^|,)\s*`?(?:\w+`?\.`?)?(?:user_active|user_deleted)`?\s*=/i', $clause) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The SQL an expression starting at $start assembles: literals joined
     * across `.`, interpolated strings and heredocs with their variables, and
     * every other operand as \0.
     *
     * @param list<string|array{int, string, int}> $t
     */
    private static function assemble(array $t, int $start): string
    {
        $sql = '';
        $i = $start;
        while ($i < count($t)) {
            $x = $t[$i];
            if (is_array($x) && $x[0] === T_CONSTANT_ENCAPSED_STRING) {
                $sql .= substr($x[1], 1, -1);
                $i++;
            } elseif ($x === '"' || (is_array($x) && $x[0] === T_START_HEREDOC)) {
                $heredoc = $x !== '"';
                $i++;
                $piece = false;
                while ($i < count($t) && !self::closesString($t[$i], $heredoc)) {
                    if (is_array($t[$i]) && $t[$i][0] === T_ENCAPSED_AND_WHITESPACE) {
                        $sql .= $t[$i][1];
                        $piece = false;
                    } elseif (!$piece) {
                        $sql .= "\0";
                        $piece = true;
                    }
                    $i++;
                }
                $i++;
            } else {
                $sql .= "\0";
                $depth = 0;
                while ($i < count($t)) {
                    $s = self::text($t[$i]);
                    if (in_array($s, ['(', '[', '{'], true)) {
                        $depth++;
                    } elseif (in_array($s, [')', ']', '}'], true)) {
                        if ($depth === 0) {
                            break;
                        }
                        $depth--;
                    } elseif ($depth === 0 && in_array($s, ['.', ',', ';'], true)) {
                        break;
                    }
                    $i++;
                }
            }
            if (self::text($t[$i] ?? '') !== '.') {
                break;
            }
            $i++;
        }

        return $sql;
    }

    /** @param string|array{int, string, int} $x */
    private static function closesString(string|array $x, bool $heredoc): bool
    {
        return $heredoc ? is_array($x) && $x[0] === T_END_HEREDOC : $x === '"';
    }

    /**
     * The token that opens the string holding the token at $i: its `"` or
     * heredoc start for a piece of an interpolated string, itself otherwise.
     *
     * @param list<string|array{int, string, int}> $t
     */
    private static function stringStart(array $t, int $i): int
    {
        if (!is_array($t[$i]) || $t[$i][0] !== T_ENCAPSED_AND_WHITESPACE) {
            return $i;
        }
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($t[$j] === '"' || (is_array($t[$j]) && $t[$j][0] === T_START_HEREDOC)) {
                return $j;
            }
        }

        return $i;
    }

    /**
     * Whether a call to Prosper202\User\RememberMeKeys::revokeAll() sits
     * between two tokens, the class name read as PHP resolves it.
     *
     * @param list<string|array{int, string, int}> $t
     */
    private static function revokesBetween(array $t, int $open, int $close, string $source): bool
    {
        [$namespace, $imports] = self::names($source);
        for ($i = $open; $i < $close; $i++) {
            $x = $t[$i];
            if (
                !is_array($x) || self::text($t[$i + 1] ?? '') !== '::'
                || strcasecmp(self::text($t[$i + 2] ?? ''), 'revokeAll') !== 0 || self::text($t[$i + 3] ?? '') !== '('
            ) {
                continue;
            }
            $class = match ($x[0]) {
                T_NAME_FULLY_QUALIFIED => ltrim($x[1], '\\'),
                T_STRING => $imports[strtolower($x[1])] ?? ($namespace === '' ? $x[1] : $namespace . '\\' . $x[1]),
                default => null,
            };
            if ($class !== null && strcasecmp($class, 'Prosper202\\User\\RememberMeKeys') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * A file's namespace and its class imports, alias (lowercase) => name.
     *
     * @return array{string, array<string, string>}
     */
    private static function names(string $source): array
    {
        $t = self::tokens($source);
        $namespace = '';
        $imports = [];
        foreach ($t as $i => $x) {
            if (is_array($x) && $x[0] === T_NAMESPACE && is_array($t[$i + 1] ?? null)) {
                $namespace = ltrim($t[$i + 1][1], '\\');
            }
            $next = $t[$i + 1] ?? null;
            if (
                is_array($x) && $x[0] === T_USE && is_array($next)
                && in_array($next[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)
            ) {
                $name = ltrim($next[1], '\\');
                $alias = strcasecmp(self::text($t[$i + 2] ?? ''), 'as') === 0
                    ? self::text($t[$i + 3] ?? '')
                    : substr((string) strrchr('\\' . $name, '\\'), 1);
                $imports[strtolower($alias)] = $name;
            }
        }

        return [$namespace, $imports];
    }

    /**
     * Each named function's or method's name and body.
     *
     * @param list<string|array{int, string, int}> $t
     * @return list<array{string, int, int}>
     */
    private static function namedFunctions(array $t): array
    {
        $out = [];
        foreach ($t as $i => $x) {
            if (!is_array($x) || $x[0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            if (self::text($t[$j] ?? '') === '&') {
                $j++;
            }
            if (!is_array($t[$j] ?? null) || $t[$j][0] !== T_STRING) {
                continue;
            }
            $open = $j;
            while ($open < count($t) && self::text($t[$open]) !== '{' && self::text($t[$open]) !== ';') {
                $open++;
            }
            if (self::text($t[$open] ?? '') !== '{') {
                continue;
            }
            $out[] = [$t[$j][1], $open, self::closing($t, $open)];
        }

        return $out;
    }

    /**
     * Lines of calls to the global function $name (any case, `\name` too).
     *
     * @return list<int>
     */
    private static function calls(string $source, string $name): array
    {
        $t = self::tokens($source);
        $lines = [];
        foreach ($t as $i => $x) {
            if (
                is_array($x) && SourceScan::callableName($x[0], $x[1]) === $name && self::text($t[$i + 1] ?? '') === '('
                && !in_array(self::text($t[$i - 1] ?? ''), ['->', '?->', '::', 'function', 'new'], true)
            ) {
                $lines[] = $x[2];
            }
        }

        return $lines;
    }

    private static function setsTheRememberMeCookie(string $source): bool
    {
        $t = self::tokens($source);
        foreach ($t as $i => $x) {
            if (
                is_array($x) && in_array(SourceScan::callableName($x[0], $x[1]), ['setcookie', 'setrawcookie'], true)
                && self::text($t[$i + 1] ?? '') === '('
                && !in_array(self::text($t[$i - 1] ?? ''), ['->', '?->', '::', 'function', 'new'], true)
                && is_array($t[$i + 2] ?? null) && $t[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING
                && substr($t[$i + 2][1], 1, -1) === 'remember_me'
            ) {
                return true;
            }
        }

        return false;
    }

    private static function callsAuthSignOut(string $source): bool
    {
        $t = self::tokens($source);
        foreach ($t as $i => $x) {
            if (
                is_array($x) && in_array($x[1], ['AUTH', '\\AUTH'], true) && self::text($t[$i + 1] ?? '') === '::'
                && strcasecmp(self::text($t[$i + 2] ?? ''), 'signOut') === 0 && self::text($t[$i + 3] ?? '') === '('
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string|array{int, string, int}> $t
     * @return int the index of the brace that closes the one at $open
     */
    private static function closing(array $t, int $open): int
    {
        $level = 0;
        for ($i = $open; $i < count($t); $i++) {
            $s = self::text($t[$i]);
            $opensInString = is_array($t[$i]) && in_array($t[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
            if ($s === '{' || $opensInString) {
                $level++;
            } elseif ($s === '}') {
                $level--;
                if ($level === 0) {
                    return $i;
                }
            }
        }

        return count($t);
    }

    /** @param string|array{int, string, int} $x */
    private static function text(string|array $x): string
    {
        return is_array($x) ? $x[1] : $x;
    }

    /** @return list<string|array{int, string, int}> */
    private static function tokens(string $source): array
    {
        return array_values(array_filter(
            token_get_all($source),
            static fn ($x): bool => !is_array($x) || !in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }
}
