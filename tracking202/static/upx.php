<?php
declare(strict_types=1);

header('P3P: CP="Prosper202 does not have a P3P policy"');
include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/static-endpoint-helpers.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/functions-tracking202api.php');

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
    error_log('upx: refusing malformed ' . $requestedClick['malformed'] . ' click id');
    exit;
}
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
                    'upx'
                ),
                $_GET
            );

            if ($click_row1 !== null) {
                $mysql['click_id'] = (string) $click_row1['click_id'];
                $mysql['ppc_account_id'] = (string) $click_row1['ppc_account_id'];
            }
}

if(!$mysql['click_id']){
    p202RespondJsonError(404, 'SubID not found');
}

// integer click_id for safe SQL interpolation
$clickId = (int) $mysql['click_id'];

// The click's latest visit, which the conversion credits (p202PostbackClickSql()).
$cvar_sql = p202PostbackClickSql($clickId);
$cvar_sql_result = $db->query($cvar_sql);
if ($cvar_sql_result === false) {
    record_mysql_error($db, $cvar_sql);
}
$cvar_sql_row = p202FetchRow($cvar_sql_result, $cvar_sql);
if (!$cvar_sql_row) {
    p202RespondJsonError(404, 'Click data not found');
}
// The traffic source and cost, whose pixels announce it: the first visit's,
// when it is the same account's (p202ClickSourceSql()).
$source_sql = p202ClickSourceSql($clickId, (int) $cvar_sql_row['user_id']);
$source_result = $db->query($source_sql);
if ($source_result === false) {
    record_mysql_error($db, $source_sql);
}
$source_row = p202FetchRow($source_result, $source_sql);
// The privacy setting in force for this visitor: the stricter of the
// install's and this click's account's (p202ApplyOwnerPrivacy()), before
// the conversion stores the address.
p202ApplyOwnerPrivacy($cvar_sql_row['user_id'] ?? null);
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
$mysql['payout'] = $db->real_escape_string((string) ($cvar_sql_row['click_payout'] ?? '0'));
$mysql['cpc'] = $db->real_escape_string((string) ($source_row['click_cpc'] ?? '0'));
$mysql['click_cpa'] = $db->real_escape_string((string) ($cvar_sql_row['click_cpa'] ?? ''));
$mysql['click_lead'] = $db->real_escape_string((string) ($cvar_sql_row['click_lead'] ?? '0'));
$mysql['click_time'] = $db->real_escape_string((string) ($cvar_sql_row['click_time'] ?? '0'));
$mysql['ppc_account_id'] = $db->real_escape_string((string) ($source_row['ppc_account_id'] ?? ''));
$mysql['referer'] = urlencode((string) $db->real_escape_string((string) ($cvar_sql_row['site_url_address'] ?? '')));

if (array_key_exists('amount', $_GET) && is_numeric($_GET['amount'])) {
	$mysql['use_pixel_payout'] = 1;
	$mysql['payout'] = $db->real_escape_string((string)$_GET['amount']);
	$mysql['click_payout'] = $db->real_escape_string((string)$_GET['amount']);
}

// Raw: replaceTokens() encodes them, and no statement here reads them.
$adClickIds = \Prosper202\Click\AdClickIdColumns::of(
    \Prosper202\Repository\LookupRepositoryFactory::connection($db),
    $clickId
);

$tokens = [
    "subid" => $mysql['click_id'],
    "t202kw" => $mysql['t202kw'],
	"c1" => $mysql['c1'],
	"c2" => $mysql['c2'],
	"c3" => $mysql['c3'],
	"c4" => $mysql['c4'],
    "gclid" => $mysql['gclid'],
    "gbraid" => $adClickIds['gbraid'],
    "wbraid" => $adClickIds['wbraid'],
    "ttclid" => $adClickIds['ttclid'],
    "msclkid" => $adClickIds['msclkid'],
    "fbclid" => $adClickIds['fbclid'],
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

if (is_numeric($mysql['click_id'])) {

	$conv_time = time();
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
		$webEvent = p202RecordWebEvent($db, $clickId, $_GET, $matchedBy, ['browser' => true]);
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
			'user_id'         => (string) ($cvar_sql_row['user_id'] ?? '0'),
			'conv_time'       => $conv_time,
			'ip'              => p202StoredVisitorIp(),
			'pixel_type'      => 3,
			'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
			'click_payout'    => $click_payout_for_log,
			'event_name'      => $eventName,
			// Without a transaction id a reloaded pixel cannot be told apart
			// from a repeat, so it converts the click once; the writer checks
			// click_lead under the click lock (as gpx.php does).
			'once_per_click'  => p202ExtractTransactionId($_GET) === '',
            // Server postbacks queued with the row, sent below (as gpb.php).
            'notify_traffic_source' => true,
		],
		$matchedBy,
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
        // The server postbacks queued with the conversion: sent now, retried
        // by the worker if this fails, and sent again by a reload of a
        // conversion already recorded if still due.
        p202SendQueuedPostbacks($db, $conversionId);
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
        // Markup only: the type-4 postbacks go out through the outbox above.
        echo p202FireTrafficSourcePixels($db, (int) $mysql['ppc_account_id'], $tokens, null, false)['markup'];
	}

}
