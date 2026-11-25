<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaReconciler;
use Prosper202\Database\Tables\UserTables;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * Every statement in the served tree that inserts a 202_users row names
 * each NOT NULL column of the table that has no DEFAULT. The API's
 * connection runs under the server's strict sql_mode, which refuses a row
 * that leaves one out; MysqlUserRepository::create() left out
 * user_dash_email, install_hash and user_hash, so its first caller there
 * would have been refused, while under the pages' empty sql_mode the same
 * row is stored with ''.
 *
 * Read with AssembledStrings. A statement whose column list is built at
 * runtime is listed in BUILT_AT_RUNTIME with what holds it; any other is
 * refused. What is not seen: a statement whose table is a runtime value,
 * and a column named only inside a quoted value. The paths that exist are
 * run under the strict sql_mode in NewAccountPrivacyIntegrationTest
 * (AccountCreationPaths).
 */
final class NewUserInsertsNameEveryRequiredColumnTest extends TestCase
{
    /** The keyword and the users table after it, backticked or not, up to what follows. */
    private const INSERT = '/\b(?:INSERT|REPLACE)\b(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*'
        . '(?:\s+INTO)?\s*(?:`?\w+`?\.)?`?202_users`?(?![\w\x00])(.*)\z/is';

    /**
     * Files whose insert builds its column list at runtime, with what holds
     * the columns it is given. The list only shrinks.
     */
    private const BUILT_AT_RUNTIME = [
        '202-config/functions-account-ui.php' => 'p202_account_save_user() writes the columns its caller'
            . ' names: Settings › Users (202-account/user-management.php) names them for a new user, and'
            . ' AccountCreationPaths makes one that way under the strict sql_mode',
    ];

    public function testTheRequiredColumnsAreTheOnesThisTestWasWrittenFor(): void
    {
        self::assertSame(
            ['user_name', 'user_pass', 'user_email', 'user_dash_email', 'user_time_register', 'install_hash', 'user_hash'],
            self::required(),
            'a column was made required or given a default: check every insert of a user names it'
        );
    }

    public function testEveryNewUserRowNamesEveryRequiredColumn(): void
    {
        $required = self::required();
        $found = 0;
        $problems = [];
        $unreadIn = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (self::refusals($source, $required, $found, $unread) as $problem) {
                $problems[] = "$path:$problem";
            }
            if ($unread !== []) {
                if (isset(self::BUILT_AT_RUNTIME[$path])) {
                    $unreadIn[] = $path;
                } else {
                    foreach ($unread as $problem) {
                        $problems[] = "$path:$problem (its columns are built at runtime)";
                    }
                }
            }
        }

        self::assertSame([], $problems, "A statement inserts a 202_users row without naming every NOT NULL column"
            . " that has no DEFAULT, which a strict sql_mode refuses:\n  " . implode("\n  ", $problems));
        self::assertSame(
            array_keys(self::BUILT_AT_RUNTIME),
            $unreadIn,
            'each file listed in BUILT_AT_RUNTIME still builds its insert at runtime: take out one that does not'
        );
        // POST /users, the installer and MysqlUserRepository, read; Settings › Users, listed.
        self::assertGreaterThanOrEqual(3, $found, 'the scan read the statements that make the rows');
    }

    /**
     * BUILT_AT_RUNTIME's one caller: the columns Settings › Users hands
     * p202_account_save_user(), its $userSet array and the keys it adds,
     * name every required one. Read by key, so a key set only on an edit
     * reads as set for a new user too; AccountCreationPaths runs the save.
     */
    public function testSettingsUsersNamesEveryRequiredColumn(): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-account/user-management.php');
        self::assertSame(1, preg_match('/\$userSet\s*=\s*\[(.*?)\];/s', $source, $literal), 'the page builds $userSet');
        preg_match_all("/'(\w+)'\s*=>/", $literal[1], $listed);
        preg_match_all('/\$userSet\[\'(\w+)\'\]\s*=(?!=)/', $source, $added);
        self::assertSame([], array_values(array_diff(self::required(), [...$listed[1], ...$added[1]])),
            'Settings › Users leaves a required column out of the user it adds');
    }

    /**
     * @return array<string, array{0: string, 1: bool|null}> statement => refused (null: built at runtime)
     */
    public static function plants(): array
    {
        $all = 'user_name, user_pass, user_email, user_dash_email, user_time_register, install_hash, user_hash';

        return [
            'every column, in a list' => ["\$q = 'INSERT INTO 202_users ($all) VALUES (?, ?, ?, ?, ?, ?, ?)';", false],
            'every column, by SET' => [
                '$q = "INSERT IGNORE INTO `202_users` SET user_name = ?, user_pass = ?, user_email = ?,'
                    . ' user_dash_email = ?, user_time_register = $now, install_hash = \'\', user_hash = \'\'";',
                false,
            ],
            'the repository\'s old list' => [
                '$q = \'INSERT INTO 202_users (user_fname, user_lname, user_name, user_pass, user_email,'
                    . ' user_timezone, user_time_register, user_active, user_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)\';',
                true,
            ],
            'one column left out of SET' => [
                '$q = "INSERT INTO 202_users SET user_name = ?, user_pass = ?, user_email = ?,'
                    . ' user_time_register = ?, install_hash = ?, user_hash = ?";',
                true,
            ],
            'a column named only inside a value' => [
                '$q = "INSERT INTO 202_users SET user_name = \'a, user_dash_email = b\', user_pass = ?,'
                    . ' user_email = ?, user_time_register = ?, install_hash = ?, user_hash = ?";',
                true,
            ],
            'no column list' => ['$q = "INSERT INTO 202_users VALUES (?, ?)";', true],
            'a copy of a row' => ['$q = "INSERT INTO 202_users SELECT * FROM 202_users WHERE user_id = 1";', true],
            'the table by its constant' => [
                'use Prosper202\Database\Schema\TableRegistry; $q = "INSERT INTO " . TableRegistry::USERS'
                    . ' . " (user_name, user_pass) VALUES (?, ?)";',
                true,
            ],
            'lower case' => ['$q = "insert into 202_users (user_name, user_pass) values (?, ?)";', true],
            'another table' => ['$q = "INSERT INTO 202_users_pref (user_id) VALUES (?)";', false],
            'a list built at runtime' => ['$q = "INSERT INTO 202_users (" . $cols . ") VALUES (?)";', null],
            'a SET built at runtime' => ['$q = \'INSERT INTO `202_users` SET \' . implode(\', \', $a);', null],
        ];
    }

    /** @dataProvider plants */
    public function testEachShapeIsReadAsItIs(string $statement, ?bool $refused): void
    {
        $found = 0;
        $refusals = self::refusals("<?php\n$statement\n", self::required(), $found, $unread);
        self::assertSame($refused === null, $unread !== [], "$statement: read as built at runtime");
        self::assertSame($refused === true, $refusals !== [], $statement);
        self::assertSame(str_contains($statement, '202_users_pref') ? 0 : 1, $found, "$statement: inserts of a user read");
    }

    /** @return list<string> */
    private static function required(): array
    {
        $out = [];
        foreach (SchemaReconciler::requiredColumns(UserTables::users()) as $name => $definition) {
            if (preg_match('/\bNOT\s+NULL\b/i', $definition) === 1
                && preg_match('/\b(?:DEFAULT|AUTO_INCREMENT)\b/i', $definition) !== 1) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * The inserts of a 202_users row in $source that leave out a required
     * column, as "line: statement"; $unread gets those whose columns cannot
     * be read. $found counts the inserts read.
     *
     * @param list<string> $required
     * @param list<string>|null $unread
     * @return list<string>
     */
    private static function refusals(string $source, array $required, int &$found, ?array &$unread): array
    {
        $out = [];
        $unread = [];
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            if (preg_match(self::INSERT, $text, $m) !== 1) {
                continue;
            }
            $found++;
            $shown = str_replace(AssembledStrings::UNREAD, '…', (string) preg_replace('/\s+/', ' ', trim($text)));
            // A quoted value names no column.
            $rest = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", "''", $m[1]);
            $named = self::columnsNamed($rest);
            if ($named === null) {
                $unread[] = "$line: $shown";
                continue;
            }
            $missing = array_values(array_diff($required, $named));
            if ($missing !== []) {
                $out[] = "$line: $shown (leaves out " . implode(', ', $missing) . ')';
            }
        }

        return $out;
    }

    /**
     * The columns what follows the table names; null when they are built at
     * runtime, [] when it names none (VALUES or a SELECT with no list).
     *
     * @return list<string>|null
     */
    private static function columnsNamed(string $rest): ?array
    {
        $u = preg_quote(AssembledStrings::UNREAD, '/');
        if (preg_match('/\A\s*\(([^)]*)\)/s', $rest, $list) === 1) {
            if (str_contains($list[1], AssembledStrings::UNREAD)) {
                return null;
            }

            return array_map(static fn (string $c): string => trim($c, " \t\r\n`"), explode(',', $list[1]));
        }
        if (preg_match('/\A\s*\(\s*' . $u . '/s', $rest) === 1) {
            return null;
        }
        if (preg_match('/\A\s*SET\b(.*?)(?:\bON\s+DUPLICATE\b|\z)/is', $rest, $set) === 1) {
            if (preg_match('/(?:\A|,)\s*' . $u . '/s', $set[1]) === 1) {
                return null;
            }
            preg_match_all('/(?:\A|,)\s*`?(\w+)`?\s*=/s', $set[1], $columns);

            return $columns[1];
        }

        return [];
    }
}
