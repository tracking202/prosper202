<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//show the template
template_top('Hourly Overview',NULL,NULL,NULL);  ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Hourly Overview</h6>
		<small>The breakdown overview allows you to see your stats per hour average.</small>
	</div>
</div>

<?php display_calendar('/tracking202/ajax/sort_hourly.php', true, true, true, false, true, true); ?>    

<script type="text/javascript">
   loadContent('/tracking202/ajax/sort_hourly.php',null);
</script>

<?php template_bottom();