<?php

declare(strict_types=1);

/**
 * Shared authentication gate for the messaging AJAX endpoints.
 *
 * Requires a logged-in user but deliberately NOT a valid license — unlike
 * AUTH::require_user(), which also calls AUTH::require_valid_api_key() and
 * redirects to api-key-required.php when the central license check fails.
 *
 * Messaging must stay reachable even when a user's license is invalid or expired:
 * that is precisely when they need to open the messenger to contact support.
 * Gating it behind the license check would lock the user out of the one channel
 * they need.
 *
 * Include this AFTER 202-config/connect.php. On failure it emits a JSON 401 and
 * exits; on success it defines $messagingUserId for the caller.
 */

// Guard against direct access (connect.php defines the AUTH class).
if (!class_exists('AUTH')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

// Same login + remember-me check require_user() uses (shared implementation, so
// the security-sensitive logic can't drift), but without the license gate and
// returning JSON 401 instead of the HTML access-denied page.
if (!AUTH::logged_in_with_remember()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'authentication required']);
    exit;
}

$messagingUserId = (int) ($_SESSION['user_id'] ?? 0);
