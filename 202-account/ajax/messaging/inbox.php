<?php

declare(strict_types=1);

include_once(str_repeat('../', 3) . '202-config/connect.php');

AUTH::require_user();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$service = MessagingService::forUser($userId);

if ($service === null) {
    echo json_encode(['ok' => true, 'unread_count' => 0, 'conversations' => []]);
    exit;
}

// Throttled internally — most polls skip the network and just read local cache.
$service->sync();

$inbox = $service->getInbox();
echo json_encode([
    'ok'            => true,
    'unread_count'  => $inbox['unread_count'],
    'conversations' => $inbox['conversations'],
]);
