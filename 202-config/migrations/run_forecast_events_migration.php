<?php

declare(strict_types=1);

/**
 * Migration script to create the forecast_events table and seed US holidays.
 * Run this script once to set up the forecast events system.
 */

include_once dirname(__DIR__) . '/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    die("Error: Database connection not available\n");
}

echo "Starting forecast events migration...\n";

try {
    $sqlFile = __DIR__ . '/create_forecast_events_table.sql';

    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: {$sqlFile}");
    }

    $sql = file_get_contents($sqlFile);

    if ($sql === false) {
        throw new Exception("Failed to read migration file");
    }

    $statements = array_filter(
        array_map(trim(...), explode(';', $sql)),
        function ($stmt) {
            return !empty($stmt) && !preg_match('/^\s*--/', (string) $stmt);
        }
    );

    echo "Found " . count($statements) . " SQL statements to execute...\n";

    $db->begin_transaction();

    foreach ($statements as $index => $statement) {
        echo "Executing statement " . ($index + 1) . "...\n";

        $result = $db->query($statement);

        if (!$result) {
            throw new Exception("SQL Error in statement " . ($index + 1) . ": " . $db->error);
        }

        if ($db->affected_rows > 0) {
            echo "  -> Affected {$db->affected_rows} rows\n";
        }
    }

    $db->commit();

    echo "\nMigration completed successfully!\n";

    // Verify table creation
    echo "\nVerifying table creation...\n";
    $result = $db->query("SHOW TABLES LIKE '202_forecast_events'");
    if ($result && $result->num_rows > 0) {
        echo "  ✓ 202_forecast_events\n";
    } else {
        echo "  ✗ 202_forecast_events - NOT FOUND\n";
    }

    // Show seed data summary
    echo "\nChecking seeded events...\n";
    $result = $db->query("SELECT event_name, COUNT(*) as occurrences FROM 202_forecast_events GROUP BY event_name ORDER BY event_name");

    if ($result && $result->num_rows > 0) {
        echo "Seeded events:\n";
        while ($row = $result->fetch_assoc()) {
            echo "  {$row['event_name']}: {$row['occurrences']} occurrence(s)\n";
        }
    }

} catch (Exception $e) {
    $db->rollback();
    echo "\nMigration failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✓ Forecast events system is ready to use!\n";
echo "Manage events via: p202 forecast-event list|create|update|delete\n";
