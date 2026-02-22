<?php
declare(strict_types=1);

/**
 * Health check endpoint for load balancers and container orchestration.
 *
 * GET /health/
 *   Returns 200 + JSON when the app and database are reachable.
 *   Returns 503 + JSON when the database is down (tracking may still
 *   work via memcache fallback, but the system is degraded).
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$status = ['status' => 'ok', 'checks' => []];
$httpCode = 200;

// ── Database check ───────────────────────────────────────
$configFile = dirname(__DIR__) . '/202-config.php';
if (file_exists($configFile)) {
    /** @noinspection PhpIncludeInspection */
    include_once $configFile;

    if (isset($db) && $db instanceof mysqli && $db->ping()) {
        $status['checks']['database'] = 'ok';
    } else {
        $status['checks']['database'] = 'unreachable';
        $status['status'] = 'degraded';
        $httpCode = 503;
    }
} else {
    $status['checks']['database'] = 'not_configured';
    $status['status'] = 'degraded';
    $httpCode = 503;
}

// ── Memcached check ──────────────────────────────────────
if (extension_loaded('memcached') && isset($mchost) && $mchost !== '' && $mchost !== 'localhostmemcache') {
    $mc = new Memcached();
    $mc->addServer($mchost, 11211);
    $mcStats = $mc->getStats();
    $status['checks']['memcached'] = !empty($mcStats) ? 'ok' : 'unreachable';
} else {
    $status['checks']['memcached'] = 'not_configured';
}

http_response_code($httpCode);
echo json_encode($status, JSON_UNESCAPED_SLASHES);
