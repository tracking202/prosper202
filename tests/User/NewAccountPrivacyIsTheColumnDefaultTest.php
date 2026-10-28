<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaReconciler;
use Prosper202\Database\Tables\UserTables;
use Prosper202\Http\PrivacySetting;
use Prosper202\User\PreferenceRules;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * A new account's privacy setting is 202_users_pref.user_pref_privacy's
 * DEFAULT, 'eu' since 1.9.79: every statement in the served tree that makes
 * a preferences row names user_id alone, and none writes the setting by
 * name. A row made with a value, copied from another account's (no column
 * list, or more columns than user_id) or given one after its insert would
 * start the account somewhere else whatever the column says.
 *
 * Read with AssembledStrings, so a column list built at runtime reads as
 * unread and is refused. What is not seen: a statement whose table is a
 * runtime value (the CRUD bases, LookupTablesWrittenOnlyByRepositoriesTest's
 * UNREAD_WRITERS, none of them this table), and the settings surfaces,
 * Personal settings and PUT /users/{id}/preferences, which build their SET
 * from the fields sent. The paths that exist are run against a database in
 * NewAccountPrivacyIntegrationTest.
 */
final class NewAccountPrivacyIsTheColumnDefaultTest extends TestCase
{
    /** The keyword and the preferences table after it, backticked or not, up to what follows. */
    private const INSERT = '/\b(?:INSERT|REPLACE)\b(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*'
        . '(?:\s+INTO)?\s*(?:`?\w+`?\.)?`?202_users_pref`?(?![\w\x00])(.*)\z/is';

    /** What may follow the table: user_id alone, set to one value or listed for VALUES or a SELECT. */
    private const NAMES_USER_ID_ALONE = '/\A\s*(?:SET\s+`?user_id`?\s*=\s*(?:\?|\x00|\d+)'
        . '|\(\s*`?user_id`?\s*\)\s*(?:VALUES?\s*\(\s*(?:\?|\x00|\d+)\s*\)|SELECT\b.*))\s*;?\s*\z/is';

    public function testANewAccountStartsAtEuASettingTheSettingsPageOffers(): void
    {
        $column = SchemaReconciler::requiredColumns(UserTables::usersPref())['user_pref_privacy'] ?? '';
        self::assertSame("`user_pref_privacy` varchar(100) NOT NULL DEFAULT 'eu'", $column);
        self::assertContains('eu', PreferenceRules::CHOICES['user_pref_privacy']);
        self::assertContains('eu', PrivacySetting::SETTINGS);
    }

    public function testEveryPreferencesRowIsMadeNamingUserIdAlone(): void
    {
        $found = 0;
        $problems = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (self::refusals($source, $found) as $problem) {
                $problems[] = "$path:$problem";
            }
        }

        self::assertSame([], $problems, "A statement makes a 202_users_pref row with more than user_id, or writes"
            . " the privacy setting by name, so a new account does not start at the column's DEFAULT:\n  "
            . implode("\n  ", $problems));
        // The installer, Settings › Users, POST /users, MysqlUserRepository
        // and the 1.9.41 rung: a scan that found none read nothing.
        self::assertGreaterThanOrEqual(5, $found, 'the scan found the statements that make the rows');
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function plants(): array
    {
        return [
            'SET user_id' => ['$q = "INSERT IGNORE INTO 202_users_pref SET user_id = ?";', false],
            'SET user_id from a variable' => ['$q = "INSERT INTO 202_users_pref SET user_id = $id";', false],
            'a list of user_id' => ['$q = \'INSERT INTO `202_users_pref` (`user_id`) VALUES (?)\';', false],
            'a list of user_id, from a SELECT' => [
                '$q = "INSERT INTO 202_users_pref(user_id) SELECT user_id FROM 202_users";',
                false,
            ],
            'another table' => [
                '$q = "INSERT INTO 202_users_prefs_x (user_id, user_pref_privacy) VALUES (?, ?)";',
                false,
            ],
            'the setting in the list' => [
                '$q = "INSERT INTO 202_users_pref (user_id, user_pref_privacy) VALUES (?, \'disabled\')";',
                true,
            ],
            'the setting in SET' => [
                '$q = "INSERT INTO 202_users_pref SET user_id = ?, user_pref_privacy = \'disabled\'";',
                true,
            ],
            'another column' => ['$q = "INSERT INTO 202_users_pref SET user_id = ?, user_pref_limit = 50";', true],
            'REPLACE with the setting' => [
                '$q = "REPLACE INTO `202_users_pref` (user_id, user_pref_privacy) VALUES (?, ?)";',
                true,
            ],
            'a copy of a row' => [
                '$q = "INSERT INTO 202_users_pref SELECT * FROM 202_users_pref WHERE user_id = 1";',
                true,
            ],
            'no column list' => ['$q = "INSERT INTO 202_users_pref VALUES (?, 50)";', true],
            'a list built at runtime' => ['$q = "INSERT INTO 202_users_pref (" . $cols . ") VALUES (?)";', true],
            'a value built at runtime' => ['$q = "INSERT INTO 202_users_pref SET user_id = ?, " . $more;', true],
            'the table by its constant' => [
                'use Prosper202\Database\Schema\TableRegistry; $q = "INSERT INTO " .'
                    . ' TableRegistry::USERS_PREF . " (user_id, user_pref_privacy) VALUES (?, ?)";',
                true,
            ],
            'joined across a dot' => [
                '$q = "INSERT INTO 202_users_pref (user_id," . " user_pref_privacy) VALUES (?, ?)";',
                true,
            ],
            'a heredoc' => [
                "\$q = <<<SQL\nINSERT INTO 202_users_pref (user_id, user_pref_privacy)\nVALUES (?, ?)\nSQL;",
                true,
            ],
            'ON DUPLICATE KEY UPDATE' => [
                '$q = "INSERT INTO 202_users_pref (user_id) VALUES (?) ON DUPLICATE KEY UPDATE'
                    . ' user_pref_privacy = \'eu\'";',
                true,
            ],
            'lower case' => ['$q = "insert into 202_users_pref (user_id, user_pref_privacy) values (?, ?)";', true],
            'an UPDATE of the setting' => [
                '$q = "UPDATE 202_users_pref SET user_pref_privacy = \'disabled\' WHERE user_id = ?";',
                true,
            ],
            'an UPDATE copying the install\'s' => [
                '$q = "UPDATE 202_users_pref p JOIN 202_users_pref o ON o.user_id = 1 SET'
                    . ' p.user_pref_privacy = o.user_pref_privacy";',
                true,
            ],
            'a read of the setting' => [
                '$q = "SELECT user_pref_privacy FROM 202_users_pref WHERE user_id = ?";',
                false,
            ],
        ];
    }

    /** @dataProvider plants */
    public function testEachShapeIsReadAsItIs(string $statement, bool $refused): void
    {
        $found = 0;
        self::assertSame($refused, self::refusals("<?php\n$statement\n", $found) !== [], $statement);
    }

    /**
     * The statements in $source that make a preferences row naming more than
     * user_id, or write user_pref_privacy by name, as "line: statement".
     * $found counts the preferences inserts read.
     *
     * @return list<string>
     */
    private static function refusals(string $source, int &$found): array
    {
        $out = [];
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            $shown = str_replace(AssembledStrings::UNREAD, '…', (string) preg_replace('/\s+/', ' ', trim($text)));
            if (preg_match(self::INSERT, $text, $m) === 1) {
                $found++;
                if (preg_match(self::NAMES_USER_ID_ALONE, $m[1]) !== 1 || stripos($m[1], 'DUPLICATE') !== false) {
                    $out[] = "$line: $shown";
                }
                continue;
            }
            if (preg_match('/\bUPDATE\b.*\bSET\b.*\buser_pref_privacy`?\s*=/is', $text) === 1) {
                $out[] = "$line: $shown";
            }
        }

        return $out;
    }
}
