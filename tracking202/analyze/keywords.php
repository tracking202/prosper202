<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-20) . '/202-config/connect.php');

AUTH::require_user();

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

$reportSortUrl = get_absolute_url() . 'tracking202/ajax/sort_keywords.php';
$reportCanary = tracking202_report_canary_config('keyword', $reportSortUrl);

//show the template
template_top('Analyze Your Keywords'); ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Analyze Your Keywords</h6>
	</div>
</div>

<?php display_calendar($reportSortUrl, true, true, true, true, true, true, true, false, [
	'json_bootstrap_dependent_filters' => $reportCanary['dependentFilters']['jsonBootstrap'],
]); ?>

<?php tracking202_render_report_canary($reportCanary); ?>

<script type="text/javascript">
   loadContent('<?php echo $reportSortUrl; ?>', null);
</script>

<?php  template_bottom();
