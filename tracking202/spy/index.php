<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//set the timezone for the user, for entering their dates.
	AUTH::set_timezone($_SESSION['user_timezone']);

//show the template
template_top('Spy View',NULL,NULL,NULL); ?>

<div id="info">
	<h2>Spy View</h2>
	Spy is a live view of visitors interacting with your affiliate campaigns.
</div>


<? display_calendar('/tracking202/ajax/click_history.php?spy=1', false, true, true, false, false, true, false); ?>
	
<script type="text/javascript">
	if($('s-status-loading')) {   $('s-status-loading').style.display='';       }  
   	runSpy();
   	new PeriodicalExecuter(runSpy, 5); 
</script>  

<?  template_bottom();