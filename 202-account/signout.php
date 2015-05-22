<?php

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');
if ($_SESSION['toolbar'] == 'true')
	$redir_url = '/202-Toolbar/';
else
	$redir_url = '/';		
session_destroy();

if (isset($_COOKIE['hideChartUpgrade'])) {
	unset($_COOKIE['hideChartUpgrade']);
	setcookie('hideChartUpgrade', '', 1, '/');
}

header('location: '.$redir_url);