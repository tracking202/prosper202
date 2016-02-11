<?php

include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php');    
include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/functions-rss.php');

AUTH::require_user();

#grab alert items
$rss = fetch_rss( TRACKING202_RSS_URL .'/prosper202/alerts');
if ( isset($rss->items) && 0 != count($rss->items) ) {
 	$rss->items = array_slice($rss->items, 0, 3);
}
if (!$rss) die("");
foreach ($rss->items as $item ) { 
	//check if this alert is already marked as seen
	$mysql['prosper_alert_id'] = $db->real_escape_string($item['prosper_alert_id']);
	$sql = "SELECT COUNT(*) AS count FROM 202_alerts WHERE prosper_alert_id='{$mysql['prosper_alert_id']}' AND prosper_alert_seen='1'";
	$result = _mysqli_query($sql);
	$row = $result->fetch_assoc();
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
		foreach ($rss->items as $item ) { 
			if ($dontShow[$item['prosper_alert_id']] == false) {
				$item_time = human_time_diff(strtotime($item['pubdate'], time())) . " ago";
				$html['time'] = htmlentities($item_time);
				$html['prosper_alert_id'] = htmlentities($item['prosper_alert_id']);
				$html['title'] = htmlentities($item['title']);
				$html['description'] = nl2br(htmlentities($item['description'])); ?>

				<div id="prosper-alerts" class="alert alert-error" data-alertid="<?php echo $html['prosper_alert_id'];?>">
		            <button type="button" class="close fui-cross" data-dismiss="alert"></button>
		            <strong><?php echo $html['title']. " - " .$html['time'];?></strong><br/>
		            <span class="small"><?php echo $html['description'];?></span>
		        </div>

	  <?php }
		}
}?>