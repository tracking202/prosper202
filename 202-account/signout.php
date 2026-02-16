<?php
declare(strict_types=1);
include_once(str_repeat("../", 1).'202-config/connect.php');

if (isset($_SESSION['toolbar']) && $_SESSION['toolbar'] == 'true')
	$redir_url = get_absolute_url().'202-Mobile/';
else
	$redir_url = get_absolute_url();
session_destroy();
$secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
setcookie('remember_me', '', ['expires' => 1, 'path' => '/', 'domain' => AUTH::cookie_domain(), 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
unset($_COOKIE['remember_me']);
header('location: '.$redir_url);
