<?php
declare(strict_types=1);
include_once(str_repeat("../", 1).'202-config/connect.php');

if (isset($_SESSION['toolbar']) && $_SESSION['toolbar'] == 'true')
	$redir_url = get_absolute_url().'202-Mobile/';
else
	$redir_url = get_absolute_url();
// The session, the cookie and the stored key the cookie names; a key that
// could not be deleted throws, after the session has ended.
AUTH::signOut();
header('location: '.$redir_url);
