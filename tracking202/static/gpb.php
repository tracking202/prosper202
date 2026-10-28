<?php
declare(strict_types=1);

header('P3P: CP="Prosper202 does not have a P3P policy"');
include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/static-endpoint-helpers.php');
// getUrl() for server-side postback pixels (not pulled in by connect2.php)
include_once(substr(__DIR__, 0,-19) . '/202-config/functions-tracking202api.php');

//get the aff_camapaign_id
$mysql['user_id'] = 1;
$mysql['use_pixel_payout'] = 0;
$mysql['cid'] = 0;
$mysql['click_id'] = 0;

// The click: an exact positive integer from subid (or sid), or a 404.
// is_numeric() plus a cast used to turn "123.9" into click 123, and a
// subid of 0 fell back to "the last click from this IP" — for a
// server-to-server postback, the network's own server.
$parsedClickId = p202ParseClickId($_GET['subid'] ?? null) ?? p202ParseClickId($_GET['sid'] ?? null);
if ($parsedClickId === null) {
	p202RespondJsonError(404, 'SubID not found');
}
$mysql['click_id'] = (string) $parsedClickId;

// The click's latest visit, which the conversion credits (p202PostbackClickSql()).
$cvar_sql = p202PostbackClickSql($parsedClickId);
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
$source_sql = p202ClickSourceSql($parsedClickId, (int) $cvar_sql_row['user_id']);
$source_result = $db->query($source_sql);
if ($source_result === false) {
    record_mysql_error($db, $source_sql);
}
$source_row = p202FetchRow($source_result, $source_sql);
// The privacy setting in force for this visitor: the stricter of the
// install's and this click's account's (p202ApplyOwnerPrivacy()), before
// the address is stored or a cookie set.
p202ApplyOwnerPrivacy($cvar_sql_row['user_id'] ?? null);
$mysql['t202kw'] = $db->real_escape_string((string)($cvar_sql_row['keyword'] ?? ''));
$mysql['c1'] = $db->real_escape_string((string)($cvar_sql_row['c1'] ?? ''));
$mysql['c2'] = $db->real_escape_string((string)($cvar_sql_row['c2'] ?? ''));
$mysql['c3'] = $db->real_escape_string((string)($cvar_sql_row['c3'] ?? ''));
$mysql['c4'] = $db->real_escape_string((string)($cvar_sql_row['c4'] ?? ''));
$mysql['gclid'] = $db->real_escape_string((string)($cvar_sql_row['gclid'] ?? ''));
$mysql['utm_source'] = $db->real_escape_string((string)($cvar_sql_row['utm_source'] ?? ''));
$mysql['utm_medium'] = $db->real_escape_string((string)($cvar_sql_row['utm_medium'] ?? ''));
$mysql['utm_campaign'] = $db->real_escape_string((string)($cvar_sql_row['utm_campaign'] ?? ''));
$mysql['utm_term'] = $db->real_escape_string((string)($cvar_sql_row['utm_term'] ?? ''));
$mysql['utm_content'] = $db->real_escape_string((string)($cvar_sql_row['utm_content'] ?? ''));
$mysql['click_user_id'] = $db->real_escape_string((string)($cvar_sql_row['user_id'] ?? ''));
$mysql['campaign_id'] = $db->real_escape_string((string)($cvar_sql_row['aff_campaign_id'] ?? ''));
$mysql['payout'] = $db->real_escape_string((string)($cvar_sql_row['click_payout'] ?? ''));
$mysql['cpc'] = $db->real_escape_string((string) ($source_row['click_cpc'] ?? ''));
$mysql['click_cpa'] = $db->real_escape_string((string)($cvar_sql_row['click_cpa'] ?? ''));
$mysql['click_lead'] = $db->real_escape_string((string)($cvar_sql_row['click_lead'] ?? ''));
$mysql['click_time'] = $db->real_escape_string((string)($cvar_sql_row['click_time'] ?? ''));
$mysql['ppc_account_id'] = $db->real_escape_string((string) ($source_row['ppc_account_id'] ?? ''));
$mysql['referer'] = urlencode((string) $db->real_escape_string((string)($cvar_sql_row['site_url_address'] ?? '')));

if (array_key_exists('amount', $_GET) && is_numeric($_GET['amount'])) {
	$mysql['use_pixel_payout'] = 1;
	$mysql['payout'] = $db->real_escape_string((string)$_GET['amount']);
	$mysql['click_payout'] = $db->real_escape_string((string)$_GET['amount']);
}

// Raw: replaceTokens() encodes them, and no statement here reads them.
$adClickIds = \Prosper202\Click\AdClickIdColumns::of(
    \Prosper202\Repository\LookupRepositoryFactory::connection($db),
    $parsedClickId
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

$conv_time = time();
$mysql['conv_time'] = $conv_time;

// Record first, then tell the traffic source. The pixel used to fire before
// the conversion was recorded, so a retried postback notified the network
// again, a failed write notified it of a conversion this install never
// kept, and no transaction id was available to send. Now the network hears
// about exactly the conversions that were newly recorded, each with its id.
//
// Atomic + idempotent: locks the click, dedupes on its ledger key, and
// writes the row and the click's recomputed value in one transaction.
// An event (plan §2.2): `event=` names what happened, and the click's goals
// decide what it is worth — stored and evaluated by the goal engine, which
// records what the goals reach and tells the traffic source itself. A
// campaign without goals records its plain conversion below, as it always
// has, with the event's name kept on the row.
try {
	$webEvent = p202RecordWebEvent($db, (int) $mysql['click_id'], $_GET, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, ['browser' => false]);
} catch (\Throwable $webEventError) {
	error_log('gpb: event recording failed for click ' . $mysql['click_id'] . ': ' . $webEventError->getMessage());
	p202RespondJsonError(500, 'Failed to record event');
}
if ($webEvent !== null && $webEvent['status'] !== 'no_goals') {
	p202RespondWebEvent($webEvent, 'gpb');
	exit;
}
$eventName = $webEvent['event_name'] ?? null;

$reversal = p202ExtractReversal($_GET);
try {
	$conversionResult = p202RecordConversion(
		$db,
		[
			'click_id'        => (int) $mysql['click_id'],
			'user_id'         => (string) ($cvar_sql_row['user_id'] ?? '0'),
			'conv_time'       => $conv_time,
			'ip'              => p202StoredVisitorIp(),
			'pixel_type'      => 2,
			'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
			'click_payout'    => ($mysql['use_pixel_payout'] == 1) ? (string) ($_GET['amount'] ?? '0') : '',
			'source'          => \Prosper202\Conversion\Ledger\ConversionSource::POSTBACK->value,
			'reversal'        => $reversal['reversal'],
			'reversal_ref'    => $reversal['reversal_ref'],
			'event_name'      => $eventName,
			// Without a transaction id a retried postback cannot be told apart
			// from a repeat, so it converts the click once; the writer checks
			// click_lead under the click lock (as gpx.php does). A reversal
			// always names its sale's transaction id.
			'once_per_click'  => p202ExtractTransactionId($_GET) === '',
            // The traffic source's server postbacks are queued with the row,
            // in its transaction, and sent below; see after the record.
            'notify_traffic_source' => true,
		],
		// The subid named the click.
		\Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID,
		(string) ($cvar_sql_row['click_cpa'] ?? ''),
		$mysql['use_pixel_payout'] == 1,
		($mysql['use_pixel_payout'] == 1) ? (string) ($_GET['amount'] ?? '') : '',
		p202ExtractTransactionId($_GET),
		p202ExtractCustomer($_GET),
		p202ExtractItems($_GET)
	);
} catch (\Prosper202\Conversion\Ledger\ReversalException $reversalError) {
	p202RespondJsonError(
		$reversalError->kind === \Prosper202\Conversion\Ledger\ReversalException::CONFLICT ? 422 : 404,
		$reversalError->getMessage()
	);
} catch (\Throwable $conversionError) {
	// A non-2xx, so a sender that retries only failures does not treat an
	// unrecorded conversion as accepted and drop it for good.
	error_log('gpb: conversion recording failed for click ' . $mysql['click_id'] . ': ' . $conversionError->getMessage());
	p202RespondJsonError(500, 'Failed to record conversion');
}
$conversionId = $conversionResult['conv_id'];
if ($conversionId > 0) {
	p202LinkConversionIdentity($db, (int) $mysql['click_id'], $_GET);
}
$newlyRecorded = $conversionId > 0 && !$conversionResult['duplicate'];

// The server postbacks were queued with the conversion (the row and the
// promise to announce it committed together). Send what is due for it now —
// on a retry of a conversion already recorded too, so a first request that
// died after its commit is recovered by the network's retry as well as by the
// worker, which retries anything still pending.
if ($conversionId > 0) {
    p202SendQueuedPostbacks($db, $conversionId);
}

// A reversal nets an earlier sale; announcing it to the traffic source as a
// conversion would tell the network about a sale that was taken back.
if ($newlyRecorded && $conversionResult['reverses_conv_id'] === 0 && $mysql['ppc_account_id']) {
	$tokens['transactionid'] = $conversionResult['transaction_id'] !== ''
		? $conversionResult['transaction_id']
		: $conversionResult['dedupe_key'];
	// This conversion's amount, not the click's cached total, which in an
	// accumulating campaign is the sum of every conversion so far.
	$tokens['payout'] = $conversionResult['payout'];
    // Markup only: the type-4 postbacks went out through the outbox above.
    $fired = p202FireTrafficSourcePixels($db, (int) $mysql['ppc_account_id'], $tokens, null, false);
	if (array_intersect($fired['types'], [1, 2, 3, 4]) !== []) {
		header('HTTP/1.1 202 Accepted', true, 202);
	}
	echo $fired['markup'];
	if (in_array(4, $fired['types'], true)) {
		header('Content-Type: application/json');
		echo json_encode(['error' => false, 'code' => 202, 'msg' => 'Postback successful']);
	}
}

