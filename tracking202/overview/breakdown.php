<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();




//show the template
template_top('Breakdown Overview',NULL,NULL,NULL);  ?>

<div id="info">
	<h2>Breakdown Overview</h2>
	The breakdown overview allows you to see your stats per day, per hour, or an interval that you set. 
</div>

<? display_calendar('/tracking202/ajax/sort_breakdown.php', true, true, true, false, true, true); ?>    

<script type="text/javascript">
   loadContent('/tracking202/ajax/sort_breakdown.php',null);
</script>

<? template_bottom();