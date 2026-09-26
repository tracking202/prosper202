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

    public function testThePurgeDeletesEveryAttributionTable(): void
    {
        $tables = array_map(static fn ($definition): string => $definition->tableName, \Prosper202\Database\Tables\AttributionTables::getDefinitions());
        // The outbox is conversion schema (ConversionTables), but its rows
        // for the user's conversions are MTA state: left, the worker would
        // rebuild the journeys the purge removed.
        $tables[] = '202_attribution_pending';
        sort($tables);
        $purged = [];
        foreach (\Prosper202\User\UserDataPurge::MTA_STATEMENTS as $sql) {
            $this->assertSame(1, preg_match('/^DELETE (?:\w+ )?FROM (\w+)/', $sql, $m), $sql);
            $purged[] = $m[1];
        }
        sort($purged);
        $this->assertSame($tables, $purged, 'an MTA table with no purge statement outlives the user who owned its rows');

        // Children before parents: credits are found through the models,
        // journeys through the journey meta, so each goes first.
        $order = array_flip(array_map(static function (string $sql): string {
            preg_match('/^DELETE (?:\w+ )?FROM (\w+)/', $sql, $m);
            return $m[1];
        }, \Prosper202\User\UserDataPurge::MTA_STATEMENTS));
        $this->assertLessThan($order['202_attribution_models'], $order['202_attribution_credits']);
        $this->assertLessThan($order['202_attribution_journey_meta'], $order['202_attribution_journeys']);

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

    /**
     * Tables that hold rows of a user (a user_id, or a key that reaches a
     * user's rows) and that the delete keeps, each with why. Every other
     * such table must be purged. A table added to any *Tables class with one
     * of these columns fails here until it is purged or named below.
     */
    private const KEPT = [
        // The account's setup and traffic record: a user delete is a soft
        // delete of the login, and these were always kept with it.
        '202_ad_network_feeds' => 'account setup, kept with the soft-deleted user',
        '202_aff_networks' => 'account setup, kept with the soft-deleted user',
        '202_ppc_accounts' => 'account setup, kept with the soft-deleted user',
        '202_ppc_networks' => 'account setup, kept with the soft-deleted user',
        '202_landing_pages' => 'account setup, kept with the soft-deleted user',
        '202_text_ads' => 'account setup, kept with the soft-deleted user',
        '202_trackers' => 'account setup, kept with the soft-deleted user',
        '202_rotators' => 'account setup, kept with the soft-deleted user',
        '202_offers' => 'account setup, kept with the soft-deleted user',
        '202_dni_networks' => 'account setup, kept with the soft-deleted user',
        '202_clicks' => 'the traffic record: clicks are kept',
        '202_clicks_advance' => 'the traffic record: clicks are kept',
        '202_clicks_counter' => 'the traffic record: clicks are kept',
        '202_clicks_record' => 'the traffic record: clicks are kept',
        '202_clicks_site' => 'the traffic record: clicks are kept',
        '202_clicks_spy' => 'the traffic record: clicks are kept',
        '202_clicks_tracking' => 'the traffic record: clicks are kept (customer_id then names no row, and reads as unlinked)',
        '202_clicks_variable' => 'the traffic record: clicks are kept',
        '202_clicks_rotator' => 'the traffic record: clicks are kept',
        '202_cpa_trackers' => 'the traffic record: clicks are kept',
        '202_google' => 'the traffic record: clicks are kept',
        '202_bing' => 'the traffic record: clicks are kept',
        '202_facebook' => 'the traffic record: clicks are kept',
        '202_conversion_logs' => 'the traffic record: conversions are kept (customer_id then names no row, and reads as unlinked)',
        '202_dataengine' => 'report cache of the kept clicks',
        '202_dirty_hours' => 'report cache of the kept clicks',
        '202_forecast_events' => 'report annotations of the kept traffic',
        '202_sort_breakdowns' => 'report preferences, kept with the soft-deleted user',
        '202_charts' => 'report preferences, kept with the soft-deleted user',
        '202_users' => 'the soft-deleted row itself (user_deleted = 1)',
        '202_users_pref' => 'kept with the soft-deleted user row',
        '202_user_role' => 'kept with the soft-deleted user row',
        '202_mysql_errors' => 'the operator\'s database error log, not the user\'s data',
        'user_data_feedback' => 'the vendor feedback cache, not tracking data',
        '202_messaging_conversations' => 'the vendor messaging cache, not tracking data',
        '202_messaging_sync' => 'the vendor messaging cache, not tracking data',
        '202_messaging_events' => 'the vendor messaging cache, not tracking data',
        '202_messaging_attributes' => 'the vendor messaging cache, not tracking data',
    ];

    /** Columns that make a table hold a user's rows: its own, or one that reaches them. */
    private const OWNING_COLUMNS = [
        'user_id', 'customer_id', 'registration_id', 'model_id', 'goal_id', 'subject_id',
        'webhook_id', 'company_id', 'product_id', 'conv_id', 'click_id', 'visitor_id',
    ];

    /** @return array<string, array{class: string, columns: list<string>}> every table of every *Tables class */
    private static function everyTable(): array
    {
        $tables = [];
        $files = glob(dirname(__DIR__, 2) . '/202-config/Database/Tables/*Tables.php') ?: [];
        foreach ($files as $file) {
            $class = 'Prosper202\\Database\\Tables\\' . basename($file, '.php');
            foreach ($class::getDefinitions() as $definition) {
                preg_match_all('/^\s*`(\w+)`\s/m', $definition->createStatement, $m);
                $tables[$definition->tableName] = ['class' => basename($file, '.php'), 'columns' => $m[1]];
            }
        }

        return $tables;
    }

    /** @return list<string> the tables the delete purges or decides on */
    private static function purgedTables(): array
    {
        $purged = [];
        foreach (\Prosper202\User\UserDataPurge::statements() as $sql) {
            self::assertSame(1, preg_match('/^DELETE (?:\w+ )?FROM (\w+)/', $sql, $m), $sql);
            $purged[] = $m[1];
        }

        return array_values(array_unique(array_merge($purged, array_keys(AppDataPurge::TABLE_ACTIONS), array_keys(AppDataPurge::LINK_ACTIONS))));
    }

    public function testEveryTableThatHoldsAUsersRowsIsPurgedOrKeptByName(): void
    {
        $tables = self::everyTable();
        $classes = array_unique(array_column($tables, 'class'));
        foreach (['CoreTables', 'ConversionTables', 'IdentityTables', 'GoalTables', 'AttributionTables', 'AppTables', 'LtvTables', 'UserTables', 'ClickTables'] as $expected) {
            $this->assertContains($expected, $classes, "the walk read $expected");
        }
        $this->assertGreaterThan(100, count($tables), 'the walk found too few tables to be the schema');

        $purged = self::purgedTables();
        $undecided = [];
        foreach ($tables as $table => $info) {
            $owning = array_values(array_intersect($info['columns'], self::OWNING_COLUMNS));
            if ($owning === [] || in_array($table, $purged, true)) {
                continue;
            }
            if (!isset(self::KEPT[$table]) || trim(self::KEPT[$table]) === '') {
                $undecided[] = $table . ' (' . $info['class'] . ': ' . implode(', ', $owning) . ')';
            }
        }
        $this->assertSame([], $undecided, 'a table holding a user\'s rows outlives the deleted user unless the purge deletes it or KEPT says why it stays');

        // No stale entries: a kept table exists, holds a user's rows, and is
        // not also purged (the two answers would contradict each other).
        foreach (array_keys(self::KEPT) as $table) {
            $this->assertArrayHasKey($table, $tables, "$table is kept but is no table of the schema");
            $this->assertNotSame([], array_intersect($tables[$table]['columns'], self::OWNING_COLUMNS), "$table is kept but holds no user's rows");
            $this->assertNotContains($table, $purged, "$table is both kept and purged");
        }
        // And every purge statement names a real table.
        foreach ($purged as $table) {
            $this->assertArrayHasKey($table, $tables, "the purge names $table, which no *Tables class defines");
        }
    }

    /** @return array<string, array{0: class-string, 1: list<string>}> */
    public static function wholeClassPurges(): array
    {
        return [
            'LTV' => [\Prosper202\Database\Tables\LtvTables::class, \Prosper202\User\UserDataPurge::LTV_STATEMENTS],
            'identity graph' => [\Prosper202\Database\Tables\IdentityTables::class, \Prosper202\User\UserDataPurge::statements()],
        ];
    }

    /**
     * @dataProvider wholeClassPurges
     * @param class-string $class
     * @param list<string> $statements
     */
    public function testEveryTableOfTheClassIsPurgedAndThePreviewSaysSo(string $class, array $statements): void
    {
        $tables = array_map(static fn ($definition): string => $definition->tableName, $class::getDefinitions());
        $named = [];
        foreach ($statements as $sql) {
            $this->assertSame(1, preg_match('/^DELETE (?:\w+ )?FROM (\w+)/', $sql, $m), $sql);
            $named[] = $m[1];
        }
        $byTable = [];
        foreach (\Prosper202\User\UserDataPurge::cascade(42) as $entry) {
            $byTable[$entry['resource']] = $entry['action'];
        }
        foreach ($tables as $table) {
            $this->assertContains($table, $named, "$table outlives the user who owned its rows");
            $this->assertSame('delete', $byTable[$table] ?? null, "$table: the preview names it");
        }
    }
}
