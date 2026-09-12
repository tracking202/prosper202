<?php
/**
 * The Prosper202 CS section tabs. Part of the shared chrome: framework-neutral
 * markup styled by 202-css/p202-chrome.css, rendered by both page shells.
 * The list keeps its historical id and li.active shape because
 * 202-js/custom.php scrolls the active tab into view on narrow screens.
 */
$p202Nav1 = (string) ($navigation[1] ?? '');
$p202Nav2 = (string) ($navigation[2] ?? '');
?>
<nav class="p202c-tabs" aria-label="Prosper202 CS sections">
	<ul id="second-nav" class="p202c-tabs__list">
		<?php if ($userObj->hasPermission("access_to_setup_section")) { ?>
			<li<?php if ($p202Nav2 == 'setup') { echo ' class="active"'; } ?>>
				<a href="<?php echo get_absolute_url();?>tracking202/setup" id="SetupPage">Setup</a>
			</li>
		<?php } ?>
		<li<?php if (($p202Nav1 == 'account' and !$p202Nav2) or ($p202Nav2 == 'overview')) { echo ' class="active"'; } ?>>
			<a href="<?php echo get_absolute_url();?>tracking202/overview" id="OverviewPage">Overview</a>
		</li>
		<li<?php if ($p202Nav2 == 'analyze') { echo ' class="active"'; } ?>>
			<a href="<?php echo get_absolute_url();?>tracking202/analyze" id="AnalyzePage">Analyze</a>
		</li>
		<li<?php if ($p202Nav2 == 'visitors') { echo ' class="active"'; } ?>>
			<a href="<?php echo get_absolute_url();?>tracking202/visitors" id="VisitorsPage">Visitors</a>
		</li>
		<li<?php if ($p202Nav2 == 'spy') { echo ' class="active"'; } ?>>
			<a href="<?php echo get_absolute_url();?>tracking202/spy" id="SpyPage">Spy</a>
		</li>
		<?php if ($userObj->hasPermission("access_to_update_section")) { ?>
			<li<?php if ($p202Nav2 == 'update') { echo ' class="active"'; } ?>>
				<a href="<?php echo get_absolute_url();?>tracking202/update" id="UpdatePage">Update</a>
			</li>
		<?php } ?>
	</ul>
</nav>
