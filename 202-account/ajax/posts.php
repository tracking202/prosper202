<?php


include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');    

AUTH::require_user();

 $rss = fetch_rss('http://prosper.tracking202.com/blog/rss/');
 if ( isset($rss->items) && 0 != count($rss->items) ) {
 	
 	$rss->items = array_slice($rss->items, 0, 2);
 	foreach ($rss->items as $item ) { 
 		
 		$item['description'] = html2txt($item['description']);
 		
 		if (strlen($item['description']) > 350) { 
 			$item['description'] = substr($item['description'],0,350) . ' [...]';
 		} ?>
 		
	<h4><a href="http://blog.tracking202.com"><img src="/202-img/blog_icon.png"/ class="news_icon"></a> <a href='<?php echo ($item['link']); ?>'><?php echo $item['title']; ?></a> - <?php printf(('%s ago'), human_time_diff(strtotime($item['pubdate'], time() ) )) ; ?></h4>
	<p><?php echo $item['description']; ?></p>
	<?php }
} ?>