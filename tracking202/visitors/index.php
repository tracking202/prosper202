<?php
declare(strict_types=1);
include_once(substr(dirname( __FILE__ ), 0,-21) . '/202-config/connect.php');

AUTH::require_user();


//set the timezone for the user, for entering their dates.
	AUTH::set_timezone($_SESSION['user_timezone']);

//show the template
template_top('Visitor History',NULL,NULL,NULL); ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Visitor History</h6>
	</div>
</div> 

<?php display_calendar(get_absolute_url().'tracking202/ajax/click_history.php', true, true, true, true, false, true, false); ?> 
    
<script type="text/javascript">
   loadContent('<?php echo get_absolute_url();?>tracking202/ajax/click_history.php',null);
</script>


<?php  template_bottom();
    