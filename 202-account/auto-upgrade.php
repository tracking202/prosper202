<?php
ini_set('memory_limit', '-1');
include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/connect.php');
include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/functions-upgrade.php');
include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/functions-rss.php');
include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/class-dataengine.php');

AUTH::require_user();

	$rss = fetch_rss('http://my.tracking202.com/clickserver/currentversion/pro/');
	if ( isset($rss->items) && 0 != count($rss->items) ) {
			 
		$rss->items = array_slice($rss->items, 0, 1) ;
		foreach ($rss->items as $item ) {
			$latest_version = $item['title'];
			$download_link = $item['link'];
			//if current version, is older than the latest version, return true for an update is now needed.
			if (version_compare($version, $latest_version) == '-1') {
				$update_needed = true;
			} else {
				$update_needed = false;
			}

		}
	}


if ($_POST['start_upgrade'] == '1') {

	if (version_compare(PROSPER202::mysql_version(), '1.9.3', '<')) {

		$date = DateTime::createFromFormat('d-m-Y', $_POST['date_from']);
		if(!$date) {
			$log .= "Select date for Data Engine!\n";
			$error = true;
		} else {
			$time_from = strtotime($date->format("d-m-Y"));
		}
	} else {
		$time_from = '';
	}
	
	if (!$error) {
		
		$GetUpdate = @file_get_contents($download_link);
		$log = "Downloading new update...\n";

		if ($GetUpdate) {
			
			if (temp_exists()) {
				$log .= "Created /202-config/temp/ directory.\n";
				$downloadUpdate = @file_put_contents(substr(dirname( __FILE__ ), 0,-12). '/202-config/temp/prosper202_'.$latest_version.'.zip', $GetUpdate);
				if ($downloadUpdate) {
					$log .= "Update downloaded and saved!\n";

					$zip = @zip_open(substr(dirname( __FILE__ ), 0,-12). '/202-config/temp/prosper202_'.$latest_version.'.zip');

						if ($zip)
						{	
							$log .= "\nUpdate process started...\n";
							$log .= "\n-------------------------------------------------------------------------------------\n";

						    while ($zip_entry = @zip_read($zip))
						    {
						    	$thisFileName = zip_entry_name($zip_entry);

						    	if (substr($thisFileName,-1,1) == '/') {
						    		if (is_dir(substr(dirname( __FILE__ ), 0,-12). '/'.$thisFileName)) {
						    			$log .= "Directory: /" . $thisFileName . "......updated\n";
						    		} else {
							    		if(@mkdir(substr(dirname( __FILE__ ), 0,-12). '/'.$thisFileName, 0755, true)) {
							    			$log .= "Directory: /" . $thisFileName . "......created\n";
							    		} else {
							    			$log .= "Can't create /" . $thisFileName . " directory! Operation aborted";
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

							    		$log .= "File: " . $thisFileName . "......".$status."\n";
							    	} else {
							    		$log .= "Can't update file:" . $thisFileName . "! Operation aborted";
							    	}
						    		
						    	}
						    $FilesUpdated = true;
						    }
						@zip_close($zip);
						}

				} else {
					$log .= "Can't save new update! Operation aborted. Make sure PHP has write permissions!";
					$FilesUpdated = false;
				}

			} else {
				$log .= "Can't create /202-config/temp/ directory! Operation aborted.";
				$FilesUpdated = false;
			}

		} else {
			$log .= "Can't download new update from link: ".$download_link." \nOperation aborted.";
			$FilesUpdated = false;
		}

		if ($FilesUpdated == true) {

			include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/functions-upgrade.php');

			$log .= "-------------------------------------------------------------------------------------\n";
			$log .= "\nUpgrading database...\n";

			if (UPGRADE::upgrade_databases($time_from) == true) {
				$log .= "Upgrade done!\n";
				$version = $latest_version;
				$upgrade_done = true;	
			} else {
				$log .= "Database upgrade failed! Please try again!\n";
				$upgrade_done = false;	
			}
		}
	}
}

if ($update_needed == true) { info_top();

	if (!function_exists('zip_open')) {
	    _die("<h6>PHP Zip module missing</h6>
			<small>In order to use 1-Click upgrade functions you must compile PHP with zip support by using the --enable-zip configure option. <a href=\"http://www.php.net/manual/en/book.zip.php\" target=\"_blank\">More info you can find here.</a></small>" );
	} ?>

<div class="main col-xs-7 install">
	<center><img src="<?php echo get_absolute_url();?>202-img/prosper202.png"></center>
	<h6>1-Click Prosper202 Upgrade</h6>
	<small>A new Prosper202 version is available. You can auto upgrade your installation or do it manually, by downloading the latest version at <a href="http://my.tracking202.com/clickserver/download/latest/pro" target="_blank">Prosper202.com</a>. Version details are below.</small>
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
			  <?php $change_logs = changelog();
			  foreach ($change_logs as $logs) {
			  	if ($logs['version'] >= $version) {?>
			  		<div class="panel panel-default">
	                    <div class="panel-heading">
	                    <a data-toggle="collapse" data-parent="#changelog_accordion" href="#release_<?php echo str_replace('.', '', $logs['version']);?>">
	                      <h4 class="panel-title">
	                          v<?php echo $logs['version'];?>
	                      </h4>
	                    </a>  
	                    </div>
	                    <div id="release_<?php echo str_replace('.', '', $logs['version']);?>" class="panel-collapse collapse">
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
			  }?>
			</div>
		</div>
		</div>

	<?php if ($_POST['start_upgrade'] == '1') { ?>
		<br>
		<textarea rows="8" class="form-control install_logs"><?php echo $log;?></textarea>
	<?php } 

if($upgrade_done != true) { ?>
	<br>
		<form method="post" action="" class="form-inline">
				<input type="hidden" name="start_upgrade" value="1"/>
				<?php if(version_compare(PROSPER202::mysql_version(), '1.9.3', '<')) { ?>
					<div class="form-group">
					    <label for="date_from">Choose a date from which to process clicks for new Data Engine:</label>
					    <input type="text" class="form-control input-sm" id="date_from" name="date_from" placeholder="dd-mm-yyyy">
					</div>
					<br></br>
				<?php } ?>
				<button class="btn btn-lg btn-p202 btn-block" type="submit">Upgrade Prosper202<span class="fui-check-inverted pull-right"></span></button>
		</form>
	<br>
	<span class="infotext"><i>We highly recommended you make a backup of your database, before upgrading.<br>
	Also make sure PHP has write permissions.</i></span>
<?php } else { unset($_SESSION['user_id']); ?>
	<h6>Success!</h6>
	<small>Prosper202 has been upgraded! You can now <a href="<?php echo get_absolute_url();?>202-account/signout.php">log in</a>.</small>

<?php } ?>
</div>
<script type="text/javascript">
	$(document).ready(function() {
		$("#date_from").datepicker({dateFormat: 'dd-mm-yy'});
	});
</script>
<?php info_bottom(); } else {

	_die("<h6>Already Upgraded</h6>
			<small>Your Prosper202 version $version is already upgraded.</small>" );
} ?>


