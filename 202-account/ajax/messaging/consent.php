<?php

declare(strict_types=1);

include_once(str_repeat('../', 3) . '202-config/connect.php');

require __DIR__ . '/_auth.php';
require_once dirname(__DIR__, 3) . '/202-config/Messaging/ConsentPolicy.class.php';

header('Content-Type: application/json');

// Shared guarded helper: fails closed when either token side is empty.
if (!AUTH::check_csrf_token()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid token']);
    exit;
}

$flag   = isset($_POST['flag']) ? (string) $_POST['flag'] : '';
$state  = isset($_POST['state']) ? (string) $_POST['state'] : '';
// Column is varchar(32) — clamp instead of relying on silent truncation.
$source = substr(isset($_POST['source']) ? (string) $_POST['source'] : 'settings', 0, 32);

if (!in_array($flag, ['analytics', 'email_marketing'], true)
    || !in_array($state, ['granted', 'denied'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_args']);
    exit;
}

// Single chokepoint — ConsentPolicy owns every raw consent-column write.
$ok = ConsentPolicy::record($db, (int) $messagingUserId, $flag, $state, $source);
echo json_encode(['ok' => (bool) $ok]);
