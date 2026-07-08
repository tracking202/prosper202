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
	// Restore whatever view the URL encodes (bookmark, reload, deep link,
	// legacy ?customer_id=N); replace-seed the history entry so Back from
	// the initial view still returns to the referring page.
	var t202LtvInitial = ltvViewFromLocation();
	ltvNav(t202LtvInitial.view, t202LtvInitial.params, true);

	// The calendar reloads the report partial directly on a date change,
	// whatever view was showing — bring the address bar back to the
	// report so the URL keeps matching the content.
	var t202LtvSetUserPrefs = set_user_prefs;
	set_user_prefs = function(page, offset) {
		if (window.history && window.history.replaceState) {
			window.history.replaceState({ ltvView: 'report', ltvParams: {} }, '', ltvUrl('report', {}));
		}
		t202LtvSetUserPrefs(page, offset);
	};
</script>

<?php template_bottom(); ?>
