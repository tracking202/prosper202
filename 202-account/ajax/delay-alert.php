<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');

AUTH::require_user(); 

if (isset($_POST['delay']) && $_POST['delay'] == true) {
	// Snoozing the update banner writes the session, and every write asks for
	// the session token (error pattern #5): without it any page the user
	// visits could hide the banner. p202-chrome.js posts the token the
	// banner's slot carries; fetch() is not covered by the jQuery prefilter.
	if (!AUTH::check_csrf_token()) {
		http_response_code(403);
		die('Invalid token');
	}
	if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
	$_SESSION['next_update_check'] = time() + 3600;
 	$_SESSION['show_update_check'] = false;
	session_write_close();
}