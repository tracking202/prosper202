<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user(); 

if ($_SESSION['update_needed'] == true) { ?>
	<div class="alert" style="padding: 0px 33px 0px 20px;">
	    <button type="button" class="close fui-cross" data-dismiss="alert"></button>
  		<small>A new version of Prosper202 is available!</small>
  		<a style="margin-left:10px; margin-right:5px;" href="/202-account/auto-upgrade.php" class="btn btn-xs btn-warning">1-Click Upgrade</a>
  		<small>or</small>
	    <a style="margin-left:5px" href="http://prosper.tracking202.com/apps/download/?rd=cs202-NA==" class="btn btn-xs btn-warning">Manual upgrade</a>
	    <small><a href="#changelogs" id="see_changelogs" data-toggle="modal" data-target="#changelogs" style="color:#428bca; font-weight:normal">see changelogs</a></small>
	</div>

	<div id="changelogs" class="modal fade" role="dialog" aria-hidden="true">
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
<?php } ?>