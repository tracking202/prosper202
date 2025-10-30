<?php
declare(strict_types=1);

include_once(str_repeat("../", 2) . '202-config/connect.php');

AUTH::require_user();

header('Content-Type: application/json');

$checks = [];

$checks[] = [
    'id' => 'php_version',
    'label' => 'PHP Version',
    'status' => version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'warning',
    'details' => sprintf('Detected PHP %s (minimum required 8.3.0)', PHP_VERSION),
];

$db = DB::getInstance()?->getConnection();

$checks[] = buildAttributionCronCheck($db);
$checks[] = buildAttributionExportCronCheck($db);

echo json_encode(['checks' => $checks]);

function buildAttributionCronCheck(?\mysqli $connection): array
{
    $label = 'Attribution Cronjob';
    if (!$connection instanceof mysqli) {
        return [
            'id' => 'attribution_cron',
            'label' => $label,
            'status' => 'warning',
            'details' => 'Database connection unavailable. Unable to verify attribution cronjob.',
        ];
    }

    $scriptPath = realpath(__DIR__ . '/../../202-cronjobs/attribution-rebuild.php');
    if ($scriptPath === false) {
        return [
            'id' => 'attribution_cron',
            'label' => $label,
            'status' => 'error',
            'details' => 'Cron script `202-cronjobs/attribution-rebuild.php` is missing. Restore the file before scheduling runs.',
        ];
    }

    $result = $connection->query('SELECT last_execution_time FROM 202_cronjob_logs WHERE id = 2 LIMIT 1');

    if (!$result || $result->num_rows === 0) {
        return [
            'id' => 'attribution_cron',
            'label' => $label,
            'status' => 'warning',
            'details' => 'Attribution cronjob has not recorded any successful executions yet.',
        ];
    }

    $row = $result->fetch_assoc();
    $result->close();

    $lastExecution = isset($row['last_execution_time']) ? (int) $row['last_execution_time'] : 0;

    if ($lastExecution === 0) {
        return [
            'id' => 'attribution_cron',
            'label' => $label,
            'status' => 'warning',
            'details' => 'Attribution cronjob has not recorded any successful executions yet.',
        ];
    }

    $hoursSinceRun = (time() - $lastExecution) / 3600;
    if ($hoursSinceRun > 24) {
        return [
            'id' => 'attribution_cron',
            'label' => $label,
            'status' => 'warning',
            'details' => sprintf('Last attribution rebuild ran %.1f hours ago (%s). Recommend scheduling hourly.', $hoursSinceRun, date('c', $lastExecution)),
        ];
    }

    return [
        'id' => 'attribution_cron',
        'label' => $label,
        'status' => 'ok',
        'details' => sprintf('Last attribution rebuild ran %s.', date('c', $lastExecution)),
    ];
}

function buildAttributionExportCronCheck(?\mysqli $connection): array
{
    $label = 'Attribution Export Cronjob';
    if (!$connection instanceof mysqli) {
        return [
            'id' => 'attribution_export_cron',
            'label' => $label,
            'status' => 'warning',
            'details' => 'Database connection unavailable. Unable to verify attribution export cronjob.',
        ];
    }

    $scriptPath = realpath(__DIR__ . '/../../202-cronjobs/attribution-export.php');
    if ($scriptPath === false) {
        return [
            'id' => 'attribution_export_cron',
            'label' => $label,
            'status' => 'error',
            'details' => 'Cron script `202-cronjobs/attribution-export.php` is missing. Restore the file before scheduling runs.',
        ];
    }

    $result = $connection->query('SELECT last_execution_time FROM 202_cronjob_logs WHERE id = 3 LIMIT 1');
    if (!$result || $result->num_rows === 0) {
        return [
            'id' => 'attribution_export_cron',
            'label' => $label,
            'status' => 'warning',
            'details' => 'Attribution export cronjob has not recorded any successful executions yet.',
        ];
    }

    $row = $result->fetch_assoc();
    $result->close();

    $lastExecution = isset($row['last_execution_time']) ? (int) $row['last_execution_time'] : 0;
    if ($lastExecution === 0) {
        return [
            'id' => 'attribution_export_cron',
            'label' => $label,
            'status' => 'warning',
            'details' => 'Attribution export cronjob has not recorded any successful executions yet.',
        ];
    }

    $hoursSinceRun = (time() - $lastExecution) / 3600;
    if ($hoursSinceRun > 24) {
        return [
            'id' => 'attribution_export_cron',
            'label' => $label,
            'status' => 'warning',
            'details' => sprintf('Last attribution export ran %.1f hours ago (%s). Recommend scheduling hourly.', $hoursSinceRun, date('c', $lastExecution)),
        ];
    }

    return [
        'id' => 'attribution_export_cron',
        'label' => $label,
        'status' => 'ok',
        'details' => sprintf('Last attribution export ran %s.', date('c', $lastExecution)),
    ];
}
