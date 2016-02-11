<?php

include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php');  
include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/functions-rss.php');

AUTH::require_user();

$rss = fetch_rss( TRACKING202_RSS_URL . '/twitter/timeline.php');
 if ( isset($rss->items) && 0 != count($rss->items) ) {
 	
 	$rss->items = array_slice($rss->items, 0, 1);
 	foreach ($rss->items as $item ) { 
 		
 		$item_time = strtotime($item['pubdate'], time());
 		//only display items that are recent within 30 days from twitter
 		if ($item_time > (time() - 60*60*24*30)) {
	 		$item['title'] = str_replace('tracking202: ', '', $item['title']);
	 		$item['description'] = html2txt($item['description']); ?>
 		
	<span class="fui-twitter"></span><a href='<?php echo ($item['link']); ?>'><?php echo $item['title']; ?></a> - <span style="font-size: 10px;">(<?php printf(('%s ago'), human_time_diff($item_time)) ; ?>)</span><br></br>
	<?php } }
} ?>