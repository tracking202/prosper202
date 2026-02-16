<?php
declare(strict_types=1);
include_once(__DIR__ . '/202-config/connect.php');
//Check if user is on the toolbar, if so send them to the toolbar login page

if (isset($_SESSION['toolbar']) && $_SESSION['toolbar'] == 'true') {
	$redir_url = get_absolute_url().'202-Toolbar/';
} else {
	$redir_url = get_absolute_url().'202-login.php?redirect='.urlencode((string) $_SERVER['REQUEST_URI']);
}

// Clear auth-related session keys but don't destroy the session entirely.
// session_destroy() was wiping the session before remember-me could recover,
// turning any transient fingerprint mismatch into a full logout.
unset(
	$_SESSION['user_name'],
	$_SESSION['user_id'],
	$_SESSION['user_own_id'],
	$_SESSION['session_fingerprint'],
	$_SESSION['session_time'],
	$_SESSION['valid_key'],
	$_SESSION['account_owner_id']
);

header('location: '.$redir_url);
?>