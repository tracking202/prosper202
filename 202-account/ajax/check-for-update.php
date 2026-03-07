<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');

AUTH::require_user(); 

//check if its the latest verison — reopen session for writes
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if(!isset($_SESSION['next_update_check']) || time() > $_SESSION['next_update_check']) {
	$_SESSION['show_update_check'] = true;
	$_SESSION['update_needed'] = check_premium_update();
} else {
    $_SESSION['show_update_check'] = false;
}
session_write_close();