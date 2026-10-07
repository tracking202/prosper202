<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
	require_once __DIR__ . '/../202-config/connect.php';

	$hash = "SELECT install_hash FROM 202_users WHERE user_id = '1'";
	$result = $db->query($hash);
	$row = $result->fetch_assoc();

	if (!isset($_GET['hash']) || !hash_equals((string)$row['install_hash'], (string)$_GET['hash'])) {
		die("Unauthorized!");
	}

	// $db (singleton mysqli) is already established by connect.php and used above.

	$user_sql = 'SELECT user_email, user_daily_email FROM 202_users LEFT JOIN 202_users_pref USING (user_id) WHERE user_id = 1';
	$user_result = $db->query($user_sql);
	$user_row = $user_result->fetch_assoc();

	if (!$user_row['user_daily_email']) {
		die();
	}
	$domain = rtrim($protocol . '' . getTrackingDomain() . get_absolute_url(), '/');
	$data = ['to' => $user_row['user_email'], 'domain' => $domain, 'campaigns' => []];
	$ids = [];

	$time['from_today'] = mktime(0, 0, 0, (int)date('m', time()), (int)date('d', time()), (int)date('Y', time()));
	$time['to_today'] = mktime(23, 59, 59, (int)date('m', time()), (int)date('d', time()), (int)date('Y', time()));

	$time['from_yesterday'] = mktime(0, 0, 0, (int)date('m', time() - 86400), (int)date('d', time() - 86400), (int)date('Y', time() - 86400));
	$time['to_yesterday'] = mktime(23, 59, 59, (int)date('m', time() - 86400), (int)date('d', time() - 86400), (int)date('Y', time() - 86400));

	// The figures are the addressee's own: user 1's clicks, each campaign
	// named only when it is user 1's (DailyEmailCampaigns says why). This read
	// every account's clicks and mailed them to user 1.
	$conn = new \Prosper202\Database\Connection($db);
	$emailUserId = 1;

	foreach (\Prosper202\Report\DailyEmailCampaigns::top($conn, $emailUserId, $time['from_today'], $time['to_today'], 5) as $row_today) {
		$columns_today = [];
		$ids[] = $row_today['aff_campaign_id'];
		foreach ($row_today as $key => $value) {
			if ($key == 'aff_campaign_id' || $key == 'aff_campaign_name') {
				$columns_today[$key] = $value;
			} else {
				// mysqli answers strings, which round() refuses under
				// strict types: this threw a TypeError on the first row.
				$columns_today[$key] = @round((float) $value, 2);
			}
		}

		$data['campaigns'][$row_today['aff_campaign_id']]['today'] = $columns_today;
	}

	// The same campaigns yesterday. A query that fails throws, and the catch
	// below reports it: an unreadable day is not a day with no campaigns.
	foreach (\Prosper202\Report\DailyEmailCampaigns::forCampaigns($conn, $emailUserId, $ids, $time['from_yesterday'], $time['to_yesterday']) as $row_yesterday) {
		$difference = [];

		foreach ($row_yesterday as $key => $value) {
			if ($key == 'aff_campaign_id' || $key == 'aff_campaign_name') {
				continue;
			}

			$today_value = $data['campaigns'][$row_yesterday['aff_campaign_id']]['today'][$key];

			$math = 0;

			// A change from nothing is not a percentage: dividing by it threw
			// DivisionByZeroError, which the catch below does not catch.
			if ($today_value != $value && (float) $value != 0.0) {
				$math = @round((($today_value - (float) $value) / (float) $value * 100), 2);
			}

			if ($math != 0) {
				$difference[$key] = $math . '%';
			}
		}

		$data['campaigns'][$row_yesterday['aff_campaign_id']]['difference'] = $difference;
	}

	if (count($data['campaigns']) > 0) {
		$curl = curl_init('https://my.tracking202.com/api/v2/send-daily-email');
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-type: application/json"]);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
		$response = curl_exec($curl);
		print_r(json_decode($response));
	}
} catch (Exception $e) {
	echo "Error: " . $e->getMessage();
	error_log("Daily Email Error: " . $e->getMessage());
}
