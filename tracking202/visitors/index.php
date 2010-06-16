<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


//set the timezone for the user, for entering their dates.
	AUTH::set_timezone($_SESSION['user_timezone']);

//show the template
template_top('Visitor History',NULL,NULL,NULL); ?>


<div id="info">
    <h2>Visitor History</h2>
</div>

<? display_calendar('/tracking202/ajax/click_history.php', true, true, true, true, false, true, false); ?> 
    
<script type="text/javascript">
   loadContent('/tracking202/ajax/click_history.php',null);
</script>





<?  template_bottom($server_row);
    