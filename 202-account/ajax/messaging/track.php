<?php

declare(strict_types=1);

include_once(str_repeat('../', 3) . '202-config/connect.php');

require __DIR__ . '/_auth.php';

/**
 * Product analytics from the page: Prosper202Messenger('update', {...}) and
 * Prosper202Messenger('trackEvent', name, metadata) post here (202-js/p202-chat.js).
 *
 * The rows are recorded, then delivered to the portal's POST /track by
 * MessagingService::sync(), throttled per user. Nothing else runs that sync
 * on a page (the chat box no longer polls this install), so a request with
 * flush=1 and nothing to record asks for the delivery alone: the answer's
 * delivery says what happened, and when it is "throttled" or "more" (one
 * batch went out, more is pending), retry_after_s says when the page may ask
 * again. The hourly cron (MessagingService::deliverPendingExclusive()) delivers what
 * a page closed before then leaves behind.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

// Shared guarded helper: fails closed when either token side is empty.
if (!AUTH::check_csrf_token()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid token']);
    exit;
}

$userId = $messagingUserId;
try {
    $service = MessagingService::forUser($userId);
} catch (\Throwable $e) {
    error_log('Prosper202 analytics: user ' . $userId . ' could not be read: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal']);
    exit;
}

if ($service === null) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'messaging unavailable for this account']);
    exit;
}

$attributes = null;
$eventName = null;
$metadata = null;

// 1. Custom attributes for segmentation: Prosper202Messenger('update', {...})
if (isset($_POST['update']) && $_POST['update'] !== '') {
    $attributes = json_decode((string) $_POST['update'], true);
    // Reject malformed input explicitly rather than silently ignoring it (CLAUDE.md #4).
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($attributes)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'update must be a JSON object']);
        exit;
    }
}

// 2. Behavioural event: Prosper202Messenger('trackEvent', name, metadata)
if (isset($_POST['event_name']) && trim((string) $_POST['event_name']) !== '') {
    $eventName = (string) $_POST['event_name'];
    if (isset($_POST['metadata']) && $_POST['metadata'] !== '') {
        $metadata = json_decode((string) $_POST['metadata'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($metadata)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'metadata must be a JSON object']);
            exit;
        }
    }
}

// 3. Delivery alone: what an earlier answer held back for retry_after_s.
$flush = $_POST['flush'] ?? null;
if ($flush !== null && $flush !== '1') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'flush must be 1']);
    exit;
}

if ($attributes === null && $eventName === null && $flush === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'nothing to track']);
    exit;
}

try {
    if ($attributes !== null) {
        $service->updateAttributes($attributes);
    }
    if ($eventName !== null) {
        $service->recordEvent($eventName, $metadata);
    }
} catch (\Throwable $e) {
    error_log('Prosper202 analytics: user ' . $userId . ' could not be recorded: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'not recorded']);
    exit;
}

// Recorded. Delivery is best effort from here: what fails stays queued for
// the next sync, so it does not change the status.
$delivery = $service->sync();
$answer = ['ok' => true, 'delivery' => $delivery];
if ($delivery === MessagingService::SYNC_THROTTLED || $delivery === MessagingService::SYNC_MORE) {
    try {
        $answer['retry_after_s'] = max(1, $service->secondsUntilSyncDue());
    } catch (\Throwable $e) {
        error_log('Prosper202 analytics: the sync time of user ' . $userId . ' could not be read: ' . $e->getMessage());
    }
}

echo json_encode($answer);
