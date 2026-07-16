<?php
$rootPath = dirname(__DIR__, 2);
include_once $rootPath . '/202-config/connect.php';
include_once $rootPath . '/202-config/functions-ui-calendar.php';

AUTH::require_user();

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

$reportSortUrl = get_absolute_url() . 'tracking202/ajax/sort_ips.php';
$reportCanary = tracking202_report_canary_config('ip', $reportSortUrl);

//show the template
template_top('Analyze Incoming IP Addresses'); ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Analyze Incoming IP Addresses</h6>
	</div>
</div>

<?php display_calendar($reportSortUrl, true, true, true, true, true, true, true, false, [
	'json_bootstrap_dependent_filters' => $reportCanary['dependentFilters']['jsonBootstrap'],
]); ?>

<?php tracking202_render_report_canary($reportCanary); ?>

<script type="text/javascript">
	loadContent('<?php echo $reportSortUrl; ?>', null);
</script>

<?php template_bottom();
