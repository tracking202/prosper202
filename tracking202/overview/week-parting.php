<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();




//show the template
template_top('Hourly Overview',NULL,NULL,NULL);  ?>

<div id="info">
	<h2>Week Parting</h2>
	Here you can see what day of the week performs best.
</div>

<? display_calendar('/tracking202/ajax/sort_weekly.php', true, true, true, false, true, true); ?>    

<script type="text/javascript">
   loadContent('/tracking202/ajax/sort_weekly.php',null);
</script>

<? template_bottom();