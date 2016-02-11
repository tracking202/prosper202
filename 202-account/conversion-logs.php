<?php include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/connect.php'); 

AUTH::require_user();


//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
$user_sql = "SELECT user_time_register FROM 202_users WHERE user_id=".$mysql['user_id'];
$user_result = _mysqli_query($user_sql);
$user_row = $user_result->fetch_assoc();

$campaign_sql = "SELECT aff_campaign_id, aff_campaign_name FROM 202_aff_campaigns WHERE user_id=".$mysql['user_id']." AND aff_campaign_deleted = '0'";
$campaign_result = _mysqli_query($campaign_sql);

$time = grab_timeframe();   
$html['from'] = date('m/d/Y - G:i', $time['from']);
$html['to'] = date('m/d/Y - G:i', $time['to']);
$mysql['to'] = $db->real_escape_string($time['to']);
$mysql['from'] = $db->real_escape_string($time['from']);

$logs_sql = "SELECT 2ca.aff_campaign_name, 2l.click_id, 2l.click_time, 2l.conv_time, 2l.time_difference, 2l.ip, 2l.pixel_type, 2l.user_agent FROM 202_conversion_logs AS 2l LEFT JOIN 202_aff_campaigns AS 2ca ON (2l.campaign_id = 2ca.aff_campaign_id) WHERE 2l.user_id=".$mysql['user_id']." AND 2l.click_time >= ".$mysql['from']." AND 2l.click_time < ".$mysql['to'];
$logs_result = _mysqli_query($logs_sql);

//show the template
template_top('Conversion Logs',NULL,NULL,NULL); ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Conversion Logs</h6>
	</div>
</div> 

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
	<div id="preferences-wrapper">
		<span style="position: absolute; font-size:12px;"><span class="fui-search"></span> Refine your search: </span>
		<form id="logs_from" onsubmit="return false;" class="form-inline text-right" role="form">
		<div class="row">
			<div class="col-xs-12">
				<label for="from">Start date: </label>
				<div class="form-group datepicker" style="margin-right: 5px;">
				    <input type="text" class="form-control input-sm" name="from" id="from" value="<?php echo $html['from']; ?>">
				</div>

				<label for="to">End date: </label>
				<div class="form-group datepicker">
				    <input type="text" class="form-control input-sm" name="to" id="to" value="<?php echo $html['to']; ?>">
				</div>

				<div class="form-group">
					<label class="sr-only" for="user_pref_time_predefined">Date</label>
					<select class="form-control input-sm" name="user_pref_time_predefined" id="user_pref_time_predefined" onchange="set_user_pref_time_predefined();">
					    <option value="">Custom Date</option>                                       
						<option <?php if ($time['user_pref_time_predefined'] == 'today') { echo 'selected=""'; } ?> value="today">Today</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'yesterday') { echo 'selected=""'; } ?> value="yesterday">Yesterday</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'last7') { echo 'selected=""'; } ?> value="last7">Last 7 Days</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'last14') { echo 'selected=""'; } ?> value="last14">Last 14 Days</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'last30') { echo 'selected=""'; } ?> value="last30">Last 30 Days</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'thismonth') { echo 'selected=""'; } ?> value="thismonth">This Month</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'lastmonth') { echo 'selected=""'; } ?> value="lastmonth">Last Month</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'thisyear') { echo 'selected=""'; } ?> value="thisyear">This Year</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'lastyear') { echo 'selected=""'; } ?> value="lastyear">Last Year</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'alltime') { echo 'selected=""'; } ?> value="alltime">All Time</option>
					</select>
				</div>
			</div>
		</div>

		<div class="form_seperator" style="margin:5px 0px; padding:1px">
			<div class="col-xs-12"></div>
		</div>

		<div class="row">
			<div class="col-xs-12">
				<label for="to">SubID: </label>
				<div class="form-group">
				    <input type="text" class="form-control input-sm" name="logs_subid" id="logs_subid">
				</div>

				<label for="to">Campaign: </label>
				<select class="form-control input-sm" name="logs_campaign" id="logs_campaign">
					<option value="0"> -- </option>
					<?php while ($campaign_row = $campaign_result->fetch_assoc()) { ?>
						<option value="<?php echo $campaign_row['aff_campaign_id'];?>"><?php echo $campaign_row['aff_campaign_name'];?></option>
					<?php } ?>
				</select>
				<button id="get-logs" style="width: 130px;" type="submit" class="btn btn-xs btn-info">Get Logs</button>
			</div>
		</div>
		</form>
	</div>	   
</div>
</div>

<div id="logs_table">
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
</div>

<script type="text/javascript">
	
function set_user_pref_time_predefined() {

	var element = $('#user_pref_time_predefined');

	if (element.val() == 'today') {
		<?php $time['from'] = mktime(0,0,0,date('m',time()),date('d',time()),date('Y',time()));
			$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

		$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
		$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
	}

	if (element.val() == 'yesterday') {
		<?php $time['from'] = mktime(0,0,0,date('m',time()-86400),date('d',time()-86400),date('Y',time()-86400));
			$time['to'] = mktime(23,59,59,date('m',time()-86400),date('d',time()-86400),date('Y',time()-86400)); ?>

		$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
		$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
	}

	if (element.val() == 'last7') {
		<?php $time['from'] = mktime(0,0,0,date('m',time()-86400*7),date('d',time()-86400*7),date('Y',time()-86400*7));
			$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

		$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
		$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
	}

	if (element.val() == 'last14') {
		<?php $time['from'] = mktime(0,0,0,date('m',time()-86400*14),date('d',time()-86400*14),date('Y',time()-86400*14));
			$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

		$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
		$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
	}

	if (element.val() == 'last30') {
		<?php $time['from'] = mktime(0,0,0,date('m',time()-86400*30),date('d',time()-86400*30),date('Y',time()-86400*30));
			$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

		$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
		$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
	}

	if (element.val() == 'thismonth') {
		<?php $time['from'] = mktime(0,0,0,date('m',time()),1,date('Y',time()));
			$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

		$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
		$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
	}

	if (element.val() == 'lastmonth') {
		<?php $time['from'] = mktime(0,0,0,date('m',time()-2629743),1,date('Y',time()-2629743));
			$time['to'] = mktime(23,59,59,date('m',time()-2629743),getLastDayOfMonth(date('m',time()-2629743), date('Y',time()-2629743)),date('Y',time()-2629743)); ?>

		$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
		$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
	}

	if (element.val() == 'thisyear') {
		<?php $time['from'] = mktime(0,0,0,1,1,date('Y',time()));
			$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

		$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
		$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
	}

	if (element.val() == 'lastyear') {
		<?php $time['from'] = mktime(0,0,0,1,1,date('Y',time()-31556926));
			$time['to'] = mktime(0,0,0,12,getLastDayOfMonth(date('m',time()-31556926), date('Y',time()-31556926)),date('Y',time()-31556926)); ?>

		$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
		$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
	}
			
	if (element.val() == 'alltime') {
		<?php  
		$time['from'] = $user_row['user_time_register'];
				
		$time['from'] = mktime(0,0,0,date('m',$time['from']),date('d',$time['from']),date('Y',$time['from']));  
		$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

		$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
		$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
	}
}
</script> 
<?php template_bottom($server_row);
    