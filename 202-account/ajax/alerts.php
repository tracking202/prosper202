<?php

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');    

AUTH::require_user();

#grab alert items
$rss = fetch_rss( TRACKING202_RSS_URL .'/prosper202/alerts');
if ( isset($rss->items) && 0 != count($rss->items) ) {
 	$rss->items = array_slice($rss->items, 0, 3);
}

foreach ($rss->items as $item ) { 
	//check if this alert is already marked as seen
	$mysql['prosper_alert_id'] = mysql_real_escape_string($item['prosper_alert_id']);
	$sql = "SELECT COUNT(*) AS count FROM 202_alerts WHERE prosper_alert_id='{$mysql['prosper_alert_id']}' AND prosper_alert_seen='1'";
	$result = _mysql_query($sql);
	$row = mysql_fetch_assoc($result);
	if ($row['count']) {
		#echo 'dont show';
		$dontShow[$item['prosper_alert_id']] = true;
	} else {
		#echo 'show alerts';
		$showAlerts = true;
	}  
}
#echo $showAlerts;
if (!$showAlerts) die();

#if items display the table
if ($rss->items) { 
	echo '<table class="alert2"><tr><td>';
		echo "<h2>Tracking202 Alerts</h2>";
		echo "<ul>";
		foreach ($rss->items as $item ) { 
			if ($dontShow[$item['prosper_alert_id']] == false) {
				$item_time = human_time_diff(strtotime($item['pubdate'], time())) . " ago";
				$html['time'] = htmlentities($item_time);
				$html['prosper_alert_id'] = htmlentities($item['prosper_alert_id']);
				$html['title'] = htmlentities($item['title']);
				$html['description'] = nl2br(htmlentities($item['description']));
				echo "<li id='prosper_alert_id_{$html['prosper_alert_id']}'>";
					echo "<strong>{$html['title']} - {$html['time']}</strong><br/>";
					echo "<div>{$html['description']} <a href='#' onclick='closeAlert({$html['prosper_alert_id']});'>[hide alert]</a></div>";
				echo "</li>";
			}
		}
	
	echo "</ul>";
	echo '</td></tr></table>';
}?>