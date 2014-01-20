<div id="nav-primary">  
	<ul name="navbar">
		<li <? if ($navigation[2] == 'setup') { echo 'class="on"'; } ?>><a href="/tracking202/setup" name="setup">Setup</a></li>
		<li <? if (($navigation[1] == 'account' and !$navigation[2]) or ($navigation[2] == 'overview'))  { echo 'class="on"'; } ?>><a href="/tracking202" name="home">Overview</a></li>
		<li <? if ($navigation[2] == 'analyze') { echo 'class="on"'; } ?>><a href="/tracking202/analyze" name="jobs">Analyze</a></li>
		<li <? if ($navigation[2] == 'visitors') { echo 'class="on"'; } ?>><a href="/tracking202/visitors" name="visitors">Visitors</a></li>      
		<li <? if ($navigation[2] == 'spy') { echo 'class="on"'; } ?>><a href="/tracking202/spy" name="spy">Spy</a></li>
		<li <? if ($navigation[2] == 'update') { echo 'class="on"'; } ?>><a href="/tracking202/update" name="update">Update</a></li>
		</li>
	</ul>
  </div>
 
  <div id="nav-secondary" <? if (($navigation[1] == 'forum') or ($navigation[1] == 'blog') or ($navigation[1] == 'misc') or ($navigation[1] == 'videos')) { echo ' class="core" '; } ?>>
	  <div>
		<? if ($navigation[2] == 'setup') { $nav = true; ?>
			<ul>
				<li <? if ($navigation[3] == 'ppc_accounts.php' or !$navigation[3]) { echo 'class="on"'; } ?>><a href="/tracking202/setup/ppc_accounts.php">#1 Traffic Sources</a></li>
				<li <? if ($navigation[3] == 'aff_networks.php') { echo 'class="on"'; } ?>><a href="/tracking202/setup/aff_networks.php">#2 Categories</a></li>
				<li <? if ($navigation[3] == 'aff_campaigns.php') { echo 'class="on"'; } ?>><a href="/tracking202/setup/aff_campaigns.php">#3 Campaigns</a></li>
				<li <? if ($navigation[3] == 'landing_pages.php') { echo 'class="on"'; } ?>><a href="/tracking202/setup/landing_pages.php">#4 Landing Pages</a></li>
				<li <? if ($navigation[3] == 'text_ads.php') { echo 'class="on"'; } ?>><a href="/tracking202/setup/text_ads.php">#5 Text Ads</a></li>
				<li <? switch($navigation[3]) { case "landing.php":  case "get_simple_landing_code.php":  case "get_adv_landing_code.php": echo 'class="on"'; break; } ?>><a href="/tracking202/setup/get_landing_code.php">#6 Get LP Code</a></li> 
				<li <? if ($navigation[3] == 'get_trackers.php') { echo 'class="on"'; } ?>><a href="/tracking202/setup/get_trackers.php">#7 Get Links</a></li> 
				<li <? if ($navigation[3] == 'get_postback.php') { echo 'class="on"'; } ?>><a href="/tracking202/setup/get_postback.php">#8 Get Postback/Pixel</a></li> 
			</ul>
		<? } ?>
		
		<? if (($navigation[1] == 'account' and !$navigation[2]) or ($navigation[2] == 'overview')) { $nav = true; ?>
			<ul>
				<li <? if ($navigation[3] == 'campaign.php' or !$navigation[3]) { echo 'class="on"'; } ?>><a href="/tracking202/overview">Campaign Overview</a></li>
				<li <? if ($navigation[3] == 'breakdown.php') { echo 'class="on"'; } ?>><a href="/tracking202/overview/breakdown.php">Breakdown Analysis</a></li>
				<li <? if ($navigation[3] == 'day-parting.php') { echo 'class="on"'; } ?>><a href="/tracking202/overview/day-parting.php">Day Parting</a></li>
				<li <? if ($navigation[3] == 'week-parting.php') { echo 'class="on"'; } ?>><a href="/tracking202/overview/week-parting.php">Week Parting</a></li> 
				<li <? if ($navigation[3] == 'group-overview.php') { echo 'class="on"'; } ?>><a href="/tracking202/overview/group-overview.php">Group Overview</a></li>
			</ul>
		<? } ?>
		
		<? if ($navigation[2] == 'analyze') { $nav = true; ?>
			<ul>
				<li <? if ($navigation[3] == 'keywords.php' or !$navigation[3]) { echo 'class="on"'; } ?>><a href="/tracking202/analyze/keywords.php">Keywords</a></li>
				<li <? if ($navigation[3] == 'keywords_lpctr.php' or !$navigation[3]) { echo ' class="on" '; } ?>><a href="/tracking202/analyze/keywords_lpctr.php">Keywords LP CTR</a></li>
				<li <? if ($navigation[3] == 'text_ads.php') { echo 'class="on"'; } ?>><a href="/tracking202/analyze/text_ads.php">Text Ads</a></li>
				<li <? if ($navigation[3] == 'text_ads_lpctr.php' or !$navigation[3]) { echo ' class="on" '; } ?>><a href="/tracking202/analyze/text_ads_lpctr.php">Text Ads LP CTR</a></li>
				<li <? if ($navigation[3] == 'referers.php') { echo 'class="on"'; } ?>><a href="/tracking202/analyze/referers.php">Referers</a></li>
				<li <? if ($navigation[3] == 'referers_lpctr.php' or !$navigation[3]) { echo ' class="on" '; } ?>><a href="/tracking202/analyze/referers_lpctr.php">Referers LP CTR</a></li>
				<li <? if ($navigation[3] == 'ips.php') { echo 'class="on"'; } ?>><a href="/tracking202/analyze/ips.php">IPs</a></li>
				<li <? if ($navigation[3] == 'landing_pages.php') { echo 'class="on"'; } ?>><a href="/tracking202/analyze/landing_pages.php">Landing Pages</a></li>
			</ul>
		<? } ?>
		
		
		<? if ($navigation[2] == 'update') {  $nav = true;?>
			<ul>
				<li <? if ($navigation[3] == 'subids.php' or !$navigation[3]) { echo 'class="on"'; } ?>><a href="/tracking202/update/subids.php">Update Subids</a></li>
				<li <? if ($navigation[3] == 'cpc.php') { echo 'class="on"'; } ?>><a href="/tracking202/update/cpc.php">Update CPC</a></li>
				<li <? if ($navigation[3] == 'clear-subids.php') { echo 'class="on"'; } ?>><a href="/tracking202/update/clear-subids.php">Reset Campaign Subids</a></li>
				<li <? if ($navigation[3] == 'delete-subids.php') { echo 'class="on"'; } ?>><a href="/tracking202/update/delete-subids.php">Delete Subids</a></li>
				<li <? if ($navigation[3] == 'upload.php') { echo 'class="on"'; } ?>><a href="/tracking202/update/upload.php">Upload Revenue Reports</span></a></li>
			</ul>
		<? } ?>
		<? if ($_SESSION['stats202_enabled']) { ?>
			<? if ($navigation[2] == 'sync') {  $nav = true;?>
				<ul>
					<li <? if ($navigation[3] == 'sync-stats202.php') { echo 'class="on"'; } ?>><a href="/tracking202/sync/sync-stats202.php">Sync Stats202</a></li>
				</ul>
			<? } ?>
		<? } ?>
		
		<? if ($nav != true) { ?><ul><li></li></ul><? } ?>
	</div>
</div>

