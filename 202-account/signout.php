<?php
include_once(str_repeat("../", 1).'202-config/connect.php');

if ($_SESSION['toolbar'] == 'true')
	$redir_url = get_absolute_url().'202-Mobile/';
else
	$redir_url = get_absolute_url();
session_destroy();
setcookie('remember_me','',1,'/',$_SERVER['HTTP_HOST'],false,true);
setcookie('remember_me',false);
unset($_COOKIE['remember_me']);
header('location: '.$redir_url);
