<?php
declare(strict_types=1);
include_once(str_repeat("../", 2) . '202-config/connect.php');
include_once(str_repeat("../", 2) . '202-config/DashboardDataManager.class.php');

AUTH::require_user();

// Get cached sponsors from local database
$dataManager = new DashboardDataManager();
$sponsors = $dataManager->getContent('sponsors', 10);

// Check if sponsors are available
if (empty($sponsors)) {
	echo '<div class="row app-row">No sponsor data available at this time.</div>';
	exit;
}

foreach ($sponsors as $sponsor) {
	// Extract data from JSON if available, fallback to direct fields
	$data = json_decode($sponsor['data'] ?? '{}', true) ?: [];
	
	$name = $sponsor['title'] ?? $data['name'] ?? '';
	$description = $sponsor['description'] ?? $data['description'] ?? '';
	$url = $sponsor['link'] ?? $data['url'] ?? '';
	$image = $sponsor['image_url'] ?? $data['image'] ?? '';
	
	// Sanitize all output
	$html = [
		'name' => htmlentities($name),
		'description' => htmlentities($description),
		'url' => htmlentities($url),
		'image' => htmlentities($image)
	];

	echo '<div class="row app-row" style="margin-bottom: 10px;">';
	echo '<div class="col-xs-2">';
	if ($html['image'] && $html['url']) {
		echo '<a href="' . $html['url'] . '" target="_blank"><img style="width: 42px;" src="' . $html['image'] . '"/></a>';
	}
	echo '</div>';
	echo '<div class="col-xs-10">';
	if ($html['name'] && $html['url']) {
		echo '<a href="' . $html['url'] . '" target="_blank">' . $html['name'] . '</a>';
	} elseif ($html['name']) {
		echo $html['name'];
	}
	if ($html['description']) {
		echo '<br/><span>' . $html['description'] . '</span>';
	}
	echo '</div>';
	echo '</div>';
}
