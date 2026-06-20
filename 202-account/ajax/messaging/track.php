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

$userId  = $messagingUserId;
$service = MessagingService::forUser($userId);

if ($service === null) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'messaging unavailable for this account']);
    exit;
}

$handled = false;

// 1. Custom attributes for segmentation: Prosper202Messenger('update', {...})
if (isset($_POST['update']) && $_POST['update'] !== '') {
    $attributes = json_decode((string) $_POST['update'], true);
    // Reject malformed input explicitly rather than silently ignoring it (CLAUDE.md #4).
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($attributes)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'update must be a JSON object']);
        exit;
    }
    $service->updateAttributes($attributes);
    $handled = true;
}

// 2. Behavioural event: Prosper202Messenger('trackEvent', name, metadata)
if (isset($_POST['event_name']) && trim((string) $_POST['event_name']) !== '') {
    $metadata = null;
    if (isset($_POST['metadata']) && $_POST['metadata'] !== '') {
        $metadata = json_decode((string) $_POST['metadata'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($metadata)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'metadata must be a JSON object']);
            exit;
        }
    }
    $service->recordEvent((string) $_POST['event_name'], $metadata);
    $handled = true;
}

if (!$handled) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'nothing to track']);
    exit;
}

echo json_encode(['ok' => true]);
