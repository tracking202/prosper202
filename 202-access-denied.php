<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 
//Check if user is on the toolbar, if so send them to the toolbar login page

if ($_SESSION['toolbar'] == 'true')
	$redir_url = '/202-Toolbar/';
else
	$redir_url = '/202-login.php';		
session_destroy();
header('location: '.$redir_url);

?>