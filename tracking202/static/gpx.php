<?php
declare(strict_types=1);

use Prosper202\Attribution\AttributionServiceFactory;
use Prosper202\Attribution\Repository\Mysql\ConversionJourneyRepository;

//write out a transparent 1x1 gif
header("content-type: image/gif"); 
header('Content-Length: 43');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Expires: Sun, 03 Feb 2008 05:00:00 GMT'); // Date in the past
header("Pragma: no-cache");
header('P3P: CP="Prosper202 does not have a P3P policy"');
echo base64_decode("R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==");

include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/class-dataengine-slim.php');

/**
 * @return int|null
 */
function resolveAdvertiserId(\mysqli $db, int $campaignId)
{
    if ($campaignId <= 0) {
        return null;
    }

    $stmt = $db->prepare('SELECT aff_network_id FROM 202_aff_campaigns WHERE aff_campaign_id = ? LIMIT 1');
    if ($stmt === false) {
        return null;
    }

    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $advertiserId = (int) ($row['aff_network_id'] ?? 0);

    return $advertiserId > 0 ? $advertiserId : null;
}

$settingsService = AttributionServiceFactory::createSettingsService();

//get the aff_camapaign_id
$mysql['user_id'] = 1;
$mysql['click_id'] = 0;
$mysql['cid'] = 0;
$mysql['use_pixel_payout'] = 0;
$advertiserId = null;

//grab the cid
if(array_key_exists('cid',$_GET) && is_numeric($_GET['cid'])) {
	$mysql['cid']= $db->real_escape_string((string)$_GET['cid']);
}
    
// grab the subid
if (array_key_exists('subid', $_GET) && is_numeric($_GET['subid'])) {
    $mysql['click_id'] = $db->real_escape_string((string)$_GET['subid']);
} elseif (array_key_exists('sid', $_GET) && is_numeric($_GET['sid'])) {
    $mysql['click_id'] = $db->real_escape_string((string)$_GET['sid']);
} else { // no subid found get from cookie or fingerprint
       
    // see if it has the cookie in the campaign id, then the general match, then do whatever we can to grab SOMETHING to tie this lead to
    if (isset($_COOKIE['tracking202subid_a_' . $mysql['cid']]) && $_COOKIE['tracking202subid_a_' . $mysql['cid']] && $mysql['cid'] != '0') {
        $mysql['click_id'] = $db->real_escape_string($_COOKIE['tracking202subid_a_' . $mysql['cid']]);
    } else
        if (isset($_COOKIE['tracking202subid']) && $_COOKIE['tracking202subid']) {
            $mysql['click_id'] = $db->real_escape_string($_COOKIE['tracking202subid']);
        } else {
            // ok grab the last click from this ip_id
            $mysql['ip_address'] = $db->real_escape_string($_SERVER['REMOTE_ADDR']);
            $daysago = time() - 2592000; // 30 days ago
            $click_sql1 = "	SELECT 	202_clicks.click_id
					FROM 		202_clicks
					LEFT JOIN	202_clicks_advance USING (click_id)
					LEFT JOIN 	202_ips USING (ip_id) 
					WHERE 	202_ips.ip_address='" . $mysql['ip_address'] . "'
					AND		202_clicks.user_id='" . $mysql['user_id'] . "'  
					AND		202_clicks.click_time >= '" . $daysago . "'
					ORDER BY 	202_clicks.click_id DESC 
					LIMIT 		1";
            
            $click_result1 = $db->query($click_sql1) or record_mysql_error($db, $click_sql1);
            $click_row1 = $click_result1->fetch_assoc();

            if ($click_row1) {
                $mysql['click_id'] = $db->real_escape_string($click_row1['click_id']);
                $mysql['ppc_account_id'] = $db->real_escape_string($click_row1['ppc_account_id'] ?? '');
            }
        }
}

if (is_numeric($mysql['click_id'])) {

	$cpa_sql = "SELECT 202_cpa_trackers.tracker_id_public, 202_trackers.click_cpa, 202_clicks.user_id, 202_clicks.aff_campaign_id, 202_clicks.click_lead, 202_clicks.click_time
				FROM 202_clicks
				LEFT JOIN 202_cpa_trackers USING (click_id) 
				LEFT JOIN 202_trackers USING (tracker_id_public)  
				WHERE click_id = '".$mysql['click_id']."'";
	$cpa_result = $db->query($cpa_sql);
	$cpa_row = $cpa_result->fetch_assoc();

        if (!$cpa_row['click_lead']) {

                $mysql['campaign_id'] = $db->real_escape_string((string) ($cpa_row['aff_campaign_id'] ?? ''));
                $mysql['click_user_id'] = $db->real_escape_string((string) ($cpa_row['user_id'] ?? ''));
                $advertiserId = resolveAdvertiserId($db, (int) $mysql['campaign_id']);
                $mysql['click_time'] = $db->real_escape_string((string) ($cpa_row['click_time'] ?? '0'));

		$conv_time = time();
		$click_time_to_date = new DateTime(date('Y-m-d h:i:s', (int) $mysql['click_time']));
		$conv_time_to_date = new DateTime(date('Y-m-d h:i:s', (int) $conv_time));
		$diff = $click_time_to_date->diff($conv_time_to_date);
		$mysql['time_difference'] =  $db->real_escape_string($diff->d.' days, '.$diff->h.' hours, '.$diff->i.' min and '.$diff->s.' sec');
		$mysql['conv_time'] = $db->real_escape_string((string) $conv_time);
		$mysql['ip'] = $db->real_escape_string($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
		$mysql['user_agent'] = $db->real_escape_string($_SERVER['HTTP_USER_AGENT'] ?? '');
		
		$mysql['click_cpa'] = $db->real_escape_string((string) ($cpa_row['click_cpa'] ?? ''));
	
		if ($mysql['click_cpa']) {
			$sql_set = "click_cpc='".$mysql['click_cpa']."', click_lead='1', click_filtered='0'";
		} else {
			$sql_set = "click_lead='1', click_filtered='0'";
		}

		if (array_key_exists('amount', $_GET) && is_numeric($_GET['amount'])) {
			$mysql['use_pixel_payout'] = 1;
			$mysql['click_payout'] = $db->real_escape_string((string)$_GET['amount']);
		}

		$click_sql = "
			UPDATE
				202_clicks
			SET
				".$sql_set."
		";
		if ($mysql['use_pixel_payout']==1) {
			$click_sql .= "
				, click_payout='".$mysql['click_payout']."'
			";
		}
		$click_sql .= "
			WHERE
				click_id='".$mysql['click_id']."'
		";
		$db->query($click_sql);

		$click_sql = "
			UPDATE
				202_clicks_spy
			SET
				".$sql_set."
		";
		if ($mysql['use_pixel_payout']==1) {
			$click_sql .= "
				, click_payout='".$mysql['click_payout']."'
			";
		}
		$click_sql .= "
			WHERE
				click_id='".$mysql['click_id']."'
		";
		$db->query($click_sql);

		// Get click_payout for conversion log
		$click_payout_for_log = $mysql['click_payout'] ?? '0';
		if (!$mysql['use_pixel_payout']) {
			$payout_sql = "SELECT click_payout FROM 202_clicks WHERE click_id = '".$mysql['click_id']."'";
			$payout_result = $db->query($payout_sql);
			if ($payout_result && $payout_row = $payout_result->fetch_assoc()) {
				$click_payout_for_log = $db->real_escape_string($payout_row['click_payout']);
			}
		}

		$log_sql = "INSERT INTO 202_conversion_logs
				SET conv_id = DEFAULT,
					click_id = '".$mysql['click_id']."',
					campaign_id = '".$mysql['campaign_id']."',
					click_payout = '".$click_payout_for_log."',
					user_id = '".$mysql['click_user_id']."',
					click_time = '".$mysql['click_time']."',
					conv_time = '".$mysql['conv_time']."',
					time_difference = '".$mysql['time_difference']."',
					ip = '".$mysql['ip']."',
					pixel_type = '1',
					user_agent = '".$mysql['user_agent']."'";
                $db->query($log_sql);
                $conversionId = (int) $db->insert_id;

                if ($conversionId > 0) {
                        $scope = [
                                'user_id' => (int) $mysql['click_user_id'],
                                'campaign_id' => (int) $mysql['campaign_id'],
                        ];
                        if ($advertiserId !== null) {
                                $scope['advertiser_id'] = $advertiserId;
                        }

                        if ($settingsService->isMultiTouchEnabled($scope)) {
                                try {
                                        $journeyRepository = new ConversionJourneyRepository($db);
                                        $journeyRepository->persistJourney(
                                                conversionId: $conversionId,
                                                userId: (int) $mysql['click_user_id'],
                                                campaignId: (int) $mysql['campaign_id'],
                                                conversionTime: (int) $mysql['conv_time'],
                                                primaryClickId: (int) $mysql['click_id'],
                                                primaryClickTime: (int) $mysql['click_time']
                                        );
                                } catch (Throwable $journeyError) {
                                        error_log('Failed to persist conversion journey for conv_id ' . $conversionId . ': ' . $journeyError->getMessage());
                                }
                        }
                }

		//set dirty hour
		$de = new DataEngine();
		$data=($de->setDirtyHour($mysql['click_id']));

		// Rebuild attribution snapshots so the attribution page reflects changes immediately
		try {
			$jobRunner = AttributionServiceFactory::createJobRunner();
			$userId = (int) $mysql['click_user_id'];
			$endTime = time();
			$startTime = $endTime - 86400;
			$jobRunner->runForUser($userId, $startTime, $endTime);
		} catch (Throwable $e) {
			error_log('Attribution rebuild after gpx conversion failed: ' . $e->getMessage());
		}
	}
}


