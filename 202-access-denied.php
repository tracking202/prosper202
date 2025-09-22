<?php
declare(strict_types=1);
include_once(__DIR__ . '/202-config/connect.php');
//Check if user is on the toolbar, if so send them to the toolbar login page

if (isset($_SESSION['toolbar']) && $_SESSION['toolbar'] == 'true')
	$redir_url = get_absolute_url().'202-Toolbar/';
else
	$redir_url = get_absolute_url().'202-login.php?redirect='.urlencode((string) $_SERVER['REQUEST_URI']);		
session_destroy();
header('location: '.$redir_url);

?>