<?php
ignore_user_abort(true);
set_time_limit(0);
//include mysql settings
include_once(dirname( __FILE__ ) . '/connect.php');
include_once(dirname( __FILE__ ) . '/class-dataengine.php');
include_once(dirname( __FILE__ ) . '/functions-upgrade.php');

//check to see if this is already installed, if so don't do anything
	if (  upgrade_needed() == false) {
	    header('location: '.get_absolute_url().'202-login.php');
		_die("<h6>Already Upgraded</h6>
			   <small>Your Prosper202 version $version is already upgraded. <a href='".get_absolute_url()."202-login.php'>log in</a></small>" ); 	
	 
	}
	
	
	$phpversion = phpversion();
	if ($phpversion < 5.4) {
	    $version_error['phpversion'] = 'Prosper202 requires PHP 5.4, or newer.';
	}
	
    // Get Database version
	$mysqlversion = $db->server_info;
	if (preg_match('/-(10\..+)-MariaDB/i', $mysqlversion, $match)) {
	    // Support For MariaDB
	    $mysqlversion = $match[1];
	    $dbwording="MariaDB >= 10.0.12";
	    if ((version_compare($mysqlversion, '10.0.12') < 0)) {
	        $version_error['mysqlversion'] = 'Prosper202 requires MariaDB 10.0.12, or newer.';
	    }
	}
	else{
	    $dbwording="MySQL >= 5.6";
	    if ((version_compare($mysqlversion, '5.6') < 0)) {
	        $version_error['mysqlversion'] = 'Prosper202 requires MySQL 5.6, or newer.';
	    }
	     
	}
	
	$html['mysqlversion'] = htmlentities($mysqlversion, ENT_QUOTES, 'UTF-8');
	
	if (!function_exists('curl_version')) {
	    $version_error['curl'] = 'Prosper202 requires CURL to be installed.';
	}
	
	if ($version_error) {
	   // header("Location: /202-config/requirements.php");
	    info_top();
	    
	?>
	<div class="main col-xs-7">
	<h4 style="color:#e74c3c">Warning: Your current host does not meet the minimum Prosper202 Server Requirements! <br><br>Please switch to an Official Hosting Partner below to upgrade without issues:</h4>
	<br></br>
	<?php 
	
		$partners = json_decode(getData('https://my.tracking202.com/api/v2/hostings'), true);

		foreach ($partners as $partner) { ?>
			<div class="media">
			  <div class="media-left">
			    <a href="<?php echo $partner['url'];?>">
			      <img class="media-object" style="width: 64px; height: 64px;" src="<?php echo $partner['thumb'];?>">
			    </a>
			  </div>
			  <div class="media-body">
			    <a href="<?php echo $partner['url'];?>" style="color: #337ab7;"><strong><?php echo $partner['title'];?></strong></a>
			    <p class="infotext"><a href="<?php echo $partner['url'];?>" style="color: #333;"><?php echo $partner['description'];?></a></p>
			  </div>
			</div>
		<?php }
	?>
	<h6>System requirements</h6>
	<table class="table table-bordered">
	<thead>
		<tr class="info">
			<th>Software / Function</th>
			<th>Status</th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td>PHP >= 5.6</td>
			<td><span class="label label-<?php if ($version_error['phpversion']) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php echo phpversion(); ?></span></td>
		</tr>
		<tr>
			<td>MySQL >= 5.6</td>
			<td><span class="label label-<?php if ($version_error['mysqlversion']) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php echo $html['mysqlversion'] ;?></span></td>
		</tr>
		<tr>
			<td>CURL</td>
			<td><span class="label label-<?php if ($version_error['curl']) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php if($version_error['curl']) echo $version_error['curl']; else echo "Installed"; ?></span></td>
		</tr>
		<tr>
			<td>xml_parser_create()</td>
			<td><span class="label label-<?php if ($version_error['xml_parser_create']) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php if($version_error['xml_parser_create']) echo $version_error['xml_parser_create']; else echo "Installed"; ?></span></td>
		</tr>
		<tr>
			<td>MySQL Partitioning</td>
			<td><span class="label label-<?php if ($partition_support==0) {echo "warning";} else {echo "primary";}?>" style="font-size: 100%;"><?php if($partition_support==0) echo "Missing"; else echo "Enabled"; ?></span></td>
		</tr>
		<tr>
			<td>PHP Memcache or Memcached (recommended)</td>
			<td><span class="label label-<?php if (!$memcacheInstalled) {echo "warning";} else {echo "primary";}?>" style="font-size: 100%;"><?php if(!$memcacheInstalled) echo "Missing"; else echo "Installed"; ?></span></td>
		</tr>

		<tr>
			<td>PHP zip_open() <br>(required for 1-Click Upgrade)</td>
			<td><span class="label label-<?php if (!function_exists('zip_open')) {echo "warning";} else {echo "primary";}?>" style="font-size: 100%;"><?php if(!function_exists('zip_open')) echo "Missing"; else echo "Installed"; ?></span></td>
		</tr>

		<tr>
			<td>PHP Mycrypt <br>(required for Enhanced Account Security and Clickbank Sales Notification Integration)</td>
			<td><span class="label label-<?php if (!function_exists('mcrypt_encrypt')) {echo "warning";} else {echo "primary";}?>" style="font-size: 100%;"><?php if(!function_exists('mcrypt_encrypt')) echo "Missing"; else echo "Installed"; ?></span></td>
		</tr>

	</tbody>
	</table>
	
	
	
	<h6>Prosper202 Official Hosting Partners:</h6>

	<?php 

		foreach ($partners as $partner) { ?>
			<div class="media">
			  <div class="media-left">
			    <a href="<?php echo $partner['url'];?>">
			      <img class="media-object" style="width: 64px; height: 64px;" src="<?php echo $partner['thumb'];?>">
			    </a>
			  </div>
			  <div class="media-body">
			    <a href="<?php echo $partner['url'];?>" style="color: #337ab7;"><strong><?php echo $partner['title'];?></strong></a>
			    <p class="infotext"><a href="<?php echo $partner['url'];?>" style="color: #333;"><?php echo $partner['description'];?></a></p>
			  </div>
			</div>
		<?php }
		info_bottom();
		die();
	} //end error check
	
	

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	if (version_compare(PROSPER202::prosper202_version(), '1.9.3', '<')) {

		$date = DateTime::createFromFormat('d-m-Y', $_POST['date_from']);
		if(!$date) {
			$error = true;
		} else {
			$time_from = strtotime($date->format("d-m-Y"));
		}
	} else {
		$time_from = '';
	}

	if (!$error) {
		if (UPGRADE::upgrade_databases($time_from) == true) {
			if (function_exists('apc_clear_cache')) {
				apc_clear_cache('user'); 
			}
			$success = true;	
		} else {
			$error = true;	
		}
	}
}

//only show install setup, if it, of course, isn't installed already.
	info_top();
		
	if(version_compare(PROSPER202::prosper202_version(),$version) > 0){
	    $task_202="Downgrade";
	    $task_202_2="Downgrading";
	}
	else{
	    $task_202="Upgrade";
	    $task_202_2="Upgrading";
	}

	 ?>	
		<div class="main col-xs-7 install">
	<center><img src="<?php echo get_absolute_url();?>202-img/prosper202.png"></center>
	<?php if ($error == true) { ?>
	
		<h2 style="color: #900;">An error occured</h2>
		<span style="color: #900;">An unexpected error occured while you were trying to <?php echo strtolower($task_202); ?>, please try again or if you keep encountering problems review our <a href="http://support.tracking202.com">support docs</a>.</span>
		<br/><br/>

	<?php } else if ($success == true) { unset($_SESSION['user_id']); 
        //('location: '.get_absolute_url().'202-account/signout.php');?>
		<h6>Success!</h6>
		<small>Prosper202 <?php echo strtolower($task_202); ?> Completed! Now you can <a href="<?php echo get_absolute_url();?>202-account/signout.php">log in</a>.</small>

	<?php } else { ?>

	<h6><?php echo $task_202; ?> to Prosper202 <?php echo $version; ?></h6>
	<small>You are <?php echo strtolower($task_202_2); ?> from version <span class="label label-primary"><?php echo PROSPER202::prosper202_version(); ?></span> to <span class="label label-primary"><?php echo $version; ?></span>. To continue with the <?php echo strtolower($task_202); ?> press the button below to begin the process. This could take a while depending on the last time you updated your software.</small>
	<div class="row">
		<div class="col-xs-12">
		<br/>
		<small>Changelogs:</small>
			<div class="panel-group" id="changelog_accordion" style="margin-top:10px;">
			  <?php $change_logs = changelog();
			  
			  if(isset($change_logs) && $change_logs != ''){
			  foreach ($change_logs as $logs) {
			  	if (version_compare(PROSPER202::prosper202_version(),$logs['version'],'<')) {?>
			  		<div class="panel panel-default">
	                    <div class="panel-heading">
	                    <a data-toggle="collapse" data-parent="#changelog_accordion" href="#release_<?php echo str_replace('.', '', $logs['version']);?>">
	                      <h4 class="panel-title">
	                          v<?php echo $logs['version'];?>
	                      </h4>
	                    </a>  
	                    </div>
	                    <div id="release_<?php echo str_replace('.', '', $logs['version']);?>" class="panel-collapse in">
	                      <div class="panel-body">
	                      	<ul id="list">
	                      <?php foreach ($logs['logs'] as $log) { ?>
	                          <li>
	                            <?php echo $log;?>
	                          </li>
	                      <?php } ?>
	                      	</ul>
	                      </div>
	                    </div>
	                </div>
			  	<?php }
			  }
			}?>
			</div>
		</div>
		</div>
	<br></br>
		<form method="post" id="upgrade-form" action="">
			<?php if(version_compare(PROSPER202::prosper202_version(), '1.9.3', '<')) { 
			$first_click_sql="select DATE_FORMAT(FROM_UNIXTIME(min(click_time)),'%d-%m-%Y') as first_click_time from 202_clicks";
			$first_click_row = memcache_mysql_fetch_assoc($first_click_sql);
			
			?>
				<div class="form-group">
				    <label for="date_from">Choose a date from which to process clicks for new Data Engine:</label>
				    <input type="text" class="form-control input-sm" id="date_from" name="date_from" placeholder="dd-mm-yyyy" value="<?php echo $first_click_row['first_click_time']; ?>">
				</div>
				<br></br>
			<?php } ?>
			<?php if(version_compare(PROSPER202::prosper202_version(), '1.9.55', '<')) {?>
				<div class="form-group">
					Google Chrome 80+ requires all landing pages to be HTTPS, or your tracking won't work. Can Prosper202 automatically upgrade your old landing page URLs to HTTPS?<br/>
					<br></br>
					<div class="form-group" >
						<label for="lp_ssl"  class="radio-inline" style="line-height:1.3">
							<input type="radio" name="lp_ssl"  id="lp_ssl_yes" value="1" Checked> Yes
						</label>
					</div>
					<div class="form-group">						
						<label for="lp_ssl_no" class="radio-inline" style="line-height:1.3">
							<input type="radio" name="lp_ssl" id="lp_ssl_no" value="0"> No
						</label>
					</div>
				</div>
				<br></br>
			<?php } ?>	

			<button class="btn btn-lg btn-p202 btn-block" id="upgrade-submit" type="submit"><?php echo $task_202; ?> Prosper202<span class="fui-check-inverted pull-right"></span></button>
		</form>
	</div>

	<script type="text/javascript">
		$(document).ready(function() {
			$("#date_from").datepicker({dateFormat: 'dd-mm-yy'});
			$("#upgrade-form").submit(function(event) {
			  $("#upgrade-submit").attr('disabled','disabled');
			});
		});
	</script>
	<?php } info_bottom(); 
