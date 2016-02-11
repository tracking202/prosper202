<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user(); 

$html['new_offers'] = htmlentities($_SESSION['new_offers']);

if ($html['new_offers'])
	echo " ({$html['new_offers']})";