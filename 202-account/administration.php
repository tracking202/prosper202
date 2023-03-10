<?php 
include_once(str_repeat("../", 1).'202-config/connect.php');
include_once(str_repeat("../", 1).'202-config/functions-tracking202.php');

AUTH::require_user();

$slack = false;
$mysql['user_own_id'] = $db->real_escape_string($_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url, maxmind_isp, user_time_register, 2up.user_auto_database_optimization_days FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();
$username = $user_row['username'];
$user_time_register = $user_row['user_time_register'];

if (!empty($user_row['url'])) 
	$slack = new Slack($user_row['url']);

$cron_log_query = "SELECT last_execution_time FROM 202_cronjob_logs";
$cron_log_result = $db->query($cron_log_query);

if ($cron_log_result->num_rows > 0) {
	$cron_log_row = $cron_log_result->fetch_assoc();
	$last_cron_job_execution_time = CronJobLastExecution('@'.$cron_log_row['last_execution_time']);
} else {
	$last_cron_job_execution_time = '<span style="color:#e74c3c">never</span>';
}


$de_query = "SELECT count(*) as total, sum(processed) as done FROM 202_dataengine_job";
$de_result = $db->query($de_query);
$de_row = $de_result->fetch_assoc();

if ($de_result->num_rows && $de_row['total'] != 0) {
    $de_total = $de_row['total'];
    $de_done = $de_row['done'];
    $de_ratio = @round(($de_done / $de_total) * 100, 2);
} else {
	$de_total = '0';
	$de_done = '0';
	$de_ratio = '0';
}

$de_minutes = @round($de_total - $de_done, 0);

$d = floor ($de_minutes / 1440);
$h = floor (($de_minutes - $d * 1440) / 60);
$m = $de_minutes - ($d * 1440) - ($h * 60);


if (isset($_POST['autocron'])) {

	$autocron = false;

	if ($_POST['autocron'] == true) {
		$endpoint = 'register';
		$sql = "SELECT * FROM 202_cronjob_logs";
	    $result = _mysqli_query($sql);
	    if ($result->num_rows > 0) {
	        $row = $result->fetch_assoc();
	                
	        $last_five_minutes = time() - 300;
	                
	        if ($row['last_execution_time'] < $last_five_minutes) {
	            $autocron = true;
	        }

	    } else {
	        $autocron = true;
	    }
	} else {
		$endpoint = 'deregister';
		$autocron = true;
	}
	
	if ($autocron) {
	    $cron = callAutoCron($endpoint);

	    if ($cron['status'] == 'success') {
	    	$mysql['auto_cron'] = $db->real_escape_string($_POST['autocron']);
			$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	        $sql = "UPDATE 202_users_pref SET auto_cron = '".$mysql['auto_cron']."' WHERE user_id = '".$mysql['user_id']."'";
	        $result = _mysqli_query($sql);
	    }
	}	
	
	die();
}

if (isset($_POST['maxmind'])) {

	if($_POST['maxmind'] == "true") {
		if (file_exists(substr(dirname( __FILE__ ), 0,-12) . '/202-config/geo/GeoIPISP.dat')) {
			$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
			$sql = "UPDATE 202_users_pref SET maxmind_isp='1' WHERE user_id='".$mysql['user_id']."'";
			$result = _mysqli_query($sql);
			if ($slack)
				$slack->push('maxmind_isp_changed', array('user' => $username, 'type' => 'Activated'));
 		} else {
 			echo "ISP Database file doesn't exist. Make sure (GeoIPISP.dat file) is in /202-config/geo/ folder.";
 		}
	} 

	if($_POST['maxmind'] == "false") {
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
		$sql = "UPDATE 202_users_pref SET maxmind_isp='0' WHERE user_id='".$mysql['user_id']."'";
		$result = _mysqli_query($sql);

		if ($slack)
			$slack->push('maxmind_isp_changed', array('user' => $username, 'type' => 'Deactivated'));
	}

	die();

}

template_top('Administration',NULL,NULL,NULL);  
$click_sql = "SELECT `AUTO_INCREMENT`-1 as clicks FROM  INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '202_clicks_counter';";
		$click_row = memcache_mysql_fetch_assoc($click_sql);
		$clicks = $click_row['clicks'];
		//$click_sql = "SELECT count(*) clicks FROM 202_clicks_total";
		//$click_row = memcache_mysql_fetch_assoc($click_sql);
		//$clicks += $click_row['clicks'];


if (isset($_POST['database_management'])) {
	 $tables = is_array($tables) ? $tables : explode(',','202_clicks_advance,202_clicks_record,202_clicks_site,202_clicks_spy,202_clicks_tracking,202_clicks');
	$click_timestamp = strtotime($_POST['database_management']. "00:00:00 ".date('T'));
    $clickid_sql= "SELECT click_id AS click_id FROM 202_clicks WHERE click_time <=". $click_timestamp." ORDER BY click_id DESC LIMIT 1";

   $clickid_result = _mysqli_query($clickid_sql);
   $clickid_row = $clickid_result->fetch_assoc();
    
    $mysql['user_delete_data_clickid'] = $db->real_escape_string($clickid_row['click_id']);
    
    $sql ="UPDATE 202_users_pref SET user_delete_data_clickid = '".$mysql['user_delete_data_clickid']."' WHERE user_id = '".$mysql['user_own_id']."'";
    $db->query($sql);

	if ($slack)
		$slack->push('click_data_deleted', array('user' => $username, 'date' => $_POST['database_management']));

	header('location: '.get_absolute_url().'202-account/administration.php');
 
}

if (isset($_POST['auto_database_management'])) {
	$mysq['auto_database_management'] = $db->real_escape_string($_POST['auto_database_management']);
	$sql ="UPDATE 202_users_pref SET user_auto_database_optimization_days = '".$mysq['auto_database_management']."' WHERE user_id = '".$mysql['user_own_id']."'";
	$db->query($sql);
}

function getClicksEraseDate(){
    global $db,$mysql;
    
    $sql="SELECT click_time FROM `202_clicks` WHERE click_id <= (SELECT user_delete_data_clickid FROM `202_users_pref` WHERE user_id='".$mysql['user_own_id']."') ORDER BY click_id DESC LIMIT 1";

    $result = $db->query($sql);
    $row = $result->fetch_array(MYSQLI_ASSOC);
    if($row){
        return date('d-m-Y', $row['click_time']);
    }
    else{
        return date('d-m-Y', time());
    }
}


function database_size() {
	global $db;
	define('GIG', 1000000000);
	$MB= 1024*1024;
	$GB= 1024*1024*1024;
	$decimals = 2;
	
	
	$sql = $db->query("SHOW TABLE STATUS");
	
	$size = 0;  
	while($row = $sql->fetch_array(MYSQLI_ASSOC)) {
	    $size += $row["Data_length"] + $row["Index_length"];  
	}
	  
	
	if($size > GIG)
	{
    	$mbytes = number_format($size/($GB),$decimals) . " GB";
	}else{
	    $mbytes = number_format($size/($MB),$decimals) . " MB";
	}
	
	return $mbytes;
}

function CronJobLastExecution($datetime) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    $week =array('w'=>floor($diff->d / 7));
    $diff->d -= $week->w * 7;
    $diff=(object) array_merge((array)$diff, $week);
    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

?>


<div class="row account">
	<div class="col-xs-12">

		<div class="row">
			<div class="col-xs-12">
				<h6>System Configuration</h6>
			</div>
		</div>

		<div class="row" id="system-prefs">
			<div class="col-xs-6">
				<div class="panel panel-default account_left">
					<div class="panel-body">

						<p>
							Prosper202 Version: <span class="pull-right"><?php echo $version; ?></span>
						</p>
						<p>
							PHP Version: <span class="pull-right"><?php echo phpversion(); ?></span>
						</p>
						<p>
							MySQL Version: <span class="pull-right">
				    	<?php
						   echo getMYSQLVersion($db); ?></span>
						</p>
						<p>
							PHP Safe Mode <span class="fui-info-circle" style="font-size: 10px;"
								data-toggle="tooltip"
								title="PHP Safe Mode needs to be turned off in order for Stats202, Offers202 or Alerts202 to work. You will have to contact your web host to have them disable it."></span><span
								class="pull-right"><?php if (@ini_get('safe_mode')) echo '<span class="label label-important">On</span> - this should be turned off.'; else echo 'Off'; ?></span>
						</p>
						<p>
							Memcache Installed <span class="fui-info-circle"
								style="font-size: 10px;" data-toggle="tooltip"
								title="If you have memcache installed and working, it will speed up click redirections."></span><span
								class="pull-right"><?php if ($memcacheInstalled) echo 'Yes'; else echo 'No'; ?></span>
						</p>
						<p>
							Memcache Running <span class="fui-info-circle" style="font-size: 10px;"
								data-toggle="tooltip"
								title="If memcache is installed, but not running, check your 202-config.php to make sure your connecting to a server that has memcache installed."></span><span
								class="pull-right"><?php if ($memcacheWorking) echo 'Yes'; else echo 'No'; ?></span>
						</p>
						<p>
							Default Keyword Preference <span class="pull-right"
								style="font-size: 10px; line-height: 2.5;">
				    		<?php  $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
							$user_sql = "SELECT * FROM 202_users_pref WHERE user_id='".$mysql['user_id']."'";
							$user_result = _mysqli_query($user_sql);
							$user_row = $user_result->fetch_assoc();
							$html['keyword_pref'] = htmlentities( strtoupper($user_row['user_keyword_searched_or_bidded']) );
							echo 'Pick up the '.$html['keyword_pref'].' keyword - <a href="'.get_absolute_url().'202-account/account.php">[change]</a>'; ?></span>
						</p>
						<p>
							BlazerCache during MySQL Failure <span class="fui-info-circle"
								style="font-size: 10px;" data-toggle="tooltip"
								title="Make sure this is working, BlazerCache will make sure your redirects still continue to work in the event of a complete MySQL failure."></span><span
								style="position: absolute; right: 31px"><?php if ($memcacheWorking) echo 'Yes'; else echo '<span style="font-size: 10px; line-height:2.5;"><span class="label label-important">No</span> - install Memcache in PHP!</span>'; ?></span>
						</p>
						<p>
							BlazerCache for User Agent Data Parsing <span class="fui-info-circle"
								style="font-size: 10px;" data-toggle="tooltip"
								title="Make sure this is working, BlazerCache will make User Agent data parsing up to 10x faster."></span><span
								style="position: absolute; right: 31px"><?php if ($memcacheWorking) echo 'Yes'; else echo '<span style="font-size: 10px; line-height:2.5;"><span class="label label-important">No</span> - install Memcache in PHP!</span>'; ?></span>
						</p>

					</div>
				</div>
			</div>
			<div class="col-xs-6">
				<div class="panel panel-default account_left">
					<div class="panel-body">
						<p>
							post_max_size: <span class="pull-right"><?php echo ini_get('post_max_size'); ?></span>
						</p>
						<p>
							upload_max_filesize: <span class="pull-right"><?php echo ini_get('upload_max_filesize'); ?></span>
						</p>
						<p>
							max_input_time: <span class="pull-right"><?php echo ini_get('max_input_time'); ?></span>
						</p>
						<p>
							max_execution_time: <span class="pull-right"><?php echo ini_get('max_execution_time'); ?></span>
						</p>
						<p>
							DataEngine Conversion Status <span class="fui-info-circle"
								style="font-size: 10px;" data-toggle="tooltip"
								title="This shows how much of your old data has been converted to the DataEngine format."></span>
							<span class="pull-right"><?php echo $de_done;?> done of <?php echo $de_total;?> tasks (<?php echo $de_ratio;?>%)</span>
						</p>
						<p>
							DataEngine Conversion ETA <span class="fui-info-circle"
								style="font-size: 10px;" data-toggle="tooltip"
								title="This shows an estimate of how much time is left till your old data is all coverted."></span>
							<span class="pull-right">Estimated time left: <?php echo "{$d}d {$h}h {$m}m";?></span>
						</p>
						<p>
							CronJob Last Execution: <span class="pull-right"><?php echo $last_cron_job_execution_time;?></span>
						</p>
					</div>
				</div>
			</div>
		</div>

	</div>
</div>

<div class="row form_seperator">
	<div class="col-xs-12"></div>
</div>

<div class="row account">
	<div class="col-xs-4">
		<h6>Tracking202 Stats</h6>
	</div>
	<div class="col-xs-8 text-right" style="padding-top: 15px;">
		<small><span class="label label-primary"><?php echo $clicks;?></span>
			clicks recorded to date.</small>
	</div>
</div>

<div class="row form_seperator">
	<div class="col-xs-12"></div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<h6>Database Management</h6>
	</div>
	<div class="col-xs-4" style="padding-top: 6px;">
		<small>Current Prosper202 Database Size: <span
			class="label label-primary"><?php echo database_size(); ?></span>
		</small>
	</div>
	<div class="col-xs-8">
		<form method="post" id="erase_clicks_form" class="form-horizontal"
			role="form">
			<div class="form-group">
				<label for="erase_clicks_date" class="col-sm-6 control-label">Delete
					Click Data Prior to Selected Date:<br>Leave blank to reset</label>
				<div class="col-sm-6">
					<input type="text" class="form-control input-sm"
						id="erase_clicks_date" name="database_management"
						value="<?php echo getClicksEraseDate();?>"> <span
						class="help-block" style="font-size: 11px;"><span
						class="label label-important">Warning:</span> This clears out
						everything except your setup data</span>
				</div>
			</div>
			<div class="form-group">
				<div class="col-sm-6 col-sm-offset-6">
					<button class="btn btn-xs btn-p202 btn-block" type="submit">Delete
						data</button>
				</div>
			</div>
		</form>
	</div>
</div>
<div class="row account">
	<div class="col-xs-12">
		<h6>Auto Database Optimization</h6>
	</div>
	<div class="col-xs-4" style="padding-top: 6px;">
		
	</div>
	<div class="col-xs-8">
		<form method="post" id="erase_clicks_form" class="form-horizontal"
			role="form">
			<div class="form-group">
				<label for="erase_clicks_date" class="col-sm-6 control-label">Automatically Delete
					Click Data Older Than # Of Days Specified:</label>
				<div class="col-sm-6">
				<div class="input-group input-group-sm">
					<input type="number" class="form-control input-sm"
						id="auto_erase_clicks_date" name="auto_database_management"
						placeholder="Enter number of days" value="<?php echo $user_row['user_auto_database_optimization_days'];?>">
						<span class="input-group-addon" id="basic-addon2">Days</span></div> <span
						class="help-block" style="font-size: 11px;"><span
						class="label label-important">Warning:</span> This deletes data about
						every click that occured before the specified number of days. <strong>(Enter 0 or leave blank if you don't want old data autormatically deleted)</strong></span>
				</div>
			</div>
			<div class="form-group">
				<div class="col-sm-6 col-sm-offset-6">
					<button class="btn btn-xs btn-p202 btn-block" type="submit">Auto Optimize Database</button>
				</div>
			</div>
		</form>
	</div>
</div>
<div class="row form_seperator">
	<div class="col-xs-12"></div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<h6>AutoCron Settings</h6>
		<span class="infotext">AutoCron is enabled by default, after you
			install Prosper202 Pro. You can change settings here.</span>
	</div>

	<div class="col-xs-12">
		<form class="form-horizontal" id="autocron" role="form"
			style="margin-top: 25px; margin-bottom: 25px;">
			<div class="form-group">
				<label for="inputEmail3" class="col-sm-2 control-label">AutoCron:</label>
				<div class="col-sm-10">
					<label id="on-label" class="radio radio-inline"
						style="margin-top: 5px"> <input type="radio" name="autocron"
						id="on" value="1" data-toggle="radio"
						<?php if($user_row['auto_cron'] == true) echo "checked";?>> On
					</label> <label id="off-label" class="radio radio-inline"
						style="margin-top: 5px"> <input type="radio" name="autocron"
						id="off" value="0" data-toggle="radio"
						<?php if($user_row['auto_cron'] == false) echo "checked";?>> Off
					</label>
				</div>
			</div>
		</form>
	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<h6>MaxMind ISP/Carrier Lookup</h6>
		<span class="infotext"><span><a href="http://click202.com/tracking202/redirect/dl.php?t202id=9159015&t202kw=p202setup" target="_blank"><img src="<?php echo get_absolute_url();?>202-img/maxmind_logo-202.png"></a></span><br>To turn on ISP/Carrier lookup feature, you need
			to <strong><a href="http://click202.com/tracking202/redirect/dl.php?t202id=9159015&t202kw=p202setup" target="_blank">buy MaxMind ISP database</a></strong> and upload (GeoIPISP.dat file) to <code><?php echo getTrackingDomain(). get_absolute_url().'202-config/geo/';?></code>
			folder.<br />(Settings will take place after 5 minutes in live
			traffic)
		</span>
	</div>

	<div class="col-xs-12">
		<form class="form-horizontal" id="maxmindisp" role="form"
			style="margin-top: 25px; margin-bottom: 25px;">
			<div class="form-group">
				<label for="inputEmail3" class="col-sm-2 control-label">ISP/Carrier
					Lookup:</label>
				<div class="col-sm-10">
					<label id="on-label" class="radio radio-inline"
						style="margin-top: 5px"> <input type="radio" name="maxmind-isp"
						id="on" value="true" data-toggle="radio"
						<?php if($user_row['maxmind_isp'] == true) echo "checked";?>> On
					</label> <label id="off-label" class="radio radio-inline"
						style="margin-top: 5px"> <input type="radio" name="maxmind-isp"
						id="off" value="false" data-toggle="radio"
						<?php if($user_row['maxmind_isp'] == false) echo "checked";?>> Off
					</label>
				</div>
			</div>
		</form>
	</div>
</div>

<div class="row form_seperator">
	<div class="col-xs-12"></div>
</div>
<div class="row account">
	<div class="col-xs-12">
		<h6>Last 50 Login Attempts</h6>
	</div>
	<div class="col-xs-12">

	<?php 
	//show the last 20 logins failed or pass
	$user_log_sql = "SELECT * FROM 202_users_log ORDER BY login_id DESC LIMIT 50";
	$user_log_result = _mysqli_query($user_log_sql);
	?>

	<table class="table table-bordered">
			<thead>
				<tr>
					<th>Time</th>
					<th>Username</th>
					<th>IP Address</th>
					<th>Attempt</th>
				</tr>
			</thead>
			<tbody>
	        <?php
			while ($user_log_row = $user_log_result->fetch_assoc()) {

				$html['user_name'] = htmlentities($user_log_row['user_name'], ENT_QUOTES, 'UTF-8');
				$html['ip_address'] = htmlentities($user_log_row['ip_address'], ENT_QUOTES, 'UTF-8');
				$html['login_time'] = htmlentities(date('M d, y \a\t g:ia', $user_log_row['login_time']), ENT_QUOTES, 'UTF-8');

				if ($user_log_row['login_success'] == 0) { $html['login_success'] = '<span style="color: #900;">Failed</span>'; } else { $html['login_success'] = 'Passed'; }

				printf('<tr>
					<td>%s</td>
					<td>%s</td>
					<td>%s :: <a target="_new" href="https://whois.arin.net/ui/query.do?q=%s">ARIN</a> / <a target="_new" href="https://apps.db.ripe.net/search/query.html?searchtext=%s&sources=RIPE_NCC">RIPE</a></td>
					<td>%s</td>
				     </tr>',$html['login_time'], $html['user_name'], $html['ip_address'], $html['ip_address'], $html['ip_address'], $html['login_success']);
			}
			?>
	    </tbody>
		</table>
	</div>
</div>
<?php template_bottom();