<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user(); 

//check if its the latest verison
$_SESSION['update_needed'] = update_needed();
die();