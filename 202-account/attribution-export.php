<?php

declare(strict_types=1);

include_once __DIR__ . '/../202-config/connect.php';
require_once __DIR__ . '/../vendor/autoload.php';

AUTH::require_user();

global $userObj;

if (!isset($userObj) || !$userObj->hasPermission('view_attribution_reports')) {
    http_response_code(403);
    echo 'Permission denied.';
    return;
}

$exportId = isset($_GET['export_id']) ? (int) $_GET['export_id'] : 0;
$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

if ($exportId <= 0 || $token === '') {
    http_response_code(400);
    echo 'Missing export identifier.';
    return;
}

$database = DB::getInstance();
$writeConnection = $database?->getConnection();
$readConnection = $database?->getConnectionro();

if (!$writeConnection instanceof \mysqli) {
    http_response_code(500);
    echo 'Database connection unavailable.';
    return;
}

$repository = new \Prosper202\Attribution\Repository\Mysql\MysqlExportRepository($writeConnection, $readConnection);
$job = $repository->findById($exportId);

if ($job === null || $job->userId !== (int) ($_SESSION['user_id'] ?? 0) || $job->downloadToken !== $token) {
    http_response_code(404);
    echo 'Export not found.';
    return;
}

if ($job->status !== \Prosper202\Attribution\Export\ExportStatus::COMPLETED || $job->filePath === null || !is_file($job->filePath)) {
    http_response_code(404);
    echo 'Export file is not available.';
    return;
}

$format = $job->format->value;
$filename = basename($job->filePath);
$mimeType = $format === 'xls' ? 'application/vnd.ms-excel' : 'text/csv';

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($job->filePath));

readfile($job->filePath);
