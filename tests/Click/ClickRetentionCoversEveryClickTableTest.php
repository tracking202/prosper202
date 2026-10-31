<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRetention;

/**
 * Every table that holds a click id is either deleted with the click
 * (ClickRetention::TABLES) or kept, with the reason (ClickRetention::KEPT).
 *
 * The cron job's deletions listed ten tables by hand while the schema grew
 * five more keyed by click — 202_facebook beside 202_google and 202_bing,
 * 202_clicks_rotator, 202_cpa_trackers, and the identity graph's per-click
 * rows — which outlived their clicks. So the list is read against the
 * schema the installer creates: a column named click_id, or ending in
 * click_id / clickid (first_click_id, next_click_id,
 * user_delete_data_clickid), puts its table in one list or the other.
 */
final class ClickRetentionCoversEveryClickTableTest extends TestCase
{
    /** @return array<string, list<string>> table => its click-id columns */
    private static function clickTables(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        $files = glob($root . '/202-config/Database/Tables/*Tables.php') ?: [];
        self::assertGreaterThan(10, count($files), 'the schema definitions were found');
        foreach ($files as $file) {
            $class = 'Prosper202\\Database\\Tables\\' . basename($file, '.php');
            foreach ($class::getDefinitions() as $definition) {
                foreach (preg_split('/\R/', $definition->createStatement) ?: [] as $line) {
                    if (preg_match('/^\s*`(\w+)`\s+\w/', $line, $c) === 1 && preg_match('/(^|_)click_?id$/', $c[1]) === 1) {
                        $out[$definition->tableName][] = $c[1];
                    }
                }
            }
        }
        ksort($out);

        return $out;
    }

    public function testEveryTableThatHoldsAClickIdIsDeletedOrKeptForAReason(): void
    {
        $tables = self::clickTables();
        self::assertArrayHasKey('202_clicks', $tables, 'the scan reads the click table');
        self::assertArrayHasKey('202_customers', $tables, 'and a column that ends in click_id');
        self::assertArrayHasKey('202_users_pref', $tables, 'and one that ends in clickid');

        $classified = array_merge(ClickRetention::TABLES, array_keys(ClickRetention::KEPT));
        self::assertSame([], array_values(array_diff(array_keys($tables), $classified)),
            'a table holds a click id and is in neither ClickRetention::TABLES (deleted with the click) nor ClickRetention::KEPT (kept, with the reason)');
        self::assertSame([], array_values(array_diff($classified, array_keys($tables))),
            'ClickRetention names a table the schema does not have, or one that holds no click id');
        self::assertSame([], array_values(array_intersect(ClickRetention::TABLES, array_keys(ClickRetention::KEPT))), 'a table is deleted or kept, not both');
        self::assertSame(count(ClickRetention::TABLES), count(array_unique(ClickRetention::TABLES)));
        foreach (ClickRetention::KEPT as $table => $reason) {
            self::assertNotSame('', trim($reason), "$table is kept for a reason");
        }
    }

    /** ClickRetention deletes WHERE click_id IN (…) from each: the column has to be that one. */
    public function testEveryDeletedTableIsKeyedByAClickIdColumn(): void
    {
        $tables = self::clickTables();
        foreach (ClickRetention::TABLES as $table) {
            self::assertContains('click_id', $tables[$table] ?? [], "$table has a click_id column");
        }
    }
}
