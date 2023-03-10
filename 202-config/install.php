<?php

//include mysql settings
include_once(dirname( __FILE__ ) . '/connect.php');
include_once(dirname( __FILE__ ) . '/functions-install.php');
if(isset($_COOKIE['user_api'])){
$html['user_api'] = htmlentities($_COOKIE['user_api'], ENT_QUOTES, 'UTF-8');
}
else{
    header("Location: ".get_absolute_url()."202-config/get_apikey.php");    
}

//check to see if this is already installed, if so don't do anything
	if (  is_installed() == true) {
	    
		_die("<h6>Already Installed</h6>
			  <small>You appear to have already installed Prosper202. To reinstall please clear your old database tables first. <a href='".get_absolute_url()."202-login.php'>Login Now</a></small>"); 	
	 
	}

if ($_SERVER['REQUEST_METHOD'] == 'POST') { 
	
	//check email
	if (check_email_address($_POST['user_email']) == false) { $error['user_email'] = '<div class="error">Please enter a valid email address</div>'; }    

	//check username
	if ($_POST['user_name']==''){    $error['user_name'] = '<div class="error">You must type in your desired username</div>';    }
	if (!ctype_alnum($_POST['user_name'])) {    $error['user_name'] .= '<div class="error">Your username may only contain alphanumeric characters</div>';  }    
	if ((strlen($_POST['user_name']) < 4) OR (strlen($_POST['user_name']) > 20)) { $error['user_name'] .= '<div class="error">Your username must be between 4 and 20 characters long</div>';}
	
	//check password
	if ($_POST['user_pass']=='') { $error['user_pass'] = '<div class="error">You must type in your desired password</div>'; }
	if ($_POST['user_pass']=='') { $error['user_pass'] .= '<div class="error">You must type verify your password</div>'; }
	if ((strlen($_POST['user_pass']) < 6) OR (strlen($_POST['user_pass']) > 35)) { $error['user_pass'] .= '<div class="error">Your passwords must be at least 6 characters long</div>'; }
	if ($_POST['user_pass'] != $_POST['verify_user_pass']) { $error['user_pass'] .= '<div class="error">Your passwords did not match, please try again</div>'; }
    

	//if no error occured, lets create the user account
	if (!$error) { 
		
		//no error, so now setup all of the mysql database structures
		INSTALL::install_databases(); 
		
		$mysql['user_email'] = $db->real_escape_string($_POST['user_email']);
		$mysql['user_name'] = $db->real_escape_string($_POST['user_name']);
		$mysql['user_timezone'] = $db->real_escape_string($_POST['user_timezone']);
		$mysql['p202_customer_api_key'] = $db->real_escape_string($_POST['user_api']);
		$mysql['user_time_register'] = $db->real_escape_string(time());
		
		//md5 the user pass with salt
	 	$user_pass = salt_user_pass($_POST['user_pass']);
		$mysql['user_pass'] = $db->real_escape_string($user_pass);      
 		
 		$hash = md5(uniqid(rand(), TRUE));
		$user_hash = intercomHash($hash);

		//insert this user
		$user_sql = "  	INSERT INTO 	202_users
					    	SET				user_email='".$mysql['user_email']."',
					    		 			user_name='".$mysql['user_name']."',
					    					user_pass='".$mysql['user_pass']."',
					    					user_timezone='".$mysql['user_timezone']."',
					    					user_time_register='".$mysql['user_time_register']."',
					    					install_hash='".$hash."',
					    					user_hash='".$user_hash."',
		                                    p202_customer_api_key ='".$mysql['p202_customer_api_key']."'";
		$user_result = _mysqli_query($user_sql);
		
		$user_id = $db->insert_id;
		$mysql['user_id'] = $db->real_escape_string($user_id);
		
		//update user preference table   
		$user_sql = "INSERT INTO 202_users_pref SET user_id='".$mysql['user_id']."'";
		$user_result = _mysqli_query($user_sql);
		
		$role_sql = "INSERT INTO `202_user_role` (`user_id`, `role_id`) VALUES (1, 1);";
		$role_result = _mysqli_query($role_sql);

		$cron = callAutoCron('register');

		if ($cron['status'] == 'success') {
		    $sql = "UPDATE 202_users_pref SET auto_cron = '1' WHERE user_id = '1'";
		    $result = _mysqli_query($sql);
		}

		registerDailyEmail('07', $mysql['user_timezone'], $hash);

		// create partitions after everything else is setup. In case of failure user can still login and use Prosper202
		INSTALL::install_database_partitions();
		
		//if this worked, show them the succes screen
		$success = true;
		 
	}
	
	
	
	$html['user_email'] = htmlentities($_POST['user_email'], ENT_QUOTES, 'UTF-8');
	$html['user_name'] = htmlentities($_POST['user_name'], ENT_QUOTES, 'UTF-8');
	$html['user_pass'] = htmlentities($_POST['user_pass'], ENT_QUOTES, 'UTF-8');
	$html['user_api'] = htmlentities($_COOKIE['user_api'], ENT_QUOTES, 'UTF-8');
}




//only show install setup, if it, of course, isn't install already.

if (!$success) {

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
		header("Location: /202-config/requirements.php");
	}

	info_top(); ?>
	<div class="main col-xs-7 install">
	<center><img src="<?php echo get_absolute_url();?>202-img/prosper202.png"></center>
	<h6>Welcome</h6>
	<small>Welcome to the five minute Prosper202 installation process!  Just fill in the information below, and you'll be on your way to using the most powerful internet marketing applications in the world.</small>
	<br><br>
	<small>Need Extra Help? Check out our <a href="http://support.tracking202.com/" target="_blank">ReadMe documentation</a>.</small>
	
	<h6>Create your account</h6>
	<small>Please provide the following information. Don't worry, you can always change these settings later.</small>
	<br><br>
		<form method="post" action="" class="form-horizontal" role="form" id="install-prosper202">

			      <input type="hidden" class="form-control input-sm" id="user_api" name="user_api" value="<?php echo $html['user_api']; ?>" >

		    <div class="form-group <?php if ($error['user_email']) echo "has-error";?>">
			    <label for="user_email" class="col-xs-4 control-label"><strong>Your Email:</strong></label>
			    <div class="col-xs-8">
			      <input type="text" class="form-control input-sm" id="user_email" name="user_email" value="<?php echo $html['user_email']; ?>">
			    </div>
			</div>

			<div class="form-group">
			    <label for="user_timezone" class="col-xs-4 control-label"><strong>Time Zone:</strong></label>
			    <div class="col-xs-8">
			      <?php
			
					

					$utc = new DateTimeZone('UTC');
					$dt = new DateTime('now', $utc);

					echo '<select class="form-control input-sm" name="user_timezone" id="user_timezone">';
					foreach(DateTimeZone::listIdentifiers() as $tz) {
					    $current_tz = new DateTimeZone($tz);
					    $offset =  $current_tz->getOffset($dt);
					    $transition =  $current_tz->getTransitions($dt->getTimestamp(), $dt->getTimestamp());
					    $abbr = $transition[0]['abbr'];

					    echo '<option value="' .$tz. '">' .$tz. ' [' .$abbr. ' '. formatOffset($offset). ']</option>';
					}
					echo '</select>';
					?>
			    </div>
			</div>

			<div class="form-group <?php if ($error['user_name']) echo "has-error";?>">
			    <label for="user_name" class="col-xs-4 control-label"><strong>Username:</strong></label>
			    <div class="col-xs-8">
			      <input type="text" class="form-control input-sm" id="user_name" name="user_name">
			    </div>
			</div>

			<div class="form-group <?php if ($error['user_pass']) echo "has-error";?>">
			    <label for="user_pass" class="col-xs-4 control-label"><strong>Password:</strong></label>
			    <div class="col-xs-8">
			      <input type="password" class="form-control input-sm" id="user_pass" name="user_pass">
			    </div>
			</div>

			<div class="form-group <?php if ($error['user_pass']) echo "has-error";?>">
			    <label for="verify_user_pass" class="col-xs-4 control-label"><strong>Verify Password:</strong></label>
			    <div class="col-xs-8">
			      <input type="password" class="form-control input-sm" id="verify_user_pass" name="verify_user_pass">
			    </div>
			</div>

			<button class="btn btn-lg btn-p202 btn-block" type="submit">Install Prosper202 ClickServer<span class="fui-check-inverted pull-right"></span></button>
<script type="text/javascript">
$('form#install-prosper202').submit(function(){
    $(this).find(':input[type=submit]').prop('disabled', true);
    $('body').css( 'cursor', 'wait' );
});

					</script>
		</form>
		</div>
	<?php info_bottom(); 
	
}


//if success is equal to true, and this campaign did complete
if ($success) {
	
	info_top(); ?>
	<div class="main col-xs-7 install">
	<center><img src="<?php echo get_absolute_url();?>202-img/prosper202.png"></center>
		<h6>Success!</h6>
		<small>Prosper202 has been installed. Now you can <a href="<?php echo get_absolute_url();?>202-login.php">log in</a>.</small><br></br>
		<div class="row" style="margin-bottom: 10px;">
		  <div class="col-xs-3"><span class="label label-default">Username:</span></div>
		  <div class="col-xs-9"><span class="label label-primary"><?php echo $html['user_name']; ?></span></div>
		</div>
		<div class="row" style="margin-bottom: 10px;">
		  <div class="col-xs-3"><span class="label label-default">Password:</span></div>
		  <div class="col-xs-9"><span class="label label-primary"><?php echo $html['user_pass']; ?></span></div>
		</div>
		<div class="row" style="margin-bottom: 10px;">
		  <div class="col-xs-3"><span class="label label-default">Login address:</span></div>
		  <div class="col-xs-9"><small><?php printf('<a href="%s202-login.php">%s202-login.php</a>',get_absolute_url(),$_SERVER['SERVER_NAME'].get_absolute_url()); ?></small></div>
		</div>

		<p><small>Were you expecting more steps? Sorry thats it!</small></p>
	</div>
	<?php info_bottom();
	
}
