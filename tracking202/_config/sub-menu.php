<?php
/**
 * The sub-menu under the Prosper202 CS section tabs: the Setup button grid,
 * or the strip of report links for Overview, Analyze and Update.
 *
 * Part of the shared chrome — framework-neutral markup styled by
 * 202-css/p202-chrome.css and rendered by both page shells, with inline SVG
 * icons from p202_chrome_icon(). The strip keeps its historical id and its
 * li.active shape because 202-js/p202-chrome.js scrolls the active item into
 * view on narrow screens. Never add a Bootstrap class of either version here;
 * tests/Api/V3/NoLegacyBootstrapClassesTest.php scans this file.
 *
 * The setup pages' own component styles (page header, side panel) used to be
 * emitted from here as an inline <style> block; they live in
 * 202-css/custom.css now, scoped to body.p202-sub-setup, and go away with the
 * classic shell.
 */
$p202Nav1 = (string) ($navigation[1] ?? '');
$p202Nav2 = (string) ($navigation[2] ?? '');
$p202Nav3 = (string) ($navigation[3] ?? '');
$p202Base = get_absolute_url();
$p202E = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

if ($p202Nav2 === 'setup') {
	// label, icon, page, the page names it is current for ('' is the section index)
	$p202SetupItems = [
		['Traffic Sources', 'globe', 'ppc_accounts.php', ['ppc_accounts.php', '']],
		['Categories', 'grid', 'aff_networks.php', ['aff_networks.php']],
		['Campaigns', 'link', 'aff_campaigns.php', ['aff_campaigns.php']],
		['Landing Pages', 'file', 'landing_pages.php', ['landing_pages.php']],
		['Text Ads', 'fonts', 'text_ads.php', ['text_ads.php']],
		['Redirector', 'repeat', 'rotator.php', ['rotator.php']],
		['Attribution', 'chart', 'attribution_models.php', ['attribution_models.php']],
		['Mobile Apps', 'phone', 'mobile_apps.php', ['mobile_apps.php']],
		['Get LP Code', 'terminal', 'get_landing_code.php', ['get_landing_code.php', 'get_simple_landing_code.php', 'get_adv_landing_code.php']],
		['Get Links', 'link', 'get_trackers.php', ['get_trackers.php']],
		['Postback/Pixel', 'transfer', 'get_postback.php', ['get_postback.php']],
	];
	?>
<nav class="p202c-subnav" aria-label="Setup">
	<ul class="p202c-subnav__list">
		<?php foreach ($p202SetupItems as [$p202Label, $p202Icon, $p202Page, $p202Pages]) {
			$p202Active = in_array($p202Nav3, $p202Pages, true); ?>
		<li><a class="p202c-subnav__link<?php echo $p202Active ? ' is-active' : ''; ?>" href="<?php echo $p202E($p202Base . 'tracking202/setup/' . $p202Page); ?>"<?php echo $p202Active ? ' aria-current="page"' : ''; ?>><?php echo p202_chrome_icon($p202Icon); ?><span><?php echo $p202E($p202Label); ?></span></a></li>
		<?php } ?>
	</ul>
</nav>
<?php
	return;
}

// label, path, the page names it is current for ('' is the section index)
$p202StripItems = [];
$p202StripLabel = '';
if (($p202Nav1 === 'account' && $p202Nav2 === '') || $p202Nav2 === 'overview') {
	$p202StripLabel = 'Overview reports';
	$p202StripItems = [
		['Campaign Overview', 'tracking202/overview', ['campaign.php', '']],
		['Breakdown Analysis', 'tracking202/overview/breakdown.php', ['breakdown.php']],
		['Day Parting', 'tracking202/overview/day-parting.php', ['day-parting.php']],
		['Week Parting', 'tracking202/overview/week-parting.php', ['week-parting.php']],
		['Group Overview', 'tracking202/overview/group-overview.php', ['group-overview.php']],
	];
} elseif ($p202Nav2 === 'analyze') {
	$p202StripLabel = 'Analyze reports';
	$p202StripItems = [
		['Keywords', 'tracking202/analyze/keywords.php', ['keywords.php', '']],
		['Text Ads', 'tracking202/analyze/text_ads.php', ['text_ads.php']],
		['Referers', 'tracking202/analyze/referers.php', ['referers.php']],
		['IPs', 'tracking202/analyze/ips.php', ['ips.php']],
		['Countries', 'tracking202/analyze/countries.php', ['countries.php']],
		['Regions', 'tracking202/analyze/regions.php', ['regions.php']],
		['Cities', 'tracking202/analyze/cities.php', ['cities.php']],
		['ISP/Carrier', 'tracking202/analyze/isp.php', ['isp.php']],
		['Landing Pages', 'tracking202/analyze/landing_pages.php', ['landing_pages.php']],
		['Devices', 'tracking202/analyze/devices.php', ['devices.php']],
		['Browsers', 'tracking202/analyze/browsers.php', ['browsers.php']],
		['Platforms', 'tracking202/analyze/platforms.php', ['platforms.php']],
		['Custom Variables', 'tracking202/analyze/variables.php', ['variables.php']],
		['Customer LTV', 'tracking202/analyze/ltv.php', ['ltv.php']],
	];
} elseif ($p202Nav2 === 'update') {
	$p202StripLabel = 'Update tools';
	$p202StripItems = [
		['Update Subids', 'tracking202/update/subids.php', ['subids.php', '']],
		['Update CPC', 'tracking202/update/cpc.php', ['cpc.php']],
		['Reset Campaign Subids', 'tracking202/update/clear-subids.php', ['clear-subids.php']],
	];
	if (isset($userObj) && $userObj->hasPermission('delete_individual_subids')) {
		$p202StripItems[] = ['Delete Subids', 'tracking202/update/delete-subids.php', ['delete-subids.php']];
	}
	$p202StripItems[] = ['Upload Revenue Reports', 'tracking202/update/upload.php', ['upload.php']];
}

if ($p202StripItems !== []) { ?>
<nav id="sub-menu" class="p202c-strip" aria-label="<?php echo $p202E($p202StripLabel); ?>">
	<ul class="p202c-strip__list">
		<?php foreach ($p202StripItems as [$p202Label, $p202Href, $p202Pages]) {
			$p202Active = in_array($p202Nav3, $p202Pages, true); ?>
		<li<?php echo $p202Active ? ' class="active"' : ''; ?>><a href="<?php echo $p202E($p202Base . $p202Href); ?>"<?php echo $p202Active ? ' aria-current="page"' : ''; ?>><?php echo $p202E($p202Label); ?></a></li>
		<?php } ?>
	</ul>
</nav>
<?php } ?>
