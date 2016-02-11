<?php 
include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 
include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/class-dataengine.php');
AUTH::require_user();
	
//set the timezone for this user.
AUTH::set_timezone($_SESSION['user_timezone']);

//grab the users date range preferences
	$time = grab_timeframe(); 
	$mysql['to'] = $db->real_escape_string($time['to']);
	$mysql['from'] = $db->real_escape_string($time['from']); 
	
	
//show real or filtered clicks
	$aff_campaigns = array();
	$count = 0;

	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$user_sql = "SELECT 2p.user_pref_show, 2p.user_cpc_or_cpv, 2ac.aff_campaign_name, 2ac.aff_campaign_id, 2ch.data AS chart_data, 2ch.chart_time_range 
				 FROM 202_users_pref AS 2p 
				 LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2p.user_id = 2ac.user_id AND 2ac.aff_campaign_deleted = 0)
				 LEFT OUTER JOIN 202_charts AS 2ch ON (2p.user_id = 2ch.user_id) 
				 WHERE 2p.user_id=".$mysql['user_id']."";
	$user_result = _mysqli_query($user_sql, $dbGlobalLink); //($user_sql);

	while ($user_row2 = $user_result->fetch_assoc()) {
		$user_row['user_pref_show'] = $user_row2['user_pref_show'];
		$user_row['user_cpc_or_cpv'] = $user_row2['user_cpc_or_cpv'];
		$user_row['user_chart_data'] = $user_row2['chart_data'];
		$user_row['chart_time_range'] = $user_row2['chart_time_range'];
		$aff_campaigns[] = array('aff_campaign_id' => $user_row2['aff_campaign_id'], 'aff_campaign_name' => $user_row2['aff_campaign_name']);
	}

	$user_row['user_chart_data'] = unserialize($user_row['user_chart_data']);

	if ($user_row['user_pref_show'] == 'all') { $click_filtered = ''; }
	if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
	if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
	if ($user_row['user_pref_show'] == 'filtered_bot') { $click_filtered = " AND click_bot='1' "; }
	if ($user_row['user_pref_show'] == 'leads') { $click_filtered = " AND click_lead='1' "; } 
	
	if ($user_row['user_cpc_or_cpv'] == 'cpv')  $cpv = true;
	else 										$cpv = false; ?>
	
<div class="row">
	<div class="col-xs-6">
		<h6>Account Overview</h6>
	</div>
	<?php if ($userObj->hasPermission("access_to_campaign_data")) { ?>
	<div class="col-xs-6 text-right" style="margin-top: 15px;">
		<button type="button" class="btn btn-primary btn-xs" id="build_chart">Build chart</button>
		<div class="btn-group btn-group-xs" data-toggle="buttons">
		  <label class="btn btn-default <?php if($user_row['chart_time_range'] == 'hours') echo'active';?>">
		    <input type="radio" name="chart_time_range" id="range1" value="hours" <?php if($user_row['chart_time_range'] == 'hours') echo'checked="checked"';?>> By hours
		  </label>
		  <label class="btn btn-default <?php if($user_row['chart_time_range'] == 'days') echo'active';?>">
		    <input type="radio" name="chart_time_range" id="range2" value="days" <?php if($user_row['chart_time_range'] == 'days') echo'checked="checked"';?>> By days
		  </label>
		</div>
	</div>
	<?php } ?>
</div>

<?php
$de = new DataEngine();

if ($userObj->hasPermission("access_to_campaign_data")) {

	echo '<div id="chart" style="width:100%; height:300px; margin-bottom: 25px;"></div>';

	$start = new DateTime('@' . $mysql['from']);
	$end = new DateTime('@' . $mysql['to']);

	switch ($user_row['chart_time_range']) {
	    case 'hours':
	        $rangeOutputFormat = 'M d h:iA';
	    break;
	            
	    case 'days':
	        $rangeOutputFormat = 'M d';
	    break;

	    default:
	    	$rangeOutputFormat = 'M d';
	    	$user_row['chart_time_range'] = 'days';
	    break;
	}

	$rangePeriod = returnRanges($start, $end, $user_row['chart_time_range']);

	$chart = $de->getChart($mysql['from'], $mysql['to'], $user_row['user_chart_data'], $user_row['chart_time_range'], $rangeOutputFormat, $rangePeriod);
}
?>

<?php if ($userObj->hasPermission("access_to_campaign_data")) { ?>
<script type="text/javascript">
	var chart = new Highcharts.Chart({
		credits: {
            enabled: false
        },
		chart: {
			renderTo: 'chart',	
            type: 'line'
        },
        title: {
            text: 'From <?php echo date('d/m/Y', $mysql['from']); ?> to <?php echo date('d/m/Y', $mysql['to']); ?>'
        },
        xAxis: {
            categories: [<?php foreach ($rangePeriod as $range) { echo "'".$range->format($rangeOutputFormat)."', ";}?>]
        },
        plotOptions: {
            line: {
                dataLabels: {
                    enabled: true
                }
            }
        },
        series: <?php echo json_encode($chart['series'], JSON_NUMERIC_CHECK);?>
	});
</script>
<?php } ?>

<?php 
$dr = new DisplayData();
$campaignsData = ($de->getReportData('campaignOverview', $mysql['from'], $mysql['to'],$cpv));
$lpsData = array();
$lpsData = ($de->getReportData('LpOverview', $mysql['from'], $mysql['to'],$cpv));

$dr->displayReport('LpOverview', $lpsData);
$dr->displayReport('campaignOverview', $campaignsData); 

$slpDirectLinkPerPPC = $de->getReportData('slp_direct_link_per_ppc', $mysql['from'], $mysql['to'],$cpv);
$dr->displayPerPPCReport('slp_direct_link', $slpDirectLinkPerPPC);

$AlpPerPPC = $de->getReportData('alp_per_ppc', $mysql['from'], $mysql['to'],$cpv);
$dr->displayPerPPCReport('alp', $AlpPerPPC); 
?>

<script type="text/javascript">
	new Tablesort(document.getElementById('stats-table'), {
	  descending: true
	});
</script>

<?php if ($userObj->hasPermission("access_to_campaign_data")) { ?>
<div class="modal fade" id="buildChartModal" tabindex="-1" role="dialog" aria-labelledby="buildChartModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
              <button type="button" class="close fui-cross" data-dismiss="modal" aria-hidden="true"></button>
              <h4 class="modal-title" id="myModalLabel">Build chart</h4>
            </div>
            <div class="modal-body">
            <div class="row">
            	<div class="col-xs-9">
	            	<form class="form-inline" id="build_chart_form">
	            	<?php foreach ($user_row['user_chart_data'] as $user_chart_data) { $count++; ?>
		            	<div class="col-xs-12">
		            		<div class="form-group">
							    <label for="data_level[]">Data level: </label>
							    <select class="form-control input-sm" name="data_level[]">
								  <option value="0" <?php if($user_chart_data['campaign_id'] == '0') echo 'selected';?>>All</option>
								  <?php foreach ($aff_campaigns as $campaign) { ?>
								  	<option value="<?php echo $campaign['aff_campaign_id'];?>" <?php if($user_chart_data['campaign_id'] == $campaign['aff_campaign_id']) echo 'selected';?>><?php echo $campaign['aff_campaign_name'];?></option>
								  <?php } ?>
								</select>
							</div>
							<div class="form-group">
							    <label for="data_type[]">type: </label>
							    <select class="form-control input-sm" name="data_type[]">
								  <option value="clicks" <?php if($user_chart_data['value_type'] == 'clicks') echo 'selected';?>>Click</option>
								  <option value="click_out" <?php if($user_chart_data['value_type'] == 'click_out') echo 'selected';?>>Click Throughs</option>
								  <option value="ctr" <?php if($user_chart_data['value_type'] == 'ctr') echo 'selected';?>>CTR</option>
								  <option value="leads" <?php if($user_chart_data['value_type'] == 'leads') echo 'selected';?>>Leads</option>
								  <option value="su_ratio" <?php if($user_chart_data['value_type'] == 'su_ratio') echo 'selected';?>>Average S/U</option>
								  <option value="payout" <?php if($user_chart_data['value_type'] == 'payout') echo 'selected';?>>Average Payout</option>
								  <option value="epc" <?php if($user_chart_data['value_type'] == 'epc') echo 'selected';?>>Average EPC</option>
								  <option value="cpc" <?php if($user_chart_data['value_type'] == 'cpc') echo 'selected';?>>Average CPC</option>
								  <option value="income" <?php if($user_chart_data['value_type'] == 'income') echo 'selected';?>>Income</option>
								  <option value="cost" <?php if($user_chart_data['value_type'] == 'cost') echo 'selected';?>>Cost</option>
								  <option value="net" <?php if($user_chart_data['value_type'] == 'net') echo 'selected';?>>Net</option>
								  <option value="roi" <?php if($user_chart_data['value_type'] == 'roi') echo 'selected';?>>ROI</option>
								</select>
							</div>
							<?php if($count > 1) { ?> <span class="small"><a href="#" class="remove_chart_data_type" style="color:#a1a6a9"><i class="fa fa-close"></i></a></span> <?php } ?>	
						</div>
					<?php } ?>			
					</form>
				</div>
				<div class="col-xs-3 text-right">
					<small><a href="#" id="add_more_chart_data_type"><i class="fa fa-plus"></i> add more</a></small>
				</div>
			</div>	
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
              <button type="button" id="build_chart_form_submit" class="btn btn-primary">Build</button>
            </div>
          </div>
        </div>
    </div>
</div>
<?php } ?>