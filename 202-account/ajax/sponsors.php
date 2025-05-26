<?php

declare(strict_types=1);
include_once(str_repeat("../", 2) . '202-config/connect.php');

AUTH::require_user();

$json = getUrl(TRACKING202_RSS_URL . '/prosper202/sponsors?type=json');
$json = json_decode($json, true);

// prevent null or missing sponsors key
if (!is_array($json) || !isset($json['sponsors']) || !is_array($json['sponsors'])) {
	http_response_code(204);
	exit;
}

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
