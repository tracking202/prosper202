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
include_once(substr(__DIR__, 0,-19) . '/202-config/static-endpoint-helpers.php');

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
	if ($cpa_result === false) {
		error_log('gpx: cpa lookup failed: ' . $db->error);
		return;
	}
	$cpa_row = $cpa_result->fetch_assoc();
	if (!$cpa_row) {
		// Unknown click id; nothing to convert.
		return;
	}

        if (!$cpa_row['click_lead']) {

                $mysql['campaign_id'] = $db->real_escape_string((string) ($cpa_row['aff_campaign_id'] ?? ''));
                $mysql['click_user_id'] = $db->real_escape_string((string) ($cpa_row['user_id'] ?? ''));
                $advertiserId = p202ResolveAdvertiserId($db, (int) $mysql['campaign_id']);
                $mysql['click_time'] = $db->real_escape_string((string) ($cpa_row['click_time'] ?? '0'));

		$conv_time = time();
		$click_time_raw = (int) ($cpa_row['click_time'] ?? 0);
		$click_time_to_date = new DateTime(date('Y-m-d H:i:s', $click_time_raw));
		$conv_time_to_date = new DateTime(date('Y-m-d H:i:s', (int) $conv_time));
		$diff = $click_time_to_date->diff($conv_time_to_date);
		$time_difference = $diff->d.' days, '.$diff->h.' hours, '.$diff->i.' min and '.$diff->s.' sec';
		$mysql['conv_time'] = $conv_time;

		$mysql['click_cpa'] = $db->real_escape_string((string) ($cpa_row['click_cpa'] ?? ''));

			if (array_key_exists('amount', $_GET) && is_numeric($_GET['amount'])) {
				$mysql['use_pixel_payout'] = 1;
				$mysql['click_payout'] = $db->real_escape_string((string)$_GET['amount']);
			}

		// payout to record: pixel amount override if present, otherwise the click's own payout
		$click_payout_for_log = ($mysql['use_pixel_payout'] == 1) ? (string) ($_GET['amount'] ?? '0') : '0';
		if ($mysql['use_pixel_payout'] != 1) {
			$payout_sql = "SELECT click_payout FROM 202_clicks WHERE click_id = " . (int) $mysql['click_id'];
			$payout_result = $db->query($payout_sql);
			if ($payout_result && $payout_row = $payout_result->fetch_assoc()) {
				$click_payout_for_log = (string) $payout_row['click_payout'];
			}
		}

		// Atomic + idempotent: locks the click, dedupes on transaction id, and
		// applies the click update and conversion_logs insert in one transaction.
		$conversionResult = p202RecordConversion(
			$db,
			[
				'click_id'        => (int) $mysql['click_id'],
				'campaign_id'     => (string) ($cpa_row['aff_campaign_id'] ?? '0'),
				'user_id'         => (string) ($cpa_row['user_id'] ?? '0'),
				'click_time'      => $click_time_raw,
				'conv_time'       => $conv_time,
				'time_difference' => $time_difference,
				'ip'              => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
				'pixel_type'      => 1,
				'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
				'click_payout'    => $click_payout_for_log,
			],
			(string) ($cpa_row['click_cpa'] ?? ''),
			$mysql['use_pixel_payout'] == 1,
			($mysql['use_pixel_payout'] == 1) ? (string) ($_GET['amount'] ?? '') : '',
			p202ExtractTransactionId($_GET)
		);
		$conversionId = $conversionResult['conv_id'];

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

