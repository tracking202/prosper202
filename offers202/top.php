
<div style="padding: 10px 0px;"></div>

<div id="nav-primary">  
	<ul name="navbar">
		<li class="<? if (!$navigation[2]) { echo 'on'; } ?>"><a href="/offers202/" name="setup">Offer Search</a></li>
		<li class="<? if ($navigation[2] == 'rss') { echo 'on'; } ?>"><a href="/offers202/rss/" name="setup">RSS Feeds</a></li>
	</ul>
  </div>
 
  <div id="nav-secondary" <? if (($navigation[2] == 'help')) { echo ' class="core" '; } ?>>
	  <div>
	  	<? if (!$nav) echo "<ul><li></li></ul>"; ?>
	</div>
</div>


