<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;

/**
 * A column that names another table's row by its id holds every id that
 * table can give (CLAUDE.md #17: the width is a transform too).
 *
 * 202_ppc_network_variables.ppc_network_id was a signed mediumint while
 * 202_ppc_networks.ppc_network_id is unsigned: under the app's empty
 * sql_mode a variable of a source past 8388607 was stored under 8388607,
 * another source's, with nothing said. A narrower column is a many-to-one
 * map from the ids it names, and the server applies it on every write.
 *
 * The scan reads every table definition. Each single-column AUTO_INCREMENT
 * primary key whose name no other table's key shares (`id` is several
 * tables', so it cannot say which it names) is an id; a column elsewhere
 * named exactly that, or ending in `_` and that (`user_pref_ppc_network_id`),
 * names it, and must be an integer type whose largest value is at least the
 * key's. The name is the whole heuristic: a column that names an id under
 * another name is not seen, and one whose name only looks like it is listed
 * below as naming something else.
 */
final class IdColumnsHoldTheIdTheyNameTest extends TestCase
{
    /** table.column => what it names instead of the key its name matches */
    private const NAMES_SOMETHING_ELSE = [
        '202_ad_network_ads.feed_id' => '202_ad_network_feeds.id, not an offline conversion feed',
        '202_ad_network_titles.feed_id' => '202_ad_network_feeds.id, not an offline conversion feed',
        '202_ad_network_bodies.feed_id' => '202_ad_network_feeds.id, not an offline conversion feed',
        '202_ad_feed_contentad_tokens.feed_id' => '202_ad_network_feeds.id, not an offline conversion feed',
        '202_ad_feed_outbrain_tokens.feed_id' => '202_ad_network_feeds.id, not an offline conversion feed',
        '202_ad_feed_taboola_tokens.feed_id' => '202_ad_network_feeds.id, not an offline conversion feed',
        '202_ad_feed_custom_tokens.feed_id' => '202_ad_network_feeds.id, not an offline conversion feed',
        '202_ad_feed_revcontent_tokens.feed_id' => '202_ad_network_feeds.id, not an offline conversion feed',
        '202_ad_feed_facebook_tokens.feed_id' => '202_ad_network_feeds.id, not an offline conversion feed',
        '202_export_keywords.export_keyword_id' => 'its own table\'s key',
        '202_products.external_product_id' => 'the billing system\'s product id',
        '202_users_pref.revcontent_user_id' => 'the RevContent account\'s user id',
    ];

    /**
     * table.column => why it is narrower than the id it names. A list that
     * only shrinks: widen the column (in its definition and an upgrade
     * rung) and take it off.
     */
    private const NARROWER_THAN_ITS_ID = [
        '202_ad_network_feeds.user_id' => 'signed mediumint; user ids are not expected past 8388607',
        '202_last_ips.user_id' => 'signed mediumint; user ids are not expected past 8388607',
        '202_auth_keys.user_id' => 'signed mediumint; user ids are not expected past 8388607',
        '202_aff_campaigns.attribution_model_id' => 'int; model ids are not expected past 2147483647',
        '202_clicks_tracking.c1_id' => 'signed bigint; no counter reaches 9223372036854775807',
        '202_clicks_tracking.c2_id' => 'signed bigint; no counter reaches 9223372036854775807',
        '202_clicks_tracking.c3_id' => 'signed bigint; no counter reaches 9223372036854775807',
        '202_clicks_tracking.c4_id' => 'signed bigint; no counter reaches 9223372036854775807',
        '202_locations_city.main_country_id' => 'mediumint unsigned; there are fewer than 300 countries',
        '202_locations_region.main_country_id' => 'mediumint unsigned; there are fewer than 300 countries',
        '202_users_pref.user_pref_country_id' => 'tinyint unsigned; the report filter refuses an id past 255'
            . ' (p202_report_pref_fields())',
        '202_users_pref.user_pref_region_id' => 'tinyint unsigned; the report filter refuses an id past 255'
            . ' (p202_report_pref_fields())',
        '202_users_pref.user_pref_device_id' => 'tinyint unsigned; the report filter refuses an id past 255'
            . ' (p202_report_pref_fields())',
        '202_users_pref.user_pref_browser_id' => 'tinyint unsigned; the report filter refuses an id past 255'
            . ' (p202_report_pref_fields())',
        '202_users_pref.user_pref_platform_id' => 'tinyint unsigned; the report filter refuses an id past 255'
            . ' (p202_report_pref_fields())',
        '202_users_pref.user_pref_isp_id' => 'tinyint unsigned; the report filter refuses an id past 255'
            . ' (p202_report_pref_fields())',
    ];

    /** @var array<string, string> signed type => largest value */
    private const SIGNED_MAX = [
        'tinyint' => '127',
        'smallint' => '32767',
        'mediumint' => '8388607',
        'int' => '2147483647',
        'bigint' => '9223372036854775807',
    ];

    /** @var array<string, string> unsigned type => largest value */
    private const UNSIGNED_MAX = [
        'tinyint' => '255',
        'smallint' => '65535',
        'mediumint' => '16777215',
        'int' => '4294967295',
        'bigint' => '18446744073709551615',
    ];

    /** @return array<string, array<string, string>> table => column => its SQL */
    private static function columns(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (glob($root . '/202-config/Database/Tables/*Tables.php') ?: [] as $file) {
            $class = 'Prosper202\\Database\\Tables\\' . basename($file, '.php');
            foreach ($class::getDefinitions() as $definition) {
                // Read from the statement itself, as UniqueKeyCollationTest
                // does: some legacy definitions carry items the reconciler
                // refuses to parse.
                $out[$definition->tableName]['PRIMARY KEY'] = '';
                foreach (preg_split('/\R/', $definition->createStatement) ?: [] as $line) {
                    if (preg_match('/^\s*(`([^`]+)`\s+.*?),?\s*$/', $line, $c) === 1) {
                        $out[$definition->tableName][$c[2]] = $c[1];
                    }
                    if (preg_match('/^\s*PRIMARY\s+KEY\s*\(\s*`([^`]+)`\s*\)/i', $line, $k) === 1) {
                        $out[$definition->tableName]['PRIMARY KEY'] = $k[1];
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> id name => the one table whose AUTO_INCREMENT key it is */
    private static function ids(array $columns): array
    {
        $owners = [];
        foreach ($columns as $table => $cols) {
            $key = $cols['PRIMARY KEY'];
            if ($key !== '' && preg_match('/\bAUTO_INCREMENT\b/i', $cols[$key] ?? '') === 1) {
                $owners[$key][] = $table;
            }
        }
        $ids = [];
        foreach ($owners as $name => $tables) {
            if (count($tables) === 1) {
                $ids[$name] = $tables[0];
            }
        }

        return $ids;
    }

    /** The largest value an integer column holds, as a decimal string, or null for any other type. */
    private static function largest(string $columnSql): ?string
    {
        if (preg_match('/^`[^`]+`\s+(tinyint|smallint|mediumint|int|integer|bigint)\b(?:\s*\(\d+\))?(\s+unsigned)?/i', $columnSql, $m) !== 1) {
            return null;
        }
        $type = strtolower($m[1]) === 'integer' ? 'int' : strtolower($m[1]);

        return (isset($m[2]) && $m[2] !== '' ? self::UNSIGNED_MAX : self::SIGNED_MAX)[$type];
    }

    private static function reaches(string $a, string $b): bool
    {
        return strlen($a) !== strlen($b) ? strlen($a) > strlen($b) : strcmp($a, $b) >= 0;
    }

    /** @return array<string, string> table.column => what is wrong, for every column that names an id */
    private static function findings(): array
    {
        $columns = self::columns();
        $ids = self::ids($columns);
        $found = [];
        foreach ($columns as $table => $cols) {
            foreach ($cols as $name => $sql) {
                if ($name === 'PRIMARY KEY') {
                    continue;
                }
                foreach ($ids as $id => $owner) {
                    if ($table === $owner && $name === $id) {
                        continue;
                    }
                    if ($name !== $id && !str_ends_with($name, '_' . $id)) {
                        continue;
                    }
                    $want = (string) self::largest($columns[$owner][$id]);
                    $have = self::largest($sql);
                    $found[$table . '.' . $name] = $have === null
                        ? 'not an integer, naming ' . $owner . '.' . $id
                        : (self::reaches($have, $want) ? '' : 'holds up to ' . $have . ', naming ' . $owner . '.'
                            . $id . ', which goes up to ' . $want);
                }
            }
        }
        ksort($found);

        return $found;
    }

    public function testTheScanFindsTheColumnsItIsAbout(): void
    {
        $found = self::findings();
        $this->assertArrayHasKey('202_ppc_network_variables.ppc_network_id', $found, 'an exact name');
        $this->assertArrayHasKey('202_users_pref.user_pref_ppc_network_id', $found, 'a name ending in the id');
        $this->assertArrayHasKey('202_ppc_accounts.ppc_network_id', $found);
        $this->assertArrayNotHasKey('202_ppc_networks.ppc_network_id', $found, 'not the key itself');
        $this->assertGreaterThan(100, count($found));
        $this->assertSame('8388607', self::largest('`a` mediumint(8) NOT NULL'));
        $this->assertSame('16777215', self::largest('`a` mediumint(8) unsigned NOT NULL'));
        $this->assertSame('18446744073709551615', self::largest('`a` bigint unsigned'));
        $this->assertNull(self::largest('`a` varchar(20)'));
        $this->assertFalse(self::reaches('8388607', '16777215'));
        $this->assertTrue(self::reaches('4294967295', '16777215'));
        $this->assertTrue(self::reaches('16777215', '16777215'));
    }

    public function testEveryColumnHoldsEveryIdItNames(): void
    {
        $narrow = [];
        foreach (self::findings() as $column => $problem) {
            if ($problem === '' || isset(self::NAMES_SOMETHING_ELSE[$column]) || isset(self::NARROWER_THAN_ITS_ID[$column])) {
                continue;
            }
            $narrow[] = $column . ' ' . $problem;
        }
        $this->assertSame([], $narrow, 'these columns cannot hold every id they name, and a server without strict'
            . ' mode stores a larger one as another; give each the type of the id it names, in its definition and'
            . ' an upgrade rung, or list it with what it names instead');
    }

    public function testTheListsNameOnlyColumnsTheyAreNeededFor(): void
    {
        $found = self::findings();
        $stale = [];
        foreach (array_keys(self::NAMES_SOMETHING_ELSE) as $column) {
            if (($found[$column] ?? '') === '') {
                $stale[] = $column . ' (fits, or is no longer read)';
            }
        }
        foreach (array_keys(self::NARROWER_THAN_ITS_ID) as $column) {
            if (($found[$column] ?? '') === '') {
                $stale[] = $column . ' (now holds its id: take it off the list)';
            }
        }
        $this->assertSame([], $stale);
    }
}
