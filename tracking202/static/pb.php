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
// `status=reversed` with a transaction id reverses that conversion.

//get the aff_camapaign_id
$mysql['aff_campaign_id_public'] = $db->real_escape_string((string)($_GET['acip'] ?? ''));

$aff_campaign_sql = "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public='".$mysql['aff_campaign_id_public']."'";
$aff_campaign_row =  memcache_mysql_fetch_assoc($db, $aff_campaign_sql);

if (!$aff_campaign_row) { die(); }

// An exact positive integer, or nothing: "123.9" must not become click 123.
$click_id = p202ParseClickId($_GET['subid'] ?? null);
if ($click_id === null) {
	p202RespondJsonError(404, 'Missing or malformed subid');
}

// An event (plan §2.2), on a click of this campaign: stored and evaluated
// by the campaign's goals. A campaign without goals records its plain
// conversion below, as always, with the event's name kept on the row.
try {
	$webEvent = p202RecordWebEvent($db, $click_id, $_GET, ['campaign_id' => (int) $aff_campaign_row['aff_campaign_id'], 'browser' => false]);
} catch (\Throwable $webEventError) {
	error_log('pb: event recording failed for click ' . $click_id . ': ' . $webEventError->getMessage());
	p202RespondJsonError(500, 'Failed to record event');
}
if ($webEvent !== null && $webEvent['status'] !== 'no_goals') {
	p202RespondWebEvent($webEvent, 'pb');
	exit;
}

try {
	// campaign_id: the postback names a campaign, and only a click on that
	// campaign may convert for it — the scope the old click update applied
	// in its WHERE clause, now checked before anything is written.
	// status=reversed with the transaction id of an earlier conversion
	// records a reversal row that nets against it (see p202ExtractReversal).
	$outcome = p202RecordLegacyConversion($db, $click_id, 2, [
		'campaign_id'    => (int) $aff_campaign_row['aff_campaign_id'],
		'transaction_id' => p202ExtractTransactionId($_GET),
		'ip'             => p202ClientIp($_SERVER),
		'user_agent'     => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
		'source'         => \Prosper202\Conversion\Ledger\ConversionSource::LEGACY_PIXEL->value,
		'event_name'     => $webEvent['event_name'] ?? null,
	] + p202ExtractReversal($_GET));
} catch (\Prosper202\Conversion\Ledger\ReversalException $reversalError) {
	// Said to the sender in its own words: which transaction, and for a
	// second reversal of one sale, the reversal already on file.
	p202RespondJsonError(
		$reversalError->kind === \Prosper202\Conversion\Ledger\ReversalException::CONFLICT ? 422 : 404,
		$reversalError->getMessage()
	);
} catch (\Throwable $conversionError) {
	// A non-2xx, so a sender that retries only failures does not treat an
	// unrecorded conversion as accepted and drop it for good.
	error_log('pb: conversion recording failed for click ' . $click_id . ': ' . $conversionError->getMessage());
	p202RespondJsonError(500, 'Failed to record conversion');
}
if ($outcome['recorded'] || $outcome['duplicate']) {
	p202LinkConversionIdentity($db, $click_id, $_GET);
}
if (!$outcome['recorded'] && !$outcome['duplicate'] && $outcome['reason'] !== 'already_lead') {
	// unknown_click or campaign_mismatch: the same 404 gpb.php answers for a
	// subid it cannot convert, so the sender's log shows the mismatch.
	p202RespondJsonError(404, 'Unknown subid for this campaign');
}
