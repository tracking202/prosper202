<?php

declare(strict_types=1);

include_once(str_repeat('../', 3) . '202-config/connect.php');

require __DIR__ . '/_auth.php';

header('Content-Type: application/json');

// Shared guarded helper: fails closed when either token side is empty.
if (!AUTH::check_csrf_token()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid token']);
    exit;
}

$conversationExternalId = isset($_POST['conversation']) ? (string) $_POST['conversation'] : '';
if ($conversationExternalId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing conversation']);
    exit;
}

$userId  = $messagingUserId;
$service = MessagingService::forUser($userId);

if ($service !== null) {
    $service->markConversationRead($conversationExternalId);
}

// Void operation — return an explicit success acknowledgement (CLAUDE.md #6).
echo json_encode(['ok' => true]);
