<?php
/**
 * The Prosper202 CS section tabs. Part of the shared chrome: framework-neutral
 * markup styled by 202-css/p202-chrome.css, rendered by both page shells.
 * The list keeps its historical id and li.active shape because
 * 202-js/p202-chrome.js scrolls the active tab into view on narrow screens.
 */
$p202Nav1 = (string) ($navigation[1] ?? '');
$p202Nav2 = (string) ($navigation[2] ?? '');
$p202E = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

// id, path, label, current?
$p202Tabs = [];
if ($userObj->hasPermission('access_to_setup_section')) {
	$p202Tabs[] = ['SetupPage', 'tracking202/setup', 'Setup', $p202Nav2 === 'setup'];
}
$p202Tabs[] = ['OverviewPage', 'tracking202/overview', 'Overview', ($p202Nav1 === 'account' && $p202Nav2 === '') || $p202Nav2 === 'overview'];
$p202Tabs[] = ['AnalyzePage', 'tracking202/analyze', 'Analyze', $p202Nav2 === 'analyze'];
$p202Tabs[] = ['VisitorsPage', 'tracking202/visitors', 'Visitors', $p202Nav2 === 'visitors'];
$p202Tabs[] = ['SpyPage', 'tracking202/spy', 'Spy', $p202Nav2 === 'spy'];
if ($userObj->hasPermission('access_to_update_section')) {
	$p202Tabs[] = ['UpdatePage', 'tracking202/update', 'Update', $p202Nav2 === 'update'];
}
?>
<nav class="p202c-tabs" aria-label="Prosper202 CS sections">
	<ul id="second-nav" class="p202c-tabs__list">
		<?php foreach ($p202Tabs as [$p202Id, $p202Path, $p202Label, $p202Active]) { ?>
		<li<?php echo $p202Active ? ' class="active"' : ''; ?>><a href="<?php echo $p202E(get_absolute_url() . $p202Path); ?>" id="<?php echo $p202E($p202Id); ?>"<?php echo $p202Active ? ' aria-current="page"' : ''; ?>><?php echo $p202E($p202Label); ?></a></li>
		<?php } ?>
	</ul>
</nav>
