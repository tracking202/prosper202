<?php

include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php');    
include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/functions-rss.php');

AUTH::require_user();

 $rss = fetch_rss('http://prosper.tracking202.com/blog/rss/');
 if ( isset($rss->items) && 0 != count($rss->items) ) {
 	
 	$rss->items = array_slice($rss->items, 0, 2);
 	foreach ($rss->items as $item ) { 
 		
 		$item['description'] = html2txt($item['description']);
 		
 		if (strlen($item['description']) > 350) { 
 			$item['description'] = substr($item['description'],0,350) . ' [...]';
 		} ?>
 		
	<i class="fa fa-rss-square"></i> <a href='<?php echo ($item['link']); ?>'><?php echo $item['title']; ?></a> - <span style="font-size: 10px;">(<?php printf(('%s ago'), human_time_diff(strtotime($item['pubdate'], time() ) )) ; ?>)</span><br/>
	<span class="infotext"><?php echo $item['description']; ?></span><br></br>
	<?php }
} ?>