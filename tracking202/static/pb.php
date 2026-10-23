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

// The campaign, by its id exactly: compared as text, MySQL read 12abc as 12.
$pbCampaignId = \Prosper202\Click\PublicId::parse($_GET['acip'] ?? null);
$aff_campaign_row = null;
if ($pbCampaignId !== null) {
    try {
        $aff_campaign_row = \Prosper202\Repository\LookupRepositoryFactory::reads($db)->offer($pbCampaignId);
    } catch (\Throwable $pbReadError) {
        record_mysql_error($db, 'reading campaign ' . $pbCampaignId, $pbReadError->getMessage());
    }
}

if ($aff_campaign_row === null) {
    die();
}

// The privacy setting in force: the stricter of the install's and the
// campaign's account's (p202ApplyOwnerPrivacy()), before the conversion
// stores the address.
p202ApplyOwnerPrivacy($aff_campaign_row['user_id'] ?? null);

// An exact positive integer, or nothing: "123.9" must not become click 123.
$click_id = p202ParseClickId($_GET['subid'] ?? null);
if ($click_id === null) {
	p202RespondJsonError(404, 'Missing or malformed subid');
}

// An event (plan §2.2), on a click of this campaign: stored and evaluated
// by the campaign's goals. A campaign without goals records its plain
// conversion below, as always, with the event's name kept on the row.
try {
	$webEvent = p202RecordWebEvent($db, $click_id, $_GET, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, ['campaign_id' => (int) $aff_campaign_row['aff_campaign_id'], 'browser' => false]);
} catch (\Throwable $webEventError) {
	error_log('pb: event recording failed for click ' . $click_id . ': ' . $webEventError->getMessage());
	p202RespondJsonError(500, 'Failed to record event');
}
if ($webEvent !== null && $webEvent['status'] !== 'no_goals') {
	p202RespondWebEvent($webEvent, 'pb');
	exit;
}

try {
    // campaign_id: the postback names a campaign, and only a click one of
    // whose visits was on it (a redirector re-click is another visit) may
    // convert for it, checked before anything is written; the conversion
    // credits the click's latest visit.
	// status=reversed with the transaction id of an earlier conversion
	// records a reversal row that nets against it (see p202ExtractReversal).
	// The subid named the click (MatchMethod::CLICK_ID).
	$outcome = p202RecordLegacyConversion($db, $click_id, 2, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, [
		'campaign_id'    => (int) $aff_campaign_row['aff_campaign_id'],
		'transaction_id' => p202ExtractTransactionId($_GET),
		'ip'             => p202StoredVisitorIp(),
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
