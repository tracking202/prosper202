<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 



	//take password retireveal and see if it is legitimate
	$mysql['user_pass_key'] = mysql_real_escape_string($_GET['key']);
	
	$user_sql = "SELECT * FROM 202_users WHERE user_pass_key='".$mysql['user_pass_key']."'";
	$user_result = _mysql_query($user_sql);
	$user_row = mysql_fetch_assoc($user_result);
	
	if (!$user_row) { $error['user_pass_key'] = '<div class="error">No key was found like that</div>'; }
	
	if (!$error) {
			
		//how many days ago was this code activated, this code will only work if the activation reset code is at least current within the last 3 days
		$date_today = time(); 
		$days = (($date_today-$user_row['user_pass_time'])/86400);  
			
		if ($days > 3) { $error['user_pass_key'] .= '<div class="error">Sorry, this key has expired, they expire in three (3) days</div>'; }		
	}

	
	//if the key is legit, make sure their new posted password is legit     
	if (!$error and ($_SERVER['REQUEST_METHOD'] == "POST")) {
		
		//check tokens    
		//if ($_POST['token'] != $_SESSION['token']) { $error['token'] = '<div class="error">You must use our forms to submit data.</div';  }
	

		if ($_POST['user_pass']=='') { $error['user_pass'] = '<div class="error">You must type in your desired password</div>'; }
		if ($_POST['user_pass']=='') { $error['user_pass'] .= '<div class="error">You must type verify your password</div>'; }
		if ((strlen($_POST['user_pass']) < 6) OR (strlen($_POST['user_pass']) > 15)) { $error['user_pass'] .= '<div class="error">Passwords must be 6 to 15 characters long</div>';}
		if ($_POST['user_pass'] != $_POST['verify_user_pass']) { $error['user_pass'] .= '<div class="error">Your passwords did not match, please try again</div>'; }
				
		if (!$error) {
			
			$user_pass = salt_user_pass($_POST['user_pass']);
			$mysql['user_pass'] = mysql_real_escape_string($user_pass);
			
			$mysql['user_id'] = mysql_real_escape_string($user_row['user_id']);

			$user_sql = "UPDATE 	202_users
						  SET		user_pass='".$mysql['user_pass']."',
									user_pass_time='0'
						  WHERE	user_id='".$mysql['user_id']."'";
			$user_result = _mysql_query($user_sql);
			
			$success = true;
		}
	}   
	
	$html['user_name'] = htmlentities($user_row['user_name'], ENT_QUOTES, 'UTF-8'); 



//if password was changed succesfully
if ($success == true) { 
	
	_die("<div style='text-align: center'><br/>Congratulations, your password has been reset.<br/>
		   You can now <a href=\"/202-login.php\">login</a> with your new password</div>");
 } 
 
 if ($error['user_pass_key']) {
	
 	_die("<div style='text-align: center'><br/>".$error['user_pass_key'] ."<p>Please use the <a href=\"/202-lost-pass\">password retrieval tool</a> to get a new password reset key.</p></div>");
 }
				
//else if none of the above, show the code to reset! ?>
 
	<? info_top(); ?>
		<form method="post" action="">
			<input type="hidden" name="token" value=""/>
			<table class="config" cellspacing="0" cellpadding="5" style="margin: 0px auto;" >
				<tr><td colspan="2" style="text-align: center;">Please create a new password and verify it to proceed.</td></tr>
				<tr><td/></tr>
				 <tr>
					<th>Username:</th>
					<td><input id="user_name" type="text" name="user_name" value="<? echo $html['user_name']; ?>"  readonly="true""/></td>
				</tr>
				 <tr>
					<th>New Pass:</th>
					<td><input id="user_name" type="password" name="user_pass" "/></td>
				</tr>
				<? if ($error['user_pass']) { printf('<tr><td colspan="2">%s</td></tr>', $error['user_pass']); } ?>
				<tr>
					<th>Verify Pass:</th>
					<td><input id="user_name" type="password" name="verify_user_pass" /></td>
				</tr>
				<tr>
					<td/>
					<td><input id="submit" type="submit" value="Reset Password  &raquo;"/></td>
				</tr>
			</table>
		</form>
	<? info_bottom(); ?> 