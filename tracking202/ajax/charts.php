<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -17) . '/202-config/class-dataengine.php');

AUTH::require_user();

AUTH::set_timezone($_SESSION['user_timezone']);

$time = grab_timeframe();
$mysql['to'] = $db->real_escape_string((string)$time['to']);
$mysql['from'] = $db->real_escape_string((string)$time['from']);
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if (isset($_POST['chart_time_range']) && $_POST['chart_time_range']) {
		header("Content-type: text/json");
		$data = [];
		$range = [];
		$de = new DataEngine();

		$mysql['chart_time_range'] = $db->real_escape_string((string)$_POST['chart_time_range']);

		$sql = "UPDATE 202_charts SET chart_time_range = '" . $mysql['chart_time_range'] . "'";
		$result = $db->query($sql);

		$sql = "SELECT * FROM 202_charts WHERE user_id = '" . $mysql['user_id'] . "'";
		$result = $db->query($sql);
		$user_row = $result->fetch_assoc();

		$start = new DateTime('@' . $mysql['from']);
		$end = new DateTime('@' . $mysql['to']);

		switch ($user_row['chart_time_range']) {
			case 'hours':
				$rangeOutputFormat = 'M d h:iA';
				break;

			case 'days':
				$rangeOutputFormat = 'M d';
				break;
		}

		$user_row['user_chart_data'] = unserialize($user_row['data']);
		$rangePeriod = returnRanges($start, $end, $user_row['chart_time_range']);
		$chart = $de->getChart($mysql['from'], $mysql['to'], $user_row['user_chart_data'], $user_row['chart_time_range'], $rangeOutputFormat, $rangePeriod);

		$data['json'] = $chart;
		foreach ($rangePeriod as $r) {
			$range[] = $r->format($rangeOutputFormat);
		}
		$data['categories'] = $range;
		$data['title'] = "From " . date('d/m/Y', $mysql['from']) . " to " . date('d/m/Y', $mysql['to']);

		echo json_encode($data, JSON_NUMERIC_CHECK);
	} else {

		$data = [];
		$keys = array_keys($_POST['levels']);

		foreach ($keys as $key) {
			$data[] = ['campaign_id' => $_POST['levels'][$key]['id'], 'value_type' => $_POST['types'][$key]['type']];
		}

		$serialize = serialize($data);
		$mysql['serialize'] = $db->real_escape_string($serialize);

		$sql = "UPDATE 202_charts SET data = '" . $mysql['serialize'] . "' WHERE user_id = '" . $mysql['user_id'] . "'";
		$result = $db->query($sql);
	}
}
