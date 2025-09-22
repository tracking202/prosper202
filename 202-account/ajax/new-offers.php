<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');


AUTH::require_user(); 

$html['new_offers'] = htmlentities((string) $_SESSION['new_offers']);

if ($html['new_offers'])
	echo " ({$html['new_offers']})";