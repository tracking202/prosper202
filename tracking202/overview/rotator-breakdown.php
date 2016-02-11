<?php include_once(substr(dirname( __FILE__ ), 0,-21) . '/202-config/connect.php'); 

AUTH::require_user();


//set the timezone for the user, for entering their dates.
	AUTH::set_timezone($_SESSION['user_timezone']);

//show the template
template_top('Redirectors Breakdown Overview',NULL,NULL,NULL); ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Redirectors Breakdown Overview</h6>
		<small>The breakdown overview allows you to see your redirector's stats per day, per hour, or an interval that you set.</small>
	</div>
</div>                                      

<?php display_calendar(get_absolute_url().'tracking202/ajax/sort_rotator.php', true, false, true, false, true, true, true); ?> 
    
<script type="text/javascript">
   loadContent('<?php echo get_absolute_url();?>tracking202/ajax/sort_rotator.php',null);
</script>




<?php  template_bottom();
	