<?php

declare(strict_types=1);

use Prosper202\Attribution\ExportFormat;
use Prosper202\Attribution\Repository\Mysql\MysqlExportJobRepository;

include_once str_repeat('../', 1) . '202-config/connect.php';

AUTH::require_user();

if (!isset($userObj) || !$userObj->hasPermission('manage_attribution_models')) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$exportId = isset($_GET['export_id']) ? (int) $_GET['export_id'] : 0;
if ($exportId <= 0) {
    http_response_code(400);
    echo 'Invalid export identifier provided.';
    exit;
}

$database = DB::getInstance();
$writeConnection = $database?->getConnection();
$readConnection = $database?->getConnectionro();

if (!$writeConnection instanceof mysqli) {
    http_response_code(500);
    echo 'Database connection unavailable.';
    exit;
}

$repository = new MysqlExportJobRepository($writeConnection, $readConnection);
$job = $repository->findById($exportId);

if ($job === null || $job->userId !== (int) $_SESSION['user_id']) {
    http_response_code(404);
    echo 'Export job not found.';
    exit;
}

if ($job->filePath === null || !is_file($job->filePath)) {
    http_response_code(404);
    echo 'Export file is not available. The job may still be running.';
    exit;
}

$format = $job->format;
$contentType = $format === ExportFormat::XLS
    ? 'application/vnd.ms-excel'
    : 'text/csv';

$filename = basename($job->filePath);
header('Content-Type: ' . $contentType);
header('Content-Length: ' . (string) filesize($job->filePath));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($job->filePath);
