<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();
$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	$from = explode('-', $_POST['from']); 
	$from = explode(':', $from[1]); 
	$from_hour = $from[0];
	$from_minute = $from[1];
	
	$from = explode('-', $_POST['from']); 
	$from = explode('/', $from[0]); 
    $from_month = trim($from[0]);
	$from_day = trim($from[1]);
	$from_year = trim($from[2]);

	$to = explode('-', $_POST['to']); 
	$to = explode(':', $to[1]); 
	$to_hour = $to[0];
	$to_minute = $to[1];
	
	$to = explode('-', $_POST['to']); 
    $to = explode('/', $to[0]); 
    $to_month = trim($to[0]);
    $to_day = trim($to[1]);
    $to_year = trim($to[2]);

    $clean['from'] = mktime($from_hour,$from_minute,0,$from_month,$from_day,$from_year);
    $clean['to'] = mktime($to_hour,$to_minute,59,$to_month,$to_day,$to_year);

    $mysql['from'] = $db->real_escape_string($clean['from']);
	$mysql['to'] = $db->real_escape_string($clean['to']);
	$mysql['click_id'] = $db->real_escape_string($_POST['logs_subid']);
	$mysql['campaign_id'] = $db->real_escape_string($_POST['logs_campaign']);
	
	$logs_sql = "SELECT 2ca.aff_campaign_name, 2l.click_id, 2l.click_time, 2l.conv_time, 2l.time_difference, 2l.ip, 2l.pixel_type, 2l.user_agent FROM 202_conversion_logs AS 2l LEFT JOIN 202_aff_campaigns AS 2ca ON (2l.campaign_id = 2ca.aff_campaign_id) WHERE 2l.user_id=".$mysql['user_id']." AND 2l.click_time >= ".$mysql['from']." AND 2l.click_time < ".$mysql['to'];
	
	if ($_POST['logs_campaign']) {
		$logs_sql .= " AND campaign_id = '".$mysql['campaign_id']."'";
	}

	if ($_POST['logs_subid']) {
		$logs_sql .= " AND click_id = '".$mysql['click_id']."'";
	}	

	$logs_result = _mysqli_query($logs_sql);


} ?>  
 
<div class="row" style="margin-top: 10px; margin-bottom: 10px;">
	<div class="col-xs-6">
		<span class="infotext"><?php printf('<div class="results">Results <b>%s</b></div>',$logs_result->num_rows);  ?></span>
	</div>
</div>

<div class="row">
	<div class="col-xs-12">
	<table class="table table-bordered" id="stats-table">
		<thead>
		    <tr style="background-color: #f2fbfa;">
		        <th>SubID</th>
		        <th>Campaign</th>
		        <th>Click Time</th>
		        <th>Conversion Time</th>
		        <th>Time Difference</th>
		        <th>IP Address</th>
		        <th>Pixel Type</th>
		    </tr>
		</thead>
		<tbody>
		<?php while ($logs_row = $logs_result->fetch_assoc()) { ?>
			<tr>
				<td><?php echo $logs_row['click_id'];?></td>
				<td><?php echo $logs_row['aff_campaign_name'];?></td>
				<td><?php echo date('m/d/y g:ia', $logs_row['click_time']);?></td>
				<td><?php echo date('m/d/y g:ia', $logs_row['conv_time']);?></td>
				<td><?php echo $logs_row['time_difference'];?></td>
				<td><?php echo $logs_row['ip'];?></td>
				<td><?php if ($logs_row['pixel_type'] == '1') { 
							  echo "Pixel";
						  } else if ($logs_row['pixel_type'] == '2') {
						  	  echo "Postback";
						  } else if ($logs_row['pixel_type'] == '3') {
						  	  echo "Universal Pixel";
						  } 
					?>
				</td>
				<tr>
					<td colspan="2">User agent:</td>
					<td colspan="6"><code style="white-space: inherit;"><?php echo $logs_row['user_agent'];?></code></td>
				</tr>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
</div> 