<?php

declare(strict_types=1);

/**
 * Migration script to create attribution models tables
 * Run this script once to set up the attribution system
 */

// Include database connection
include_once dirname(__DIR__) . '/connect.php';

// Ensure we have database connection
if (!isset($db) || !($db instanceof mysqli)) {
    die("Error: Database connection not available\n");
}

echo "Starting attribution models migration...\n";

try {
    // Read the SQL file
    $sqlFile = __DIR__ . '/create_attribution_models_table.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: {$sqlFile}");
    }
    
    $sql = file_get_contents($sqlFile);
    
    if ($sql === false) {
        throw new Exception("Failed to read migration file");
    }
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map(trim(...), explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^\s*--/', (string) $stmt);
        }
    );
    
    echo "Found " . count($statements) . " SQL statements to execute...\n";
    
    // Execute each statement
    $db->begin_transaction();
    
    foreach ($statements as $index => $statement) {
        echo "Executing statement " . ($index + 1) . "...\n";
        
        $result = $db->query($statement);
        
        if (!$result) {
            throw new Exception("SQL Error in statement " . ($index + 1) . ": " . $db->error);
        }
        
        // Show affected rows for data manipulation statements
        if ($db->affected_rows > 0) {
            echo "  -> Affected {$db->affected_rows} rows\n";
        }
    }
    
    $db->commit();
    
    echo "\nMigration completed successfully!\n";
    echo "Attribution models tables have been created.\n";
    
    // Verify table creation
    $tables = ['202_attribution_models', '202_attribution_snapshots', '202_attribution_touchpoints', '202_attribution_settings'];
    
    echo "\nVerifying table creation...\n";
    foreach ($tables as $table) {
        $result = $db->query("SHOW TABLES LIKE '{$table}'");
        if ($result && $result->num_rows > 0) {
            echo "  ✓ {$table}\n";
        } else {
            echo "  ✗ {$table} - NOT FOUND\n";
        }
    }
    
    // Show default models created
    echo "\nChecking default attribution models...\n";
    $result = $db->query("SELECT user_id, model_name, model_type, is_default FROM 202_attribution_models WHERE is_default = 1 ORDER BY user_id LIMIT 10");
    
    if ($result && $result->num_rows > 0) {
        echo "Default models created:\n";
        while ($row = $result->fetch_assoc()) {
            echo "  User {$row['user_id']}: {$row['model_name']} ({$row['model_type']})\n";
        }
        
        if ($result->num_rows >= 10) {
            $totalResult = $db->query("SELECT COUNT(*) as total FROM 202_attribution_models WHERE is_default = 1");
            $totalRow = $totalResult->fetch_assoc();
            echo "  ... and " . ($totalRow['total'] - 10) . " more users\n";
        }
    }
    
} catch (Exception $e) {
    $db->rollback();
    echo "\nMigration failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✓ Attribution system is ready to use!\n";
echo "You can now access Attribution Models in the Setup section.\n";