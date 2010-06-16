<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 



if ($_SERVER['REQUEST_METHOD'] == 'POST') { 
	
	$mysql['user_name'] = mysql_real_escape_string($_POST['user_name']);
	$mysql['user_email'] = mysql_real_escape_string($_POST['user_email']);
	
	$user_sql = "SELECT user_id FROM 202_users WHERE user_name='".$mysql['user_name']."' AND user_email='".$mysql['user_email']."'";
	$user_result = _mysql_query($user_sql);
	$user_row = mysql_fetch_assoc($user_result);
	
	if (!$user_row) { $error['user'] = '<div class="error"> Invalid username /email combination.</div>'; }
	
	//i there isn't any error, give this user, a new password, and email it to them!
	if (!$error) {
		
		$mysql['user_id'] = mysql_real_escape_string($user_row['user_id']);
		
		//generate random key
		$user_pass_key = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
		$user_pass_key = substr(str_shuffle($user_pass_key), 0, 40) . time();
		$mysql['user_pass_key'] = mysql_real_escape_string($user_pass_key);

		//set the user pass time
		$mysql['user_pass_time'] = time(); 
			
		//insert this verification key into the database, and the timestamp of inserting it
		$update_sql = "	UPDATE 	202_users 
							SET 		user_pass_key='" . $mysql['user_pass_key'] . "',
										user_pass_time='" . $mysql['user_pass_time'] . "'
							WHERE		user_id='".$mysql['user_id']."'";
		$update_result = _mysql_query($update_sql);
			
		
		//now email the user the script to reset their email
		$to = $_POST['user_email'];
		$subject = "[Propser202 on ".$_SERVER['SERVER_NAME']."] Password Reset"; 
		
		$message = "
<p>Someone has asked to reset the password for the following site and username.</p>

<p><a href=\"http://".$_SERVER['SERVER_NAME']."\">http://".$_SERVER['SERVER_NAME']."</a></p>

<p>Username: ".$_POST['user_name']."</p>

<p>To reset your password visit the following address, otherwise just ignore this email and nothing will happen.</p>

<p><a href=\"http://".$_SERVER['SERVER_NAME']."/202-pass-reset.php?key=$user_pass_key\">http://".$_SERVER['SERVER_NAME']."/202-pass-reset.php?key=$user_pass_key</a></p>";
		
		$from = "propser202@".$_SERVER['SERVER_NAME'];
		
		$header = "From: Propser202<" . $from . "> \r\n";
	    	$header .= "Reply-To: ".$from." \r\n";
	    	$header .=  "To: " . $to . " \r\n";
	    	$header .= "Content-Type: text/html; charset=\"iso-8859-1\" \r\n";
	    	$header .= "Content-Transfer-Encoding: 8bit \r\n";
	    	$header .= "MIME-Version: 1.0 \r\n";
				
		mail($to,$subject,$message,$header);
		
		$success = true;
	}
	
	 
	
	
	$html['user_name'] = htmlentities($_POST['user_name'], ENT_QUOTES, 'UTF-8');
	$html['user_email'] = htmlentities($_POST['user_email'], ENT_QUOTES, 'UTF-8');
	
} ?>



<? info_top(); ?>

	<? if ($success == true) { ?>
	
		<div class="error" style="text-align: center;"><br/>An email has been sent with a link where you can change your password.</div>
	
	<? } else { ?>
	
		<form method="post" action="">
			<input type="hidden" name="token" value=""/>
			<table class="config" cellspacing="0" cellpadding="5" style="margin: 0px auto;" >
				<tr><td colspan="2" style="text-align: center;">Please enter your username and e-mail address.<br/>You will receive a new password via e-mail to <a href="/202-login.php">login</a> with.</td></tr>
				<tr><td/></tr>
				 <tr>
					<th>Username:</th>
					<td><input id="user_name" type="text" name="user_name" value="<? echo $html['user_name']; ?>"/></td>
				</tr>
				 <tr>
					<th>Email:</th>
					<td><input id="user_name" type="text" name="user_email" value="<? echo $html['user_email']; ?>"/></td>
				</tr>
				<? if ($error['user']) { printf('<tr><td colspan="2">%s</td></tr>', $error['user']); } ?>
				<tr>
					<td/>
					<td><input id="submit" type="submit" value="Get New Password  &raquo;"/></td>
				</tr>
			</table>
		</form>
		
	<? } ?>
<? info_bottom(); ?>