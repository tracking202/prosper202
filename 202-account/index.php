<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user(); 

template_top();  ?>

<div id="tracking202_alerts"><table cellspacing="0" cellpadding="0" style="margin: 0px auto;"><tr><td style="padding: 20px"><img src="/202-img/loader-small.gif" style="display: block; margin-right: 4px;"/></td><td><em>checking for new alerts...</em></td></tr></table></div>

<div class="slim">
		<div class="welcome">
			<table cellspacing="0" cellpadding="0" class="section">
				<tr>
					<td class="left" ><h2>Sponsors <a href="http://prosper202.com/advertise/" style="font-size: 10px;">(advertise)</span></h2></td>
					<td><hr></td>
				</tr> 
			</table>
			<p>
				<script type="text/javascript">
				var is_ssl = ("https:" == document.location.protocol);
				var asset_url = is_ssl ? "https://ads.tracking202.com/prosper202-home/" : "<?php echo TRACKING202_ADS_URL; ?>/prosper202-home/";
				document.write(unescape("%3Ciframe%20class%3D%22advertise%22%20src%3D%22"+asset_url+"%22%20scrolling%3D%22no%22%20frameborder%3D%220%22%3E%3C/iframe%3E"));
				</script>
			</p> 
			
			<table cellspacing="0" cellpadding="0" class="section">
				<tr>
					<td class="left" ><h2>Tracking202 News</h2></td>
					<td><hr></td>
				</tr>
			</table>
			<div id="tracking202_tweets"><img src="/202-img/loader-small.gif" style="display: block;"/></div>
			<div id="tracking202_posts"><img src="/202-img/loader-small.gif" style="display: block;"/></div>
			
			<table cellspacing="0" cellpadding="0" class="section">
				<tr>
					<td class="left" ><h2>Upcoming Meetup202 Events <a href="http://meetup.tracking202.com/" style="font-size: 10px;">(all meetups)</span> - <a href="http://apply.meetup.tracking202.com/" style="font-size: 10px;">(become an organizer)</span></h2> </td>
					<td><hr></td>
				</tr>
			</table>
			<div id="tracking202_meetups"><img src="/202-img/loader-small.gif" style="display: block;"/></div>
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
					<td class="product-image"><a href="/tracking202/"><img src="/202-img/icons/tracking202.png" border="0"/></a></td>
					<td><a href="/tracking202/">Tracking202</a><br/>PPC affiliate conversion tracking software.</td>
				</tr>
								<tr>
					<td class="product-image"><a href="../202-Mobile" target="_blank"><img src="/202-img/icons/mobile202.png" border="0"/></a></td>
					<td><a href="../202-Mobile" target="_blank">Mobile202</a><br/>View your stats on the go with mobile optimized version of Tracking202</td></tr>
				<tr>
					<td class="product-image"><a href="http://www.conversion202.com" target="_blank"><img src="/202-img/icons/con202.png" border="0"/></a></td>
					<td><a href="http://www.conversion202.com" target="_blank">Conversion202</a><br/>A/B and Multivariate Testing to optimize conversions.</td></tr>
				<tr>
					<td class="product-image"><a href="/202-resources/"><img src="/202-img/icons/resources.png" border="0"/></a></td>
					<td><a href="/202-resources/">Resources202</a><br/>Discover more applications to help you sell.</td></tr>
				<tr>
					<td class="product-image"><a href="/offers202/"><img src="/202-img/icons/offers202.png" border="0"/></a></td>
					<td><a href="/offers202/">Offers202</a><br/>Search for offers across many affiliate networks.</td>
				</tr>
			
				<!-- <tr>
					<td class="product-image"><img src="/202-img/icons/alerts202.png"/></td>
					<td><a href="/alerts202/">Alerts202</a><br/>Monitor certain offers and know when new ones arrive.</td>
				</tr><tr>
					<td class="product-image"><img src="/202-img/icons/stats202.png"/></td>
					<td><a href="/stats202/">Stats202</a><br/>Automatically updates subids and has a mobile web stats app.</td>
				</tr><tr>
					<td class="product-image"><img src="/202-img/icons/resources.png"/></td>
					<td><a href="/202-resources/">Resources</a><br/>Discover more applications to help you sell.</td>
				</tr>-->
			</table>
			
			<br/>
			<table cellspacing="0" cellpadding="0" class="section">
				<tr>
					<td class="left"><h2>Extra Resources</h2></td>
					<td><hr></td>
				</tr>
			</table>
			<table cellspacing="0" cellpadding="0" class="apps">
				<!--<tr>
					<td class="product-image"><img src="/202-img/icons/revolution202.png"/></td>
					<td><a href="http://revolution.tracking202.com/">Revolution202</a><br/>The official Tracking202 Partner Network.</td>
				</tr>-->
				<tr>
					<td class="product-image"><img src="/202-img/icons/blog.png"/></td>
					<td><a href="http://blog.tracking202.com/">Blog</a> - <a href="http://twitter.tracking202.com/">Twitter</a> - <a href="http://newsletter.tracking202.com">Newsletter</a><br/>The official Prosper202 company blog, newsletter &amp; twitter feed.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/forum.png"/></td>
					<td><a href="http://support.tracking202.com/" target="_blank">Community Support</a><br/>Talk with other users, and get help.</td>
				</tr>
				<!--<tr>
					<td class="product-image"><img src="/202-img/icons/directory.png"/></td>
					<td><a href="http://directory.tracking202.com">Directory</a><br/>Sponsored networks and top converting offers.</td>
				</tr>-->
				<tr>
					<td class="product-image"><img src="/202-img/icons/developers.png"/></td>
					<td><a href="http://developers.tracking202.com">Developers</a><br/>Do cool things with the Tracking202 APIs.</td>
				</tr>
				<tr>
					<td class="product-image"><img src="/202-img/icons/meetup202.png"/></td>
					<td><a href="http://meetup.tracking202.com">Meetup202</a><br/>Affiliate Marketing Meetup Groups around the World.</td>
				</tr>
				<!--<tr>
					<td class="product-image"><img src="/202-img/icons/tracking202pro.png"/></td>
					<td><a href="http://pro.tracking202.com">Tracking202 Pro</a><br/>Affiliate conversion tracking software with full integration into Google, MSN and Yahoo.</td>
				</tr>-->
				<tr>
					<td class="product-image"><img src="/202-img/icons/tv202.png"/></td>
					<td><a href="http://tv202.com">TV202</a><br/>Affiliate Marketing Interviews.</td>
				</tr>
				<!--<tr>
					<td class="product-image"><img src="/202-img/icons/worldproxy202.png"/></td>
					<td><a href="http://worldproxy202.com">WorldProxy202</a><br/>Proxies from around the world to view international offers.</td>
				</tr>-->
			</table>
			
			<br/>
			<table cellspacing="0" cellpadding="0" class="section">
				<tr>
					<td class="left" ><h2>Sponsors <a href="http://prosper202.com/advertise/" style="font-size: 10px;" target="_blank">(advertise)</span></h2></td>
					<td><hr></td>
				</tr>
			</table>
			<div id="tracking202_sponsors"><img src="/202-img/loader-small.gif" style="display: block;"/></div>
		</div>
	</div>
	
				
	<script type="text/javascript">
	new Ajax.Updater('tracking202_alerts', '/202-account/ajax/alerts.php');
	new Ajax.Updater('tracking202_meetups', '/202-account/ajax/meetups.php');
	new Ajax.Updater('tracking202_tweets', '/202-account/ajax/tweets.php');
	new Ajax.Updater('tracking202_posts', '/202-account/ajax/posts.php');
	new Ajax.Updater('tracking202_sponsors', '/202-account/ajax/sponsors.php');

	//run background checks
	new Ajax.Request('/202-account/ajax/system-checks.php');
	
	//check if update needed
	new Ajax.Request('/202-account/ajax/check-for-update.php', {
		onSuccess: function() {
			 new Ajax.Updater('update_needed', '/202-account/ajax/update-needed.php');
	      }
	});

	//check for new offers
	new Ajax.Request('/202-account/ajax/check-for-offers.php', {
		onSuccess: function() {
			new Ajax.Updater('new_offers', '/202-account/ajax/new-offers.php');
	      }
	});

	</script>
<? template_bottom(); ?>