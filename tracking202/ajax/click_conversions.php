<?php

declare(strict_types=1);

/**
 * One click's conversions, explained: the breakdown the Visitors and Spy
 * rows open (202-js/p202-overview.js draws it into the click history's
 * modal).
 *
 * Every conversion row of the click is listed, counted or not, with what
 * produced it and why a row is left out of the click's value — the same
 * read as GET /api/v3/clicks/{id}/conversions (ClickBreakdown). It reads no
 * report filters: a click's rows are the same whatever window the page
 * shows, so it takes no view.
 */

include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$flash = static function (string $tone, string $icon, string $html): string {
	return '<div class="alert alert-' . $tone . ' p202-flash" role="' . ($tone === 'danger' ? 'alert' : 'status') . '"><i class="bi ' . $icon . '"></i><div class="p202-flash__body">' . $html . '</div></div>';
};

$raw = (string) ($_GET['click_id'] ?? '');
if (preg_match('/^[1-9][0-9]{0,18}$/D', $raw) !== 1) {
	http_response_code(400);
	echo $flash('danger', 'bi-x-circle', 'That is not a click id. Open the breakdown from a row of the click history.');
	exit;
}
$clickId = (int) $raw;

// The same owner rule as the click history the row came from: a publisher
// sees their own clicks, every other session sees every account's.
$owner = !empty($_SESSION['publisher']) ? (int) $_SESSION['user_own_id'] : null;

try {
	$breakdown = (new \Prosper202\Conversion\Ledger\ClickBreakdown(new \Prosper202\Database\Connection($db)))->forClick($clickId, $owner);
} catch (\Prosper202\Conversion\Ledger\LedgerIntegrityException $refused) {
	http_response_code(500);
	echo $flash('danger', 'bi-x-circle', 'This click\'s conversions cannot be explained: ' . $e($refused->getMessage()));
	exit;
}
if ($breakdown === null) {
	http_response_code(404);
	echo $flash('danger', 'bi-x-circle', 'There is no click ' . $e($clickId) . ' in your account.');
	exit;
}

$click = $breakdown['click'];
$masked = isset($userObj) && !$userObj->hasPermission('access_to_campaign_data') && empty($_SESSION['publisher']);
$money = static fn (?string $v): string => $masked ? '?' : ($v === null ? '—' : (string) dollar_format((float) $v));

$tiles = [
	['label' => 'Click value', 'value' => $click['lead'] ? $money($click['click_payout']) : 'Not converted', 'sub' => 'what the reports show'],
	['label' => 'Counted', 'value' => $click['counted_rows'] . ' of ' . $click['rows'], 'sub' => 'conversions in the value'],
	['label' => 'Payout mode', 'value' => $click['payout_mode'] === 'accumulate' ? 'Accumulate' : 'Replace', 'sub' => $click['payout_mode'] === 'accumulate' ? 'conversions add up' : 'the latest one counts'],
];
echo '<div class="p202-tiles mb-3">';
foreach ($tiles as $tile) {
	echo '<div class="p202-tile"><div class="p202-tile__label">' . $e($tile['label']) . '</div><div class="p202-tile__value">' . $e($tile['value']) . '</div><div class="p202-tile__sub">' . $e($tile['sub']) . '</div></div>';
}
echo '</div>';

if ($click['ledger_state'] === 'pre_ledger') {
	echo $flash('info', 'bi-info-circle', 'This click converted before the conversion ledger, so its value is the click\'s own figure. Its next conversion carries that value in as a row of its own.');
} elseif (!$click['matches_click']) {
	echo $flash('warning', 'bi-exclamation-triangle', 'The counted conversions add up to <strong>' . $e($money($click['ledger_value'])) . '</strong>, but the click shows <strong>'
		. $e($click['lead'] ? $money($click['click_payout']) : 'not converted') . '</strong>. The next conversion on this click recomputes its value from these rows.');
}

$columns = [
	['key' => 'conv', 'label' => 'Conversion'],
	['key' => 'time', 'label' => 'Time'],
	['key' => 'amount', 'label' => 'Amount', 'num' => true],
	['key' => 'counts', 'label' => 'In the value'],
	['key' => 'source', 'label' => 'Source'],
	['key' => 'linked', 'label' => 'Linked to'],
	['key' => 'transaction', 'label' => 'Transaction ID'],
];
$rows = [];
$countedUnits = 0;
foreach ($breakdown['rows'] as $row) {
	if ($row['counted']) {
		$countedUnits += \Prosper202\Conversion\Ledger\Amount::toUnits($row['amount']);
		$counts = '<span class="p202-pill p202-pill--good">counted</span>';
	} else {
		$label = $row['not_counted_reason'] === 'superseded' && $row['superseded_reason'] !== null
			? 'superseded · ' . $row['superseded_reason']
			: (string) $row['not_counted_reason'];
		$counts = '<span class="p202-pill' . ($row['not_counted_reason'] === 'deleted' ? ' p202-pill--bad' : '') . '">' . $e(str_replace('_', ' ', $label)) . '</span>'
			. '<div class="form-text">' . $e((string) $row['explanation'])
			. ($row['superseded_by'] !== null ? ' ' . $e('Replaced by conversion ' . $row['superseded_by'] . '.') : '') . '</div>';
	}
	$linked = $row['linked_to']['label'] ?? '';
	if ($row['event_name'] !== null) {
		$linked .= ($linked !== '' ? ' · ' : '') . 'event ' . $row['event_name'];
	}
	$rows[] = [
		'conv' => ['text' => '#' . $row['conv_id'], 'sort' => $row['conv_id']],
		'time' => ['text' => date('m/d/y g:ia', $row['conv_time']), 'sort' => $row['conv_time']],
		'amount' => ['text' => $money($row['amount']), 'sort' => $masked ? null : (float) $row['amount']],
		'counts' => ['html' => $counts],
		'source' => $row['source_label'],
		'linked' => $linked !== '' ? $linked : '—',
		'transaction' => $row['transaction_id'] ?? '—',
	];
}

echo p202_data_table($columns, $rows, [
	'id' => 'click-conversions-table',
	'caption' => 'Conversions recorded on click ' . $clickId . ', oldest first',
	'totals' => $rows === [] ? null : [
		'conv' => 'Counted toward the click',
		'amount' => $click['ledger_state'] === 'pre_ledger' ? $money($click['click_payout']) : $money(\Prosper202\Conversion\Ledger\Amount::fromUnits($countedUnits)),
	],
	'empty' => ['icon' => 'bi-receipt', 'title' => 'No conversions on this click', 'body' => $click['ledger_state'] === 'pre_ledger'
		? 'It converted before the ledger kept rows, so only its value is known.'
		: 'A postback, pixel, upload or goal on this click would be listed here.'],
]);
