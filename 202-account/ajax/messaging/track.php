<?php

declare(strict_types=1);

include_once(str_repeat('../', 3) . '202-config/connect.php');

require __DIR__ . '/_auth.php';
require_once dirname(__DIR__, 3) . '/202-config/Messaging/Analytics.class.php';

header('Content-Type: application/json');

// Shared guarded helper: fails closed when either token side is empty.
if (!AUTH::check_csrf_token()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid token']);
    exit;
}

// --- analytics consent gate: client events are analytics-tier ---
require_once dirname(__DIR__, 3) . '/202-config/Messaging/ConsentPolicy.class.php';
if (!ConsentPolicy::analyticsAllowed($db, $messagingUserId)) {
    echo json_encode(['ok' => true, 'recorded' => false, 'reason' => 'analytics_not_consented']);
    exit;
}

$handled = false;

// 1. Custom attributes for segmentation: Prosper202Messenger('update', {...})
if (isset($_POST['update']) && $_POST['update'] !== '') {
    $decoded = json_decode((string) $_POST['update'], true);
    // Reject malformed input explicitly rather than silently ignoring it (CLAUDE.md #4).
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_update_json']);
        exit;
    }
    Analytics::attr($decoded, 'analytics');
    $handled = true;
}

// 2. Behavioural event: Prosper202Messenger('trackEvent', name, metadata)
if (isset($_POST['event_name']) && trim((string) $_POST['event_name']) !== '') {
    $meta = [];
    if (isset($_POST['metadata']) && $_POST['metadata'] !== '') {
        $meta = json_decode((string) $_POST['metadata'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($meta)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_metadata_json']);
            exit;
        }
    }
    Analytics::event((string) $_POST['event_name'], $meta, 'analytics');
    $handled = true;
}

if (!$handled) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'nothing to track']);
    exit;
}

echo json_encode(['ok' => true]);
