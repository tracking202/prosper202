<?php
include_once(str_repeat("../", 1).'202-config/connect.php'); 

	$hash = "SELECT install_hash FROM 202_users WHERE user_id = '1'";
	$result = $db->query($hash);
	$row = $result->fetch_assoc();

	if ($row['install_hash'] != $_GET['hash']) {
		die("Unautorized!");
	}

    $database = DB::getInstance();
	$db = $database->getConnection();
	
	$user_sql = 'SELECT user_email, user_daily_email FROM 202_users LEFT JOIN 202_users_pref USING (user_id) WHERE user_id = 1';
	$user_result = $db->query($user_sql);
	$user_row = $user_result->fetch_assoc();
	
	if (!$user_row['user_daily_email']) {
		die();
	}
    $domain = rtrim($protocol . '' . getTrackingDomain(). get_absolute_url(), '/');
	$data = array('to' => $user_row['user_email'], 'domain' => $domain, 'campaigns' => array());
	$ids = array();

	$time['from_today'] = mktime(0,0,0,date('m',time()),date('d',time()),date('Y',time()));
	$time['to_today'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));
	
	$time['from_yesterday'] = mktime(0,0,0,date('m',time()-86400),date('d',time()-86400),date('Y',time()-86400));
	$time['to_yesterday'] = mktime(23,59,59,date('m',time()-86400),date('d',time()-86400),date('Y',time()-86400));
		
	$sql_today = "SELECT 
			2ca.aff_campaign_id,
			2ca.aff_campaign_name,
			COUNT(*) AS clicks,
			SUM(2cr.click_out) AS click_throughs,
			(SUM(2cr.click_out)/COUNT(*))*100 AS ctr,
			SUM(2c.click_lead) AS leads,
			(SUM(2c.click_lead)/COUNT(*))*100 as su_ratio,
			SUM(2c.click_payout*2c.click_lead) AS income,
			SUM(2c.click_cpc) AS cost,
			(SUM(2c.click_payout*2c.click_lead)-SUM(2c.click_cpc)) AS net,
			((SUM(2c.click_payout*2c.click_lead)-SUM(2c.click_cpc))/SUM(2c.click_cpc)*100 ) as roi 
			FROM 202_clicks AS 2c
			LEFT JOIN 202_clicks_record AS 2cr USING (click_id)
			LEFT JOIN 202_aff_campaigns AS 2ca USING (aff_campaign_id)
			WHERE 2c.click_time >= ".$time['from_today']."
			AND 2c.click_time <= ".$time['to_today']."
			GROUP BY 2ca.aff_campaign_id
			ORDER BY net DESC
			LIMIT 5";
	$result_today = $db->query($sql_today);

	if ($result_today->num_rows > 0) {
		while ($row_today = $result_today->fetch_assoc()) {
			$columns_today = array();
			$ids[] = $row_today['aff_campaign_id'];
			foreach ($row_today as $key => $value) {
				if ($key == 'aff_campaign_id' || $key == 'aff_campaign_name') {
					$columns_today[$key] = $value;
				} else {
					$columns_today[$key] = @round($value, 2);
				}
			}

			$data['campaigns'][$row_today['aff_campaign_id']]['today'] = $columns_today;

		}
	}

	$sql_yesterday = "SELECT
		2c.aff_campaign_id, 
		2ca.aff_campaign_name,
		COUNT(*) AS clicks,
		SUM(2cr.click_out) AS click_throughs,
		(COUNT(*)/SUM(2cr.click_out))*100 AS ctr,
		SUM(2c.click_lead) AS leads,
		(SUM(2c.click_lead)/COUNT(*))*100 as su_ratio,
		SUM(2c.click_payout*2c.click_lead) AS income,
		SUM(2c.click_cpc) AS cost,
		(SUM(2c.click_payout*2c.click_lead)-SUM(2c.click_cpc)) AS net,
		((SUM(2c.click_payout*2c.click_lead)-SUM(2c.click_cpc))/SUM(2c.click_cpc)*100 ) as roi 
		FROM 202_clicks AS 2c
		LEFT JOIN 202_clicks_record AS 2cr USING (click_id)
		LEFT JOIN 202_aff_campaigns AS 2ca USING (aff_campaign_id)
		WHERE 2c.aff_campaign_id IN (".implode(",", $ids).")
		AND 2c.click_time >= ".$time['from_yesterday']."
		AND 2c.click_time <= ".$time['to_yesterday']."
		GROUP BY 2c.aff_campaign_id";
	$result_yesterday = $db->query($sql_yesterday);
	
	if ($result_yesterday->num_rows > 0) {
		while ($row_yesterday = $result_yesterday->fetch_assoc()) {
			$difference = array();

			foreach ($row_yesterday as $key => $value) {
				if ($key == 'aff_campaign_id' || $key == 'aff_campaign_name') {
					continue;
				}

				$today_value = $data['campaigns'][$row_yesterday['aff_campaign_id']]['today'][$key];

				$math = 0;

				if ($today_value != $value) {
					$math = @round((($today_value - $value) / $value * 100),2);
				}
				
				if ($math != 0) {
					$difference[$key] = $math.'%';
				}
			}

			$data['campaigns'][$row_yesterday['aff_campaign_id']]['difference'] = $difference;
		}
	}

	if (count($data['campaigns']) > 0) {
		$curl = curl_init('https://my.tracking202.com/api/v2/send-daily-email');
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, true));
		$response = curl_exec($curl);
		print_r(json_decode($response));
	}
	
?>	