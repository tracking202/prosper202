<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();




//show the template
template_top('Hourly Overview',NULL,NULL,NULL);  ?>

<div id="info">
	<h2>Hourly Overview</h2>
	The breakdown overview allows you to see your stats per hour average.
</div>

<? display_calendar('/tracking202/ajax/sort_hourly.php', true, true, true, false, true, true); ?>    

<script type="text/javascript">
   loadContent('/tracking202/ajax/sort_hourly.php',null);
</script>

<? template_bottom();