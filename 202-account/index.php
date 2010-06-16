<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//check if its the latest verison
$_SESSION['update_needed'] = update_needed();

//check to see if this user has stats202 enabled
$_SESSION['stats202_enabled'] = AUTH::is_valid_app_key('stats202', $_SESSION['user_api_key'], $_SESSION['user_stats202_app_key']);

template_top();  ?>

<div class="slim">
		<div class="welcome">
			<table cellspacing="0" cellpadding="0" class="section">
				<tr>
					<td class="left" ><h2>Sponsor <a href="http://prosper202.com/advertise/" style="font-size: 10px;">(advertise)</span></h2></td>
					<td><hr></td>
				</tr> 
			</table>
			<p><iframe class="advertise" src="http://prosper202.com/ads/prosper202/" scrolling="no" frameborder="0"></iframe></p> 
			
			<table cellspacing="0" cellpadding="0" class="section">
				<tr>
					<td class="left" ><h2>Prosper202 Development Blog</h2></td>
					<td><hr></td>
				</tr>
			</table><?php
			 $rss = fetch_rss('http://prosper202.com/blog/rss/');
			 if ( isset($rss->items) && 0 != count($rss->items) ) {
			 	
			 	$rss->items = array_slice($rss->items, 0, 5);
			 	foreach ($rss->items as $item ) { 
			 		
			 		$item['description'] = html2txt($item['description']);
			 		
			 		if (strlen($item['description']) > 350) { 
			 			$item['description'] = substr($item['description'],0,350) . ' [...]';
			 		} ?>
			 		
				<h4><a href='<?php echo ($item['link']); ?>'><?php echo $item['title']; ?></a> - <?php printf(('%s ago'), human_time_diff(strtotime($item['pubdate'], time() ) )) ; ?></h4>
				<p><?php echo $item['description']; ?></p>
				<?php }
			} ?>

		</div>
		
		<div class="products">
			<table cellspacing="0" cellpadding="0" class="section">
				<tr>
					<td class="left"><h2>My Applications</h2></td>
					<td><hr></td>
				</tr>
			</table>
			<table cellspacing="0" cellpadding="0" class="apps">
				<tr>
					<td class="product-image"><img src="/202-img/icons/tracking202.png"/></td>
					<td><a href="/tracking202/">Tracking202</a><br/>PPC affiliate conversion tracking software.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/stats202.png"/></td>
					<td><a href="/stats202/">Stats202</a><br/>Automatically updates subids and has a mobile web stats app.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/offers202.png"/></td>
					<td><a href="/offers202/">Offers202</a><br/>Search for offers across many affiliate networks.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/alerts202.png"/></td>
					<td><a href="/alerts202/">Alerts202</a><br/>Monitor certain offers and know when new ones arrive.</td>
				</tr>
				
			</table>
			<br/>
			<table cellspacing="0" cellpadding="0" class="section">
				<tr>
					<td class="left"><h2>Extra Resources</h2></td>
					<td><hr></td>
				</tr>
			</table>
		
			<table cellspacing="0" cellpadding="0" class="apps">
				<tr>
					<td class="product-image"><img src="/202-img/icons/revolution202.png"/></td>
					<td><a href="http://revolution.tracking202.com/">Revolution202</a><br/>The official Tracking202 Partner Network.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/blog.png"/></td>
					<td><a href="http://prosper202.com/blog">Blog</a> - <a href="http://twitter.com/wesmahler/">Twitter</a> - <a href="http://newsletter.tracking202.com">Newsletter</a><br/>The official Prosper202 company blog, newsletter &amp; twitter feed.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/forum.png"/></td>
					<td><a href="http://prosper202.com/forum">Forum</a><br/>Talk with other users, and get help.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/directory.png"/></td>
					<td><a href="http://directory.tracking202.com">Directory</a><br/>Sponsored networks and top converting offers.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/developers.png"/></td>
					<td><a href="http://developers.tracking202.com">Developers</a><br/>Do cool things with the Tracking202 APIs.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/meetup202.png"/></td>
					<td><a href="http://meetup.tracking202.com">Meetup202</a><br/>Affiliate Marketing Meetup Groups around the World.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/tracking202pro.png"/></td>
					<td><a href="http://pro.tracking202.com">Tracking202 Pro</a><br/>Affiliate conversion tracking software with full integration into Google, MSN and Yahoo.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/tv202.png"/></td>
					<td><a href="http://tv202.com">TV202</a><br/>Affiliate Marketing Interviews.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/worldproxy202.png"/></td>
					<td><a href="http://worldproxy202.com">WorldProxy202</a><br/>Proxies from around the world to view international offers.</td>
				</tr>
				
			</table>
		</div>
	</div>
<? template_bottom(); ?>