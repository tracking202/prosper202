<?php

declare(strict_types=1);

namespace Tests\User;

use Api\V3\Apps\AppDataPurge;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\AppTables;

/**
 * Deleting a user is one operation with one cascade (plan §4.6, §7.2).
 *
 * The account page, DELETE /api/v3/users/{id} and the user repository each
 * marked the user deleted, and only the page purged anything — and it named only the MTA
 * tables, so a deleted user's app registration kept its global UNIQUE slot
 * forever and nobody could register that app again. Both now go through
 * UserDataPurge::deleteUser(); these checks keep it that way:
 *
 *  - nothing outside UserDataPurge writes user_deleted = 1 (a third writer
 *    would be a delete with no purge);
 *  - every 202_app_* table has a decision in AppDataPurge (a table added to
 *    AppTables without one would outlive the user who owned its rows).
 */
final class UserDeletionPurgeTest extends TestCase
{
    /** @return list<string> repo-relative paths of every PHP file outside vendor and tests */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = str_replace($root . '/', '', $file->getPathname());
            if (
                !$file->isFile()
                || $file->getExtension() !== 'php'
                || preg_match('#^(vendor|tests|node_modules|\.git|build|go-cli)/#', $path) === 1
            ) {
                continue;
            }
            $found[] = $path;
        }
        sort($found);
        return $found;
    }

    public function testOnlyUserDataPurgeMarksAUserDeleted(): void
    {
        $writers = [];
        $sources = self::sources();
        $this->assertGreaterThan(200, count($sources), 'the scan found too few files to mean anything');
        foreach ($sources as $path) {
            $src = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
            // Any spelling of the write: = 1, = '1', = "1", with or without
            // spaces or backticks, in any case.
            if (preg_match('/`?user_deleted`?\s*=\s*[\'"]?1\b/i', $src) === 1) {
                $writers[] = $path;
            }
        }
        $this->assertSame(
            ['202-config/User/UserDataPurge.php'],
            $writers,
            'a user is marked deleted only by UserDataPurge::deleteUser(), which purges in the same transaction'
        );
    }

    public function testEveryDeletePathGoesThroughUserDataPurge(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            '202-account/user-management.php',
            'api/v3/Controllers/UsersController.php',
            '202-config/User/MysqlUserRepository.php',
        ] as $path) {
            $src = (string)file_get_contents($root . '/' . $path);
            // The call, not the name: `MyUserDataPurge(` contains the
            // substring, and constructing the class without calling
            // deleteUser() purges nothing (error pattern #21). The
            // unqualified spelling is the real class only inside its own
            // namespace.
            $qualified = '\\\\Prosper202\\\\User\\\\';
            $prefix = preg_match('/^namespace Prosper202\\\\User;$/m', $src) === 1 ? '(?:' . $qualified . ')?' : $qualified;
            $this->assertMatchesRegularExpression(
                '/\\(new ' . $prefix . 'UserDataPurge\\([^;]*\\)\\)->deleteUser\\(/',
                $src,
                "$path deletes users; it must delete them through UserDataPurge::deleteUser()"
            );
        }
    }

    public function testTheDeletePreviewNamesTheCascadeTheDeleteRuns(): void
    {
        $cascade = \Prosper202\User\UserDataPurge::cascade(42);
        $byTable = [];
        foreach ($cascade as $entry) {
            $byTable[$entry['resource']] = $entry['action'];
        }
        $this->assertSame('delete', $byTable['202_api_keys'] ?? null, 'the preview says the keys go');
        $this->assertSame('delete', $byTable['202_identity_keys'] ?? null);
        $this->assertSame('delete', $byTable['202_attribution_models'] ?? null);
        foreach (AppDataPurge::TABLE_ACTIONS as $table => $action) {
            $this->assertStringStartsWith($action, $byTable[$table] ?? '', "$table: the preview says $action");
        }
        foreach (AppDataPurge::LINK_ACTIONS as $table => $action) {
            $this->assertSame($action, $byTable[$table] ?? null, "$table: the preview says $action");
        }
        $this->assertSame('202_users', end($cascade)['resource'], 'the soft delete is last, as it runs');
    }

    public function testThePurgeDeletesEveryGoalTable(): void
    {
        $tables = array_map(static fn ($definition): string => $definition->tableName, \Prosper202\Database\Tables\GoalTables::getDefinitions());
        sort($tables);
        $purged = [];
        foreach (\Prosper202\User\UserDataPurge::GOAL_STATEMENTS as $sql) {
            $this->assertSame(1, preg_match('/^DELETE (?:\w+ )?FROM (\w+)/', $sql, $m), $sql);
            $purged[] = $m[1];
        }
        sort($purged);
        $this->assertSame($tables, $purged, 'a goal table with no purge statement outlives the user who owned its rows');

        $byTable = [];
        foreach (\Prosper202\User\UserDataPurge::cascade(42) as $entry) {
            $byTable[$entry['resource']] = $entry['action'];
        }
        foreach ($tables as $table) {
            $this->assertSame('delete', $byTable[$table] ?? null, $table . ': the preview names it');
        }
    }

    public function testThePurgeHasADecisionForEveryAppTable(): void
    {
        $tables = array_map(static fn ($definition): string => $definition->tableName, AppTables::getDefinitions());
        sort($tables);
        $decided = array_keys(AppDataPurge::TABLE_ACTIONS);
        sort($decided);
        $this->assertSame($tables, $decided, 'an app table with no purge decision outlives the user who owned its rows');

        // And the decision is carried out: every table named is written by
        // purgeUser(), with the action it declares.
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/api/v3/Apps/AppDataPurge.php');
        foreach (AppDataPurge::TABLE_ACTIONS as $table => $action) {
            $statement = $action === 'release' ? 'UPDATE ' . $table . ' SET user_id = 0' : 'DELETE FROM ' . $table . ' WHERE user_id = ?';
            $this->assertStringContainsString($statement, $src, "$table: $action");
        }
        foreach (array_keys(AppDataPurge::LINK_ACTIONS) as $table) {
            $this->assertStringContainsString('UPDATE ' . $table . ' SET app_registration_id = NULL', $src, "$table: unlinked");
        }
    }
}
