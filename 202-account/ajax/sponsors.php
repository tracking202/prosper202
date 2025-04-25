<?php
include_once(str_repeat("../", 2) . '202-config/connect.php');

AUTH::require_user();

$json = getUrl(TRACKING202_RSS_URL . '/prosper202/sponsors?type=json');
$json = json_decode($json, true);

// Check if $json is not null and contains the 'sponsors' key
if (is_array($json) && isset($json['sponsors'])) {
	$sponsors = $json['sponsors'];
	if (!$sponsors) die();
	foreach ($sponsors as $sponsor) {

		$html = array_map('htmlentities', $sponsor);

		echo '<div class="row app-row" style="margin-bottom: 10px;">';
		echo '<div class="col-xs-2">';
		echo '<a href="' . $html['url'] . '" target="_blank"><img style="width: 42px;" src="' . $html['image'] . '"/></a>';
		echo '</div>';
		echo '<div class="col-xs-10">';
		echo '<a href="' . $html['url'] . '" target="_blank">' . $html['name'] . '</a><br/><span>' . $html['description'] . '</span>';
		echo '</div>';
		echo '</div>';
	}
} else {
	// Handle the case when JSON is invalid or doesn't contain sponsors
	echo '<div class="row app-row">No sponsor data available at this time.</div>';
}
