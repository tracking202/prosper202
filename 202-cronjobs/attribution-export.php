<?php

declare(strict_types=1);

use Prosper202\Attribution\ExportFormat;
use Prosper202\Attribution\ExportJob;
use Prosper202\Attribution\ExportStatus;
use Prosper202\Attribution\Repository\Mysql\MysqlExportJobRepository;
use Prosper202\Attribution\Repository\Mysql\MysqlSnapshotRepository;
use Prosper202\Attribution\Snapshot;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../202-config/connect.php';
require_once __DIR__ . '/../vendor/autoload.php';

$database = DB::getInstance();
$writeConnection = $database?->getConnection();
$readConnection = $database?->getConnectionro();

if (!$writeConnection instanceof mysqli) {
    fwrite(STDERR, "[attribution-export] Database connection unavailable.\n");
    exit(1);
}

$exportRepository = new MysqlExportJobRepository($writeConnection, $readConnection);
$snapshotRepository = new MysqlSnapshotRepository($writeConnection, $readConnection);

$jobs = $exportRepository->findPending(5);
if (count($jobs) === 0) {
    echo "[attribution-export] No pending export jobs found.\n";
    updateCronLog($writeConnection);
    exit(0);
}

$exportDirectory = __DIR__ . '/../202-config/temp/attribution-exports';
if (!is_dir($exportDirectory) && !mkdir($exportDirectory, 0775, true) && !is_dir($exportDirectory)) {
    fwrite(STDERR, "[attribution-export] Unable to create export directory: {$exportDirectory}\n");
    exit(1);
}

foreach ($jobs as $job) {
    $jobId = $job->exportId ?? 0;
    echo "[attribution-export] Processing job #{$jobId}...\n";

    $now = time();
    $exportRepository->markProcessing($jobId, $now);

    try {
        $fileInfo = generateExport($job, $snapshotRepository, $exportDirectory);
        $exportRepository->markCompleted($jobId, $fileInfo['path'], $fileInfo['rows'], $fileInfo['completed_at']);

        if ($job->webhook !== null) {
            $webhookResult = dispatchWebhook($job, $fileInfo);
            $exportRepository->recordWebhookAttempt($jobId, $webhookResult['attempted_at'], $webhookResult['status_code'], $webhookResult['response_body']);
            if (!$webhookResult['success']) {
                $exportRepository->markFailed($jobId, $webhookResult['error'] ?? 'Webhook delivery failed.', $webhookResult['attempted_at']);
                fwrite(STDERR, "[attribution-export] Webhook delivery failed for job #{$jobId}: {$webhookResult['error']}\n");
                continue;
            }
        }

        echo "[attribution-export] Job #{$jobId} completed with {$fileInfo['rows']} rows.\n";
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        fwrite(STDERR, "[attribution-export] Job #{$jobId} failed: {$message}\n");
        $exportRepository->markFailed($jobId, $message, time());
    }
}

updateCronLog($writeConnection);

echo "[attribution-export] Done.\n";

/**
 * @return array{path:string,rows:int,completed_at:int}
 */
function generateExport(ExportJob $job, MysqlSnapshotRepository $snapshotRepository, string $directory): array
{
    $extension = $job->format->fileExtension();
    $fileName = sprintf('attribution_export_%d_%s.%s', $job->exportId ?? time(), date('Ymd_His'), $extension);
    $filePath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

    $handle = fopen($filePath, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open export file for writing.');
    }

    $header = ['snapshot_id', 'model_id', 'scope_type', 'scope_id', 'date_hour', 'lookback_start', 'lookback_end', 'attributed_clicks', 'attributed_conversions', 'attributed_revenue', 'attributed_cost', 'created_at'];
    writeRow($handle, $job->format, $header);

    $rows = 0;
    $offset = 0;
    $limit = 500;
    do {
        $chunk = $snapshotRepository->findForRange(
            $job->modelId,
            $job->scopeType,
            $job->scopeId,
            $job->startHour,
            $job->endHour,
            $limit,
            $offset
        );

        foreach ($chunk as $snapshot) {
            $rows++;
            writeRow($handle, $job->format, snapshotToRow($snapshot));
        }

        $offset += count($chunk);
    } while (count($chunk) === $limit);

    fclose($handle);

    return [
        'path' => $filePath,
        'rows' => $rows,
        'completed_at' => time(),
    ];
}

/**
 * @return array<string, mixed>
 */
function snapshotToRow(Snapshot $snapshot): array
{
    return [
        $snapshot->snapshotId,
        $snapshot->modelId,
        $snapshot->scopeType->value,
        $snapshot->scopeId,
        $snapshot->dateHour,
        $snapshot->lookbackStart,
        $snapshot->lookbackEnd,
        $snapshot->attributedClicks,
        $snapshot->attributedConversions,
        $snapshot->attributedRevenue,
        $snapshot->attributedCost,
        $snapshot->createdAt,
    ];
}

/**
 * @param resource $handle
 * @param array<int, mixed> $row
 */
function writeRow($handle, ExportFormat $format, array $row): void
{
    if ($format === ExportFormat::XLS) {
        $line = implode("\t", array_map(static function ($value): string {
            if (is_float($value)) {
                return (string) round($value, 4);
            }
            return (string) $value;
        }, $row));
        fwrite($handle, $line . "\r\n");

        return;
    }

    fputcsv($handle, $row);
}

/**
 * @return array{success:bool,attempted_at:int,status_code:?int,response_body:?string,error?:string}
 */
function dispatchWebhook(ExportJob $job, array $fileInfo): array
{
    $webhook = $job->webhook;
    if ($webhook === null) {
        return [
            'success' => true,
            'attempted_at' => time(),
            'status_code' => null,
            'response_body' => null,
        ];
    }

    $payload = [
        'export_id' => $job->exportId,
        'model_id' => $job->modelId,
        'status' => ExportStatus::COMPLETED->value,
        'format' => $job->format->value,
        'scope_type' => $job->scopeType->value,
        'scope_id' => $job->scopeId,
        'start_hour' => $job->startHour,
        'end_hour' => $job->endHour,
        'rows_exported' => $fileInfo['rows'],
        'file_name' => basename($fileInfo['path']),
        'download_url' => '/downloads/exports/' . basename($fileInfo['path']),
        'completed_at' => $fileInfo['completed_at'],
    ];

    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    $headers = ['Content-Type: application/json'];
    foreach ($webhook->headers as $key => $value) {
        $headers[] = $key . ': ' . $value;
    }

    if ($webhook->secret !== null) {
        $signature = hash_hmac('sha256', $json, $webhook->secret);
        $headers[] = 'X-Prosper202-Signature: ' . $signature;
    }

    $ch = curl_init($webhook->url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: null;
    $error = null;

    if ($response === false) {
        $error = curl_error($ch) ?: 'Unknown cURL error.';
    }
    curl_close($ch);

    $attemptedAt = time();

    $success = $error === null && $statusCode !== null && $statusCode >= 200 && $statusCode < 300;

    return [
        'success' => $success,
        'attempted_at' => $attemptedAt,
        'status_code' => $statusCode,
        'response_body' => $response !== false ? (string) $response : null,
        'error' => $error ?? ($success ? null : ('Unexpected status code ' . ($statusCode ?? 'unknown'))),
    ];
}

function updateCronLog(mysqli $connection): void
{
    $timestamp = time();
    $connection->query('REPLACE INTO 202_cronjob_logs (id, last_execution_time) VALUES (3, ' . $timestamp . ')');
}
