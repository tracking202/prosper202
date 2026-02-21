<?php
declare(strict_types=1);

use Prosper202\Database\Tables\TrackingCxTables;

/**
 * Migrate c1–c4 tracking data into the new EAV tables.
 *
 * Designed to run incrementally — safe to interrupt and resume.
 * Uses INSERT IGNORE so re-processing a batch is harmless.
 *
 * Usage:
 *   php 202-config/migrations/run_tracking_cx_migration.php [--batch-size=5000] [--sleep=50] [--dry-run]
 *
 * Must be run from the Prosper202 root directory.
 */

// ── CLI options ──────────────────────────────────────────────

$options = getopt('', ['batch-size:', 'sleep:', 'dry-run']);
$batchSize = max(100, (int) ($options['batch-size'] ?? 5000));
$sleepMs   = max(0, (int) ($options['sleep'] ?? 50));
$dryRun    = isset($options['dry-run']);

echo "Prosper202 Tracking CX Migration (c1-c4 → EAV)\n";
echo "================================================\n\n";

if ($dryRun) {
    echo "[DRY RUN] No data will be written.\n\n";
}

// ── Bootstrap ────────────────────────────────────────────────

if (!file_exists('202-config.php')) {
    fwrite(STDERR, "Error: 202-config.php not found. Run from the Prosper202 root directory.\n");
    fwrite(STDERR, "Usage: php 202-config/migrations/run_tracking_cx_migration.php\n");
    exit(1);
}

require_once '202-config.php';

// The config file exposes $db via the DB singleton.
// Verify we have a working connection.
if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Error: No database connection. Check 202-config.php.\n");
    exit(1);
}

if ($db->connect_error) {
    fwrite(STDERR, "Database connection failed: " . $db->connect_error . "\n");
    exit(1);
}

echo "Database connected.\n\n";

// ── Helpers ──────────────────────────────────────────────────

/**
 * Execute a query, die with context on failure.
 */
function mig_query(mysqli $db, string $sql, string $context): mysqli_result|bool
{
    $result = $db->query($sql);
    if ($result === false) {
        throw new RuntimeException("{$context}: {$db->error}\nSQL: {$sql}");
    }
    return $result;
}

/**
 * Read or initialise migration state.
 *
 * @return array{last_processed_id: int, total_rows: int, completed_at: ?string}
 */
function get_migration_state(mysqli $db, string $name): array
{
    $stmt = $db->prepare(
        "SELECT last_processed_id, total_rows, completed_at
         FROM 202_migration_state WHERE migration_name = ?"
    );
    if (!$stmt) {
        throw new RuntimeException("prepare failed: " . $db->error);
    }
    $stmt->bind_param('s', $name);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException("execute failed: " . $stmt->error);
    }
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row) {
        return [
            'last_processed_id' => (int) $row['last_processed_id'],
            'total_rows'        => (int) $row['total_rows'],
            'completed_at'      => $row['completed_at'],
        ];
    }

    return ['last_processed_id' => 0, 'total_rows' => 0, 'completed_at' => null];
}

/**
 * Upsert migration progress.
 */
function save_migration_state(mysqli $db, string $name, int $lastId, int $total, bool $complete): void
{
    $now = date('Y-m-d H:i:s');
    $completedAt = $complete ? $now : null;

    $stmt = $db->prepare(
        "INSERT INTO 202_migration_state
                (migration_name, last_processed_id, total_rows, started_at, updated_at, completed_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                last_processed_id = VALUES(last_processed_id),
                total_rows        = VALUES(total_rows),
                updated_at        = VALUES(updated_at),
                completed_at      = VALUES(completed_at)"
    );
    if (!$stmt) {
        throw new RuntimeException("Failed to prepare migration state: " . $db->error);
    }
    $stmt->bind_param('siisss', $name, $lastId, $total, $now, $now, $completedAt);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException("Failed to save migration state: " . $stmt->error);
    }
    $stmt->close();
}

// ── Phase 0: Create tables ──────────────────────────────────

echo "Phase 0: Creating tables (if needed)...\n";

$definitions = TrackingCxTables::getDefinitions();

if (!$dryRun) {
    foreach ($definitions as $definition) {
        $result = $db->query($definition->createStatement);
        if (!$result) {
            fwrite(STDERR, "Table creation failed ({$definition->tableName}): " . $db->error . "\n");
            exit(1);
        }
    }
}

echo "  Tables ready.\n\n";

// ── Phase 1: Migrate value tables ───────────────────────────

echo "Phase 1: Migrating c1–c4 values into 202_tracking_cx...\n";

$cVars = ['c1', 'c2', 'c3', 'c4'];

foreach ($cVars as $cName) {
    $table = "202_tracking_{$cName}";
    $idCol = "{$cName}_id";

    // Verify source table exists
    $check = $db->query("SHOW TABLES LIKE '{$table}'");
    if (!$check || $check->num_rows === 0) {
        echo "  Skipping {$cName}: table {$table} does not exist.\n";
        continue;
    }

    // Count source rows
    $countResult = mig_query($db, "SELECT COUNT(*) AS cnt FROM `{$table}`", "count {$table}");
    $countRow = $countResult->fetch_assoc();
    $sourceCount = (int) $countRow['cnt'];

    if ($dryRun) {
        echo "  [DRY RUN] Would copy {$sourceCount} values from {$table}.\n";
        continue;
    }

    // Bulk insert, skipping duplicates
    $sql = "INSERT IGNORE INTO 202_tracking_cx (cx_name, cx_value)
            SELECT '{$cName}', `{$cName}` FROM `{$table}`";
    mig_query($db, $sql, "copy values from {$table}");
    $inserted = $db->affected_rows;

    echo "  {$cName}: {$inserted} new / {$sourceCount} total values.\n";
}

echo "  Done.\n\n";

// ── Phase 2: Migrate junction data (batched) ────────────────

echo "Phase 2: Migrating 202_clicks_tracking → 202_clicks_tracking_cx...\n";
echo "  Batch size: {$batchSize}, sleep: {$sleepMs}ms\n";

$migrationName = 'tracking_c1c4_to_cx';

// Verify source table exists
$check = $db->query("SHOW TABLES LIKE '202_clicks_tracking'");
if (!$check || $check->num_rows === 0) {
    echo "  Source table 202_clicks_tracking does not exist. Nothing to migrate.\n";
    exit(0);
}

// Get total click count and max click_id
$statsResult = mig_query(
    $db,
    "SELECT COUNT(*) AS cnt, COALESCE(MAX(click_id), 0) AS max_id FROM 202_clicks_tracking",
    "count clicks_tracking"
);
$stats = $statsResult->fetch_assoc();
$totalClicks = (int) $stats['cnt'];
$maxClickId  = (int) $stats['max_id'];

if ($totalClicks === 0) {
    echo "  No clicks to migrate.\n";
    if (!$dryRun) {
        save_migration_state($db, $migrationName, 0, 0, true);
    }
    echo "\nMigration complete.\n";
    exit(0);
}

echo "  Total clicks: {$totalClicks}, max click_id: {$maxClickId}\n";

// Check previous progress
$state = get_migration_state($db, $migrationName);

if ($state['completed_at'] !== null) {
    echo "  Migration already completed at {$state['completed_at']}.\n";
    echo "  To re-run, DELETE FROM 202_migration_state WHERE migration_name = '{$migrationName}';\n";
    exit(0);
}

$cursor = $state['last_processed_id'];
if ($cursor > 0) {
    echo "  Resuming from click_id > {$cursor}\n";
}

if ($dryRun) {
    $remaining = mig_query(
        $db,
        "SELECT COUNT(*) AS cnt FROM 202_clicks_tracking WHERE click_id > {$cursor}",
        "count remaining"
    )->fetch_assoc();
    echo "  [DRY RUN] Would migrate {$remaining['cnt']} remaining clicks.\n";
    exit(0);
}

// Save initial state
save_migration_state($db, $migrationName, $cursor, $totalClicks, false);

$batchNum  = 0;
$totalMigrated = 0;
$startTime = microtime(true);

while (true) {
    // Get the next batch boundary.
    // We select the actual click_ids in range so we know the exact upper bound.
    $batchResult = mig_query(
        $db,
        "SELECT MAX(click_id) AS batch_max, COUNT(*) AS batch_count
         FROM (
             SELECT click_id FROM 202_clicks_tracking
             WHERE click_id > {$cursor}
             ORDER BY click_id ASC
             LIMIT {$batchSize}
         ) AS batch",
        "find batch boundary"
    );
    $batch = $batchResult->fetch_assoc();
    $batchMax   = $batch['batch_max'];
    $batchCount = (int) $batch['batch_count'];

    if ($batchMax === null || $batchCount === 0) {
        break; // No more rows
    }

    $batchMax = (int) $batchMax;
    $batchNum++;
    $batchStart = $cursor + 1;

    // Insert all c-variable mappings for this batch in a single transaction
    $db->begin_transaction();

    try {
        $batchInserted = 0;

        foreach ($cVars as $cName) {
            $table = "202_tracking_{$cName}";
            $idCol = "{$cName}_id";

            // Check source table exists (cached from phase 1, but be safe)
            $check = $db->query("SHOW TABLES LIKE '{$table}'");
            if (!$check || $check->num_rows === 0) {
                continue;
            }

            $sql = "INSERT IGNORE INTO 202_clicks_tracking_cx (click_id, cx_id)
                    SELECT ct.click_id, cx.cx_id
                    FROM 202_clicks_tracking ct
                    JOIN `{$table}` tv ON tv.`{$idCol}` = ct.`{$idCol}`
                    JOIN 202_tracking_cx cx ON cx.cx_name = '{$cName}' AND cx.cx_value = tv.`{$cName}`
                    WHERE ct.click_id > {$cursor} AND ct.click_id <= {$batchMax}
                      AND ct.`{$idCol}` > 0";

            mig_query($db, $sql, "junction batch {$batchNum} {$cName}");
            $batchInserted += max(0, $db->affected_rows);
        }

        // Record progress BEFORE commit — if commit succeeds, state is consistent.
        // If commit fails, we'll re-process this batch (INSERT IGNORE is safe).
        save_migration_state($db, $migrationName, $batchMax, $totalClicks, false);

        if (!$db->commit()) {
            throw new RuntimeException("commit failed: " . $db->error);
        }
    } catch (Throwable $e) {
        $db->rollback();
        fwrite(STDERR, "\nBatch {$batchNum} failed: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Migration paused at click_id {$cursor}. Re-run to resume.\n");
        exit(1);
    }

    $cursor = $batchMax;
    $totalMigrated += $batchCount;

    // Progress output
    $pct = $maxClickId > 0 ? round(($cursor / $maxClickId) * 100, 1) : 100;
    $elapsed = microtime(true) - $startTime;
    $rate = $elapsed > 0 ? round($totalMigrated / $elapsed) : 0;

    echo sprintf(
        "\r  Batch %d: click_id %d..%d (%d clicks, %d junctions) — %.1f%% [%d clicks/s]",
        $batchNum,
        $batchStart,
        $batchMax,
        $batchCount,
        $batchInserted,
        $pct,
        $rate
    );

    if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
    }
}

echo "\n  Done. Migrated {$totalMigrated} clicks in {$batchNum} batches.\n\n";

// Mark complete
save_migration_state($db, $migrationName, $cursor, $totalClicks, true);

// ── Phase 3: Verify ─────────────────────────────────────────

echo "Phase 3: Verification...\n";

// Count values
$cxCount = mig_query($db, "SELECT COUNT(*) AS cnt FROM 202_tracking_cx", "count cx")
    ->fetch_assoc();
echo "  202_tracking_cx: {$cxCount['cnt']} values\n";

// Count per cx_name
foreach ($cVars as $cName) {
    $escaped = $db->real_escape_string($cName);
    $row = mig_query(
        $db,
        "SELECT COUNT(*) AS cnt FROM 202_tracking_cx WHERE cx_name = '{$escaped}'",
        "count cx {$cName}"
    )->fetch_assoc();

    $table = "202_tracking_{$cName}";
    $check = $db->query("SHOW TABLES LIKE '{$table}'");
    if ($check && $check->num_rows > 0) {
        $oldRow = mig_query($db, "SELECT COUNT(*) AS cnt FROM `{$table}`", "count old {$cName}")
            ->fetch_assoc();
        $match = ((int) $row['cnt'] === (int) $oldRow['cnt']) ? 'OK' : 'MISMATCH';
        echo "    {$cName}: {$row['cnt']} (old: {$oldRow['cnt']}) [{$match}]\n";
    } else {
        echo "    {$cName}: {$row['cnt']}\n";
    }
}

// Count junction rows
$junctionCount = mig_query(
    $db,
    "SELECT COUNT(*) AS cnt FROM 202_clicks_tracking_cx",
    "count junction"
)->fetch_assoc();
echo "  202_clicks_tracking_cx: {$junctionCount['cnt']} junction rows\n";

// Count distinct clicks in junction vs source
$srcDistinct = mig_query(
    $db,
    "SELECT COUNT(*) AS cnt FROM 202_clicks_tracking WHERE c1_id > 0 OR c2_id > 0 OR c3_id > 0 OR c4_id > 0",
    "count source clicks with data"
)->fetch_assoc();
$dstDistinct = mig_query(
    $db,
    "SELECT COUNT(DISTINCT click_id) AS cnt FROM 202_clicks_tracking_cx",
    "count dest distinct clicks"
)->fetch_assoc();
echo "  Clicks with tracking data: {$srcDistinct['cnt']} (old) → {$dstDistinct['cnt']} (new)\n";

echo "\nMigration complete.\n";
