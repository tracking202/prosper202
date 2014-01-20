<?php

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');    

AUTH::require_user();

 #Meetup202 Global Calendar
 $json = @file_get_contents( TRACKING202_RSS_URL . '/meetup202/events.php?type=json');
 $items = json_decode($json, true);
 $items = $items['value']['items'];
 $counter = 0;
 if ($items) {
 foreach ($items as $item) {
 	$counter++;
 	$html['title'] = htmlentities($item['meetup_group']);
 	$html['description'] = htmlentities($item['summary'] . '. ' . $item['description']);
 	$html['link'] = htmlentities($item['link']);
 	$html['time'] = htmlentities(date('l, M j \a\t g:i A T', $item['meetup_start_time']));
 	#Saturday, July 10 at 10:30 AM
 	if (strlen($html['description']) > 350) { 
 		$html['description'] = substr($html['description'],0,350) . ' [...]';
 	}
 	
 	if ($counter < 20) {?>
	 	<h4><a href="http://meetup.tracking202.com" target="_blank"><img src="/202-img/meetup_logo.png"/ class="news_icon"></a> <a href='<?php echo ($html['link']); ?>'  target="_blank"><?php echo $html['title']; ?></a> - <?php echo $html['time']; ?></h4>
		<p><?php echo $html['description']; ?></p><?php  
 	}
}
 } ?>