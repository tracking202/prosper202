<?php
declare(strict_types=1);

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
p202InboundLogWatch($db, 'gpx');

//get the aff_camapaign_id
$mysql['click_id'] = 0;
$mysql['cid'] = 0;
$mysql['use_pixel_payout'] = 0;

//grab the cid (the campaign whose own cookie names the click)
$campaignIdFromRequest = p202ParseClickId($_GET['cid'] ?? null) ?? 0;
$mysql['cid'] = (string) $campaignIdFromRequest;

// The click: the subid parameter, the campaign's cookie, the general cookie
// (p202ClickIdFromRequest). A value that is present and not an exact click
// id is refused rather than cast ("123.9" is not click 123) and rather than
// falling back to the IP lookup below.
$requestedClick = p202ClickIdFromRequest($_GET, $_COOKIE, $campaignIdFromRequest);
if ($requestedClick['malformed'] !== null) {
    error_log('gpx: refusing malformed ' . $requestedClick['malformed'] . ' click id');
    \Prosper202\InboundLog\RequestLog::current()?->refuse('malformed_click', 'The ' . $requestedClick['malformed'] . ' does not hold a click id.');
    exit;
}
// A signed PurLink SubID names its click once its MAC verifies, and records
// on its offer (as on gpb.php); one that does not verify records nothing.
try {
    $purLink = p202PurLinkFromRequest($db, $requestedClick, 'gpx');
} catch (\RuntimeException $purLinkError) {
    // The image is already sent; the log is the only place to say so.
    error_log($purLinkError->getMessage());
    exit;
}
if ($purLink === false) {
    exit;
}
$purLinkSubid = $purLink === null ? null : $requestedClick['purlink'];
$purLinkKey = $purLink['key'] ?? null;
// How the click was found, kept on the conversion (MatchMethod): the
// parameter or the cookie that named it, or the address it was guessed from.
$matchedBy = $requestedClick['method'] ?? \Prosper202\Conversion\Ledger\MatchMethod::SAME_IP;
if ($requestedClick['click_id'] !== null) {
    $mysql['click_id'] = (string) $requestedClick['click_id'];
} else {
            // Nothing named a click: the latest click from this address of
            // the account whose pixel this is (pixelAccount()), when
            // that account has the fallback on and may recognise this
            // visitor (p202SameIpFallbackClick()). A guess.
            $click_row1 = p202SameIpFallbackClick(
                $db,
                \Prosper202\Conversion\SameIpFallback::pixelAccount(
                    new \Prosper202\Database\Connection($db),
                    $_GET['cid'] ?? null,
                    'gpx'
                ),
                $_GET
            );

            if ($click_row1 !== null) {
                $mysql['click_id'] = (string) $click_row1['click_id'];
                $mysql['ppc_account_id'] = (string) $click_row1['ppc_account_id'];
            }
}

if (is_numeric($mysql['click_id'])) {

    if ((int) $mysql['click_id'] <= 0) {
        \Prosper202\InboundLog\RequestLog::current()?->refuse('no_click', 'Nothing named a click, and the same-IP fallback found none.');
        return; // nothing named a click, and none from this address
    }
    // The click's latest visit, which the conversion credits (p202PixelClickSql()).
    $cpa_sql = p202PixelClickSql((int) $mysql['click_id']);
    $cpa_result = $db->query($cpa_sql);
    if ($cpa_result === false) {
        record_mysql_error($db, $cpa_sql);
    }
    $cpa_row = p202FetchRow($cpa_result, $cpa_sql);
	if (!$cpa_row) {
		// Unknown click id; nothing to convert.
		\Prosper202\InboundLog\RequestLog::current()?->click((int) $mysql['click_id']);
		\Prosper202\InboundLog\RequestLog::current()?->legacyResult('unknown_click');
		return;
	}
	// The click's account owns this request's log row, the event path's included.
	\Prosper202\InboundLog\RequestLog::current()?->account($cpa_row['user_id'] ?? null);
	\Prosper202\InboundLog\RequestLog::current()?->click((int) $mysql['click_id']);

	// Postback authentication: the click's account's token or signature,
	// before anything is written. The image went out first, so a refusal is logged.
	if (p202PostbackAuthRefusal($db, $cpa_row['user_id'] ?? null, 'gpx') !== null) {
		return;
	}

	// An event (plan §2.2): stored on the click and evaluated by its goals.
	// The image has already been sent, so a refusal is logged. The event
	// id dedupes a reload, so the one-conversion-per-click gate below is
	// for plain conversions only; a campaign without goals takes that path
	// as it always has, with the event's name kept on the row.
	$eventName = null;
	try {
		$webEvent = $purLinkSubid !== null
			? p202RecordPurLinkWebEvent($db, $purLinkSubid, (string) $purLinkKey, $_GET)
			: p202RecordWebEvent($db, (int) $mysql['click_id'], $_GET, $matchedBy, ['browser' => false]);
	} catch (\Throwable $webEventError) {
		error_log('gpx: event recording failed for click ' . $mysql['click_id'] . ': ' . $webEventError->getMessage());
		\Prosper202\InboundLog\RequestLog::current()?->refuse('failed', 'The event could not be recorded just now.');
		return;
	}
	if ($webEvent !== null && $webEvent['status'] !== 'no_goals') {
		if ($webEvent['status'] !== 'recorded') {
			error_log('gpx: event refused for click ' . $mysql['click_id'] . ' (' . $webEvent['status'] . '): '
				. ($webEvent['message'] ?? $webEvent['reason'] ?? ''));
			\Prosper202\InboundLog\RequestLog::current()?->refuse('event_' . $webEvent['status'], (string) ($webEvent['message'] ?? $webEvent['reason'] ?? ''));
		} else {
			\Prosper202\InboundLog\RequestLog::current()?->converted('event_recorded', null, null, 'The event was recorded.');
		}
		return;
	}
	$eventName = $webEvent['event_name'] ?? null;

        // Record when the click has not converted yet, OR when the pixel
        // carries a transaction id: a distinct txid is a repeat purchase from
        // the same click (LTV), while a replayed txid is safely de-duplicated
        // by p202RecordConversion. Without a txid a retry cannot be told apart
        // from a repeat, so the legacy one-conversion-per-click gate stands.
        if (!$cpa_row['click_lead'] || p202ExtractTransactionId($_GET) !== '') {

                $mysql['campaign_id'] = $db->real_escape_string((string) ($cpa_row['aff_campaign_id'] ?? ''));
                $mysql['click_user_id'] = $db->real_escape_string((string) ($cpa_row['user_id'] ?? ''));
                $mysql['click_time'] = $db->real_escape_string((string) ($cpa_row['click_time'] ?? '0'));

		$conv_time = time();
		$mysql['conv_time'] = $conv_time;

		$mysql['click_cpa'] = $db->real_escape_string((string) ($cpa_row['click_cpa'] ?? ''));

			if (array_key_exists('amount', $_GET) && is_numeric($_GET['amount'])) {
				$mysql['use_pixel_payout'] = 1;
				$mysql['click_payout'] = $db->real_escape_string((string)$_GET['amount']);
			}

		// The pixel's amount, when it names one. Without one p202RecordConversion()
		// reads no payout from here: the writer applies the campaign's default.
		$click_payout_for_log = ($mysql['use_pixel_payout'] == 1) ? (string) ($_GET['amount'] ?? '0') : '0';

		// Atomic + idempotent: locks the click, dedupes on transaction id, and
		// applies the click update and conversion_logs insert in one transaction.
		$conversionResult = ['conv_id' => 0, 'duplicate' => false];
        // The conversion keeps the address under the setting of the click's
        // account (p202ApplyOwnerPrivacy()).
        p202ApplyOwnerPrivacy($cpa_row['user_id'] ?? null);
        \Prosper202\InboundLog\RequestLog::current()?->account($cpa_row['user_id'] ?? null);
		try {
		$conversionResult = p202RecordConversion(
			$db,
			[
				'click_id'        => (int) $mysql['click_id'],
				'user_id'         => (string) ($cpa_row['user_id'] ?? '0'),
				'conv_time'       => $conv_time,
				'ip'              => p202StoredVisitorIp(),
				'pixel_type'      => 1,
				'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
				'click_payout'    => $click_payout_for_log,
				'event_name'      => $eventName,
				// The click_lead read above is a fast path; the writer re-checks
				// it under the click lock so two concurrent id-less pixels
				// record one conversion, not two.
				'once_per_click'  => p202ExtractTransactionId($_GET) === '',
			],
			$matchedBy,
			(string) ($cpa_row['click_cpa'] ?? ''),
			$mysql['use_pixel_payout'] == 1,
			($mysql['use_pixel_payout'] == 1) ? (string) ($_GET['amount'] ?? '') : '',
			p202ExtractTransactionId($_GET),
			p202ExtractCustomer($_GET),
			p202ExtractItems($_GET),
			$purLinkSubid,
			$purLinkKey,
		);
		} catch (\Prosper202\Conversion\Ledger\AmountOutOfRange $amountError) {
			// The pixel answers its image either way; the inbound log says why nothing was recorded (Y5N2).
			\Prosper202\InboundLog\RequestLog::current()?->refuse('amount_out_of_range', $amountError->getMessage(), 422);
		} catch (\Throwable $conversionError) {
			error_log('gpx: conversion recording failed for click ' . $mysql['click_id'] . ': ' . $conversionError->getMessage());
			\Prosper202\InboundLog\RequestLog::current()?->refuse('failed', 'The conversion could not be recorded just now.');
		}
		$conversionId = $conversionResult['conv_id'];
		if ($conversionId > 0) {
			p202LinkConversionIdentity($db, (int) $mysql['click_id'], $_GET);
		}

	} else {
		\Prosper202\InboundLog\RequestLog::current()?->account($cpa_row['user_id'] ?? null);
		\Prosper202\InboundLog\RequestLog::current()?->click((int) $mysql['click_id']);
		\Prosper202\InboundLog\RequestLog::current()?->legacyResult('already_lead');
	}
}

