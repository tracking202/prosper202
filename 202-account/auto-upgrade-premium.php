<?php
include_once(str_repeat("../", 1).'202-config/connect.php');
include_once(str_repeat("../", 1).'202-config/functions-upgrade.php');
include_once(str_repeat("../", 1).'202-config/functions-rss.php');
include_once(str_repeat("../", 1).'202-config/class-dataengine.php');

AUTH::require_user();

ini_set('memory_limit', '-1');
$mysql['user_own_id'] = $db->real_escape_string($_SESSION['user_own_id']);
$user_sql = "SELECT install_hash, p202_customer_api_key FROM 202_users WHERE user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

$missing_api_key = true;
if (!empty($user_row['p202_customer_api_key'])) {
	$missing_api_key = false;
}else{
	$missing_api_key = true;
}

$json = @getData('https://my.tracking202.com/api/v2/premium-p202/version');
$array = json_decode($json, true);
$latest_version = $array['version'];
if (version_compare($version, $latest_version) == '-1') {
	$update_needed = true;
} else {
	$update_needed = false;
}

if ($_POST['start_upgrade'] == '1') {

		$GetUpdate = @getData('https://my.tracking202.com/api/v2/premium-p202/download/'.$user_row['install_hash'].'/'.$user_row['p202_customer_api_key']);
		$installlog = "Downloading new update...\n";
		$checkError = json_decode($GetUpdate, true);
		if (json_last_error() == JSON_ERROR_NONE) {
			$installlog .= $checkError['msg']."...\n";
			$FilesUpdated = false;
			$GetUpdate = false;
		}

		if ($GetUpdate) {
			
			if (temp_exists()) {
				$installlog .= "Created /202-config/temp/ directory.\n";
				$downloadUpdate = @file_put_contents(substr(dirname( __FILE__ ), 0,-12). '/202-config/temp/prosper202_'.$latest_version.'.zip', $GetUpdate);
				if ($downloadUpdate) {
					$installlog .= "Update downloaded and saved!\n";

					$zip = @zip_open(substr(dirname( __FILE__ ), 0,-12). '/202-config/temp/prosper202_'.$latest_version.'.zip');

						if ($zip)
						{	
							$installlog .= "\nUpdate process started...\n";
							$installlog .= "\n-------------------------------------------------------------------------------------\n";

						    while ($zip_entry = @zip_read($zip))
						    {
						    	$thisFileName = zip_entry_name($zip_entry);

						    	if (substr($thisFileName,-1,1) == '/') {
						    		if (is_dir(substr(dirname( __FILE__ ), 0,-12). '/'.$thisFileName)) {
						    			$installlog .= "Directory: /" . $thisFileName . "......updated\n";
						    		} else {
							    		if(@mkdir(substr(dirname( __FILE__ ), 0,-12). '/'.$thisFileName, 0755, true)) {
							    			$installlog .= "Directory: /" . $thisFileName . "......created\n";
							    		} else {
							    			$installlog .= "Can't create /" . $thisFileName . " directory! Operation aborted";
							    		}
							    	}
						    		
						    	} else {
						    		$contents = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
						    		$file_ext = array_pop(explode(".", $thisFileName));

						    		if (file_exists(substr(dirname( __FILE__ ), 0,-12).'/'.$thisFileName)) {
						    			$status = "updated";
						    		} else {
						    			$status = "created";
						    		}

							    	if($updateThis = @fopen(substr(dirname( __FILE__ ), 0,-12).'/'.$thisFileName, 'wb')) {
							    		fwrite($updateThis, $contents);
		                            	fclose($updateThis);
		                            	unset($contents);	                      

							    		$installlog .= "File: " . $thisFileName . "......".$status."\n";
							    	} else {
							    		$installlog .= "Can't update file:" . $thisFileName . "! Operation aborted";
							    	}
						    		
						    	}
						    $FilesUpdated = true;
						    }
						@zip_close($zip);
						}

				} else {
					$installlog .= "Can't save new update! Operation aborted. Make sure PHP has write permissions!";
					$FilesUpdated = false;
				}

			} else {
				$installlog .= "Can't create /202-config/temp/ directory! Operation aborted.";
				$FilesUpdated = false;
			}

		} else {
			$installlog .= "Can't download new update : \nOperation aborted.";
			$FilesUpdated = false;
		}

		if ($FilesUpdated == true) {
			if (function_exists('apc_clear_cache')) {
				apc_clear_cache('user'); 
			}
			include_once(str_repeat("../", 1).'202-config/functions-upgrade.php');

			$installlog .= "-------------------------------------------------------------------------------------\n";
			$installlog .= "\nUpgrading database...\n";

			if (UPGRADE::upgrade_databases($time_from) == true) {
				$installlog .= "Upgrade done!\n";
				$version = $latest_version;
				$upgrade_done = true;
			} else {
				$installlog .= "Database upgrade failed! Please try again!\n";
				$upgrade_done = false;	
			}
		}
}

if ($missing_api_key == true) { info_top(); ?>
	<div class="main col-xs-7 install">
		<center><img src="<?php echo get_absolute_url();?>202-img/prosper202.png"></center>
		<h6>1-Click Prosper202 Upgrade</h6>
		<h4><?php echo $_SESSION['premium_p202_details']['headline'];?></h4>
		<small><?php echo $_SESSION['premium_p202_details']['body'];?></small><br></br>
		<small>Release date: <?php echo $_SESSION['premium_p202_details']['release-date'];?></small>
		<small><p style="color:red">Your Prosper202 Customer API key is missing.</p></small>
		<small><p>Sign up at Prosper202 Customer Dashboard and fill out your billing information!</p></small>
  		<small><p>Save P202 Customer API key at Personal Settings!</p></small>
  		<a style="margin-right:5px;" href="<?php echo $_SESSION['premium_p202_details']['register-link'];?>" target="_blank" class="btn btn-sm btn-p202"><?php echo $_SESSION['premium_p202_details']['register-button-text'];?></a>
  		<br><br/>
		<div class="row" style="margin-bottom: 10px;">
		  <div class="col-xs-3"><span class="label label-default">Current version:</span></div>
		  <div class="col-xs-9"><span class="label label-primary"><?php echo $version; ?></span></div>
		</div>
		<div class="row">
		  <div class="col-xs-3"><span class="label label-default">Latest Version:</span></div>
		  <div class="col-xs-9"><span class="label label-primary"><?php echo $latest_version; ?></span></div>
		</div>

		<div class="row">
		<div class="col-xs-12">
		<br/>
		<small>Changelogs:</small>
			<div class="panel-group" id="changelog_accordion" style="margin-top:10px;">
			  <?php $change_logs = changelogPremium();
			  foreach ($change_logs as $key => $logs) { ?>
			  		<div class="panel panel-default">
	                    <div class="panel-heading" style="padding: 5px 5px;">
	                    <a data-toggle="collapse" data-parent="#changelog_accordion" href="#release_<?php echo str_replace('.', '', $key);?>">
	                      <h4 class="panel-title" style="font-size: 14px;">
	                          v<?php echo $key;?>
	                      </h4>
	                    </a>  
	                    </div>
	                    <div id="release_<?php echo str_replace('.', '', $key);?>" class="panel-collapse collapse">
	                      <div class="panel-body">
	                      	<ul id="list">
	                      <?php foreach ($logs as $log) { ?>
	                          <li>
	                            <?php echo $log;?>
	                          </li>
	                      <?php } ?>
	                      	</ul>
	                      </div>
	                    </div>
	                </div>
			 <?php } ?>
			</div>
		</div>
		</div>
	</div>
<?php } else if ($update_needed == true) { info_top();

	if (!function_exists('zip_open')) {
	    _die("<h6>PHP Zip module missing</h6>
			<small>In order to use 1-Click upgrade functions you must compile PHP with zip support by using the --enable-zip configure option. <a href=\"http://www.php.net/manual/en/book.zip.php\" target=\"_blank\">More info you can find here.</a></small>" );
	} ?>

<div class="main col-xs-7 install">
	<center><img src="<?php echo get_absolute_url();?>202-img/prosper202.png"></center>
	<h6>1-Click Prosper202 Upgrade</h6>
	<h4><?php echo $_SESSION['premium_p202_details']['headline'];?></h4>
	<small><?php echo $_SESSION['premium_p202_details']['body'];?></small><br></br>
	<small>Release date: <?php echo $_SESSION['premium_p202_details']['release-date'];?></small>
		<br><br/>
		<div class="row" style="margin-bottom: 10px;">
		  <div class="col-xs-3"><span class="label label-default">Current version:</span></div>
		  <div class="col-xs-9"><span class="label label-primary"><?php echo $version; ?></span></div>
		</div>
		<div class="row">
		  <div class="col-xs-3"><span class="label label-default">Latest Version:</span></div>
		  <div class="col-xs-9"><span class="label label-primary"><?php echo $latest_version; ?></span></div>
		</div>

		<div class="row">
		<div class="col-xs-12">
		<br/>
		<small>Changelogs:</small>
			<div class="panel-group" id="changelog_accordion" style="margin-top:10px;">
			  <?php $change_logs = changelogPremium();
			  foreach ($change_logs as $key => $logs) { ?>
			  		<div class="panel panel-default">
	                    <div class="panel-heading" style="padding: 5px 5px;">
	                    <a data-toggle="collapse" data-parent="#changelog_accordion" href="#release_<?php echo str_replace('.', '', $key);?>">
	                      <h4 class="panel-title" style="font-size: 14px;">
	                          v<?php echo $key;?>
	                      </h4>
	                    </a>   
	                    </div>
	                    <div id="release_<?php echo str_replace('.', '', $key);?>" class="panel-collapse collapse">
	                      <div class="panel-body">
	                      	<ul id="list">
	                      <?php foreach ($logs as $log) { ?>
	                          <li>
	                            <?php echo $log;?>
	                          </li>
	                      <?php } ?>
	                      	</ul>
	                      </div>
	                    </div>
	                </div>
			 <?php } ?>
			</div>
		</div>
		</div>

	<?php if ($_POST['start_upgrade'] == '1') { ?>
		<br>
		<textarea rows="8" class="form-control install_logs"><?php echo $installlog;?></textarea>
	<?php } 

if($upgrade_done !== true) { ?>
	<br>
		<form method="post" action="" class="form-inline">
			<input type="hidden" name="start_upgrade" value="1"/>
			<button class="btn btn-lg btn-p202 btn-block" type="submit"><?php echo $_SESSION['premium_p202_details']['order-button-text'];?></span></button>
		</form>
	<br>
	<span class="infotext"><i>We highly recommended you make a backup of your database, before upgrading.<br>
	Also make sure PHP has write permissions.</i></span>
<?php } else  
if($FilesUpdated==true){
?>
	<h6>Success!</h6>
	<small>Prosper202 has been upgraded! You can now <a href="<?php echo get_absolute_url();?>202-account/signout.php">Log In</a>.</small>
<?php 
}
else{
    ?>
    	<h6>Upgrade Failed!</h6>
    	<small>Prosper202 was unable to upgrade. Please make sure PHP hase the correct permissions to modifiy files. For help <a href="http://support.tracking202.com">check our support site</a>. You can continue using <a href="<?php echo get_absolute_url();?>202-account/signout.php">your old version</a>.</small>
    <?php     
    
}
 ?>
</div>
<?php  } 
 if ($update_needed != true) {
	_die("<h6>Already Upgraded</h6>
			<small>Your Prosper202 version $version is already upgraded.</small> <a href='./'> Log In Again</a>");
}

info_bottom();?>


