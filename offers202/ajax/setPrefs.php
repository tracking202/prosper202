<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//if changing the limit, only change the limit
if ($_POST['limit']) { 
	$_SESSION['offers202_limit'] = $_POST['limit'];
} else { 
	//set the preferences
	$_SESSION['offers202_query'] = $_POST['query'];
	$_SESSION['offers202_network_id'] = $_POST['networkId'];
}