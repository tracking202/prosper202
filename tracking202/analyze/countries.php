<?php include_once(substr(dirname(__FILE__), 0, -21) . '/202-config/connect.php');
include_once(substr(dirname(__FILE__), 0, -21) . '/202-config/functions-ui-calendar.php');

AUTH::require_user();

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

//show the template
template_top('Analyze Incoming Countries', NULL, NULL, NULL); ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Analyze Incoming Countries</h6>
	</div>
</div>

<?php display_calendar(get_absolute_url() . 'tracking202/ajax/sort_countries.php', true, true, true, true, true, true); ?>

<script type="text/javascript">
	loadContent('<?php echo get_absolute_url(); ?>tracking202/ajax/sort_countries.php', null);
</script>

<?php template_bottom(); ?>