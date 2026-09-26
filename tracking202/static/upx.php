<?php
declare(strict_types=1);

use Prosper202\Attribution\AttributionServiceFactory;
use Prosper202\Attribution\Repository\Mysql\ConversionJourneyRepository;
header('P3P: CP="Prosper202 does not have a P3P policy"');
include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/static-endpoint-helpers.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/functions-tracking202api.php');

$settingsService = AttributionServiceFactory::createSettingsService();

//get the aff_camapaign_id
$mysql['user_id'] = 1;
$mysql['click_id'] = 0;
$mysql['cid'] = 0;
$mysql['use_pixel_payout'] = 0;
$advertiserId = null;

//grab the cid (the campaign whose own cookie names the click)
$campaignIdFromRequest = p202ParseClickId($_GET['cid'] ?? null) ?? 0;
$mysql['cid'] = (string) $campaignIdFromRequest;

// The click: the subid parameter, the campaign's cookie, the general cookie
// (p202ClickIdFromRequest). A value that is present and not an exact click
// id is refused rather than cast ("123.9" is not click 123) and rather than
// falling back to the IP lookup below.
$requestedClick = p202ClickIdFromRequest($_GET, $_COOKIE, $campaignIdFromRequest);
if ($requestedClick['malformed'] !== null) {
    error_log('upx: refusing malformed ' . $requestedClick['malformed'] . ' click id');
    exit;
}
if ($requestedClick['click_id'] !== null) {
    $mysql['click_id'] = (string) $requestedClick['click_id'];
} else { // nothing named a click: fall back to this address's last click
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

if(!$mysql['click_id']){
    p202RespondJsonError(404, 'SubID not found');
}

// integer click_id for safe SQL interpolation
$clickId = (int) $mysql['click_id'];

$site_urls=" LEFT JOIN `202_clicks_site` AS 2cs ON (2c.click_id=2cs.click_id)
                     LEFT JOIN `202_site_urls` AS 2su ON (2cs.click_referer_site_url_id=2su.site_url_id) ";

//get c1-c4 values etc
$cvar_sql ="
SELECT 
	2cid.click_id,
	2c.user_id,
	2c.aff_campaign_id,
	2c.click_payout,
	2c.click_cpc,
	2c.click_lead,
	2c.click_time,
	2c1.c1,
	2c2.c2,
	2c3.c3,
	2c4.c4,
	2kw.keyword,
	2g.gclid,
	2us.utm_source,
	2um.utm_medium,
	2uca.utm_campaign,
	2ut.utm_term,
	2uco.utm_content,
	2trc.click_cpa,
    2su.site_url_address
FROM `202_clicks_tracking` AS 2cid
LEFT JOIN `202_clicks_advance` AS 2ca USING (`click_id`)
LEFT JOIN `202_google` AS 2g USING (`click_id`)
LEFT JOIN `202_clicks` AS 2c USING (`click_id`)
LEFT JOIN `202_tracking_c1` AS 2c1 USING (`c1_id`)
LEFT JOIN `202_tracking_c2` AS 2c2 USING (`c2_id`)
LEFT JOIN `202_tracking_c3` AS 2c3 USING (`c3_id`)
LEFT JOIN `202_tracking_c4` AS 2c4 USING (`c4_id`)
LEFT JOIN `202_utm_source` AS 2us USING (`utm_source_id`)
LEFT JOIN `202_utm_medium` AS 2um USING (`utm_medium_id`)
LEFT JOIN `202_utm_campaign` AS 2uca USING (`utm_campaign_id`)
LEFT JOIN `202_utm_term` AS 2ut USING (`utm_term_id`)
LEFT JOIN `202_utm_content` AS 2uco USING (`utm_content_id`)
LEFT JOIN `202_keywords` AS 2kw ON (2ca.`keyword_id` = 2kw.`keyword_id`)
LEFT JOIN `202_cpa_trackers` AS 2cpa USING (`click_id`)
LEFT JOIN `202_trackers` AS 2trc ON (2cpa.`tracker_id_public` = 2trc.`tracker_id_public`)".$site_urls."
WHERE 2c.`click_id` = {$clickId}
LIMIT 1";

$cvar_sql_result = $db->query($cvar_sql);
$cvar_sql_row = $cvar_sql_result ? $cvar_sql_result->fetch_assoc() : null;
if (!$cvar_sql_row) {
    p202RespondJsonError(404, 'Click data not found');
}
$mysql['t202kw'] = $db->real_escape_string((string) ($cvar_sql_row['keyword'] ?? ''));
$mysql['c1'] = $db->real_escape_string((string) ($cvar_sql_row['c1'] ?? ''));
$mysql['c2'] = $db->real_escape_string((string) ($cvar_sql_row['c2'] ?? ''));
$mysql['c3'] = $db->real_escape_string((string) ($cvar_sql_row['c3'] ?? ''));
$mysql['c4'] = $db->real_escape_string((string) ($cvar_sql_row['c4'] ?? ''));
$mysql['gclid'] = $db->real_escape_string((string) ($cvar_sql_row['gclid'] ?? ''));
$mysql['utm_source'] = $db->real_escape_string((string) ($cvar_sql_row['utm_source'] ?? ''));
$mysql['utm_medium'] = $db->real_escape_string((string) ($cvar_sql_row['utm_medium'] ?? ''));
$mysql['utm_campaign'] = $db->real_escape_string((string) ($cvar_sql_row['utm_campaign'] ?? ''));
$mysql['utm_term'] = $db->real_escape_string((string) ($cvar_sql_row['utm_term'] ?? ''));
$mysql['utm_content'] = $db->real_escape_string((string) ($cvar_sql_row['utm_content'] ?? ''));
$mysql['click_user_id'] = $db->real_escape_string((string) ($cvar_sql_row['user_id'] ?? ''));
$mysql['campaign_id'] = $db->real_escape_string((string) ($cvar_sql_row['aff_campaign_id'] ?? ''));
$advertiserId = p202ResolveAdvertiserId($db, (int) $mysql['campaign_id']);
$mysql['payout'] = $db->real_escape_string((string) ($cvar_sql_row['click_payout'] ?? '0'));
$mysql['cpc'] = $db->real_escape_string((string) ($cvar_sql_row['click_cpc'] ?? '0'));
$mysql['click_cpa'] = $db->real_escape_string((string) ($cvar_sql_row['click_cpa'] ?? ''));
$mysql['click_lead'] = $db->real_escape_string((string) ($cvar_sql_row['click_lead'] ?? '0'));
$mysql['click_time'] = $db->real_escape_string((string) ($cvar_sql_row['click_time'] ?? '0'));
$mysql['referer'] = urlencode((string) $db->real_escape_string((string) ($cvar_sql_row['site_url_address'] ?? '')));

if (array_key_exists('amount', $_GET) && is_numeric($_GET['amount'])) {
	$mysql['use_pixel_payout'] = 1;
	$mysql['payout'] = $db->real_escape_string((string)$_GET['amount']);
	$mysql['click_payout'] = $db->real_escape_string((string)$_GET['amount']);
}

$tokens = [
    "subid" => $mysql['click_id'],
    "t202kw" => $mysql['t202kw'],
	"c1" => $mysql['c1'],
	"c2" => $mysql['c2'],
	"c3" => $mysql['c3'],
	"c4" => $mysql['c4'],
    "gclid" => $mysql['gclid'],
    "utm_source" => $mysql['utm_source'],
    "utm_medium" => $mysql['utm_medium'],
    "utm_campaign" => $mysql['utm_campaign'],
    "utm_term" => $mysql['utm_term'],
    "utm_content" => $mysql['utm_content'],
    "cpc" => round((float) $mysql['cpc'], 2),
	"cpc2" => $mysql['cpc'],
    "timestamp" => time(),
	"payout" => $mysql['payout'],
	"random" => mt_rand(1000000, 9999999),
    "referer" => $mysql['referer']
];

$account_id_sql="SELECT 202_clicks.ppc_account_id
				 FROM 202_clicks
				 WHERE click_id={$clickId}";

$account_id_result = $db->query($account_id_sql);
$account_id_row = $account_id_result ? $account_id_result->fetch_assoc() : null;
$mysql['ppc_account_id'] = $db->real_escape_string($account_id_row['ppc_account_id'] ?? '');

if (is_numeric($mysql['click_id'])) {

	$conv_time = time();
	$click_time_raw = (int) ($cvar_sql_row['click_time'] ?? 0);
	$click_time_to_date = new DateTime(date('Y-m-d H:i:s', $click_time_raw));
	$conv_time_to_date = new DateTime(date('Y-m-d H:i:s', (int) $conv_time));
	$diff = $click_time_to_date->diff($conv_time_to_date);
	$time_difference = $diff->d.' days, '.$diff->h.' hours, '.$diff->i.' min and '.$diff->s.' sec';
	$mysql['conv_time'] = $conv_time;

		if (array_key_exists('amount', $_GET) && is_numeric($_GET['amount'])) {
			$mysql['use_pixel_payout'] = 1;
			$mysql['click_payout'] = $db->real_escape_string((string)$_GET['amount']);
		}

	// payout to record: pixel amount override if present, otherwise the click's own payout
	$click_payout_for_log = ($mysql['use_pixel_payout'] == 1)
		? (string) ($_GET['amount'] ?? '0')
		: (string) ($cvar_sql_row['click_payout'] ?? '0');

	// An event (plan §2.2): stored on the click and evaluated by its goals,
	// which record what they reach and tell the traffic source; the
	// browser pixels of that notification are this response. A campaign
	// without goals records its plain conversion below, as always.
	try {
		$webEvent = p202RecordWebEvent($db, $clickId, $_GET, ['browser' => true]);
	} catch (\Throwable $webEventError) {
		error_log('upx: event recording failed for click ' . $clickId . ': ' . $webEventError->getMessage());
		p202RespondJsonError(500, 'Failed to record event');
	}
	if ($webEvent !== null && $webEvent['status'] !== 'no_goals') {
		p202RespondWebEvent($webEvent, 'upx', true);
		exit;
	}
	$eventName = $webEvent['event_name'] ?? null;

	// Atomic + idempotent: locks the click, dedupes on transaction id, and
	// applies the click update and conversion_logs insert in one transaction.
	$conversionResult = ['conv_id' => 0, 'duplicate' => false];
	try {
	$conversionResult = p202RecordConversion(
		$db,
		[
			'click_id'        => $clickId,
			'campaign_id'     => (string) ($cvar_sql_row['aff_campaign_id'] ?? '0'),
			'user_id'         => (string) ($cvar_sql_row['user_id'] ?? '0'),
			'click_time'      => $click_time_raw,
			'conv_time'       => $conv_time,
			'time_difference' => $time_difference,
			'ip'              => p202ClientIp($_SERVER),
			'pixel_type'      => 3,
			'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
			'click_payout'    => $click_payout_for_log,
			'event_name'      => $eventName,
			// Without a transaction id a reloaded pixel cannot be told apart
			// from a repeat, so it converts the click once; the writer checks
			// click_lead under the click lock (as gpx.php does).
			'once_per_click'  => p202ExtractTransactionId($_GET) === '',
		],
		(string) ($cvar_sql_row['click_cpa'] ?? ''),
		$mysql['use_pixel_payout'] == 1,
		($mysql['use_pixel_payout'] == 1) ? (string) ($_GET['amount'] ?? '') : '',
		p202ExtractTransactionId($_GET),
		p202ExtractCustomer($_GET),
		p202ExtractItems($_GET)
	);
	} catch (\Throwable $conversionError) {
		error_log('upx: conversion recording failed for click ' . $mysql['click_id'] . ': ' . $conversionError->getMessage());
	}
	$conversionId = $conversionResult['conv_id'];
	if ($conversionId > 0) {
		p202LinkConversionIdentity($db, $clickId, $_GET);
	}

	// Tell the traffic source after recording, and only about a conversion
	// that was newly recorded: the pixel used to fire first, so a reload of
	// the page notified the network again and a failed write notified it of
	// a conversion this install never kept.
	if ($conversionId > 0 && !$conversionResult['duplicate'] && ($conversionResult['reverses_conv_id'] ?? 0) === 0 && $mysql['ppc_account_id']) {
		$tokens['transactionid'] = $conversionResult['transaction_id'] !== ''
			? $conversionResult['transaction_id']
			: $conversionResult['dedupe_key'];
		$tokens['payout'] = $conversionResult['payout'];
		echo p202FireTrafficSourcePixels($db, (int) $mysql['ppc_account_id'], $tokens)['markup'];
	}

        if ($conversionId > 0 && !$conversionResult['duplicate']) {
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
		error_log('Attribution rebuild after upx conversion failed: ' . $e->getMessage());
	}
}
