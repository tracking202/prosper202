<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 
	
$mysql['chart_id'] = mysql_real_escape_string($_GET['chart_id']);
$chart_sql = "SELECT chart_xml FROM 202_charts WHERE chart_id='".$mysql['chart_id']."'";
$chart_result = mysql_query($chart_sql) or record_mysql_error($chart_sql);
$chart_row = mysql_fetch_assoc($chart_result);

echo $chart_row['chart_xml']; 
