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
 * "Writes" is read from the string literals: a statement that begins with
 * INSERT, REPLACE, UPDATE, DELETE, TRUNCATE, DROP, ALTER, RENAME or CREATE
 * and names a source table, and any literal that is exactly a source
 * table's name (a table picked by a variable: `foreach (['202_clicks', …])`).
 * A new file with such a statement fails until it is classified; a
 * classified file with none left fails too, so the list cannot rot.
 *
 * What this cannot see: it classifies files, not statements, so a second
 * writer added to a file already classified `marks` is trusted to mark as
 * well — RollupMatchesFullComputationTest exercises the writers that carry
 * the rollup's correctness (the worker, retraction, replacement, reversal,
 * revival, model and override changes, a rewritten click, a CPC update) and
 * catches an unmarked one there. SQL built without a literal naming the
 * table (a table name read from configuration) is invisible to it.
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
        'tracking202/update/cpc.php' => self::MARKS,
        '202-config/Attribution/ModelRepository.php' => self::ROLLUP,
        '202-config/User/UserDataPurge.php' => self::ROLLUP,
        '202-config/Report/RollupDirty.php' => 'the marks themselves: they read the click rows they mark',
        '202-config/Click/MysqlClickRepository.php' => 'inserts a new click at the time of the request that records it; its hour is not summed until it is sealed',
        '202-config/connect2.php' => 'inserts new clicks (and new device models) at the time of the request that records them',
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
        'tracking202/update/subids.php' => 'writes click_filtered, which no sum reads',
        'tracking202/update/delete-subids.php' => 'writes click_filtered, which no sum reads',
    ];

    /** @return array<string, list<string>> file => the statements found */
    private static function writers(): array
    {
        $tables = implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), self::SOURCES));
        $names = '/\b(' . $tables . ')\b(?!_)/';
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            $rel = substr($path, strlen(self::ROOT));
            if (!str_ends_with($rel, '.php') || preg_match('#^(vendor|tests|node_modules|sdk|go-cli|\.git|\.claude)/#', $rel) === 1) {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($path)) as $t) {
                if (!is_array($t) || !in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                if (preg_match($names, $t[1]) !== 1) {
                    continue;
                }
                $bare = in_array(trim($t[1], '\'"'), self::SOURCES, true);
                $write = preg_match('/^\s*["\']?\s*(INSERT|REPLACE|UPDATE|DELETE|TRUNCATE|DROP|ALTER|RENAME|CREATE)\b/i', $t[1]) === 1;
                if ($bare || $write) {
                    $found[$rel][] = $t[2] . ': ' . preg_replace('/\s+/', ' ', substr($t[1], 0, 100));
                }
            }
        }
        ksort($found);

        return $found;
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
                self::assertMatchesRegularExpression('/\\\\?(Prosper202\\\\Report\\\\)?RollupDirty::(hours|timeRange|click|clickCost|clickOfAnyAccount|conversion)\(/', $src, "$file is classified as marking and calls no RollupDirty mark");
            } elseif ($kind === self::ROLLUP) {
                self::assertStringContainsString('202_attribution_rollup', $src, "$file is classified as deleting the rollup's rows and names none");
            }
        }
    }
}
