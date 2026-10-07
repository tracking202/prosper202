<?php

declare(strict_types=1);

/**
 * Overview › Rotator Breakdown: each rotator's totals, then each of its rules
 * and its default redirect, over the window.
 *
 * Drawn into tracking202/overview/rotator-breakdown.php on the v2 shell; the
 * window, the clicks counted and CPC or CPV are the user's report
 * preferences, which that page has just applied from its URL. The figures
 * are Tracking202\Report\RotatorBreakdown's: a click's rotator and rule are
 * its 202_clicks_rotator row, as GET /rotators/{id}/stats reads them (that
 * class says what counting from 202_clicks got wrong). A rule's criteria and
 * redirects, which the classic page fetched into a modal from the Setup
 * page's endpoint, are read here and shown under the rule, so the report
 * does not depend on Setup's markup.
 */

include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');
require_once(substr(__DIR__, 0, -17) . '/202-config/functions-ui-overview.php');

use Prosper202\Report\CampaignDataMask;

AUTH::require_user();

// Draw the view the page rendered, not whatever the stored filters say by
// now (ReportView); a request that carries none reads the stored ones.
$reportView = p202_report_view_begin();

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
$show = (string) ($prefs['user_pref_show'] ?? '');
$cpv = ($prefs['user_cpc_or_cpv'] ?? '') === 'cpv';
// The one masking decision every report surface shares (CampaignDataMask):
// it exempts a publisher session, whose queries are already scoped to its own
// rows, as every other report does -- the classic rotator page was the one
// screen that masked publishers.
$canSee = !CampaignDataMask::hidden();

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

/**
 * One row of figures (RotatorBreakdown's clicks, leads, income, cost),
 * formatted as the classic page formatted them. Payout is income per lead and
 * the average CPC cost per click, both from the row's own sums.
 */
$cells = static function (array $s, int $roiPrecision) use ($cpv, $canSee, $e): array {
	$net = $s['income'] - $s['cost'];
	$su = $s['clicks'] > 0 ? round($s['leads'] / $s['clicks'] * 100, 2) : 0;
	$epc = $s['clicks'] > 0 ? round($s['income'] / $s['clicks'], 2) : 0;
	$roi = $s['cost'] > 0 ? round($net / $s['cost'] * 100, $roiPrecision) : 0;
	$s['payout'] = $s['leads'] > 0 ? $s['income'] / $s['leads'] : 0;
	$s['avg_cpc'] = $s['clicks'] > 0 ? $s['cost'] / $s['clicks'] : 0;
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

try {
	$rotators = (new \Tracking202\Report\RotatorBreakdown($db))->rotators($userId, $from, $to, $show);
} catch (\Prosper202\Database\Exceptions\QueryException $failed) {
	// The classic page's database error, not an empty report.
	record_mysql_error($db, $failed->getMessage());
}

$rows = [];
$total = \Tracking202\Report\RotatorBreakdown::zero();
foreach ($rotators as $rotator) {
	$total = \Tracking202\Report\RotatorBreakdown::add($total, $rotator['totals']);
	$rows[] = ['name' => ['html' => '<strong>' . $e($rotator['name']) . '</strong>']] + $cells($rotator['totals'], 0);

	foreach ($rotator['rules'] as $rule) {
		$label = $rule['deleted']
			? '<div class="ps-3">Deleted rule <span class="text-secondary small">#' . $e($rule['id']) . ', since removed; its clicks in this window</span></div>'
			: '<div class="ps-3">' . $e($rule['name']) . $ruleDetails($rule['id']) . '</div>';
		$rows[] = ['name' => ['html' => $label]] + $cells($rule['figures'], 2);
	}

	$rows[] = ['name' => ['html' => '<div class="ps-3">Default <span class="text-secondary small">when no rule matches</span></div>']] + $cells($rotator['default'], 2);
}

// The report's totals are every rotator's clicks summed, its payout income
// per lead over all of them. The classic page averaged the rotators' payouts
// with the last rotator's default counted twice, over that rotator's rule
// count plus one: a figure that described none of the rows above it.
$totals = $rotators === [] ? null : ['name' => 'Totals for report'] + $cells($total, 0);

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
