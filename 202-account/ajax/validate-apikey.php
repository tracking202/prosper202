<?php
include_once(str_repeat("../", 2).'202-config/connect.php');
if (isset($_POST['apikey'])) {
	echo api_key_validate($_POST['apikey']);
}
?>

