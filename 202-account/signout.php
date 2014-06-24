<?php

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');
if ($_SESSION['toolbar'] == 'true')
	$redir_url = '/202-Toolbar/';
else
	$redir_url = '/';		
session_destroy();
header('location: '.$redir_url);