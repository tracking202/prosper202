<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user(); 

//check if its the latest verison
$_SESSION['update_needed'] = update_needed();
die();