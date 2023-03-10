<?php 
include_once(str_repeat("../", 2).'202-config/connect.php'); 

AUTH::require_user(); 

if ($_SESSION['auto_upgraded_not_possible'] == true) { ?>
	<div class="panel-group alertaccordion" id="accordion" role="tablist" aria-multiselectable="true">
	  <div class="panel panel-default">
	    <div class="panel-heading" role="tab" id="headingOne">
	      <h4 class="panel-title">
	        <a role="button" data-toggle="collapse" data-parent="#accordion" href="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
	          A New Version of Prosper202 Is Available!
	        </a>
	        <a role="button" data-toggle="collapse" data-parent="#accordion" href="#collapseOne" aria-expanded="true" aria-controls="collapseOne" style="float:right">&times;</a>
	      </h4>
	    </div>
	    <div id="collapseOne" class="panel-collapse collapse <?php if ($_SESSION['show_update_check'] == true) echo "in"; ?>" role="tabpanel" aria-labelledby="headingOne">
	      <div class="panel-body">
	        <small><p>Your /202-config/ directory is not writable or PHP zip extension is disabled.</p></small>
	  		<small><p>Resolve this issue to use 1-Click auto upgrade function!</p></small>
	  		<small>or</small>
		    <a style="margin-left:5px" href="https://my.tracking202.com/" class="btn btn-xs btn-warning">Download Manual Upgrade Files</a>
		    <small><a href="javascript:$('#changelogs').modal('show')" id="see_changelogs"style="color:#428bca; font-weight:normal">See what's new</a></small>
	      </div>
	    </div>
	  </div>
	</div>
<?php } else if($_SESSION['update_needed'] == true) { ?>	
	<div class="panel-group alertaccordion" id="accordion" role="tablist" aria-multiselectable="true">
	  <div class="panel panel-default">
	    <div class="panel-heading" role="tab" id="headingOne">
	      <h4 class="panel-title">
	        <a role="button" data-toggle="collapse" data-parent="#accordion" href="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
	          A New Version of Prosper202 Is Available!
	        </a>
	        <a role="button" data-toggle="collapse" data-parent="#accordion" href="#collapseOne" aria-expanded="true" aria-controls="collapseOne" style="float:right">&times;</a>
	      </h4>
	    </div>
	    <div id="collapseOne" class="panel-collapse collapse <?php if ($_SESSION['show_update_check'] == true) echo "in"; ?>" role="tabpanel" aria-labelledby="headingOne">
	      <div class="panel-body">
	        <a style="margin-left:10px; margin-right:5px;" href="<?php echo get_absolute_url();?>202-account/auto-upgrade-premium.php" class="btn btn-xs btn-success">1-Click Upgrade</a>
	  		<small>or</small>
		    <a style="margin-left:5px" href="https://my.tracking202.com/" class="btn btn-xs btn-warning">Download Manual Upgrade Files</a>
		    <small><a href="javascript:$('#changelogs').modal('show')" id="see_changelogs"style="color:#428bca; font-weight:normal">See what's new</a></small>
	      </div>
	    </div>
	  </div>
	</div>

	<div id="changelogs" class="modal fade" role="dialog">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
              <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
              <h4 class="modal-title">Version changelogs</h4>
            </div>
            <div class="modal-body">
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
            <div class="modal-footer">
              <button class="btn btn-wide btn-default" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php } else if ($_SESSION['premium_update_available'] == true){ 
	$mysql['user_own_id'] = $db->real_escape_string($_SESSION['user_own_id']);
	$user_sql = "SELECT p202_customer_api_key FROM 202_users WHERE user_id = '".$mysql['user_own_id']."'";
	$user_results = $db->query($user_sql);
	$user_row = $user_results->fetch_assoc();
	if (empty($user_row['p202_customer_api_key'])) { ?>
		<div class="panel-group alertaccordion" id="accordion" role="tablist" aria-multiselectable="true">
		  <div class="panel panel-default">
		    <div class="panel-heading" role="tab" id="headingOne">
		      <h4 class="panel-title">
		        <a role="button" data-toggle="collapse" data-parent="#accordion" href="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
		          <?php echo $_SESSION['premium_p202_details']['headline'];?>
		        </a>
		        <a role="button" data-toggle="collapse" data-parent="#accordion" href="#collapseOne" aria-expanded="true" aria-controls="collapseOne" style="float:right">&times;</a>
		      </h4>
		    </div>
		    <div id="collapseOne" class="panel-collapse collapse <?php if ($_SESSION['show_update_check'] == true) echo "in"; ?>" role="tabpanel" aria-labelledby="headingOne">
		      <div class="panel-body" id="noKeyBody">
		        <small><p><?php echo $_SESSION['premium_p202_details']['body'];?></p></small>
		  		<small><p>Release date: <?php echo $_SESSION['premium_p202_details']['release-date'];?> - <a href="#changelogs" id="see_changelogs" data-toggle="modal" data-target="#changelogsPremium" style="color:#428bca; font-weight:normal; margin-top: 15px;">See what's new</a></p></small>
		  		<small><p style="color:red">Your Prosper202 Customer API key is missing.</p></small>
		  		<small>
		  			<ul>
		  				<li><p>1#: Sign up at Prosper202 Customer Dashboard and fill out your billing information!</br><a href="<?php echo $_SESSION['premium_p202_details']['register-link'];?>" target="_blank" class="btn btn-xs btn-p202"><?php echo $_SESSION['premium_p202_details']['register-button-text'];?></a></p></li>
		  				<li><p>2#: Submit your P202 Customer API key!</br>
		  				<form class="form-inline" id="upgradeAlertApiKey" role="form" method="post" action="">
		  					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
		  					<div class="form-group">
							    <label class="sr-only" for="api_key">API Key</label>
							    <input type="text" class="form-control input-sm" id="api_key" name="api_key" placeholder="API Key" style="height: 26px; width: 220px;">
							</div>
							<button type="submit" class="btn btn-p202 btn-xs" id="submitApiKey" data-loading-text="Validating...">Submit API key</button>
		  				</form>
		  				</p></li>
		  			</ul>
		  		</small>
	  			<small><p>You can access your API key on "Personal Settings"</p></small>
		      </div>
		    </div>
		  </div>
		</div>
	<?php } else { ?>
		<div class="panel-group alertaccordion" id="accordion" role="tablist" aria-multiselectable="true">
		  <div class="panel panel-default">
		    <div class="panel-heading" role="tab" id="headingOne">
		      <h4 class="panel-title">
		        <a role="button" data-toggle="collapse" data-parent="#accordion" href="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
		          <?php echo $_SESSION['premium_p202_details']['headline'];?>
		        </a>
		        <a role="button" data-toggle="collapse" data-parent="#accordion" href="#collapseOne" aria-expanded="true" aria-controls="collapseOne" style="float:right">&times;</a>
		      </h4>
		    </div>
		    <div id="collapseOne" class="panel-collapse collapse <?php if ($_SESSION['show_update_check'] == true) echo "in"; ?>" role="tabpanel" aria-labelledby="headingOne">
		      <div class="panel-body">
		        <small><p><?php echo $_SESSION['premium_p202_details']['body'];?></p></small>
		  		<small><p>Release date: <?php echo $_SESSION['premium_p202_details']['release-date'];?> - <a href="#changelogs" id="see_changelogs" data-toggle="modal" data-target="#changelogsPremium" style="color:#428bca; font-weight:normal; margin-top: 15px;">See what's new</a></p></small>
		  		<a style="margin-right:5px;" href="<?php echo get_absolute_url();?>202-account/auto-upgrade-premium.php" class="btn btn-xs btn-warning"><?php echo $_SESSION['premium_p202_details']['order-button-text'];?> ($<?php echo $_SESSION['premium_p202_details']['upgrade-price'];?>)</a>
		      </div>
		    </div>
		  </div>
		</div>
	<?php } ?>
	<div id="changelogsPremium" class="modal fade" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
              <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
              <h4 class="modal-title">Version changelogs</h4>
            </div>
            <div class="modal-body">
            	<div class="panel-group" id="changelog_accordion" style="margin-top:10px;">
				  <?php $change_logs = changelogPremium();
				  foreach ($change_logs as $key => $logs) { ?>
				  		<div class="panel panel-default">
		                    <div class="panel-heading">
		                    <a data-toggle="collapse" data-parent="#changelog_accordion" href="#release_<?php echo str_replace('.', '', $key);?>">
		                      <h4 class="panel-title">
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
            <div class="modal-footer">
              <button class="btn btn-wide btn-default" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
    </div>
<?php } ?>
<script type="text/javascript">
	$('.alertaccordion').on('hidden.bs.collapse', function () {
		$.post("<?php echo get_absolute_url();?>202-account/ajax/delay-alert.php", { delay: true });
	});
</script>