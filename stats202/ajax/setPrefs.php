<? include_once('/home/admin202/private_202files/connect-dashboard.php');   

AUTH::require_user();


//if changing the limit, only change the limit
if ($_POST['limit']) { 
	$_SESSION['stats202_limit'] = $_POST['limit'];
}