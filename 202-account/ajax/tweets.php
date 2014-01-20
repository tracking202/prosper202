<?php


include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');    

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
 		
	<h4><a href="http://twitter.tracking202.com"><img src="/202-img/twitter_logo.png"/ class="news_icon"></a> <a href='<?php echo ($item['link']); ?>'><?php echo $item['title']; ?></a> - <?php printf(('%s ago'), human_time_diff($item_time)) ; ?></h4>
	<?php } }
} ?>