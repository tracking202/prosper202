<?php

declare(strict_types=1);

include_once(str_repeat('../', 3) . '202-config/connect.php');

require __DIR__ . '/_auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');

$userId  = $messagingUserId;
$service = MessagingService::forUser($userId);

if ($service === null) {
    echo json_encode(['ok' => true, 'unread_count' => 0, 'conversations' => []]);
    exit;
}

// Throttled internally — most polls skip the network and just read local cache.
$service->sync();

$inbox = $service->getInbox();
// Substitute invalid UTF-8 rather than letting json_encode() fail (which would
// send an empty body) when a message body contains bad bytes.
echo json_encode([
    'ok'            => true,
    'unread_count'  => $inbox['unread_count'],
    'conversations' => $inbox['conversations'],
], JSON_INVALID_UTF8_SUBSTITUTE);
