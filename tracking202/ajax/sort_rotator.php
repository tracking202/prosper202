<?php

declare(strict_types=1);

/**
 * Overview › Rotator Breakdown: each rotator's totals, then each of its rules
 * and its default redirect, over the window.
 *
 * Drawn into tracking202/overview/rotator-breakdown.php on the v2 shell; the
 * window, the clicks counted and CPC or CPV are the user's report
 * preferences, which that page has just applied from its URL. The figures
 * are the classic page's, query for query. A rule's criteria and redirects,
 * which the classic page fetched into a modal from the Setup page's endpoint,
 * are read here and shown under the rule, so the report does not depend on
 * Setup's markup.
 */

include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');
require_once(substr(__DIR__, 0, -17) . '/202-config/functions-ui-overview.php');

AUTH::require_user();

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$base = get_absolute_url();

//grab user time range preference
$time = grab_timeframe();
$from = (int) $time['from'];
$to = (int) $time['to'];
$userId = (int) $_SESSION['user_id'];

$prefs = p202_report_prefs_load(new \Prosper202\Database\Connection($db), $userId);
$click_filtered = \Prosper202\DataEngine\UserPrefFilters::showFilter((string) ($prefs['user_pref_show'] ?? ''));
$cpv = ($prefs['user_cpc_or_cpv'] ?? '') === 'cpv';
$canSee = isset($userObj) && $userObj->hasPermission('access_to_campaign_data');

/**
 * Run one of the page's queries; a failure is the classic page's database
 * error, not an empty report.
 */
$fetchAll = static function (string $sql) use ($db): array {
	$result = $db->query($sql);
	if (!$result instanceof mysqli_result) {
		record_mysql_error($sql);
	}
	return $result->fetch_all(MYSQLI_ASSOC);
};

/** The figures for one set of clicks, as the classic page summed them. */
$stats = static function (string $where) use ($fetchAll, $click_filtered, $from, $to): array {
	$rows = $fetchAll("SELECT
			COUNT(*) AS clicks,
			SUM(c.click_lead) AS leads,
			ac.aff_campaign_payout AS payout,
			SUM(c.click_payout*c.click_lead) AS income,
			AVG(c.click_cpc) AS avg_cpc,
			SUM(c.click_cpc) AS cost
		FROM 202_clicks AS c
		LEFT OUTER JOIN 202_aff_campaigns AS ac ON (c.aff_campaign_id = ac.aff_campaign_id)
		WHERE " . $where . $click_filtered . " AND click_time >= '" . $from . "' AND click_time <= '" . $to . "'");
	$row = $rows[0] ?? [];
	return [
		'clicks' => (float) ($row['clicks'] ?? 0),
		'leads' => (float) ($row['leads'] ?? 0),
		'payout' => (float) ($row['payout'] ?? 0),
		'income' => (float) ($row['income'] ?? 0),
		'avg_cpc' => (float) ($row['avg_cpc'] ?? 0),
		'cost' => (float) ($row['cost'] ?? 0),
	];
};

/** One row of figures, formatted as the classic page formatted them. */
$cells = static function (array $s, int $roiPrecision) use ($cpv, $canSee, $e): array {
	$net = $s['income'] - $s['cost'];
	$su = $s['clicks'] > 0 ? round($s['leads'] / $s['clicks'] * 100, 2) : 0;
	$epc = $s['clicks'] > 0 ? round($s['income'] / $s['clicks'], 2) : 0;
	$roi = $s['cost'] > 0 ? round($net / $s['cost'] * 100, $roiPrecision) : 0;
	$tone = $net > 0 ? 'text-success' : ($net < 0 ? 'text-danger' : '');
	$toned = static fn (string $text): string => $tone === '' ? $e($text) : '<span class="' . $tone . '">' . $e($text) . '</span>';
	$hidden = ['text' => '?'];
	return [
		'clicks' => $canSee ? ['text' => number_format($s['clicks']), 'sort' => $s['clicks']] : $hidden,
		'leads' => $canSee ? ['text' => number_format($s['leads']), 'sort' => $s['leads']] : $hidden,
		'su' => ['text' => $su . '%', 'sort' => $su],
		'payout' => ['text' => dollar_format($s['payout']), 'sort' => $s['payout']],
		'epc' => ['text' => dollar_format($epc), 'sort' => $epc],
		'cpc' => ['text' => dollar_format($s['avg_cpc'], $cpv), 'sort' => $s['avg_cpc']],
		'income' => $canSee ? ['text' => dollar_format($s['income'], $cpv), 'sort' => $s['income']] : $hidden,
		'cost' => $canSee ? ['text' => '(' . dollar_format($s['cost'], $cpv) . ')', 'sort' => $s['cost']] : $hidden,
		'net' => $canSee ? ['html' => $toned((string) dollar_format($net, $cpv)), 'sort' => $net] : $hidden,
		'roi' => ['html' => $toned($roi . '%'), 'sort' => $roi],
	];
};

/** A rule's criteria and where it sends a click, under its name. */
$ruleDetails = static function (int $ruleId) use ($fetchAll, $e): string {
	$criteria = $fetchAll('SELECT DISTINCT type, statement, value FROM 202_rotator_rules_criteria WHERE rule_id = ' . $ruleId);
	$redirects = $fetchAll('SELECT rr.redirect_url, rr.redirect_campaign, rr.redirect_lp, rr.weight, ac.aff_campaign_name, lp.landing_page_nickname
		FROM 202_rotator_rules_redirects AS rr
		LEFT JOIN 202_aff_campaigns AS ac ON (ac.aff_campaign_id = rr.redirect_campaign)
		LEFT JOIN 202_landing_pages AS lp ON (lp.landing_page_id = rr.redirect_lp AND lp.landing_page_deleted = 0)
		WHERE rr.rule_id = ' . $ruleId);

	$items = [];
	foreach ($criteria as $c) {
		$items[] = 'If ' . $e(ucfirst((string) $c['type'])) . ' ' . ($c['statement'] === 'is' ? 'is' : 'is not') . ' <strong>' . $e($c['value']) . '</strong>';
	}
	foreach ($redirects as $r) {
		$weight = (string) ($r['weight'] ?? '') !== '' ? ' (weight ' . $e($r['weight']) . ')' : '';
		if (!empty($r['redirect_campaign'])) {
			$items[] = 'Sends to campaign <strong>' . $e($r['aff_campaign_name'] ?? ('#' . $r['redirect_campaign'])) . '</strong>' . $weight;
		} elseif (!empty($r['redirect_lp'])) {
			$items[] = 'Sends to landing page <strong>' . $e($r['landing_page_nickname'] ?? ('#' . $r['redirect_lp'])) . '</strong>' . $weight;
		} elseif (!empty($r['redirect_url'])) {
			$items[] = 'Sends to <strong>' . $e($r['redirect_url']) . '</strong>' . $weight;
		}
	}
	if ($redirects === []) {
		$items[] = 'No redirect configured';
	}
	return '<details class="small mt-1"><summary class="text-secondary">Criteria and redirects</summary><ul class="mb-0 ps-3">'
		. implode('', array_map(static fn (string $item): string => '<li>' . $item . '</li>', $items))
		. '</ul></details>';
};

$rotators = $fetchAll('SELECT id, name FROM 202_rotators WHERE user_id = ' . $userId);

$rows = [];
$total = ['clicks' => 0.0, 'leads' => 0.0, 'payout' => 0.0, 'income' => 0.0, 'cost' => 0.0];
$lastRules = 0;
$lastDefaultPayout = 0.0;
foreach ($rotators as $rotator) {
	$rotatorId = (int) $rotator['id'];
	$all = $stats("rotator_id='" . $rotatorId . "'");
	foreach (['clicks', 'leads', 'income', 'cost'] as $key) {
		$total[$key] += $all[$key];
	}
	$total['payout'] += $all['payout'];
	$rows[] = ['name' => ['html' => '<strong>' . $e($rotator['name']) . '</strong>']] + $cells($all, 0);

	$rules = $fetchAll('SELECT id, rule_name FROM 202_rotator_rules WHERE rotator_id = ' . $rotatorId);
	foreach ($rules as $rule) {
		$ruleStats = $stats("rule_id='" . (int) $rule['id'] . "'");
		$rows[] = ['name' => ['html' => '<div class="ps-3">' . $e($rule['rule_name']) . $ruleDetails((int) $rule['id']) . '</div>']] + $cells($ruleStats, 2);
	}

	$default = $stats("rotator_id='" . $rotatorId . "' AND rule_id='0'");
	$lastRules = count($rules);
	$lastDefaultPayout = $default['payout'];
	$rows[] = ['name' => ['html' => '<div class="ps-3">Default <span class="text-secondary small">when no rule matches</span></div>']] + $cells($default, 2);
}

// The classic page's own arithmetic for the report's average payout: every
// rotator's payout plus the LAST rotator's default payout, over the last
// rotator's rule count plus one. Kept as it was, odd as it is: it is a
// figure, and changing what it means is a change to the report rather than
// to its page.
$totals = null;
if ($rotators !== []) {
	$payout = round(($total['payout'] + $lastDefaultPayout) / ($lastRules + 1), 2);
	$totalCells = $cells([
		'clicks' => $total['clicks'],
		'leads' => $total['leads'],
		'payout' => $payout,
		'income' => $total['income'],
		'avg_cpc' => $total['clicks'] > 0 ? round($total['cost'] / $total['clicks'], 5) : 0,
		'cost' => $total['cost'],
	], 0);
	$totals = ['name' => 'Totals for report'] + $totalCells;
}

$columns = [['key' => 'name', 'label' => 'Rotator']];
foreach (['clicks' => 'Clicks', 'leads' => 'Leads', 'su' => 'S/U', 'payout' => 'Payout', 'epc' => 'EPC', 'cpc' => 'Avg CPC', 'income' => 'Income', 'cost' => 'Cost', 'net' => 'Net', 'roi' => 'ROI'] as $key => $label) {
	$columns[] = ['key' => $key, 'label' => $label, 'num' => true];
}

echo p202_data_table($columns, $rows, [
	'id' => 'rotator-table',
	'caption' => 'Each rotator, its rules and its default',
	'totals' => $totals,
	'empty' => [
		'icon' => 'bi-shuffle',
		'title' => 'No rotators yet',
		'body' => 'A rotator sends each click to a campaign, landing page or URL by rules you set. Its figures appear here once it has one.',
		'action' => 'Set up a rotator',
		'href' => $base . 'tracking202/setup/rotator.php',
	],
]);
