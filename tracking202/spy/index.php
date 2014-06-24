<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//set the timezone for the user, for entering their dates.
	AUTH::set_timezone($_SESSION['user_timezone']);

//show the template
template_top('Spy View',NULL,NULL,NULL); ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Spy View</h6>
		<small>Spy is a live view of visitors interacting with your campaigns.</small>
	</div>
</div> 


<?php display_calendar('/tracking202/ajax/click_history.php?spy=1', false, true, true, false, false, true, false); ?>
	
<script type="text/javascript">
	runSpy();
   	window.setInterval(function(){
	  runSpy();
	}, 5000); 
</script>  

<?php  template_bottom();