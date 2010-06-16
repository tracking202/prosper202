<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//before loading the offers202 page, check to make sure this users api key is valid, 
//if they do not have one, they will have to generated one 
AUTH::require_valid_api_key();

template_top('RSS Feeds'); 

include_once('../top.php'); ?>

<br/><br/>

<table style="margin: 0px auto;"> 
	<tr>
		<td>
			<h3 class="green">Offer RSS Feeds</h3>
			<p>Here are list of the rss feeds you can subscribe to. </p>
		</td>
	</tr>
	<tr>
		<td>
			<h3 class="green" style="margin: 40px 0px 10px;">All Networks RSS Feeds (most recent 500 offers) &amp; Customizable Offer RSS Feeds</h3>
			<p style="margin: 8px 0px;">Here are a few offer RSS feeds.  One showing the entire offer feed, and the other two showing customizable examples.</p>
			<table class="setup-table" cellpadding="0" cellspacing="0" style="margin: 0px;">
				<tr>
					<th>Affiliate Network</th> 
					<th>RSS Feed</th>
				</tr>
				<tr>
					<td>All Offers, All Networks</th>
					<td><? echo "<a href='".TRACKING202_RSS_URL."/offers202'>".TRACKING202_RSS_URL."/offers202</a>"; ?></td>
				</tr>
				<tr>
					<td>Ringtones Offers, All Networks</th>
					<td><? echo "<a href='".TRACKING202_RSS_URL."/offers202?q=ringtones'>".TRACKING202_RSS_URL."/offers202?q=ringtones</a>"; ?></td>
				</tr>
				<tr>
					<td>Credit Report Offers, All Networks</th>
					<td><? echo "<a href='".TRACKING202_RSS_URL."/offers202?q=credit+report'>".TRACKING202_RSS_URL."/offers202?q=credit+report</a>"; ?></td>
				</tr>
			</table>
		</td>
	</tr>
	<tr>
		<td>
			<?	//build the get query for the offers202 restful api
			$get = array();
			$get['apiKey'] = $_SESSION['user_api_key'];
			$query = http_build_query($get);
			
			//build the offers202 api string
			$url = TRACKING202_API_URL . "/offers202/getNetworks?$query";
			
			//grab the url
			$xml = getUrl($url);
			$getNetworks = convertXmlIntoArray($xml);
			$getNetworks = $getNetworks['getNetworks'];
			$networks = $getNetworks['networks'][0]['network'];		?>
			
			
			<h3 class="green" style="margin: 40px 0px 10px;">Specific Affiliate Network RSS Feeds</h3>
			<table class="setup-table" cellpadding="0" cellspacing="0" style="margin: 0px;">
				<tr>
					<th>Affiliate Network</th>
					<th>RSS Feed</th>
				</tr>
			
				<?  for ($x = 0; $x < count($networks); $x++) { 
						
					$html = array_map('htmlentities', $networks[$x]);
					echo "<tr>";
						echo "<td>{$html['networkName']} ({$html['networkOffers']})</td>";
						echo "<td><a href='{$html['networkOffersRssFeed']}'>{$html['networkOffersRssFeed']}</a></td>";
					echo "</tr>"; 
				} ?>
			 
			</table>
		</td>
	</tr>
</table>


<? template_bottom();