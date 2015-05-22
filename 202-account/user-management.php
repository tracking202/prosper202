<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//show the template
template_top('User Management',NULL,NULL,NULL); ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>User Management</h6>
	</div>
</div> 


<div class="row upgradeToProContainer">
<div class="upgradeToProOverlay" style="height:391px; width: 981px; margin-top:-15px;">
	<div class="upgradeToProOverlayBackground"></div>
	<a href="http://click202.com/tracking202/redirect/dl.php?t202id=8151295&t202kw=user-management" target="_blank" class="btn btn-lg btn-p202 upgradeToProOverlayButton" style="margin-top: 170px; margin-left:344px;" id="upgradeUserManagment">This is a Prosper202 Pro Feature: Upgrade Now To Access!</a>
</div>
	<div class="col-xs-7">
		<div class="row">
			<div class="col-xs-12" style="margin-top: 15px;">
				<small><strong>Manage all your users</strong></small><br/>
				<span class="infotext">Add and Manage users</span>
				
				<form style="margin:15px 0px;" method="post" class="form-horizontal" role="form">
				  <div class="form-group" style="margin-bottom: 0px;">
				    <label for="ppc_network_id" class="col-xs-4 control-label" style="text-align: left;" placeholder="">First Name:</label>
				    <div class="col-xs-5">
				    <input type="text" class="form-control input-sm" id="user_fname" name="user_fname">
				    </div>
				  </div>
				  <div class="form-group" style="margin-bottom: 0px;">
				    <label for="ppc_network_id" class="col-xs-4 control-label" style="text-align: left;">Last Name:</label>
				    <div class="col-xs-5">
				       <input type="text" class="form-control input-sm" id="user_lname" name="user_lname">
				    </div>
				    
				  </div>
				  <div class="form-group" style="margin-bottom: 0px;">
				    <label for="ppc_account_name" class="col-xs-4 control-label" style="text-align: left;">E-mail:</label>
				    <div class="col-xs-5">
				      <input type="ppc_account_name" class="form-control input-sm" id="user_email" name="user_email">
				    </div>
				    
				  </div>
				  <div class="form-group" style="margin-bottom: 0px;">
				    <label for="ppc_account_name" class="col-xs-4 control-label" style="text-align: left;">Username:</label>
				    <div class="col-xs-5">
				      <input type="text" class="form-control input-sm" id="user_name" name="user_name">
				    </div>
				  </div>

				  <div class="form-group" style="margin-bottom: 0px;">
					 	<label for="user_role" class="col-xs-4 control-label" style="text-align: left;">Role:</label>
					 	<div class="col-xs-5">	
						 	<select class="form-control input-sm" name="user_role">
						 		<option value="2">Admin</option>
						 		<option value="3">Campaign manager</option>
						 		<option value="4">Campaign optimizer</option>
						 		<option value="5">Campaign viewer</option>
						 	</select>
					    </div>    
					</div>

				 <div class="form-group" style="margin-bottom: 0px;">
				 	<label for="user_active" class="col-xs-4 control-label" style="text-align: left;">Active:</label>
				 	<div class="col-xs-5">	
					 	<div class="bootstrap-switch-square">
				            <input type="checkbox" name="user_active" checked data-toggle="switch" id="custom-switch-03" data-on-text="<span class='fui-check'></span>" data-off-text="<span class='fui-cross'></span>" />
				        </div>
				    </div>    
				 </div>


				  	<div class="form-group" style="margin-top:7px;">
				    	<div class="col-xs-5 col-xs-offset-4">
				    	<button class="btn btn-sm btn-p202 btn-block" type="submit">Add User</button>
						</div>
					</div>

				</form>

			</div>
		</div>
	</div>
	<div class="col-xs-4 col-xs-offset-1">
		<div class="panel panel-default">
			<div class="panel-heading">My Users</div>
			<div class="panel-body">
			  
			<ul>
				<li>Steve (Campaign Manager) - <a href="#">edit</a> - <a href="#">delete</a>
				<li>Dave (Admin) - <a href="#">edit</a> - <a href="#">delete</a>
				<li>Lisa (Campaign optimizer) - <a href="#">edit</a> - <a href="#">delete</a>
			</ul>
			</div>
		</div>
	</div>
</div>
<script type="text/javascript">
(function ($) {
	$('[data-toggle="switch"]').bootstrapSwitch();
}(jQuery));
</script>
<?php template_bottom($server_row);
    