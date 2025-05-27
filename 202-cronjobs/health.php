<?php
/**
 * Cron Job Health Check
 * 
 * This file provides status information about cron job execution.
 * Can be used for monitoring and alerting.
 */

declare(strict_types=1);

// Require authentication for health endpoint
include_once(str_repeat("../", 1) . '202-config/connect.php');
include_once(str_repeat("../", 1) . '202-config/functions-auth.php');

session_start();

// Check if user is authenticated
if (!isset($_SESSION['user_id']) || !is_authenticated()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Authentication required',
        'status' => 'unauthorized'
    ]);
    exit;
}

header('Content-Type: application/json');

try {
    $database = DB::getInstance();
    $db = $database->getConnection();
    
    if (!$db || !($db instanceof mysqli)) {
        throw new Exception("Database connection failed");
    }
    
    // Get last cron execution time
    $log_sql = "SELECT last_execution_time FROM 202_cronjob_logs WHERE id = 1";
    $log_result = $db->query($log_sql);
    
    $lastRun = null;
    $timeSinceLastRun = null;
    $status = 'unknown';
    
    if ($log_result && $log_result->num_rows > 0) {
        $log_row = $log_result->fetch_assoc();
        $lastRun = (int)$log_row['last_execution_time'];
        $timeSinceLastRun = time() - $lastRun;
        
        // Status based on time since last run
        if ($timeSinceLastRun < 120) { // Less than 2 minutes
            $status = 'healthy';
        } elseif ($timeSinceLastRun < 600) { // Less than 10 minutes
            $status = 'warning';
        } else {
            $status = 'critical';
        }
    } else {
        $status = 'never_run';
    }
    
    // Get DataEngine job status
    $de_sql = "SELECT COUNT(*) as total, SUM(processed) as done FROM 202_dataengine_job";
    $de_result = $db->query($de_sql);
    
    $dataEngineStatus = [
        'total' => 0,
        'processed' => 0,
        'pending' => 0,
        'percentage' => 0
    ];
    
    if ($de_result && $de_result->num_rows > 0) {
        $de_row = $de_result->fetch_assoc();
        $dataEngineStatus['total'] = (int)($de_row['total'] ?? 0);
        $dataEngineStatus['processed'] = (int)($de_row['done'] ?? 0);
        $dataEngineStatus['pending'] = $dataEngineStatus['total'] - $dataEngineStatus['processed'];
        
        if ($dataEngineStatus['total'] > 0) {
            $dataEngineStatus['percentage'] = round(($dataEngineStatus['processed'] / $dataEngineStatus['total']) * 100, 2);
        }
    }
    
    // Check for lock file
    $lockFile = __DIR__ . '/cron.lock';
    $isLocked = file_exists($lockFile);
    $lockAge = $isLocked ? (time() - filemtime($lockFile)) : null;
    
    // Build response
    $response = [
        'status' => $status,
        'lastRun' => $lastRun ? date('Y-m-d H:i:s', $lastRun) : null,
        'lastRunTimestamp' => $lastRun,
        'timeSinceLastRun' => $timeSinceLastRun,
        'timeSinceLastRunFormatted' => $timeSinceLastRun ? formatTimeDiff($timeSinceLastRun) : null,
        'isLocked' => $isLocked,
        'lockAge' => $lockAge,
        'lockAgeFormatted' => $lockAge ? formatTimeDiff($lockAge) : null,
        'dataEngine' => $dataEngineStatus,
        'serverTime' => date('Y-m-d H:i:s'),
        'serverTimestamp' => time()
    ];
    
    // Add recommendations
    $response['recommendations'] = [];
    
    if ($status === 'critical' || $status === 'never_run') {
        $response['recommendations'][] = 'Cron jobs have not run recently. Check AutoCron or set up server cron.';
    }
    
    if ($isLocked && $lockAge > 600) {
        $response['recommendations'][] = 'Lock file is stale. Previous cron job may have failed.';
    }
    
    if ($dataEngineStatus['pending'] > 1000) {
        $response['recommendations'][] = 'Large DataEngine backlog. Consider running manual processing.';
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage(),
        'serverTime' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}

function formatTimeDiff($seconds) {
    if ($seconds < 60) {
        return $seconds . ' seconds';
    } elseif ($seconds < 3600) {
        return round($seconds / 60) . ' minutes';
    } elseif ($seconds < 86400) {
        return round($seconds / 3600, 1) . ' hours';
    } else {
        return round($seconds / 86400, 1) . ' days';
    }
}