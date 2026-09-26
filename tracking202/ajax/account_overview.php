<?php

declare(strict_types=1);

/**
 * Overview › Account Overview: the chart the user built, then every landing
 * page and campaign, then each campaign by traffic source account.
 *
 * Drawn into tracking202/overview/index.php on the v2 shell by
 * 202-js/p202-overview.js, which also draws the chart from the config below
 * (data-p202-chart), switches it between hours and days, and saves the chart
 * builder's form to tracking202/ajax/charts.php. The window, the clicks
 * counted and CPC or CPV are the user's report preferences, which the page
 * has just applied from its URL.
 */

include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -17) . '/202-config/class-dataengine.php');
require_once(substr(__DIR__, 0, -17) . '/202-config/functions-ui-overview.php');

AUTH::require_user();

// Draw the view the page rendered, not whatever the stored filters say by
// now (ReportView); a request that carries none reads the stored ones.
$reportView = p202_report_view_begin();

//set the timezone for this user.
AUTH::set_timezone($_SESSION['user_timezone']);

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$base = get_absolute_url();

//grab the users date range preferences
$time = grab_timeframe();
$from = (string) (int) $time['from'];
$to = (string) (int) $time['to'];

$mysql['user_id'] = $db->real_escape_string((string) $_SESSION['user_id']);
$user_sql = "SELECT up.user_pref_show, up.user_cpc_or_cpv, ac.aff_campaign_name, ac.aff_campaign_id, ch.data AS chart_data, ch.chart_time_range
				 FROM 202_users_pref AS up
				 LEFT OUTER JOIN 202_aff_campaigns AS ac ON (up.user_id = ac.user_id AND ac.aff_campaign_deleted = 0)
				 LEFT OUTER JOIN 202_charts AS ch ON (up.user_id = ch.user_id)
				 WHERE up.user_id=" . $mysql['user_id'] . "";
$user_result = _mysqli_query($user_sql);
if (!$user_result instanceof mysqli_result) {
	record_mysql_error($user_sql);
}

$aff_campaigns = [];
$user_row = ['user_cpc_or_cpv' => 'cpc', 'chart_data' => '', 'chart_time_range' => ''];
while ($user_row2 = $user_result->fetch_assoc()) {
	$user_row2 = \Prosper202\DataEngine\ReportView::apply($user_row2, $_SESSION['user_id']);
	$user_row['user_cpc_or_cpv'] = (string) $user_row2['user_cpc_or_cpv'];
	$user_row['chart_data'] = (string) ($user_row2['chart_data'] ?? '');
	$user_row['chart_time_range'] = (string) ($user_row2['chart_time_range'] ?? '');
	if ($user_row2['aff_campaign_id'] && !isset($aff_campaigns[(int) $user_row2['aff_campaign_id']])) {
		$aff_campaigns[(int) $user_row2['aff_campaign_id']] = (string) $user_row2['aff_campaign_name'];
	}
}

$cpv = $user_row['user_cpc_or_cpv'] === 'cpv';
$canSee = isset($userObj) && $userObj->hasPermission('access_to_campaign_data');
$masked = isset($userObj) && !$userObj->hasPermission('access_to_campaign_data') && empty($_SESSION['publisher']);

$de = new DataEngine();

// ── The chart ────────────────────────────────────────────────────────
if ($canSee) {
	$chartLines = unserialize($user_row['chart_data'], ['allowed_classes' => false]);
	if (!is_array($chartLines) || $chartLines === []) {
		// No chart saved yet (or none readable): the builder opens on one
		// line, all campaigns' clicks, which is what the chart then shows.
		$chartLines = [['campaign_id' => '0', 'value_type' => 'clicks']];
	}
	$range = $user_row['chart_time_range'] === 'hours' ? 'hours' : 'days';
	$rangeOutputFormat = $range === 'hours' ? 'M d h:iA' : 'M d';
	$rangePeriod = returnRanges(new DateTime('@' . $from), new DateTime('@' . $to), $range);
	$chart = $de->getChart($from, $to, $chartLines, $range, $rangeOutputFormat, $rangePeriod);

	$categories = [];
	foreach ($rangePeriod as $point) {
		$categories[] = $point->format($rangeOutputFormat);
	}
	$chartConfig = json_encode([
		'credits' => ['enabled' => false],
		'chart' => ['type' => 'line'],
		'title' => ['text' => 'From ' . date('d/m/Y', (int) $from) . ' to ' . date('d/m/Y', (int) $to)],
		'xAxis' => ['categories' => $categories],
		'yAxis' => ['title' => ['text' => null]],
		'plotOptions' => ['line' => ['dataLabels' => ['enabled' => true]]],
		'series' => $chart['series'] ?? [],
	], JSON_NUMERIC_CHECK | JSON_THROW_ON_ERROR);

	$types = [
		'clicks' => 'Clicks', 'click_out' => 'Click throughs', 'ctr' => 'CTR', 'leads' => 'Leads',
		'su_ratio' => 'Average S/U', 'payout' => 'Average payout', 'epc' => 'Average EPC', 'cpc' => 'Average CPC',
		'income' => 'Income', 'cost' => 'Cost', 'net' => 'Net', 'roi' => 'ROI',
	];
	?>
	<div class="p202-table-toolbar">
		<div class="p202-toolbar">
			<div class="btn-group btn-group-sm" role="group" aria-label="Chart resolution">
				<?php foreach (['hours' => 'By hour', 'days' => 'By day'] as $value => $label) { ?>
					<input type="radio" class="btn-check" name="chart_time_range" id="overview-chart-<?php echo $value; ?>" value="<?php echo $value; ?>" autocomplete="off"
						data-p202-chart-range="overview-chart" data-p202-chart-url="<?php echo $e(p202_report_view_url($base . 'tracking202/ajax/charts.php', $reportView)); ?>"<?php echo $range === $value ? ' checked' : ''; ?>>
					<label class="btn btn-outline-primary" for="overview-chart-<?php echo $value; ?>"><?php echo $label; ?></label>
				<?php } ?>
			</div>
		</div>
		<div class="p202-table-toolbar__aside">
			<button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#overview-chart-builder"><i class="bi bi-graph-up"></i> Choose what the chart shows</button>
		</div>
	</div>
	<div id="overview-chart" class="mb-4" style="height: 300px;" data-p202-chart="<?php echo $e($chartConfig); ?>" role="img" aria-label="Chart of the figures you chose, over the window above"></div>

	<div class="modal fade" id="overview-chart-builder" tabindex="-1" aria-labelledby="overview-chart-builder-title" aria-hidden="true">
		<div class="modal-dialog modal-lg">
			<form class="modal-content" id="p202-build-chart" method="post" action="<?php echo $e(p202_report_view_url($base . 'tracking202/ajax/charts.php', $reportView)); ?>">
				<div class="modal-header">
					<h5 class="modal-title" id="overview-chart-builder-title">Choose what the chart shows</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<p class="text-secondary small">One line per figure. Pick a campaign, or all of them, and the figure to plot.</p>
					<?php foreach ($chartLines as $index => $line) {
						$lineCampaign = (string) ($line['campaign_id'] ?? '0');
						$lineType = (string) ($line['value_type'] ?? 'clicks'); ?>
						<div class="row g-2 align-items-end mb-2" data-p202-chart-line>
							<div class="col-sm-6">
								<label class="form-label" for="overview-chart-level-<?php echo (int) $index; ?>">Campaign</label>
								<select class="form-select form-select-sm" id="overview-chart-level-<?php echo (int) $index; ?>" name="data_level[]">
									<option value="0"<?php echo $lineCampaign === '0' ? ' selected' : ''; ?>>All campaigns</option>
									<?php foreach ($aff_campaigns as $campaignId => $campaignName) { ?>
										<option value="<?php echo (int) $campaignId; ?>"<?php echo $lineCampaign === (string) $campaignId ? ' selected' : ''; ?>><?php echo $e($campaignName); ?></option>
									<?php } ?>
								</select>
							</div>
							<div class="col-sm-4">
								<label class="form-label" for="overview-chart-type-<?php echo (int) $index; ?>">Figure</label>
								<select class="form-select form-select-sm" id="overview-chart-type-<?php echo (int) $index; ?>" name="data_type[]">
									<?php foreach ($types as $value => $label) { ?>
										<option value="<?php echo $value; ?>"<?php echo $lineType === $value ? ' selected' : ''; ?>><?php echo $label; ?></option>
									<?php } ?>
								</select>
							</div>
							<div class="col-sm-2">
								<button type="button" class="btn btn-outline-danger btn-sm w-100" data-p202-chart-remove>Remove</button>
							</div>
						</div>
					<?php } ?>
					<button type="button" class="btn btn-link btn-sm px-0" data-p202-chart-add><i class="bi bi-plus-lg"></i> Add a line</button>
					<div class="invalid-feedback d-block" data-p202-chart-error hidden></div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary">Draw the chart</button>
				</div>
			</form>
		</div>
	</div>
	<?php
}

// ── The tables ───────────────────────────────────────────────────────
$lpsData = $de->getReportData('LpOverview', $from, $to, $cpv);
$campaignsData = $de->getReportData('campaignOverview', $from, $to, $cpv);
$slpDirectLinkPerPPC = $de->getReportData('slp_direct_link_per_ppc', $from, $to, $cpv);
$alpPerPPC = $de->getReportData('alp_per_ppc', $from, $to, $cpv);

$empty = p202_overview_empty($base);

echo '<h3 class="p202-section__title">Direct links and landing pages</h3>';
echo p202_overview_metrics_table($lpsData, [
	'id' => 'overview-landing-pages',
	'label' => 'Direct link / landing page',
	'caption' => 'Each landing page, and direct links together',
	'key' => static fn (array $row): string => (string) ($row['landing_page_nickname'] ?? ''),
	'masked' => $masked,
	'empty' => $empty,
]);

echo '<h3 class="p202-section__title mt-4">Campaigns</h3>';
echo p202_overview_metrics_table($campaignsData, [
	'id' => 'overview-campaigns',
	'label' => 'Campaign',
	'caption' => 'Each campaign',
	'key' => static function (array $row): string {
		$network = (string) ($row['aff_network_name'] ?? '');
		$campaign = (string) ($row['aff_campaign_name'] ?? '');
		return $network === '' ? $campaign : $network . ' &middot; ' . $campaign;
	},
	'masked' => $masked,
	'empty' => $empty,
]);

// Each campaign (and each advanced landing page) split by traffic source
// account, one table apiece, as the classic page listed them.
$perSource = [
	'slp_direct_link' => [$slpDirectLinkPerPPC, 'direct link and simple landing page', static function (array $c): string {
		$network = (string) ($c['total_aff_network_name'] ?? '');
		$campaign = (string) ($c['total_aff_campaign_name'] ?? '');
		return $network === '' ? $campaign : $network . ' &middot; ' . $campaign;
	}],
	'alp' => [$alpPerPPC, 'advanced landing page', static fn (array $c): string => (string) ($c['total_landing_page_nickname'] ?? '')],
];
$sourceTables = 0;
foreach ($perSource as $type => [$byCampaign, $kind, $name]) {
	foreach ((array) $byCampaign as $campaignId => $campaign) {
		if (!is_array($campaign) || empty($campaign['ppc_accounts'])) {
			continue;
		}
		if ($sourceTables === 0) {
			echo '<h3 class="p202-section__title mt-4">By traffic source</h3>';
		}
		$sourceTables++;
		echo '<p class="mb-2 mt-3"><strong>' . $name($campaign) . '</strong> <span class="text-secondary small">' . $e($kind) . '</span></p>';
		echo p202_overview_metrics_table(array_merge(array_values($campaign['ppc_accounts']), [$campaign]), [
			'id' => 'overview-sources-' . $type . '-' . (int) $campaignId,
			'label' => 'Traffic source account',
			'caption' => 'Traffic source accounts for this campaign',
			'key' => static function (array $row): string {
				$network = (string) ($row['ppc_network_name'] ?? '');
				$account = (string) ($row['ppc_account_name'] ?? '');
				return $network !== '' && $account !== '' ? $network . ' &middot; ' . $account : '[No traffic source account]';
			},
			'masked' => $masked,
		]);
	}
}
