<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;

/**
 * Every writer of what the report rollup sums leaves a mark, or says why it
 * need not (AttributionRollup rule 2; CLAUDE.md #5).
 *
 * The rollup is only as right as its marks: an hour summed before a change
 * that did not mark it is read stale for good. So every PHP file that writes
 * one of the rollup's source tables — the clicks and their dimension rows,
 * the journeys and credits, the ledger — is classified here:
 *
 * - `marks`: it calls RollupDirty in the transaction of its change;
 * - `rollup`: it deletes the rollup's own rows with the rows they sum;
 * - a reason it need not: it only inserts new clicks at the request's time
 *   (whose hour is not summed yet), writes columns no sum reads, or reads.
 *
 * "Writes" is read from the strings as PHP assembles them (findings()):
 * literals, interpolated strings and heredocs, and class constants resolved
 * through the file's imports (TableRegistry::CLICKS), joined across `.`. A
 * file is a writer when it has a statement that begins with INSERT,
 * REPLACE, UPDATE, DELETE, TRUNCATE, DROP, ALTER, RENAME or CREATE and
 * names a source table; a string that is nothing but table names, one of
 * them a source (`'202_clicks'`, the cron job's old
 * `explode(',', '202_clicks,202_clicks_advance,…')`, a constant listing
 * them) — the table a loop picks; or a write statement whose table the
 * scan cannot read (`"DELETE FROM `$table`"`, `'UPDATE ' . $t`, a sprintf
 * %s), which is reported as `unread` rather than taken to be some other
 * table (CLAUDE.md #20). That comma-joined list hid the cron job's
 * deletion of every click table from the scan this replaced, which read
 * each literal alone. A new file with any of these fails until it is
 * classified; a classified file with none left fails too, so the list
 * cannot rot. testTheScanReadsEverySpellingOfASummedTable plants each
 * spelling the scan claims.
 *
 * What this cannot see: it classifies files, not statements, so a second
 * writer added to a file already classified `marks` is trusted to mark as
 * well — RollupMatchesFullComputationTest exercises the writers that carry
 * the rollup's correctness (the worker, retraction, replacement, reversal,
 * revival, model and override changes, a rewritten click, a CPC update,
 * click-data retention) and catches an unmarked one there. A table name
 * that reaches a statement through anything but a string — a function's
 * return value, configuration — is seen only where it is spelled, if it is
 * spelled in PHP at all.
 */
final class RollupWritersAreMarkedTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /** What the rollup sums, directly or through a join. */
    private const SOURCES = [
        '202_clicks', '202_clicks_advance', '202_clicks_tracking', '202_device_models',
        '202_attribution_credits', '202_attribution_journeys', '202_attribution_journey_meta',
        '202_conversion_logs',
    ];

    private const MARKS = 'marks';
    private const ROLLUP = 'rollup';

    /** @var array<string, string> file => marks | rollup | the reason it need not */
    private const WRITERS = [
        '202-config/Attribution/AttributionStore.php' => self::MARKS,
        '202-config/static-endpoint-helpers.php' => self::MARKS,
        '202-config/Ltv/MysqlCustomerRepository.php' => self::MARKS,
        'tracking202/redirect/rtr.php' => self::MARKS,
        'tracking202/redirect/off.php' => self::MARKS,
        'tracking202/redirect/offrtr.php' => self::MARKS,
        // The Update CPC write, behind the page and POST /api/v3/clicks/cpc.
        '202-config/Update/CpcUpdate.php' => self::MARKS,
        // Click-data retention: both of the cron job's deletions.
        '202-config/Click/ClickRetention.php' => self::MARKS,
        '202-config/Attribution/ModelRepository.php' => self::ROLLUP,
        '202-config/User/UserDataPurge.php' => self::ROLLUP,
        '202-config/Report/RollupDirty.php' => 'the marks themselves: they read the click rows they mark',
        '202-config/Click/MysqlClickRepository.php' => 'inserts a new click at the time of the request that records it; its hour is not summed until it is sealed',
        '202-config/connect2.php' => 'inserts new clicks (and new device models) at the time of the request that records them',
        '202-config/Repository/Mysql/MysqlDeviceRepository.php' => 'inserts a device model only when its name is absent, while recording the click that uses it; no click already summed can join a row that did not exist',
        '202-config/class-indexes.php' => 'INDEXES::get_device_id(): inserts a device model only when its name is absent, while recording the click that uses it; no click already summed can join a row that did not exist',
        '202-config/Conversion/Ledger/MysqlConversionLedger.php' => 'ledger rows change counted state through the outbox, and the worker marks every journey and credit it rewrites; the click columns it writes (click_lead, click_payout) are summed by no part of the rollup',
        '202-config/Conversion/MysqlConversionRepository.php' => 'ledger rows change counted state through the outbox, and the worker marks every journey and credit it rewrites; campaign_id and user_id, which the effective rows read, are written only when a row is inserted',
        '202-config/functions-upgrade.php' => 'upgrade rungs below the one that creates the rollup tables: no database they run on has a rollup yet',
        '202-config/migrations/run_ltv_migration.php' => 'schema only (customer_id columns and keys)',
        '202-config/migrations/run_ltv_backfill.php' => 'writes customer_id, which no sum reads; tracking rows it creates go through stampClickCustomer(), which marks',
        '202-config/Database/Schema/TableRegistry.php' => 'names the tables; writes nothing',
        '202-config/Ltv/MysqlCustomerCrmRepository.php' => 'reads the ledger and tracking rows',
        '202-config/Ltv/MysqlRecommendationRepository.php' => 'writes its own tables, reading the ledger',
        '202-config/Report/MysqlReportRepository.php' => 'reads',
        'api/v3/Controllers/ReportsController.php' => 'reads',
        'api/v3/Controllers/SystemController.php' => 'counts rows',
        // POST /system/retention/delete-before: names the click tables to count
        // what the cron job's one-off deletion (ClearOldClicks) would remove.
        'api/v3/Controllers/AdministrationController.php' => 'reads: counts the rows ClickRetention would delete (CLICK_DATA_TABLES) and stores the deletion marker on 202_users_pref; the cron job deletes',
        // The subid writes behind the Update pages and the /conversions/subids API.
        '202-config/Update/SubidBatch.php' => 'writes click_filtered, which no sum reads',
        '202-config/Attribution/ConversionBackfill.php' => 'MARK_SQL writes the ledger backfill\'s marker, reading MAX(click_id) of 202_clicks; the conversions it backfills are written by MysqlConversionLedger',
        '202-config/Database/Tables/AttributionTables.php' => 'schema definitions: CREATE TABLE IF NOT EXISTS, no row',
        '202-config/Database/Tables/ClickTables.php' => 'schema definitions: CREATE TABLE IF NOT EXISTS, no row',
        '202-config/Database/Tables/ConversionTables.php' => 'schema definitions: CREATE TABLE IF NOT EXISTS, no row',
        '202-config/Database/Tables/MiscTables.php' => 'schema definitions: CREATE TABLE IF NOT EXISTS, no row',
        '202-config/Database/Schema/PartitionStrategy.php' => 'names the tables it partitions: DDL that moves no row and changes no value',
        // Writers whose table the scan cannot read (a variable, a property,
        // a sprintf %s), each with the tables it can reach.
        '202-config/Database/Schema/SchemaBuilder.php' => 'unread: assembles CREATE TABLE IF NOT EXISTS from a definition; no row',
        '202-config/Database/SchemaReconciler.php' => 'unread: adds missing columns and keys, and lets a column take NULL where the definition does with the same type; no value changes',
        '202-config/Crud/MysqlCrudRepository.php' => 'unread: writes the table its TableConfig names, and every TableConfig (202-config/Crud/TableConfig.php) names a setup table',
        'api/v3/Controller.php' => 'unread: creates, updates and deletes the table a controller names in tableName(), and every subclass names a setup or app table (networks, traffic sources and accounts, campaigns, landing pages, text ads, trackers, forecast events, app registrations and SKAN encodings)',
        '202-config/Repository/Mysql/MysqlTrackingRepository.php' => 'unread: inserts a c1-c4 or UTM value, when it is absent, into the dictionary table its kind names (202_tracking_c*, 202_utm_*); names are looked up when a report runs, never summed',
        '202-config/DataEngine/ClickRollupSql.php' => 'unread: builds the dataengine\'s insert into the table its caller names (202_dataengine or its _new copy), reading the clicks',
        '202-config/class-dataengine.php' => 'unread: writes 202_dataengine or its _new copy, the Overview\'s aggregate',
        '202-config/Messaging/MessagingService.class.php' => 'unread: deletes from its own messaging tables, named in the literal list the loop beside the statement walks',
        'api/v3/Apps/AppRetention.php' => 'unread: prunes the app measurement tables its retention classes name',
    ];

    /** @return array<string, list<string>> file => the statements found */
    private static function writers(): array
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            $rel = substr($path, strlen(self::ROOT));
            if (!str_ends_with($rel, '.php') || preg_match('#^(vendor|tests|node_modules|sdk|go-cli|\.git|\.claude)/#', $rel) === 1) {
                continue;
            }
            foreach (self::findings((string) file_get_contents($path)) as [$line, $kind, $text]) {
                $found[$rel][] = $line . ': ' . $kind . ': ' . preg_replace('/\s+/', ' ', substr(str_replace(self::UNREAD, '…', $text), 0, 100));
            }
        }
        ksort($found);

        return $found;
    }

    /** Stands in, inside an assembled string, for a part the scan cannot read. */
    private const UNREAD = "\x00";

    /** A statement that writes: its first keyword, after any whitespace and comments. */
    private const WRITE_HEAD = '/^\s*(?:(?:\/\*.*?\*\/|(?:--|#)[^\n]*\n)\s*)*(INSERT|REPLACE|UPDATE|DELETE|TRUNCATE|DROP|ALTER|RENAME|CREATE)\b/is';

    /**
     * Where a write statement names a table, holding a part the scan cannot
     * read: a variable, an expression, a constant it cannot resolve, a
     * sprintf %s, or the end of the string (the rest is appended later).
     */
    private const UNREAD_TABLE = '/(?:\bINTO|(?<!\bKEY\s)\bUPDATE(?:\s+LOW_PRIORITY)?(?:\s+IGNORE)?|\bFROM|\bJOIN|\bUSING|\bTABLE(?:\s+IF(?:\s+NOT)?\s+EXISTS)?|\bTRUNCATE)(?:\s+`?|`)(?:\x00|%(?:\d+\$)?s)/i';

    /**
     * What one PHP source says about the summed tables, as [line, kind, text]:
     *
     * - `write`: a statement that writes and names a summed table;
     * - `names`: a string that is nothing but table names, at least one of
     *   them summed (`'202_clicks'`, `'202_clicks,202_clicks_advance'`, a
     *   constant naming one) — the table a loop or explode() picks;
     * - `unread`: a statement that writes a table the scan cannot read the
     *   name of. It is reported, never taken to be some other table (#20).
     *
     * Strings are read as PHP assembles them: literals, interpolated and
     * heredoc strings (each interpolation an unread part) and class
     * constants, joined across `.`, with a constant resolved through the
     * file's namespace and imports (TableRegistry::CLICKS is '202_clicks';
     * a constant that holds a list is read element by element).
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private static function findings(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $scope = self::scope($tokens);
        $out = [];
        $n = count($tokens);
        $i = 0;
        while ($i < $n) {
            $operand = self::operand($tokens, $i, $scope, $out);
            if ($operand === null || $operand[0] === null) {
                $i = $operand === null ? $i + 1 : $operand[1];
                continue;
            }
            [$text, $next, $line] = $operand;
            while (($tokens[$next] ?? null) === '.') {
                $more = self::operand($tokens, $next + 1, $scope, $out);
                if ($more === null || $more[0] === null) {
                    break;
                }
                $text .= $more[0];
                $next = $more[1];
            }
            // Whatever follows the string — an unread operand, a `.=`, a
            // sprintf argument — is unread too.
            $kind = self::classify($text . self::UNREAD);
            if ($kind !== null) {
                $out[] = [$line, $kind, $text];
            }
            $i = $next;
        }

        return $out;
    }

    /**
     * The string an operand at $i contributes: [text, next index, line];
     * [null, next index] for an operand the scan cannot read; null when $i
     * starts no operand it knows. A constant that holds a list is recorded
     * element by element into $out and reads as unread.
     *
     * @param list<mixed> $tokens
     * @param array{ns: string, uses: array<string, string>, classes: array<int, string>} $scope
     * @param list<array{0: int, 1: string, 2: string}> $out
     * @return array{0: string|null, 1: int, 2?: int}|null
     */
    private static function operand(array $tokens, int $i, array $scope, array &$out): ?array
    {
        $t = $tokens[$i] ?? null;
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $q = $t[1][0];
            $body = substr($t[1], 1, -1);
            $body = $q === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);

            return [$body, $i + 1, $t[2]];
        }
        if ($t === '"' || (is_array($t) && $t[0] === T_START_HEREDOC)) {
            $end = $t === '"' ? '"' : T_END_HEREDOC;
            $line = self::lineAt($tokens, $i);
            $text = '';
            $unread = false;
            for ($j = $i + 1; isset($tokens[$j]); $j++) {
                $u = $tokens[$j];
                if ($u === $end || (is_array($u) && $u[0] === $end)) {
                    break;
                }
                if (is_array($u) && $u[0] === T_ENCAPSED_AND_WHITESPACE) {
                    $text .= $u[1];
                    $unread = false;
                } elseif (!$unread) {
                    $text .= self::UNREAD;
                    $unread = true;
                }
            }

            return [$text, $j + 1, $line];
        }
        if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC], true)
            && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_COLON
            && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_STRING
            && ($tokens[$i + 3] ?? null) !== '(') {
            $value = self::constantValue($t[1], $tokens[$i + 2][1], $scope, $i);
            if (is_string($value)) {
                return [$value, $i + 3, $t[2]];
            }
            if (is_array($value)) {
                array_walk_recursive($value, static function ($v) use (&$out, $t): void {
                    if (is_string($v) && ($kind = self::classify($v . self::UNREAD)) !== null) {
                        $out[] = [$t[2], $kind, $v];
                    }
                });
            }

            return [null, $i + 3];
        }
        if (is_array($t) && in_array($t[0], [T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_LNUMBER, T_DNUMBER], true)) {
            return [null, $i + 1];
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function lineAt(array $tokens, int $i): int
    {
        for (; $i >= 0; $i--) {
            if (is_array($tokens[$i])) {
                return $tokens[$i][2];
            }
        }

        return 0;
    }

    private static function classify(string $text): ?string
    {
        $names = '/(?<![\w])(' . implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), self::SOURCES)) . ')(?![\w])/';
        if (preg_match(self::WRITE_HEAD, $text) === 1) {
            if (preg_match($names, $text) === 1) {
                return 'write';
            }

            return preg_match(self::UNREAD_TABLE, $text) === 1 ? 'unread' : null;
        }
        $parts = preg_split('/[\s,`"\'\x00]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === [] || array_filter($parts, static fn (string $p): bool => preg_match('/^202_\w+$/', $p) !== 1) !== []) {
            return null;
        }

        return array_intersect($parts, self::SOURCES) !== [] ? 'names' : null;
    }

    /**
     * The file's namespace, its class imports, and the class each token
     * offset is inside (for self:: and static::).
     *
     * @param list<mixed> $tokens
     * @return array{ns: string, uses: array<string, string>, classes: array<int, string>}
     */
    private static function scope(array $tokens): array
    {
        $ns = '';
        $uses = [];
        $classes = [];
        $depth = 0;
        foreach ($tokens as $i => $t) {
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
            }
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE && is_array($tokens[$i + 1] ?? null) && in_array($tokens[$i + 1][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $ns = $tokens[$i + 1][1];
            } elseif ($t[0] === T_USE && $depth === 0 && is_array($tokens[$i + 1] ?? null)
                && in_array($tokens[$i + 1][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                // use A\B; use A\B as C; use A\B, D\E; (not use function / const, not a group)
                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== ';'; $j++) {
                    $name = $tokens[$j];
                    if (!is_array($name) || !in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        continue;
                    }
                    $full = ltrim($name[1], '\\');
                    $alias = substr($full, (int) strrpos('\\' . $full, '\\'));
                    if (is_array($tokens[$j + 1] ?? null) && $tokens[$j + 1][0] === T_AS && is_array($tokens[$j + 2] ?? null)) {
                        $alias = $tokens[$j + 2][1];
                        $j += 2;
                    }
                    $uses[strtolower($alias)] = $full;
                }
            } elseif (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING
                && !(is_array($tokens[$i - 1] ?? null) && in_array($tokens[$i - 1][0], [T_DOUBLE_COLON, T_NEW], true))) {
                $classes[$i] = ($ns === '' ? '' : $ns . '\\') . $tokens[$i + 1][1];
            }
        }

        return ['ns' => $ns, 'uses' => $uses, 'classes' => $classes];
    }

    /**
     * The value of $class::$name as this file names it; null when the scan
     * cannot resolve it (it is then unread).
     *
     * @param array{ns: string, uses: array<string, string>, classes: array<int, string>} $scope
     */
    private static function constantValue(string $class, string $name, array $scope, int $at): mixed
    {
        $lower = strtolower($class);
        if ($lower === 'self' || $lower === 'static') {
            $fq = null;
            foreach ($scope['classes'] as $offset => $declared) {
                if ($offset < $at) {
                    $fq = $declared;
                }
            }
        } elseif ($lower === 'parent') {
            return null;
        } elseif ($class[0] === '\\') {
            $fq = substr($class, 1);
        } else {
            $first = strtolower(explode('\\', $class)[0]);
            $rest = substr($class, strlen($first));
            $fq = isset($scope['uses'][$first]) ? $scope['uses'][$first] . $rest : ($scope['ns'] === '' ? '' : $scope['ns'] . '\\') . $class;
        }
        if ($fq === null) {
            return null;
        }
        try {
            if (!class_exists($fq) && !interface_exists($fq)) {
                return null;
            }
            $reflection = new \ReflectionClass($fq);

            return $reflection->hasConstant($name) ? $reflection->getConstant($name) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every spelling of a summed table the scan claims to read, each planted
     * in a source of its own: a comma-joined list once hid the cron job's
     * deletion of 202_clicks from this test.
     *
     * @return array<string, array{0: string, 1: string}> source, the kind it must be found as
     */
    public static function spellings(): array
    {
        return [
            'a comma-joined list exploded at runtime' => ["<?php foreach (explode(',', '202_clicks_spy,202_clicks,202_google') as \$t) {}", 'names'],
            'a space-joined list' => ["<?php \$tables = '202_bing 202_clicks_advance';", 'names'],
            'a literal list a loop walks' => ["<?php foreach (['202_google', '202_clicks_tracking'] as \$t) {}", 'names'],
            'a table interpolated in a loop' => ["<?php foreach (\$tables as \$t) { \$db->query(\"DELETE FROM `\$t` WHERE click_id < 5\"); }", 'unread'],
            'a property interpolated' => ['<?php $db->query("UPDATE {$this->table} SET x = 1");', 'unread'],
            'a table concatenated' => ["<?php \$db->query('DELETE FROM ' . \$table . ' WHERE click_id < 5');", 'unread'],
            'a name split across literals' => ["<?php \$db->query('DELETE FROM 202_' . 'clicks WHERE click_id < 5');", 'write'],
            'a statement split across literals' => ["<?php \$sql = 'UPDATE ' . '202_conversion_logs' . ' SET deleted = 1';", 'write'],
            'a statement finished later' => ["<?php \$sql = 'DELETE FROM '; \$sql .= \$t;", 'unread'],
            'sprintf' => ["<?php \$db->query(sprintf('DELETE FROM %s WHERE click_id < 5', \$t));", 'unread'],
            'positional sprintf' => ["<?php \$sql = sprintf('TRUNCATE TABLE %1\$s', \$t);", 'unread'],
            'TableRegistry::CLICKS, imported' => ["<?php namespace X;\nuse Prosper202\\Database\\Schema\\TableRegistry;\n\$db->query('DELETE FROM ' . TableRegistry::CLICKS . ' WHERE click_id < 5');", 'write'],
            'TableRegistry, fully qualified' => ["<?php \$t = \\Prosper202\\Database\\Schema\\TableRegistry::CLICKS_ADVANCE;", 'names'],
            'TableRegistry under an alias' => ["<?php use Prosper202\\Database\\Schema\\TableRegistry as T;\n\$t = T::CLICKS_TRACKING;", 'names'],
            'TableRegistry relative to the namespace' => ["<?php namespace Prosper202\\Database;\n\$t = Schema\\TableRegistry::CLICKS;", 'names'],
            'a list constant of another class' => ["<?php foreach (\\Prosper202\\Click\\ClickRetention::TABLES as \$t) {}", 'names'],
            'a list constant in the same class' => ["<?php namespace P;\nfinal class Purge { private const T = ['202_clicks']; public function f(): void { foreach (self::T as \$t) {} } }", 'names'],
            'a constant naming an unloadable class' => ["<?php \$db->query('DELETE FROM ' . Nowhere\\Tables::CLICKS . ' WHERE 1');", 'unread'],
            'a heredoc' => ["<?php \$sql = <<<SQL\nDELETE FROM 202_clicks_advance WHERE click_id < 5\nSQL;", 'write'],
            'a heredoc with an interpolated table' => ["<?php \$sql = <<<SQL\nDELETE FROM {\$t} WHERE click_id < 5\nSQL;", 'unread'],
            'a nowdoc' => ["<?php \$sql = <<<'SQL'\nUPDATE 202_attribution_credits SET credit = 0\nSQL;", 'write'],
            'lower case' => ["<?php \$db->query('delete from 202_clicks where click_id < 5');", 'write'],
            'a leading comment' => ["<?php \$db->query('/* retention */ DELETE FROM 202_clicks');", 'write'],
            'a backticked name' => ['<?php $db->query("INSERT INTO `202_clicks_tracking` SET click_id = 1");', 'write'],
            'a multi-table delete' => ["<?php \$db->query('DELETE j FROM 202_attribution_journeys j JOIN x ON 1');", 'write'],
        ];
    }

    /** @dataProvider spellings */
    public function testTheScanReadsEverySpellingOfASummedTable(string $source, string $kind): void
    {
        $found = self::findings($source);
        self::assertContains($kind, array_column($found, 1), 'found as ' . $kind . ': ' . print_r($found, true));
    }

    /** @return array<string, array{0: string}> */
    public static function notWrites(): array
    {
        return [
            'a read' => ["<?php \$db->query('SELECT * FROM 202_clicks WHERE click_id = 5');"],
            'a read assembled' => ["<?php \$db->query('SELECT * FROM ' . \$t . ' JOIN 202_clicks_advance USING (click_id)');"],
            'another table written' => ["<?php \$db->query('DELETE FROM 202_users WHERE user_id = 5');"],
            'another table, by its constant' => ["<?php use Prosper202\\Database\\Schema\\TableRegistry;\n\$db->query('DELETE FROM ' . TableRegistry::USERS . ' WHERE 1');"],
            'another table named' => ["<?php \$t = '202_clicks_spy';"],
            'a qualified column' => ["<?php \$c = '202_clicks.click_id';"],
            'a table in prose' => ["<?php \$m = 'the rows of 202_clicks stay';"],
            'a keyword as a word' => ["<?php \$action = 'update'; \$label = 'Delete';"],
            'an upsert\'s column' => ["<?php \$db->query('INSERT INTO 202_users SET a = 1 ON DUPLICATE KEY UPDATE ' . \$col . ' = 2');"],
        ];
    }

    /** @dataProvider notWrites */
    public function testTheScanLeavesReadsAndOtherTablesAlone(string $source): void
    {
        self::assertSame([], self::findings($source));
    }

    public function testEveryWriterOfASummedTableIsClassified(): void
    {
        $found = self::writers();
        self::assertArrayHasKey('tracking202/redirect/rtr.php', $found, 'the scan sees the redirect that rewrites clicks');

        $unclassified = array_diff_key($found, self::WRITERS);
        self::assertSame([], $unclassified, "a file writes a table the report rollup sums and is not classified here:\n"
            . print_r($unclassified, true)
            . 'Mark the change with RollupDirty in its transaction, or add the file with the reason it need not.');

        $stale = array_diff_key(self::WRITERS, $found);
        self::assertSame([], $stale, 'classified files with no such statement left; drop them from the list');
    }

    public function testTheWritersClassifiedAsMarkingDoMark(): void
    {
        foreach (self::WRITERS as $file => $kind) {
            $src = (string) file_get_contents(self::ROOT . $file);
            if ($kind === self::MARKS) {
                self::assertMatchesRegularExpression('/\\\\?(Prosper202\\\\Report\\\\)?RollupDirty::(hours|timeRange|hourRuns|click|clickCost|clickOfAnyAccount|clicksDeleted|conversion)\(/', $src, "$file is classified as marking and calls no RollupDirty mark");
            } elseif ($kind === self::ROLLUP) {
                self::assertStringContainsString('202_attribution_rollup', $src, "$file is classified as deleting the rollup's rows and names none");
            }
        }
    }
}
