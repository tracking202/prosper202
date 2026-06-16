<?php

declare(strict_types=1);

include_once(str_repeat('../', 3) . '202-config/connect.php');

AUTH::require_user();

header('Content-Type: application/json');

// CSRF: the same-origin token must match (auto-attached by template.php for jQuery;
// the messenger widget attaches it explicitly to its fetch() POSTs).
if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid token']);
    exit;
}

$body = isset($_POST['body']) ? trim((string) $_POST['body']) : '';
if ($body === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty message']);
    exit;
}

// Optional: existing conversation to reply into; absent/empty starts a new thread.
$conversationExternalId = isset($_POST['conversation']) && $_POST['conversation'] !== ''
    ? (string) $_POST['conversation']
    : null;

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$service = MessagingService::forUser($userId);

if ($service === null) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'messaging unavailable for this account']);
    exit;
}

$result = $service->sendMessage($conversationExternalId, $body);
if ($result === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'could not queue message']);
    exit;
}

echo json_encode([
    'ok'                       => true,
    'conversation_external_id' => $result['conversation_external_id'],
    'message'                  => $result['message'],
]);
