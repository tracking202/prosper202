<?php

declare(strict_types=1);

/**
 * Overview › Group Overview: the traffic grouped by up to four dimensions,
 * each group's rows under it.
 *
 * Drawn into tracking202/overview/group-overview.php on the v2 shell; the
 * groupings, the filters and the window are the user's report preferences,
 * which that page has just applied from its URL. The query and the grouping
 * are ReportSummaryForm's, as the classic page and the download use them;
 * only the drawing is new — a .p202-table whose rows are indented by depth,
 * instead of the classic nested tables. It is not sortable in the browser:
 * sorting would pull a row out from under its group.
 */

include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -17) . '/202-config/ReportSummaryForm.class.php');
require_once(substr(__DIR__, 0, -17) . '/202-config/functions-ui-overview.php');

AUTH::require_user();

// Draw the view the page rendered, not whatever the stored filters say by
// now (ReportView); a request that carries none reads the stored ones.
$reportView = p202_report_view_begin(array_keys(p202_overview_groupings()));

//set the timezone for this user.
AUTH::set_timezone($_SESSION['user_timezone']);

//grab the users date range preferences
$time = grab_timeframe();
$mysql['to'] = $db->real_escape_string((string) $time['to']);
$mysql['from'] = $db->real_escape_string((string) $time['from']);

$user_row = p202_report_prefs_load(new \Prosper202\Database\Connection($db), (int) $_SESSION['user_id']);

$summary_form = new ReportSummaryForm();
$summary_form->setDetails([$user_row['user_pref_group_1'] ?? null, $user_row['user_pref_group_2'] ?? null, $user_row['user_pref_group_3'] ?? null, $user_row['user_pref_group_4'] ?? null]);
$summary_form->setDetailsSort([ReportBasicForm::SORT_NAME]);
$summary_form->setDisplayType([ReportBasicForm::DISPLAY_TYPE_TABLE]);
$summary_form->setStartTime($mysql['from']);
$summary_form->setEndTime($mysql['to']);

$mysql['user_id'] = $db->real_escape_string((string) $_SESSION['user_id']);
$info_sql = $summary_form->getQuery($mysql['user_id'], $user_row);
$info_result = $db->query($info_sql);
if (!$info_result instanceof mysqli_result) {
	record_mysql_error($info_sql);
}
while ($row = $info_result->fetch_assoc()) {
	$summary_form->addReportData($row);
}

$masked = isset($userObj) && !$userObj->hasPermission('access_to_campaign_data') && empty($_SESSION['publisher']);
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/**
 * One row's figures, formatted as the classic page formatted them, with the
 * raw value to sort by.
 *
 * @return array<string, array{html?: string, text?: string, sort?: float|int|null}>
 */
$figures = static function (object $row) use ($masked, $e): array {
	$money = static fn ($v): string => (string) dollar_format($v);
	$tone = static function (float $value, string $text): string {
		return $value > 0 ? '<span class="text-success">' . $text . '</span>'
			: ($value < 0 ? '<span class="text-danger">' . $text . '</span>' : $text);
	};
	$hidden = ['text' => '?'];
	return [
		'clicks' => $masked ? $hidden : ['text' => number_format((float) $row->getClicks()), 'sort' => $row->getClicks()],
		'click_out' => $masked ? $hidden : ['text' => number_format((float) $row->getClickOut()), 'sort' => $row->getClickOut()],
		'ctr' => ['text' => $row->getCtr() . '%', 'sort' => $row->getCtr()],
		'leads' => $masked ? $hidden : ['text' => number_format((float) $row->getLeads()), 'sort' => $row->getLeads()],
		'su_ratio' => ['text' => round($row->getSu() * 100, 2) . '%', 'sort' => $row->getSu()],
		'payout' => ['text' => $money($row->getPayout()), 'sort' => $row->getPayout()],
		'epc' => ['text' => $money($row->getEpc()), 'sort' => $row->getEpc()],
		'cpc' => ['text' => $money($row->getCpc()), 'sort' => $row->getCpc()],
		'income' => $masked ? $hidden : ['text' => $money($row->getIncome()), 'sort' => $row->getIncome()],
		'cost' => $masked ? $hidden : ['text' => $money($row->getCost()), 'sort' => $row->getCost()],
		'net' => $masked ? $hidden : ['html' => $tone((float) $row->getNet(), $e($money($row->getNet()))), 'sort' => $row->getNet()],
		'roi' => ['html' => $tone((float) $row->getRoi(), $e($row->getRoi() . '%')), 'sort' => $row->getRoi()],
	];
};

$rows = [];
$walk = static function (object $group, int $depth) use (&$walk, &$rows, $figures, $e): void {
	foreach ($group->getChildArrayBySort() as $child) {
		$title = (string) $child->getTitle();
		$label = '<span class="d-inline-block' . ($depth === 0 ? ' fw-bold' : '') . '" style="padding-left: ' . ($depth * 1.25) . 'rem;">' . $e($title) . '</span>';
		$rows[] = ['label' => ['html' => $label]] + $figures($child);
		if (is_callable([$child, 'getChildArrayBySort'])) {
			$walk($child, $depth + 1);
		}
	}
};
$walk($summary_form->getReportData(), 0);

$columns = [['key' => 'label', 'label' => 'Group']];
foreach (['clicks' => 'Clicks', 'click_out' => 'Click throughs', 'ctr' => 'LP CTR', 'leads' => 'Leads', 'su_ratio' => 'S/U', 'payout' => 'Payout', 'epc' => 'EPC', 'cpc' => 'CPC', 'income' => 'Income', 'cost' => 'Cost', 'net' => 'Net', 'roi' => 'ROI'] as $key => $label) {
	$columns[] = ['key' => $key, 'label' => $label, 'num' => true];
}

echo '<p class="text-secondary small mb-2">' . $e('Report for ' . date('m/d/Y', strtotime($summary_form->getStartDate())) . ' to ' . date('m/d/Y', strtotime($summary_form->getEndDate())) . ' · ' . $summary_form->getRanOn()) . '</p>';
echo p202_data_table($columns, $rows, [
	'id' => 'group-overview-table',
	'caption' => 'Your traffic, grouped',
	'totals' => $rows === [] ? null : ['label' => 'Totals for report'] + $figures($summary_form->getReportData()),
	'empty' => p202_overview_empty(get_absolute_url()),
]);

// The two levels that split a click by its conversions say how, once.
$groups = array_map('intval', [$user_row['user_pref_group_1'] ?? 0, $user_row['user_pref_group_2'] ?? 0, $user_row['user_pref_group_3'] ?? 0, $user_row['user_pref_group_4'] ?? 0]);
if ($rows !== [] && array_intersect($groups, [ReportBasicForm::DETAIL_LEVEL_TRANSACTIONS, ReportBasicForm::DETAIL_LEVEL_GOAL_SOURCE]) !== []) {
	echo '<p class="form-text mb-0" data-p202-ledger-note>Transaction ID and Goal / source rows add up the conversions that count toward each click, so a click with three transactions shows three amounts. Its click, lead and cost sit on the row of its latest counted conversion.</p>';
}
