<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/static-endpoint-helpers.php');

// The per-campaign server-to-server postback: pb.php?acip=...&subid=...
// It records a real conversion row (pixel_type 2, like the global postback)
// through the same writer every other conversion path uses. A transaction
// id (txid / transaction_id / order_id ...) de-duplicates a replay and lets
// one click carry a repeat purchase; without one the click converts once.
// Before, this endpoint only flagged the click and left no trace.

//get the aff_camapaign_id
$mysql['aff_campaign_id_public'] = $db->real_escape_string((string)($_GET['acip'] ?? ''));

$aff_campaign_sql = "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public='".$mysql['aff_campaign_id_public']."'";
$aff_campaign_row =  memcache_mysql_fetch_assoc($db, $aff_campaign_sql);

if (!$aff_campaign_row) { die(); }

if (empty($_GET['subid']) || !is_numeric($_GET['subid'])) { die(); }

$click_id = (int) $_GET['subid'];

try {
	// campaign_id: the postback names a campaign, and only a click on that
	// campaign may convert for it — the scope the old click update applied
	// in its WHERE clause, now checked before anything is written.
	$outcome = p202RecordLegacyConversion($db, $click_id, 2, [
		'campaign_id'    => (int) $aff_campaign_row['aff_campaign_id'],
		'transaction_id' => p202ExtractTransactionId($_GET),
		'ip'             => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
		'user_agent'     => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
	]);
	if (!$outcome['recorded'] && !$outcome['duplicate'] && $outcome['reason'] !== 'already_lead') {
		error_log('pb: no conversion recorded for click ' . $click_id . ': ' . $outcome['reason']);
	}
} catch (\Throwable $conversionError) {
	error_log('pb: conversion recording failed for click ' . $click_id . ': ' . $conversionError->getMessage());
}
