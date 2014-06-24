<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 
	
$mysql['chart_id'] = $db->real_escape_string($_GET['chart_id']);
$chart_sql = "SELECT chart_xml FROM 202_charts WHERE chart_id='".$mysql['chart_id']."'";
$chart_result = $db->query($chart_sql) or record_mysql_error($chart_sql);
$chart_row = $chart_result->fetch_assoc();

echo $chart_row['chart_xml']; 
