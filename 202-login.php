<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	$mysql['user_name'] = mysql_real_escape_string($_POST['user_name']);
	
	$user_pass = salt_user_pass($_POST['user_pass']);
	$mysql['user_pass'] = mysql_real_escape_string($user_pass);
	
	//check to see if this user exists
	$user_sql = "	SELECT 	* 
					FROM 		202_users  
				 	WHERE 	user_name='".$mysql['user_name']."'
					AND     		user_pass='".$mysql['user_pass']."'";
	$user_result = _mysql_query($user_sql);
	$user_row = mysql_fetch_assoc($user_result);
		
	if (!$user_row) { 
		$error['user'] = '<div class="error">Your username or password is incorrect.</div>';
	}
		
	//check tokens	
	/* ($_POST['token'] != $_SESSION['token']) {
		$error['token'] = '<div class="error">You must use theses forms to submit data.</div'; 
	}*/
	
	
	
	//RECORD THIS USER LOGIN, into user_logs
		$mysql['login_server'] = mysql_real_escape_string ( serialize($_SERVER) );
		$mysql['login_session'] = mysql_real_escape_string ( serialize($_SESSION) );
		$mysql['login_error'] = mysql_real_escape_string ( serialize($error) );
		$mysql['ip_address'] = mysql_real_escape_string ( $_SERVER['REMOTE_ADDR'] ); 
		
		$mysql['login_time'] = time();
		
		if ($error) { 
			$mysql['login_success'] = 0;
		} else {
			$mysql['login_success'] = 1;	
		}
	
	//record everything that happend during this crime scene.
		$user_log_sql = "INSERT INTO 			202_users_log
								   SET			user_name='".$mysql['user_name']."',
												user_pass='".$mysql['user_pass']."',
												ip_address='".$mysql['ip_address']."',
												login_time='".$mysql['login_time']."',
												login_success = '".$mysql['login_success']."',
												login_error='".$mysql['login_error']."',
												login_server='".$mysql['login_server']."',
												login_session='".$mysql['login_session']."'";
		$user_log_result = mysql_query($user_log_sql) or record_mysql_error($user_log_sql);
	
	if (!$error) {
		
		$ip_id = INDEXES::get_ip_id($_SERVER['HTTP_X_FORWARDED_FOR']);
		$mysql['ip_id'] = mysql_real_escape_string($ip_id);
   
		//update this users last login_ip_address
		$user_sql = "	UPDATE 	202_users  
						SET			user_last_login_ip_id='".$mysql['ip_id']."'
					 	WHERE 	user_name='".$mysql['user_name']."'
						AND     		user_pass='".$mysql['user_pass']."'";
		$user_result = _mysql_query($user_sql);
		
		//regenerate session_id to prevent fixation
		//session_regenerate_id();     have to remove this because it wouldn't like IE8 users login
			
		//set session variables			
		$_SESSION['session_fingerprint'] = md5('session_fingerprint' . $_SERVER['HTTP_USER_AGENT'] . session_id());
		$_SESSION['session_time'] = time();
		$_SESSION['user_name'] = $user_row['user_name'];
		$_SESSION['user_id'] = $user_row['user_id'];
		$_SESSION['user_api_key'] = $user_row['user_api_key'];
		$_SESSION['user_stats202_app_key'] = $user_row['user_stats202_app_key'];
		$_SESSION['user_timezone'] = $user_row['user_timezone']; 
		
		//redirect to account scree
		header('location: /202-account');
	}
		
	$html['user_name'] = htmlentities($_POST['user_name'], ENT_QUOTES, 'UTF-8');
	
}




info_top(); ?>
	<form method="post" action="">
		<input type="hidden" name="token" value="<? echo $_SESSION['token']; ?>"/>
		<table cellspacing="0" cellpadding="5" style="margin: 0px auto;" >
			<? if ($error['token']) { printf('<tr><td colspan="2">%s</td></tr>', $error['token']); } ?>
			<tr>
				<td>Username:</td>
				<td><input id="user_name" type="text" name="user_name" value="<? echo $html['user_name']; ?>"/></td>
			</tr>
			<? if ($error['user']) { printf('<tr><td colspan="2">%s</td></tr>', $error['user']); } ?>
			<tr>
				<td>Password:</td>
				<td>
					<input id="user_pass" type="password" name="user_pass"/>
					<span id="forgot_pass">(<a href="/202-lost-pass.php">I forgot my password/username</a>)</a>
				</td>
			</tr>
			<tr>
				<td/>
				<td><input id="submit" type="submit" value="Sign In"/></td>
			</tr>
		</table>
	</form>
<? info_bottom(); ?>