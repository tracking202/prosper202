<?php

//include mysql settings
include_once(dirname( __FILE__ ) . '/connect.php');
include_once(dirname( __FILE__ ) . '/class-dataengine.php');
include_once(dirname( __FILE__ ) . '/functions-upgrade.php');

//check to see if this is already installed, if so don't do anything
	if (  upgrade_needed() == false) {
		
		_die("<h6>Already Upgraded</h6>
			   <small>Your Prosper202 version $version is already upgraded. <a href='".get_absolute_url()."202-login.php'>log in</a></small>" ); 	
	 
	}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	if (version_compare(PROSPER202::mysql_version(), '1.9.3', '<')) {

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
			$success = true;	
		} else {
			$error = true;	
		}
	}
}

//only show install setup, if it, of course, isn't installed already.

	info_top(); ?>	
	<div class="main col-xs-7">
	<center><img src="<?php echo get_absolute_url();?>202-img/prosper202.png"></center>
	<?php if ($error == true) { ?>
	
		<h2 style="color: #900;">An error occured</h2>
		<span style="color: #900;">An unexpected error occured while you were trying to upgrade, please try again or if you keep encountering problems please contact <a href="http://getsatisfaction.com/tracking202">our support forum</a>.</span>
		<br/><br/>

	<?php } else if ($success == true) { unset($_SESSION['user_id']); ?>

		<h6>Success!</h6>
		<small>Prosper202 has been upgraded! Now you can <a href="<?php echo get_absolute_url();?>202-account/signout.php">log in</a>.</small>

	<?php } else { ?>

	<h6>Upgrade to Prosper202 <?php echo $version; ?></h6>
	<small>You are upgrading from version <span class="label label-primary"><?php echo PROSPER202::mysql_version(); ?></span> to <span class="label label-primary"><?php echo $version; ?></span>. To continue with the upgrade press the button below to begin the update process. This could take a while depending on the last time you updated your software.</small>
	<div class="row">
		<div class="col-xs-12">
		<br/>
		<small>Changelogs:</small>
			<div class="panel-group" id="changelog_accordion" style="margin-top:10px;">
			  <?php $change_logs = changelog();
			  
			  foreach ($change_logs as $logs) {
			  	if (version_compare(PROSPER202::mysql_version(),$logs['version'],'<')) {?>
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
			  }?>
			</div>
		</div>
		</div>
	<br></br>
		<form method="post" id="upgrade-form" class="form-inline" action="">
			<?php if(version_compare(PROSPER202::mysql_version(), '1.9.3', '<')) { 
			$first_click_sql="select DATE_FORMAT(FROM_UNIXTIME(min(click_time)),'%d-%m-%Y') as first_click_time from 202_clicks";
			$first_click_row = memcache_mysql_fetch_assoc($first_click_sql);
			
			?>
				<div class="form-group">
				    <label for="date_from">Choose a date from which to process clicks for new Data Engine:</label>
				    <input type="text" class="form-control input-sm" id="date_from" name="date_from" placeholder="dd-mm-yyyy" value="<?php echo $first_click_row['first_click_time']; ?>">
				</div>
				<br></br>
			<?php } ?>	
			<button class="btn btn-lg btn-p202 btn-block" id="upgrade-submit" type="submit">Upgrade Prosper202<span class="fui-check-inverted pull-right"></span></button>
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
