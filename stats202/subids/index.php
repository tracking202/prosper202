<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user();
AUTH::require_valid_app_key('stats202', $_SESSION['user_api_key'], $_SESSION['user_stats202_app_key']);


template_top('My Subid Stats'); 

include_once('../top.php');


#function display_calendar($page, $show_time, $show_adv, $show_bottom, $show_limit, $show_breakdown, $show_type, $show_cpc_or_cpv = true) { 
	
$page = '/stats202/ajax/getSubids.php';
$show_time = true;
$show_adv = false;
$show_bottom = true;
$show_limit = true;
$show_breakdown = false;
$show_cpc_or_cpv = false;
$show_type = false;
display_calendar($page, $show_time, $show_adv, $show_bottom, $show_limit, $show_breakdown, $show_type, $show_cpc_or_cpv);    ?>
 

<script type="text/javascript">
 loadContent('/stats202/ajax/getSubids.php',null);
</script>



<?php template_bottom(); 

