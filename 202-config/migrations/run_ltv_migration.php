<?php

declare(strict_types=1);

/**
 * Migration script for the LTV (customer lifetime value) feature.
 *
 * Creates the LTV tables (customers, aliases, custom fields, revenue ledger,
 * products/line items, subscriptions, integrations, webhooks) and adds the
 * LTV columns to existing tables:
 *   - 202_conversion_logs.customer_id (+ index)
 *   - 202_clicks_tracking.customer_id (+ index)
 *   - 202_users_pref.user_ltv_customer_cparam
 *
 * The table DDL comes from LtvTables::getDefinitions() — the same definitions
 * used on fresh installs — so this script cannot drift from the installer.
 * All statements are idempotent (CREATE IF NOT EXISTS; ALTERs guarded by
 * INFORMATION_SCHEMA checks), so re-running is safe.
 *
 * Operational note for large installs: the ADD COLUMN statements are nullable
 * with no backfill, which MySQL 8.0 executes as ALGORITHM=INSTANT; the added
 * indexes build INPLACE (online). 202_conversion_logs is the only large table
 * touched.
 */

include_once dirname(__DIR__) . '/connect.php';

use Prosper202\Database\Tables\LtvTables;

if (!isset($db) || !($db instanceof mysqli)) {
    die("Error: Database connection not available\n");
}

echo "Starting LTV migration...\n";

/**
 * @return bool True if the column exists on the table in the current schema.
 */
function ltv_column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    if ($stmt === false) {
        throw new Exception('Failed to prepare column check: ' . $db->error);
    }
    $stmt->bind_param('ss', $table, $column);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Failed to check column ' . $table . '.' . $column . ': ' . $db->error);
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return ((int) ($row['c'] ?? 0)) > 0;
}

/**
 * @return bool True if the index exists on the table in the current schema.
 */
function ltv_index_exists(mysqli $db, string $table, string $index): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    if ($stmt === false) {
        throw new Exception('Failed to prepare index check: ' . $db->error);
    }
    $stmt->bind_param('ss', $table, $index);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Failed to check index ' . $table . '.' . $index . ': ' . $db->error);
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return ((int) ($row['c'] ?? 0)) > 0;
}

function ltv_run(mysqli $db, string $sql, string $label): void
{
    echo "  {$label}...\n";
    $result = $db->query($sql);
    if ($result === false) {
        throw new Exception("SQL error in '{$label}': " . $db->error);
    }
}

try {
    // --- 1. New tables (DDL shared with the fresh-install SchemaInstaller) ---
    echo "Creating LTV tables...\n";
    foreach (LtvTables::getDefinitions() as $definition) {
        ltv_run($db, $definition->createStatement, "create {$definition->tableName}");
    }

    // --- 2. Columns on existing tables (guarded; MySQL has no ADD COLUMN IF NOT EXISTS) ---
    echo "Adding LTV columns to existing tables...\n";

    if (!ltv_column_exists($db, '202_conversion_logs', 'customer_id')) {
        ltv_run(
            $db,
            'ALTER TABLE `202_conversion_logs` ADD COLUMN `customer_id` bigint(20) unsigned DEFAULT NULL',
            'add 202_conversion_logs.customer_id'
        );
    } else {
        echo "  202_conversion_logs.customer_id already exists — skipping\n";
    }
    if (!ltv_index_exists($db, '202_conversion_logs', 'customer_id')) {
        ltv_run(
            $db,
            'ALTER TABLE `202_conversion_logs` ADD KEY `customer_id` (`customer_id`)',
            'index 202_conversion_logs.customer_id'
        );
    }

    if (!ltv_column_exists($db, '202_clicks_tracking', 'customer_id')) {
        ltv_run(
            $db,
            'ALTER TABLE `202_clicks_tracking` ADD COLUMN `customer_id` bigint(20) unsigned DEFAULT NULL',
            'add 202_clicks_tracking.customer_id'
        );
    } else {
        echo "  202_clicks_tracking.customer_id already exists — skipping\n";
    }
    if (!ltv_index_exists($db, '202_clicks_tracking', 'customer_id')) {
        ltv_run(
            $db,
            'ALTER TABLE `202_clicks_tracking` ADD KEY `customer_id` (`customer_id`)',
            'index 202_clicks_tracking.customer_id'
        );
    }

    if (!ltv_column_exists($db, '202_users_pref', 'user_ltv_customer_cparam')) {
        ltv_run(
            $db,
            "ALTER TABLE `202_users_pref` ADD COLUMN `user_ltv_customer_cparam` tinyint(1) unsigned NOT NULL DEFAULT '0'",
            'add 202_users_pref.user_ltv_customer_cparam'
        );
    } else {
        echo "  202_users_pref.user_ltv_customer_cparam already exists — skipping\n";
    }

    if (!ltv_column_exists($db, '202_users_pref', 'user_ltv_personalization_fields')) {
        ltv_run(
            $db,
            "ALTER TABLE `202_users_pref` ADD COLUMN `user_ltv_personalization_fields` varchar(500) NOT NULL DEFAULT ''",
            'add 202_users_pref.user_ltv_personalization_fields'
        );
    } else {
        echo "  202_users_pref.user_ltv_personalization_fields already exists — skipping\n";
    }

    // --- 3. Verify ---
    echo "\nVerifying...\n";
    $allOk = true;
    foreach (LtvTables::getDefinitions() as $definition) {
        $result = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($definition->tableName) . "'");
        if ($result && $result->num_rows > 0) {
            echo "  ✓ {$definition->tableName}\n";
        } else {
            echo "  ✗ {$definition->tableName} - NOT FOUND\n";
            $allOk = false;
        }
    }
    foreach ([
        ['202_conversion_logs', 'customer_id'],
        ['202_clicks_tracking', 'customer_id'],
        ['202_users_pref', 'user_ltv_customer_cparam'],
        ['202_users_pref', 'user_ltv_personalization_fields'],
    ] as [$table, $column]) {
        if (ltv_column_exists($db, $table, $column)) {
            echo "  ✓ {$table}.{$column}\n";
        } else {
            echo "  ✗ {$table}.{$column} - NOT FOUND\n";
            $allOk = false;
        }
    }

    if (!$allOk) {
        throw new Exception('Verification failed — see missing items above.');
    }
} catch (Exception $e) {
    echo "\nMigration failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✓ LTV schema is ready.\n";
echo "Next: run 202-config/migrations/run_ltv_backfill.php to link historical\n";
echo "conversions for accounts using the c1-c4 customer fallback.\n";
