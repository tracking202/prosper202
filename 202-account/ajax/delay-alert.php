<?php
include_once(str_repeat("../", 2).'202-config/connect.php');

AUTH::require_user(); 

if (isset($_POST['delay']) && $_POST['delay'] == true) {
	$_SESSION['next_update_check'] = time() + 3600;
 	$_SESSION['show_update_check'] = false;
} 
?>