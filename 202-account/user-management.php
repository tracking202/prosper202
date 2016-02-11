<?php include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/connect.php'); 

AUTH::require_user();

if (!$userObj->hasPermission("add_users")) {
	header('location: '.get_absolute_url().'202-account/');
}

$slack = false;
$mysql['user_own_id'] = $db->real_escape_string($_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();
$username = $user_row['username'];

if (!empty($user_row['url'])) 
	$slack = new Slack($user_row['url']);

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

if ($_GET ['edit_user_id']) {
    $editing = true;
}

if ($_GET ['delete_user_id']) {
    $deleting = true;
}

$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);

$user_sql = "SELECT user_fname,user_lname,user_name,user_id,user_email,user_time_register,user_timezone,install_hash,user_hash,role_name FROM 202_users LEFT JOIN 202_user_role USING (user_id) LEFT JOIN 202_roles USING (role_id) WHERE user_id!=1 and user_deleted!=1";
$user_result = _mysqli_query($user_sql);
//$user_row = $user_result->fetch_assoc();

$user_sql = "SELECT user_id,user_email,user_time_register,user_timezone,install_hash,user_hash,modal_status,vip_perks_status FROM 202_users WHERE user_id=1";
$user_result2 = _mysqli_query($user_sql);
$user_row2 = $user_result2->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $mysql['form_user_id'] = $db->real_escape_string(trim ( $_POST ['user_id'] ));
     $mysql['user_fname'] = $db->real_escape_string(trim ( $_POST ['user_fname'] ));
    if (empty ( $mysql['user_fname'] )) {
        $error ['user_fname'] = '<div class="error help-block">Enter a first name.</div>';
    }

     $mysql['user_lname'] = $db->real_escape_string(trim ( $_POST ['user_lname'] ));
    if (empty ( $mysql['user_lname'] )) {
        $error ['user_lname'] = '<div class="error help-block">Enter a last name.</div>';
    }
    
     $mysql['user_email'] = $db->real_escape_string(trim ( $_POST ['user_email'] ));
    if (empty ( $mysql['user_email'] )) {
        $error ['user_email'] = '<div class="error help-block">Enter an email address.</div>';
    }

     $mysql['user_name'] = $db->real_escape_string(trim ( $_POST ['user_name'] ));
    if (empty ( $mysql['user_name'] )) {
        $error ['user_name'] = '<div class="error help-block">Enter a username.</div>';
    }

    if (!empty ( $mysql['user_name'] ) && !$editing) {
        $check_user="select user_name,user_id from 202_users where user_name='".$mysql['user_name']."'";
        $check_user_result = _mysqli_query($check_user);
        $user_name_row=$check_user_result->fetch_array(MYSQLI_ASSOC);
        if($user_result->num_rows != 0 && $user_name_row['user_id']!=$mysql['form_user_id']){
            $error ['user_name'] = '<div class="error help-block">The username you entered already exists.</div>';
        }
            
    }
    
    if($editing !== true){
     $mysql['user_password'] = $db->real_escape_string(trim ( $_POST ['user_password'] ));
    if (empty (  $mysql['user_password'] )) {
        $error ['user_password'] = '<div class="error help-block">Enter a password.</div>';
    }
    }
    
    if ($editing !== true || ($mysql['user_password']!='' && $editing === true)) {
     $mysql['user_password2'] = $db->real_escape_string(trim ( $_POST ['user_password2'] ));
    if (empty (  $mysql['user_password2'] )) {
        $error ['user_password2'] = '<div class="error help-block">Retype the users password.</div>';
    }
    }

    if (strcmp( $mysql['user_password'],  $mysql['user_password2']) !== 0){
        $error ['user_password2'] = '<div class="error help-block">Make sure the passwords you entered match.</div>';
    }

    $mysql['user_role'] = $db->real_escape_string(trim($_POST['user_role']));
    if (empty($mysql['user_role'])){
        $error ['user_role'] = '<div class="error help-block">Please select user role</div>';
    }

    if($_POST ['user_active']!=='on')
        $mysql['user_active'] = 0;
    else
        $mysql['user_active'] = 1;
    
/*     if($editing === true){
        $password_sql="select user_id from 202_users where user_pass='".$mysql['user_pass']."' AND user_id='".$mysql['form_user_id']."'";
        $pass_result = _mysqli_query($password_sql);
        if($pass_result->num_rows == 0){
            $error ['user_password'] = '<div class="error help-block">You entered an invalid password.</div>';
        }
       
    } */

    if (!$error) {

        $mysql['user_pass'] = $db->real_escape_string(salt_user_pass($mysql['user_password']));
        $hash = md5(uniqid(rand(), TRUE));
        $user_hash = intercomHash($hash);
        
        $mysql['user_time_register'] = $db->real_escape_string(time());
        
            if ($editing === true) {
                $user_sql  = " UPDATE 202_users SET"; 
            }
            else {
                $user_sql = "INSERT INTO `202_users` SET";
            }	

                $user_sql .= " `user_fname`='".$mysql['user_fname']."',
								  `user_lname`='".$mysql['user_lname']."',
								  `user_email`='".$mysql['user_email']."',
								  `user_name`='".$mysql['user_name']."',
								  `user_time_register`='".$user_row2['user_time_register']."',
								  `user_timezone`='".$user_row2['user_timezone']."',"; 
                if ($editing !== true) {
					$user_sql .= "`user_pass`='".$mysql['user_pass']."',";
  
				    $user_sql .= "`install_hash`='".$user_row2['install_hash']."',
					`user_hash`='".$user_hash."',
				    `modal_status`='".$user_row2['modal_status']."',
					`vip_perks_status`='".$user_row2['vip_perks_status']."',";
                }
					$user_sql .= "`user_active`='".$mysql['user_active']."'";
								  
                if ($editing == true) { 
                	$user_sql  .= "WHERE user_id='".$mysql['form_user_id']."'";
                	$user_result = _mysqli_query($user_sql);

                	$role_sql = "UPDATE 202_user_role SET role_id = '".$mysql['user_role']."' WHERE user_id = '".$mysql['form_user_id']."'";
                } else {
                	$user_result = _mysqli_query($user_sql);
                	$user_id = $db->insert_id;

                	$role_sql = "INSERT INTO 202_user_role SET user_id = '".$user_id."', role_id = '".$mysql['user_role']."'";
                }
 				
 				$role_result = _mysqli_query($role_sql);
                $add_success = true;
               	
               	switch ($_POST['user_role']) {
                	case '2':
                		$role = 'Admin';
                		break;
                			
                	case '3':
                		$role = 'Campaign manager';
                		break;

                	case '4':
                		$role = 'Campaign optimizer';
                		break;

                	case '5':
                		$role = 'Campaign viewer';
                		break;		
                }

                if ($slack) {
                	if ($editing === true) {
                		$slack->push('user_management_user', array('user' => $username, 'type' => 'Updated', 'username' => $_POST['user_name'], 'role' => $role));
                	} else {
                		$slack->push('user_management_user', array('user' => $username, 'type' => 'Created', 'username' => $_POST['user_name'], 'role' => $role));
                	}
                }

                header('location: '.get_absolute_url().'202-account/user-management.php');
    }

}

if ($editing == true) {
    $mysql['user_id'] = $db->real_escape_string(trim(filter_input(INPUT_GET, 'edit_user_id', FILTER_SANITIZE_NUMBER_INT)));
    $user_sql_edit = "SELECT user_fname,user_lname,user_name,user_id,user_email,user_time_register,user_active,role_id FROM 202_users LEFT JOIN 202_user_role USING (user_id) WHERE user_id=".$mysql['user_id'];
    $user_result_edit = _mysqli_query($user_sql_edit);
    $user_row_edit = $user_result_edit->fetch_assoc();
    
    if ($user_row_edit['role_id'] == '2') {
    	if (!$userObj->hasPermission("add_edit_delete_admin")) {
	    	header('location: '.get_absolute_url().'202-account/user-management.php');
	    	die();
	    }
    }

    $html = array_map ( 'htmlentities', $user_row_edit );
}

if ($deleting == true) {
	
	$mysql['user_id'] = $db->real_escape_string(trim(filter_input(INPUT_GET, 'delete_user_id', FILTER_SANITIZE_NUMBER_INT)));
    
    if (!$userObj->hasPermission("add_edit_delete_admin")) {
	    header('location: '.get_absolute_url().'202-account/user-management.php');
	    die();
	}

    $user_sql_delete = "UPDATE 202_users SET user_deleted = '1' WHERE user_id = ".$mysql['user_id'];
    $user_result_delete = _mysqli_query($user_sql_delete);

    if ($slack) {
    	$sql = "SELECT user_name, role_id FROM 202_users LEFT JOIN 202_user_role USING (user_id) WHERE user_id = ".$mysql['user_id'];
    	$result = _mysqli_query($sql);
    	$row = $result->fetch_assoc();

    	switch ($row['role_id']) {
            case '2':
                $role = 'Admin';
                break;
                			
            case '3':
                $role = 'Campaign manager';
                break;

            case '4':
                $role = 'Campaign optimizer';
                break;

            case '5':
                $role = 'Campaign viewer';
                break;		
        }

        $slack->push('user_management_user', array('user' => $username, 'type' => 'Removed', 'username' => $row['user_name'], 'role' => $role));
    }
    
    header('location: '.get_absolute_url().'202-account/user-management.php');

}

//show the template
template_top('User Management',NULL,NULL,NULL); ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>User Management</h6>
	</div>
</div> 


<div class="row">
	<div class="col-xs-7">
		<div class="row">
			<div class="col-xs-12" style="margin-top: 15px;">
				<small><strong>Manage all your users</strong></small><br/>
				<span class="infotext">Add and Manage users</span>
				
				<form style="margin:15px 0px;" method="post" action="<?php if ($delete_success == true) { echo $_SERVER['REDIRECT_URL']; }?>" class="form-horizontal" role="form">
				  <div class="form-group <?php if($error['user_fname']) echo "has-error"; ?>" style="margin-bottom: 0px;">
				    <label for="ppc_network_id" class="col-xs-4 control-label" style="text-align: left;" placeholder="">First Name:</label>
				    <div class="col-xs-5">
				    <input type="text" class="form-control input-sm" id="user_fname" name="user_fname" value="<?php echo $html['user_fname']; ?>">
				    <?php echo $error['user_fname']; ?>
				    </div>
				  </div>
				  <div class="form-group <?php if($error['user_lname']) echo "has-error"; ?>" style="margin-bottom: 0px;">
				    <label for="ppc_network_id" class="col-xs-4 control-label" style="text-align: left;">Last Name:</label>
				    <div class="col-xs-5">
				       <input type="text" class="form-control input-sm" id="user_lname" name="user_lname" value="<?php echo $html['user_lname']; ?>">
				    	<?php echo $error['user_lname']; ?>
				    </div>
				    
				  </div>
				  <div class="form-group <?php if($error['user_email']) echo "has-error"; ?>" style="margin-bottom: 0px;">
				    <label for="ppc_account_name" class="col-xs-4 control-label" style="text-align: left;">E-mail:</label>
				    <div class="col-xs-5">
				      <input type="ppc_account_name" class="form-control input-sm" id="user_email" name="user_email" value="<?php echo $html['user_email']; ?>">
				    	<?php echo $error['user_email']; ?>
				    </div>
				    
				  </div>
				  <div class="form-group <?php if($error['user_name']) echo "has-error"; ?>" style="margin-bottom: 0px;">
				    <label for="ppc_account_name" class="col-xs-4 control-label" style="text-align: left;">Username:</label>
				    <div class="col-xs-5">
				      <input type="text" class="form-control input-sm" id="user_name" name="user_name" value="<?php echo $html['user_name']; ?>">
				    	<?php echo $error['user_name']; ?>
				    </div>
				  </div>
				  <?php if(!$editing){ ?>
				  <div class="form-group <?php if($error['user_password']) echo "has-error"; ?>" style="margin-bottom: 0px;">
				    <label for="ppc_account_name" class="col-xs-4 control-label" style="text-align: left;">Password:</label>
				    <div class="col-xs-5">
				      <input type="password" class="form-control input-sm" id="user_password" name="user_password" value="<?php echo $html['ppc_account_name']; ?>">
				    	<?php echo $error['user_password']; ?>
				    </div>
				    
				  </div>
				  <div class="form-group <?php if($error['user_password2']) echo "has-error"; ?>" style="margin-bottom: 0px;">
				    <label for="ppc_account_name" class="col-xs-4 control-label" style="text-align: left;">Retype Password:</label>
				    <div class="col-xs-5">
				      <input type="password" class="form-control input-sm" id="user_password2" name="user_password2" value="<?php echo $html['ppc_account_name']; ?>">
				    	<?php echo $error['user_password2']; ?>
				    </div>
				    
				  </div>
				  <?php  } ?>

				  <div class="form-group" style="margin-bottom: 0px;">
					 	<label for="user_role" class="col-xs-4 control-label" style="text-align: left;">Role:</label>
					 	<div class="col-xs-5">	
						 	<select class="form-control input-sm" name="user_role">
						 		<option value="2" <?php if($html['role_id'] == '2') echo "selected";?>>Admin</option>
						 		<option value="3" <?php if($html['role_id'] == '3') echo "selected";?>>Campaign manager</option>
						 		<option value="4" <?php if($html['role_id'] == '4') echo "selected";?>>Campaign optimizer</option>
						 		<option value="5" <?php if($html['role_id'] == '5') echo "selected";?>>Campaign viewer</option>
						 	</select>
						 	<?php echo $error['user_role']; ?>
					    </div>    
					</div>

				 <div class="form-group" style="margin-bottom: 0px;">
				 	<label for="user_active" class="col-xs-4 control-label" style="text-align: left;">Active:</label>
				 	<div class="col-xs-5">	
					 	<div class="bootstrap-switch-square">
				            <input type="checkbox" name="user_active"  <?php if ($editing == true) { if($html['user_active'] == true) echo 'checked="true"'; } else { echo 'checked="true"'; }?> data-toggle="switch" id="custom-switch-03" data-on-text="<span class='fui-check'></span>" data-off-text="<span class='fui-cross'></span>" />
				        </div>
				    </div>    
				 </div>


				  	<div class="form-group" style="margin-top:7px;">
				    	<div class="col-xs-5 col-xs-offset-4">
				    	<?php if ($editing == true) { ?>
					    	<div class="row">
					    		<div class="col-xs-6">
					    			<button class="btn btn-sm btn-p202 btn-block" type="submit">Edit</button>					
					    		</div>
					    		<div class="col-xs-6">
									<input type="hidden" name="user_id" value="<?php echo $html['user_id'];?>">
									<button type="submit" class="btn btn-sm btn-danger btn-block" onclick="window.location='<?php echo get_absolute_url();?>202-account/user-management.php'; return false;">Cancel</button>					    		</div>
					    	</div>
				    	<?php } else { ?>
				    		<button class="btn btn-sm btn-p202 btn-block" type="submit">Add User</button>					
						<?php } ?>
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
			<?php  $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
			
			if ($user_result ->num_rows == 0 ) {
				?>
				<li>You have not added any users.</li>
				<?php
			}

			while ($user_row = $user_result ->fetch_array(MYSQLI_ASSOC)) {
				//print out the PPC networks
				if($user_row['user_fname']){
				    $html['user_display_name'] = htmlentities($user_row['user_fname'], ENT_QUOTES, 'UTF-8');
				    if($user_row['user_lname']){
				        $html['user_display_name'] .= " ".htmlentities($user_row['user_lname'], ENT_QUOTES, 'UTF-8'). " (".$user_row['role_name'].")";
				    }
				 }
				 else{
				     $html['user_display_name'] .= " ".$user_row['user_name']. " (".$user_row['role_name'].")";
				 }
				   
				//$html['ppc_network_name'] = htmlentities($ppc_network_row['ppc_network_name'], ENT_QUOTES, 'UTF-8');
				$url['user_id'] = urlencode($user_row['user_id']);

				if ($user_row['role_name'] == 'Admin') {
					if (!$userObj->hasPermission("add_edit_delete_admin")) {
						printf('<li>%s</li>', $html['user_display_name']);
					} else {
						printf('<li>%s - <a href="?edit_user_id=%s">edit</a> - <a href="?delete_user_id=%s" onclick="return confirmSubmit(\'Are You Sure You Want To Delete This Traffic Source?\');">remove</a></li>', $html['user_display_name'],$url['user_id'],$url['user_id']);
					}
				} else {
					printf('<li>%s - <a href="?edit_user_id=%s">edit</a> - <a href="?delete_user_id=%s" onclick="return confirmSubmit(\'Are You Sure You Want To Delete This Traffic Source?\');">remove</a></li>', $html['user_display_name'],$url['user_id'],$url['user_id']);
				}
				
				?>
				
				<?php

			} ?>
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
    