<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//set the timezone for the user, for entering their dates.
	AUTH::set_timezone($_SESSION['user_timezone']);

//show the template
template_top('Analyze Incoming IP Addresses',NULL,NULL,NULL); ?>


<div id="info">
    <h2>Analyze Incoming IP Addresses</h2>                                                                                                                       
</div>       

<? display_calendar('/tracking202/ajax/sort_ips.php', true, true, true, true, true, true); ?> 
    
<script type="text/javascript">
   loadContent('/tracking202/ajax/sort_ips.php',null);
</script>



<?  template_bottom();
    