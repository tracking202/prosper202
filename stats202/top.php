<?/* if ($_SESSION['stats202_enabled']) { ?>
	<div id="stats202-download-status"></div>
	<script type="text/javascript">
		downloadStatus();
		new PeriodicalExecuter(downloadStatus, 5); 	
	</script>
<? } else { ?>
	<div style="padding: 10px 0px;"></div>
<? } */?>



<div id="nav-primary">  
	<ul name="navbar">
		<li class="<? if ($navigation[2] == 'setup') { echo 'on'; } ?>"><a href="/stats202/setup/" name="setup">Setup</a></li>
		<li class="<? if ($navigation[2] == 'earnings') { echo 'on'; } ?>"><a href="/stats202/earnings/" name="setup">Network Earnings</a></li>
		<li class="<? if ($navigation[2] == 'offers') { echo 'on'; } ?>"><a href="/stats202/offers/" name="jobs">Offer Stats</a></li>
		<li class="<? if ($navigation[2] == 'subids') { echo 'on'; } ?>"><a href="/stats202/subids/" name="visitors">Subid Stats</a></li>      
		<li class="<? if ($navigation[2] == 'postback') { echo 'on'; } ?>"><a href="/stats202/postback/" name="spy">Postback URLs</a></li>
	</ul>
  </div>
 
  <div id="nav-secondary" <? if (($navigation[2] == 'help')) { echo ' class="core" '; } ?>>
	  <div>
	  	<? if ($navigation[2] == 'setup') { $nav = true; ?>
			<ul>
				<li <? if (!$navigation[3]) { echo 'class="on"'; } ?>><a href="/stats202/setup/">Manage Accounts</a></li>
				<li <? if ($navigation[3] == 'new') { echo 'class="on"'; } ?>><a href="/stats202/setup/new/">Add New Account</a></li> 
			</ul>
		<? } ?>
		
		<? if (!$nav) echo "<ul><li></li></ul>"; ?>
	</div>
</div>

<br/>