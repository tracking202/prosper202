<?php
include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php');    

AUTH::require_user();

$json = getUrl( TRACKING202_RSS_URL . '/prosper202/sponsors?type=json');
$json = json_decode($json, true);



$sponsors = $json['sponsors'];
if (!$sponsors) die();
foreach ($sponsors as $sponsor) { 
	
	$html = array_map('htmlentities', $sponsor);
 	
 		echo '<div class="row app-row" style="margin-bottom: 10px;">';
  			echo '<div class="col-xs-2">';
  				echo '<a href="'.$html['url'].'" target="_blank"><img style="width: 42px;" src="'.$html['image'].'"/></a>';
  			echo '</div>';
  			echo '<div class="col-xs-10">';
  				echo '<a href="'.$html['url'].'" target="_blank">'.$html['name'].'</a><br/><span>'.$html['description'].'</span>';
  			echo '</div>';
  		echo '</div>';
}



