<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);

$time = grab_timeframe();   
$html['from'] = date('m/d/Y - G:i', $time['from']);
$html['to'] = date('m/d/Y - G:i', $time['to']);
$mysql['to'] = $db->real_escape_string($time['to']);
$mysql['from'] = $db->real_escape_string($time['from']);

//show the template
template_top('Conversion Logs',NULL,NULL,NULL); ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Conversion Logs</h6>
	</div>
</div> 

<div class="row upgradeToProContainer" style="margin-bottom: 15px;">
<div class="upgradeToProOverlay" style="height:391px; width: 981px; margin-top:-15px;">
	<div class="upgradeToProOverlayBackground"></div>
	<a href="http://click202.com/tracking202/redirect/dl.php?t202id=8151295&t202kw=conversionlogs" target="_blank" class="btn btn-lg btn-p202 upgradeToProOverlayButton" style="margin-top: 170px; margin-left:344px;" id="upgradeConversionLogs">This is a Prosper202 Pro Feature: Upgrade Now To Access!</a>
</div>
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
				</select>
				<button id="get-logs" style="width: 130px;" type="submit" class="btn btn-xs btn-info">Get Logs</button>
			</div>
		</div>
		</form>
	</div>	   
</div>


<div id="logs_table">
<div class="col-xs-6" style="margin-top: 10px; margin-bottom: 10px;">
	<span class="infotext"><div class="results">Results: <b>3</b></div></span>
</div>

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
			<tr>
				<td>34754</td>
				<td>HostNine Web Hosting</td>
				<td><?php echo date('m/d/y g:ia', time() - 86400);?></td>
				<td><?php echo date('m/d/y g:ia', time() - 21600);?></td>
				<td>6 hours</td>
				<td>168.143.157.235</td>
				<td>Pixel</td>
				<tr>
					<td colspan="2">User agent:</td>
					<td colspan="6"><code style="white-space: inherit;">Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36</code></td>
				</tr>
			</tr>
			<tr>
				<td>34635</td>
				<td>Simple Forex Tester</td>
				<td><?php echo date('m/d/y g:ia', time() - 85700);?></td>
				<td><?php echo date('m/d/y g:ia', time() - 45600);?></td>
				<td>4 hours</td>
				<td>184.14.147.200</td>
				<td>Pixel</td>
				<tr>
					<td colspan="2">User agent:</td>
					<td colspan="6"><code style="white-space: inherit;">Mozilla/5.0 (Windows NT 6.2) AppleWebKit/535.7 (KHTML, like Gecko) Comodo_Dragon/16.1.1.0 Chrome/16.0.912.63</code></td>
				</tr>
			</tr>
			<tr>
				<td>32475</td>
				<td>Popcorn TV (DK) (Incentive)</td>
				<td><?php echo date('m/d/y g:ia', time() - 95700);?></td>
				<td><?php echo date('m/d/y g:ia', time() - 55600);?></td>
				<td>7 hours</td>
				<td>92.466.445.33</td>
				<td>Pixel</td>
				<tr>
					<td colspan="2">User agent:</td>
					<td colspan="6"><code style="white-space: inherit;">Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10; rv:33.0) Gecko/20100101 Firefox/33.0</code></td>
				</tr>
			</tr>
		</tbody>
	</table>
	</div>
</div>
</div>
<?php template_bottom($server_row);
    