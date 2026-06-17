<?php

declare(strict_types=1);

include_once(str_repeat('../', 3) . '202-config/connect.php');

require __DIR__ . '/_auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');

$conversationExternalId = isset($_GET['conversation']) ? (string) $_GET['conversation'] : '';
if ($conversationExternalId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing conversation']);
    exit;
}

$userId  = $messagingUserId;
$service = MessagingService::forUser($userId);

if ($service === null) {
    echo json_encode(['ok' => true, 'conversation' => null, 'messages' => []]);
    exit;
}

// Opening a thread marks its inbound messages read (local state only — fast).
$service->markConversationRead($conversationExternalId);

$thread = $service->getConversation($conversationExternalId);
echo json_encode([
    'ok'           => true,
    'conversation' => $thread['conversation'],
    'messages'     => $thread['messages'],
]);
