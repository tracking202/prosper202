<?php


include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');    

AUTH::require_user();

$json = getUrl( TRACKING202_RSS_URL . '/prosper202/sponsors?type=json');
$json = json_decode($json, true);


echo '<table cellspacing="0" cellpadding="0" class="apps">';

$sponsors = $json['sponsors'];
foreach ($sponsors as $sponsor) { 
	
	$html = array_map('htmlentities', $sponsor);
 	
 	echo '<tr>';
 		echo '<td class="product-image"><img src="'.$html['image'].'"/></td>';
 		echo '<td><a href="'.$html['url'].'" target="_blank">'.$html['name'].'</a><br/>'.$html['description'].'</td>';
 	echo '</tr>';
}

echo '</table>';


