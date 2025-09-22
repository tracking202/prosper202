<?php
/**
 * Dashboard Data Sync Cron Job
 * 
 * Synchronizes dashboard content from my.tracking202.com API
 * and caches it locally for fast page loads.
 * 
 * Run this via cron every 15-30 minutes.
 * Example (quarter-hour cadence): 0,15,30,45 * * * * /usr/bin/php /path/to/prosper202/202-cronjobs/sync-dashboard-data.php
 */

declare(strict_types=1);

// Include required files
include_once(str_repeat("../", 1) . '202-config/connect.php');
include_once(str_repeat("../", 1) . '202-config/DashboardAPI.class.php');
include_once(str_repeat("../", 1) . '202-config/DashboardDataManager.class.php');

// Lock file to prevent concurrent executions
$lockFile = __DIR__ . '/dashboard-sync.lock';

// Check if another sync is already running
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    $maxLockAge = 300; // 5 minutes
    
    if (time() - $lockTime < $maxLockAge) {
        echo "Sync already in progress, exiting.\n";
        exit(0);
    } else {
        // Lock file is stale, remove it
        unlink($lockFile);
    }
}

// Create lock file
touch($lockFile);

// Function to cleanup on exit
function cleanup() {
    global $lockFile;
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

// Register cleanup function
register_shutdown_function('cleanup');

// Set up error handling
set_error_handler(function($severity, $message, $file, $line): void {
    error_log("Dashboard Sync Error: {$message} in {$file} on line {$line}");
});

try {
    echo "Starting dashboard data sync at " . date('Y-m-d H:i:s') . "\n";
    
    // Initialize the data manager
    $dataManager = new DashboardDataManager();
    
    // Content types to sync with their frequencies (in minutes)
    $contentConfig = [
        'alerts' => 15,    // Every 15 minutes
        'tweets' => 30,    // Every 30 minutes  
        'posts' => 60,     // Every hour
        'meetups' => 60,   // Every hour
        'sponsors' => 1440 // Once a day (24 hours)
    ];
    
    $syncResults = [];
    $overallSuccess = true;
    
    foreach ($contentConfig as $contentType => $frequencyMinutes) {
        echo "Checking {$contentType}...\n";
        
        // Check if sync is needed based on frequency
        $status = $dataManager->getSyncStatus($contentType);
        $needsSync = false;
        
        if (!$status || !$status['last_success']) {
            $needsSync = true; // Never synced before
        } else {
            $lastSync = strtotime((string) $status['last_success']);
            $minsSinceLastSync = (time() - $lastSync) / 60;
            
            if ($minsSinceLastSync >= $frequencyMinutes) {
                $needsSync = true;
            }
        }
        
        if ($needsSync) {
            echo "Syncing {$contentType}...\n";
            $result = $dataManager->syncContentType($contentType);
            $syncResults[$contentType] = $result;
            
            if ($result) {
                echo "✓ {$contentType} synced successfully\n";
            } else {
                echo "✗ {$contentType} sync failed\n";
                $overallSuccess = false;
            }
        } else {
            echo "→ {$contentType} is up to date\n";
            $syncResults[$contentType] = 'skipped';
        }
    }
    
    // Log overall results
    $successCount = count(array_filter($syncResults, fn($result) => $result === true));
    $failCount = count(array_filter($syncResults, fn($result) => $result === false));
    $skipCount = count(array_filter($syncResults, fn($result) => $result === 'skipped'));
    
    echo "\nSync completed at " . date('Y-m-d H:i:s') . "\n";
    echo "Results: {$successCount} synced, {$failCount} failed, {$skipCount} skipped\n";
    
    // Log to system log for monitoring
    $logMessage = "Dashboard sync completed: {$successCount} synced, {$failCount} failed, {$skipCount} skipped";
    error_log($logMessage);
    
    if ($overallSuccess || $failCount === 0) {
        exit(0); // Success
    } else {
        exit(1); // Some syncs failed
    }
    
} catch (Exception $e) {
    $errorMsg = "Dashboard sync failed with exception: " . $e->getMessage();
    echo $errorMsg . "\n";
    error_log($errorMsg);
    exit(1);
} catch (Error $e) {
    $errorMsg = "Dashboard sync failed with fatal error: " . $e->getMessage();
    echo $errorMsg . "\n";
    error_log($errorMsg);
    exit(1);
} finally {
    // Cleanup is handled by shutdown function
}
