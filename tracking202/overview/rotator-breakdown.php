<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


//set the timezone for the user, for entering their dates.
	AUTH::set_timezone($_SESSION['user_timezone']);

//show the template
template_top('Rotators Breakdown Overview',NULL,NULL,NULL); ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Rotators Breakdown Overview</h6>
		<small>The breakdown overview allows you to see your rotators stats per day, per hour, or an interval that you set.</small>
	</div>
</div>                                      

<?php display_calendar('/tracking202/ajax/sort_rotator.php', true, false, true, false, true, true, true); ?> 
    
<script type="text/javascript">
   loadContent('/tracking202/ajax/sort_rotator.php',null);
</script>




<?php  template_bottom();
	