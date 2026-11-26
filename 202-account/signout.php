<?php

declare(strict_types=1);

include_once(str_repeat("../", 1) . '202-config/connect.php');

// Signs out only on a POST carrying the session's token
// (p202_signout_form()): a GET signed out, so a link or an image on any
// other site could sign a person out. A GET, or a POST without the token,
// shows the Sign out button instead.

if (isset($_SESSION['toolbar']) && $_SESSION['toolbar'] == 'true') {
    $redir_url = get_absolute_url() . '202-Mobile/';
} else {
    $redir_url = get_absolute_url();
}

$posted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($posted && AUTH::check_csrf_token()) {
    // The session, the cookie and the stored key the cookie names; a key that
    // could not be deleted throws, after the session has ended.
    AUTH::signOut();
    header('location: ' . $redir_url);
    exit;
}

// Nobody signed in: nothing to sign out of.
if (!AUTH::logged_in_with_remember()) {
    header('location: ' . $redir_url);
    exit;
}

header('Cache-Control: no-store');
if ($posted) {
    http_response_code(403);
}
info_top(['title' => 'Sign out of Prosper202']);
echo p202_standalone_card(
    'Sign out of Prosper202?',
    'This browser stops being signed in, and stops remembering you.'
);
if ($posted) {
    echo p202_flash(
        'warn',
        'That sign-out did not come from a page of yours, so you are still signed in. Press Sign out to sign out.'
    );
}
echo p202_signout_form(get_absolute_url(), $_SESSION['token'] ?? null, 'Sign out', 'btn btn-primary w-100');
$stay = htmlspecialchars(get_absolute_url() . '202-account/', ENT_QUOTES, 'UTF-8');
echo '<p class="mt-3 mb-0 text-center"><a href="' . $stay . '">Stay signed in</a></p>';
echo p202_standalone_card_end();
info_bottom();
