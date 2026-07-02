<?php
$rootPath = dirname(__DIR__, 2);
include_once $rootPath . '/202-config/connect.php';
include_once $rootPath . '/202-config/functions-ui-calendar.php';

AUTH::require_user();

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

//show the template
template_top('Customer Lifetime Value'); ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Customer Lifetime Value</h6>
		<small>Revenue per customer across their lifetime &mdash; repeat purchases, subscriptions and refunds included. The date range selects customers by acquisition date (first seen).</small>
	</div>
</div>

<?php display_calendar(get_absolute_url() . 'tracking202/ajax/sort_ltv.php', true, false, true, false, false, false); ?>

<script type="text/javascript">
	loadContent('<?php echo get_absolute_url(); ?>tracking202/ajax/sort_ltv.php', null);
</script>

<?php template_bottom(); ?>
