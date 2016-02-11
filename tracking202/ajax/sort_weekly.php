<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 
include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/class-dataengine.php');

AUTH::require_user();

//set the timezone for the user, for entering their dates.
	AUTH::set_timezone($_SESSION['user_timezone']);
	
	//grab user time range preference
	$time = grab_timeframe();
	$mysql['to'] = $db->real_escape_string($time['to']);
	$mysql['from'] = $db->real_escape_string($time['from']);
	$de = new DataEngine();
	$data=($de->getReportData('weekly', $mysql['from'], $mysql['to'],$cpv));
	
	$dr= new DisplayData();
	$dr->displayReport('weekly', $data);

	?>
	
	<script type="text/javascript">
		new Tablesort(document.getElementById('stats-table'), {
		  descending: true
		});
	</script>
