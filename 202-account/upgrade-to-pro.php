<?php
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user();

	$rss = fetch_rss('http://my.tracking202.com/clickserver/currentversion/pro/');
	if ( isset($rss->items) && 0 != count($rss->items) ) {
			 
		$rss->items = array_slice($rss->items, 0, 1) ;
		foreach ($rss->items as $item ) {
			$latest_version = $item['title'];
		}
	}

	$upgrade_done = false;
	$logs = false;

	if (isset($_POST['username'])) {
		
		$ch = curl_init();
		// Set the url
		curl_setopt($ch, CURLOPT_URL, 'http://my.tracking202.com/api/v2/getpro/'.base64_encode($_POST['username']));
		// Disable SSL verification
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		// Will return the response, if false it print the response
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		// Execute
		$result = curl_exec($ch);
		//close connection
		curl_close($ch);

		$response = json_decode($result, true);

		if ($response['success'] == true) {
			$GetUpdate = @file_get_contents($response['data']['url']);

			$log = "Downloading new update...\n";

			if ($GetUpdate) {
				
				if (temp_exists()) {
					$log .= "Created /202-config/temp/ directory.\n";
					$downloadUpdate = @file_put_contents($_SERVER['DOCUMENT_ROOT']. '/202-config/temp/prosper202_pro.zip', $GetUpdate);
					if ($downloadUpdate) {
						$log .= "Update downloaded and saved!\n";

						$zip = @zip_open($_SERVER['DOCUMENT_ROOT']. '/202-config/temp/prosper202_pro.zip');

							if ($zip)
							{	
								$log .= "\nUpdate process started...\n";
								$log .= "\n-------------------------------------------------------------------------------------\n";

							    while ($zip_entry = @zip_read($zip))
							    {
							    	$thisFileName = zip_entry_name($zip_entry);

							    	if (substr($thisFileName,-1,1) == '/') {
							    		if (is_dir($_SERVER['DOCUMENT_ROOT']. '/'.$thisFileName)) {
							    			$log .= "Directory: /" . $thisFileName . "......updated\n";
							    		} else {
								    		if(@mkdir($_SERVER['DOCUMENT_ROOT']. '/'.$thisFileName, 0755, true)) {
								    			$log .= "Directory: /" . $thisFileName . "......created\n";
								    		} else {
								    			$log .= "Can't create /" . $thisFileName . " directory! Operation aborted";
								    		}
								    	}
							    		
							    	} else {
							    		$contents = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
							    		$file_ext = array_pop(explode(".", $thisFileName));

							    		if (file_exists($_SERVER['DOCUMENT_ROOT'].'/'.$thisFileName)) {
							    			$status = "updated";
							    		} else {
							    			$status = "created";
							    		}

								    	if($updateThis = @fopen($_SERVER['DOCUMENT_ROOT'].'/'.$thisFileName, 'wb')) {
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
							zip_close($zip);
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
				$log .= "Can't download new update from link! \nOperation aborted.";
				$FilesUpdated = false;
			}

			if ($FilesUpdated == true) {

				header("Location: /202-config/upgrade.php");

				/*
				include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions-upgrade.php');

				$log .= "-------------------------------------------------------------------------------------\n";
				$log .= "\nUpgrading database...\n";

				if (UPGRADE::upgrade_databases() == true) {
					$log .= "Upgrade done!\n";
					$version = $latest_version;
					$upgrade_done = true;	
				} else {
					$log .= "Database upgrade failed! Please try again!\n";
					$upgrade_done = false;	
				}
				*/
			}

		} else {
			$log = 'Invalid username!';
		}

		$logs = true;
	}

template_top('Upgrade To Pro',NULL,NULL,NULL); 
?>
 
<div class="row account">
		<div class="col-xs-12">
			<h6>1-Click Prosper202 Upgrade To Pro</h6>
			<small>You can auto upgrade your installation or do it manualy, by downloading the latest version at <a href="http://prosper.tracking202.com/apps/download/?s=dl-manual#notice" target="_blank">Prosper202.com</a>. Version details are below.</small>
				<br><br/>
				<div class="row" style="margin-bottom: 10px;">
				  <div class="col-xs-3"><span class="label label-default">Current version:</span></div>
				  <div class="col-xs-9"><span class="label label-primary"><?php echo $version; ?></span></div>
				</div>
				<div class="row">
				  <div class="col-xs-3"><span class="label label-default">Latest Version (PRO):</span></div>
				  <div class="col-xs-9"><span class="label label-primary"><?php echo $latest_version; ?></span></div>
				</div>
			
			<?php if (!$upgrade_done && !$logs) { ?>
			<br/>	
			<small>Enter username you received, after purchasing Pro version, to start 1-click upgrade to Pro!</small>
			<br><br/>
			<div class="row">
				<div class="col-xs-6">
					<form class="form-inline" role="form" method="post" action="">
						<div class="form-group" style="margin:0px;">
							<label for="username">Enter username:</label>
							<input type="text" class="form-control input-sm" id="username" name="username" placeholder="Enter username">
						</div>
						<button type="submit" class="btn btn-p202 btn-sm">Start upgrade!</button>
					</form>
				</div>
			</div>
			<?php } ?>
			<?php if ($logs) { ?>
				<br><br/>
				<textarea rows="8" class="form-control install_logs"><?php echo $log;?></textarea>
				<br/>
				<a href="/202-config/upgrade.php" class="btn btn-p202 btn-sm">Upgrade Database Now!</a>
			<?php } ?>

			<?php if ($upgrade_done) { ?>
				<h6>Success!</h6>
				<small>Prosper202 has been upgraded!</small>
			<?php } ?>
			
		</div>
</div>
<?php template_bottom();
