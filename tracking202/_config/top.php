
<ul id="second-nav" class="nav nav-tabs">
	<?php if ($userObj->hasPermission("access_to_setup_section")) { ?>
		<li <?php if ($navigation[2] == 'setup') { echo 'class="active"'; } ?>>
			<a href="<?php echo get_absolute_url();?>tracking202/setup" id="SetupPage">Setup</a>
		</li>
	<?php } ?>	
	<li
		<?php if (($navigation[1] == 'account' and !$navigation[2]) or ($navigation[2] == 'overview'))  { echo 'class="active"'; } ?>><a
		href="<?php echo get_absolute_url();?>tracking202/overview" id="OverviewPage">Overview</a></li>
	<li <?php if ($navigation[2] == 'analyze') { echo 'class="active"'; } ?>><a
		href="<?php echo get_absolute_url();?>tracking202/analyze" id="AnalyzePage">Analyze</a></li>
	<li <?php if ($navigation[2] == 'visitors') { echo 'class="active"'; } ?>><a
		href="<?php echo get_absolute_url();?>tracking202/visitors" id="VisitorsPage">Visitors</a></li>
	<li <?php if ($navigation[2] == 'spy') { echo 'class="active"'; } ?>><a
		href="<?php echo get_absolute_url();?>tracking202/spy" id="SpyPage">Spy</a></li>
	<?php if ($userObj->hasPermission("access_to_update_section")) { ?>
		<li <?php if ($navigation[2] == 'update') { echo 'class="active"'; } ?>>
			<a href="<?php echo get_absolute_url();?>tracking202/update" id="UpdatePage">Update</a>
		</li>
	<?php } ?>	
</ul>


