<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();




//set the timezone for the user, for entering their dates.
	AUTH::set_timezone($_SESSION['user_timezone']);

//show the template
template_top('Analyze Landing Pages',NULL,NULL,NULL); ?>



<div id="info">
	<h2>Analyze Incoming Landing Pages</h2>
</div>                                        

<? display_calendar('/tracking202/ajax/sort_landing_pages.php', true, true, true, true, true, true); ?> 
    
<script type="text/javascript">
   loadContent('/tracking202/ajax/sort_landing_pages.php',null);
</script>




<?  template_bottom();
	