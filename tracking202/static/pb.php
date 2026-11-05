<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/static-endpoint-helpers.php');
p202InboundLogWatch($db, 'pb');

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
    \Prosper202\InboundLog\RequestLog::current()?->refuse('unknown_campaign', 'No campaign has this acip; copy the postback URL from Setup › Postback/Pixel.');
    die();
}

// The privacy setting in force: the stricter of the install's and the
// campaign's account's (p202ApplyOwnerPrivacy()), before the conversion
// stores the address.
p202ApplyOwnerPrivacy($aff_campaign_row['user_id'] ?? null);
\Prosper202\InboundLog\RequestLog::current()?->account($aff_campaign_row['user_id'] ?? null);

// Postback authentication: the campaign's account's token or signature, before anything is written.
p202RequirePostbackAuth($db, $aff_campaign_row['user_id'] ?? null, 'pb');

// An exact positive integer, or nothing: "123.9" must not become click 123.
$click_id = p202ParseClickId($_GET['subid'] ?? null);
// A PurLink SubID must name this postback's campaign. The signed
// click-offer-mac form records on its offer, routing the click there in the
// same transaction, so one that beats the browser's click-out report (or
// follows another offer's sale) still counts; its goal events go to the
// offer's own subject, as on gpb.php. The unsigned 1.9.56
// click-offer form only names a click already on the offer.
$pbRawSubid = $_GET['subid'] ?? null;
$pbSignedSubid = $click_id === null ? p202VerifyPurLinkSubid($db, $pbRawSubid, 'pb') : null;
if ($pbSignedSubid !== null) {
    if ($pbSignedSubid['claims']['campaign_public_id'] !== $pbCampaignId) {
        p202RespondJsonError(404, 'Unknown subid for this campaign');
    }
    $click_id = $pbSignedSubid['claims']['click_id'];
} elseif ($click_id === null && is_string($pbRawSubid)) {
    $pbLegacy = \Prosper202\Click\PurLinkSubid::legacy($pbRawSubid);
    if ($pbLegacy !== null && $pbLegacy['campaign_public_id'] === $pbCampaignId) {
        $click_id = $pbLegacy['click_id'];
    }
}
if ($click_id === null) {
	p202RespondJsonError(404, 'Missing or malformed subid');
}

// An event (plan §2.2), on a click of this campaign: stored and evaluated
// by the campaign's goals. A campaign without goals records its plain
// conversion below, as always, with the event's name kept on the row.
try {
	$webEvent = $pbSignedSubid !== null
		? p202RecordPurLinkWebEvent($db, (string) $pbRawSubid, $pbSignedSubid['key'], $_GET)
		: p202RecordWebEvent($db, $click_id, $_GET, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, ['campaign_id' => (int) $aff_campaign_row['aff_campaign_id'], 'browser' => false]);
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
		// A signed SubID records on its own offer, routed in the same transaction.
		'purlink_subid'  => $pbSignedSubid !== null ? (string) $pbRawSubid : null,
		'purlink_key'    => $pbSignedSubid['key'] ?? null,
	] + p202ExtractReversal($_GET));
} catch (\Prosper202\Conversion\PurLinkCampaignChanged $campaignError) {
	p202RespondJsonError(404, 'Unknown subid for this campaign');
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
