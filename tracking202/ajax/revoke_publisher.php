<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

// Get Links: the account's publisher links stop crediting a publisher it
// granted (Prosper202\Click\PublisherCredit). Taking a grant away is open to
// whoever may make the account's links; giving one asks more (add_users).

AUTH::require_user();
AUTH::require_permissions('access_to_setup_section');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    die('Send this as a POST.');
}

// The page posts the session token with the publisher id.
if (!AUTH::check_csrf_token()) {
    http_response_code(403);
    die('Invalid token, please reload the page and try again.');
}

$publisherId = \Prosper202\Http\PostedId::read($_POST['publisher_id'] ?? null);
if ($publisherId === null) {
    http_response_code(422);
    die('That is not a publisher id.');
}

try {
    (new \Prosper202\Click\PublisherCredit(new \Prosper202\Database\Connection($db)))
        ->revoke((int) $_SESSION['user_id'], $publisherId);
} catch (\RuntimeException $e) {
    // The list removes the row when this answers 200 (#1).
    error_log('revoke_publisher.php: the grant of publisher ' . $publisherId . ' by user '
        . (int) $_SESSION['user_id'] . ' was not removed: ' . $e->getMessage());
    http_response_code(500);
    die('Your links still credit this publisher: the change could not be saved. Reload the page and try again.');
}
