<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/static-endpoint-helpers.php');

// The per-campaign image pixel: <img src="px.php?acip=..."> on the
// advertiser's thank-you page. It has no subid of its own, so the click is
// found from the tracking cookie, and failing that from the campaign owner's
// most recent click from this IP in the last 30 days.
//
// It records a real conversion row (pixel_type 1, like the global pixel)
// through the same writer every other conversion path uses, so the payout
// it produces can be listed, broken down and attributed. Before, it only
// flagged the click and left no trace.

//get the aff_camapaign_id
$mysql['aff_campaign_id_public'] = $db->real_escape_string((string)($_GET['acip'] ?? ''));
$aff_campaign_sql = "SELECT user_id FROM 202_aff_campaigns WHERE aff_campaign_id_public='".$mysql['aff_campaign_id_public']."'";
$aff_campaign_row =  memcache_mysql_fetch_assoc($aff_campaign_sql);

if (!$aff_campaign_row) { die(); }

$mysql['user_id'] = $db->real_escape_string((string)$aff_campaign_row['user_id']);

//see if it has the cookie, do whatever we can to grab to grab SOMETHING to tie this lead to
// A cookie is untrusted input: only an exact positive integer names a click
// ("123.9" or "1e3" must not become click 123 or 1000). A cookie that is
// PRESENT but not a click id is refused outright, never treated as absent:
// the IP fallback below is for a browser with no cookie, and behind a NAT it
// would otherwise credit whichever of the owner's clicks last came from that
// address to a request whose own identity was garbage.
$cookie = isset($_COOKIE['tracking202subid']) ? (string) $_COOKIE['tracking202subid'] : '';
$click_id = 0;
if ($cookie !== '') {
	$click_id = p202ParseClickId($cookie) ?? 0;
	if ($click_id === 0) {
		error_log('px: refusing malformed tracking202subid cookie for acip ' . $mysql['aff_campaign_id_public']);
		exit;
	}
} else {

	//ok grab the last click from this ip_id
	$mysql['ip_address'] = $db->real_escape_string((string)($_SERVER['REMOTE_ADDR'] ?? ''));
	$daysago = time() - 2592000; // 30 days ago
	$click_sql1 = "	SELECT 	202_clicks.click_id 
					FROM 		202_clicks
					LEFT JOIN	202_clicks_advance USING (click_id)
					LEFT JOIN 	202_ips USING (ip_id) 
					WHERE 	202_ips.ip_address='".$mysql['ip_address']."'
					AND		202_clicks.user_id='".$mysql['user_id']."'  
					AND		202_clicks.click_time >= '".$daysago."'
					ORDER BY 	202_clicks.click_id DESC 
					LIMIT 		1";
	$click_result1 = $db->query($click_sql1) or record_mysql_error($click_sql1);
	$click_row1 = $click_result1->fetch_assoc();
	if ($click_row1) {
		$click_id = (int) $click_row1['click_id'];
	}

}

if ($click_id > 0) {
	// An event (plan §2.2), on a click of the campaign's owner: stored and
	// evaluated by the click's goals; a refusal is logged (an image cannot
	// say more). A campaign without goals records its plain conversion
	// below, as always, with the event's name kept on the row.
	$webEvent = null;
	try {
		$webEvent = p202RecordWebEvent($db, $click_id, $_GET, ['user_id' => (int) $mysql['user_id'], 'browser' => false]);
	} catch (\Throwable $webEventError) {
		error_log('px: event recording failed for click ' . $click_id . ': ' . $webEventError->getMessage());
		exit;
	}
	if ($webEvent !== null && $webEvent['status'] !== 'no_goals') {
		if ($webEvent['status'] !== 'recorded') {
			error_log('px: event refused for click ' . $click_id . ' (' . $webEvent['status'] . '): ' . ($webEvent['message'] ?? $webEvent['reason'] ?? ''));
		}
		exit;
	}
	try {
		// user_id: a cookie names any click on this install; only one that
		// belongs to the campaign's owner may convert for this campaign.
		$outcome = p202RecordLegacyConversion($db, $click_id, 1, [
			'user_id'    => (int) $mysql['user_id'],
			'ip'         => p202ClientIp($_SERVER),
			'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
			'event_name' => $webEvent['event_name'] ?? null,
		]);
		if ($outcome['recorded'] || ($outcome['duplicate'] ?? false)) {
			p202LinkConversionIdentity($db, $click_id, $_GET);
		}
		if (!$outcome['recorded'] && $outcome['reason'] !== 'already_lead') {
			error_log('px: no conversion recorded for click ' . $click_id . ': ' . $outcome['reason']);
		}
	} catch (\Throwable $conversionError) {
		error_log('px: conversion recording failed for click ' . $click_id . ': ' . $conversionError->getMessage());
	}
}
