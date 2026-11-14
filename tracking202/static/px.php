<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/static-endpoint-helpers.php');
p202InboundLogWatch($db, 'px');

// The per-campaign image pixel: <img src="px.php?acip=..."> on the
// advertiser's thank-you page. It has no subid of its own, so the click is
// found from the tracking cookie, and failing that, where the campaign
// owner has the same-IP fallback on, from the owner's most recent click from
// this IP in the last 30 days.
//
// It records a real conversion row (pixel_type 1, like the global pixel)
// through the same writer every other conversion path uses, so the payout
// it produces can be listed, broken down and attributed. Before, it only
// flagged the click and left no trace.

// The campaign, by its id exactly: compared as text, MySQL read 12abc as 12.
$pxCampaignId = \Prosper202\Click\PublicId::parse($_GET['acip'] ?? null);
$aff_campaign_row = null;
if ($pxCampaignId !== null) {
    try {
        $aff_campaign_row = \Prosper202\Repository\LookupRepositoryFactory::reads($db)->offer($pxCampaignId);
    } catch (\Throwable $pxReadError) {
        record_mysql_error($db, 'reading campaign ' . $pxCampaignId, $pxReadError->getMessage());
    }
}

if ($aff_campaign_row === null) {
    \Prosper202\InboundLog\RequestLog::current()?->refuse('unknown_campaign', 'No campaign has this acip; copy the pixel from Setup › Postback/Pixel.');
    die();
}

$mysql['user_id'] = $db->real_escape_string((string)$aff_campaign_row['user_id']);
// The privacy setting in force: the stricter of the install's and the
// campaign's account's (p202ApplyOwnerPrivacy()) — whose clicks the lookup
// below searches, and whose click alone may convert here — before the
// address is looked up or stored.
p202ApplyOwnerPrivacy($aff_campaign_row['user_id'] ?? null);
\Prosper202\InboundLog\RequestLog::current()?->account($aff_campaign_row['user_id'] ?? null);

// The click the cookie names, kept on the log row so a refused request can be recorded from it.
\Prosper202\InboundLog\RequestLog::current()?->matched(
    p202ParseClickId((string) (getCookie202('tracking202subid') ?? '')),
    \Prosper202\Conversion\Ledger\MatchMethod::COOKIE
);

// Postback authentication: the campaign's account's token or signature, before anything is written.
p202RequirePostbackAuth($db, $aff_campaign_row['user_id'] ?? null, 'px');

//see if it has the cookie, do whatever we can to grab to grab SOMETHING to tie this lead to
// A cookie is untrusted input: only an exact positive integer names a click
// ("123.9" or "1e3" must not become click 123 or 1000). A cookie that is
// PRESENT but not a click id is refused outright, never treated as absent:
// the IP fallback below is for a browser with no cookie, and behind a NAT it
// would otherwise credit whichever of the owner's clicks last came from that
// address to a request whose own identity was garbage.
$cookie = (string) (getCookie202('tracking202subid') ?? '');
$click_id = 0;
// How the click was found, kept on the conversion (MatchMethod).
$matchedBy = \Prosper202\Conversion\Ledger\MatchMethod::COOKIE;
if ($cookie !== '') {
	$click_id = p202ParseClickId($cookie) ?? 0;
	if ($click_id === 0) {
		error_log('px: refusing malformed tracking202subid cookie for acip ' . $pxCampaignId);
		\Prosper202\InboundLog\RequestLog::current()?->refuse('malformed_click', 'The visitor\'s tracking cookie does not hold a click id.');
		exit;
	}
} else {

	// Nothing named a click: the campaign's account's latest click from this
	// address, when the account has the fallback on and may recognise this
	// visitor (p202SameIpFallbackClick()). A guess.
	$click_row1 = p202SameIpFallbackClick(
		$db,
		\Prosper202\Conversion\SameIpFallback::ofCampaign($aff_campaign_row, 'px'),
		$_GET
	);
	if ($click_row1 !== null) {
		$click_id = $click_row1['click_id'];
		$matchedBy = \Prosper202\Conversion\Ledger\MatchMethod::SAME_IP;
	} else {
		\Prosper202\InboundLog\RequestLog::current()?->refuse('no_click', 'No tracking cookie named a click, and the same-IP fallback found none.');
	}

}

if ($click_id > 0) {
	// An event (plan §2.2), on a click of the campaign's owner: stored and
	// evaluated by the click's goals; a refusal is logged (an image cannot
	// say more). A campaign without goals records its plain conversion
	// below, as always, with the event's name kept on the row.
	$webEvent = null;
	try {
		$webEvent = p202RecordWebEvent($db, $click_id, $_GET, $matchedBy, ['user_id' => (int) $mysql['user_id'], 'browser' => false]);
	} catch (\Throwable $webEventError) {
		error_log('px: event recording failed for click ' . $click_id . ': ' . $webEventError->getMessage());
		\Prosper202\InboundLog\RequestLog::current()?->refuse('failed', 'The event could not be recorded just now.');
		exit;
	}
	if ($webEvent !== null && $webEvent['status'] !== 'no_goals') {
		if ($webEvent['status'] !== 'recorded') {
			error_log('px: event refused for click ' . $click_id . ' (' . $webEvent['status'] . '): ' . ($webEvent['message'] ?? $webEvent['reason'] ?? ''));
			\Prosper202\InboundLog\RequestLog::current()?->refuse('event_' . $webEvent['status'], (string) ($webEvent['message'] ?? $webEvent['reason'] ?? ''));
		} else {
			\Prosper202\InboundLog\RequestLog::current()?->converted('event_recorded', null, null, 'The event was recorded.');
		}
		exit;
	}
	try {
		// user_id: a cookie names any click on this install; only one that
		// belongs to the campaign's owner may convert for this campaign.
		$outcome = p202RecordLegacyConversion($db, $click_id, 1, $matchedBy, [
			'user_id'    => (int) $mysql['user_id'],
			'ip'         => p202StoredVisitorIp(),
			'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
			'event_name' => $webEvent['event_name'] ?? null,
            // A refusal on this request: no destination is sent it.
            'consent_withheld' => !\Prosper202\Identity\RequestSignals::consentGiven($_GET),
		]);
		if ($outcome['recorded'] || ($outcome['duplicate'] ?? false)) {
			p202LinkConversionIdentity($db, $click_id, $_GET);
		}
		if (!$outcome['recorded'] && $outcome['reason'] !== 'already_lead') {
			error_log('px: no conversion recorded for click ' . $click_id . ': ' . $outcome['reason']);
		}
	} catch (\Prosper202\Conversion\Ledger\AmountOutOfRange $amountError) {
		// The pixel answers its image either way; the inbound log says why nothing was recorded (Y5N2).
		\Prosper202\InboundLog\RequestLog::current()?->refuse('amount_out_of_range', $amountError->getMessage(), 422);
	} catch (\Throwable $conversionError) {
		error_log('px: conversion recording failed for click ' . $click_id . ': ' . $conversionError->getMessage());
		\Prosper202\InboundLog\RequestLog::current()?->refuse('failed', 'The conversion could not be recorded just now.');
	}
}
