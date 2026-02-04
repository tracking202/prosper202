<?php

declare(strict_types=1);

require_once __DIR__ . '/../202-config/connect.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Prosper202\Attribution\AttributionServiceFactory;

$processor = AttributionServiceFactory::createExportProcessor();
$results = $processor->processPending(10);

foreach ($results as $result) {
    $status = strtoupper((string) ($result['status'] ?? 'unknown'));
    $exportId = $result['export_id'] ?? 'n/a';
    $message = $result['error'] ?? ($result['webhook_error'] ?? '');

    if ($message !== '') {
        fwrite(STDOUT, sprintf("[%s] Export %s: %s\n", $status, $exportId, $message));
    } else {
        fwrite(STDOUT, sprintf("[%s] Export %s processed.\n", $status, $exportId));
    }
}
