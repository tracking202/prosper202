<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


if ($_GET['edit_ppc_account_id']) { 
	$editing = true;    
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	
	if (isset($_POST['ppc_network_name'])) {
		$ppc_network_name = trim($_POST['ppc_network_name']);
		if (empty($ppc_network_name)) { $error['ppc_network_name'] = '<div class="error">Type in the name the ppc network.</div>'; }
		
		if (!$error) {
			$mysql['ppc_network_name'] = mysql_real_escape_string($_POST['ppc_network_name']);
			$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
			$mysql['ppc_network_time'] = time();
			
			$ppc_network_sql = "INSERT INTO `202_ppc_networks`
								SET         `user_id`='".$mysql['user_id']."',
											`ppc_network_name`='".$mysql['ppc_network_name']."',
											`ppc_network_time`='".$mysql['ppc_network_time']."'";
			$ppc_network_result = _mysql_query($ppc_network_sql) ; //($ppc_network_sql);
			$add_success = true;
		}
	}
	
	if (isset($_POST['ppc_network_id'])) {  
		
		$ppc_account_name = trim($_POST['ppc_account_name']);
		if ($ppc_account_name == '') { $error['ppc_account_name'] = '<div class="error">What is the username for this account?</div>'; }
		
		$ppc_network_id = trim($_POST['ppc_network_id']);
		if ($ppc_network_id == '') { $error['ppc_network_id'] = '<div class="error">What is the PPC network is this account attached to?</div>'; }
		
		if (!$error) { 
			//check to see if this user is the owner of the ppc network hes trying to add an account to
			$mysql['ppc_network_id'] = mysql_real_escape_string($_POST['ppc_network_id']);
			$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
			
			$ppc_network_sql = "SELECT COUNT(*) FROM `202_ppc_networks` WHERE `user_id`='".$mysql['user_id']."' AND `ppc_network_id`='".$mysql['ppc_network_id']."'";
			$ppc_network_result = _mysql_query($ppc_network_sql) ; //($ppc_network_sql);
			if (mysql_result($ppc_network_result,0,0) == 0 ) {
				$error['wrong_user'] = '<div class="error">You are not authorized to add an account to another users\' network</div>';    
			}
		}
		
		if (!$error) { 
			//if editing, check to make sure the own the ppc account they are editing
			 if ($editing == true) {
				$mysql['ppc_account_id'] = mysql_real_escape_string($_GET['edit_ppc_account_id']);
				$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
				$ppc_account_sql = "SELECT COUNT(*) FROM `202_ppc_accounts` WHERE `user_id`='".$mysql['user_id']."' AND `ppc_account_id`='".$mysql['ppc_account_id']."'";
				$ppc_account_result = _mysql_query($ppc_account_sql) ; //($ppc_account_sql);
				if (mysql_result($ppc_account_result,0,0) == 0 ) {
					$error['wrong_user'] .= '<div class="error">You are not authorized to modify another users ppc account</div>';    
				}
			}
		}
			
		if (!$error) {
			$mysql['ppc_network_id'] = mysql_real_escape_string($_POST['ppc_network_id']);
			$mysql['ppc_account_name'] = mysql_real_escape_string($_POST['ppc_account_name']);
			$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
			$mysql['ppc_account_time'] = time();
			
			if ($editing == true) { $ppc_account_sql  = " UPDATE 202_ppc_accounts SET"; } 
			else {                  $ppc_account_sql  = " INSERT INTO 202_ppc_accounts SET"; }
			
									$ppc_account_sql .= " ppc_account_name='".$mysql['ppc_account_name']."',
														  ppc_network_id='".$mysql['ppc_network_id']."',
														  user_id='".$mysql['user_id']."',
														  ppc_account_time='".$mysql['ppc_account_time']."'";
														  
			if ($editing == true) { $ppc_account_sql  .= "WHERE ppc_account_id='".$mysql['ppc_account_id']."'"; } 
			$ppc_account_result = _mysql_query($ppc_account_sql) ; //($ppc_account_sql);
			$add_success = true;
		
			if ($editing == true) { 
				//if editing true, refresh back with the edit get variable GONE GONE!
				header('location: /tracking202/setup/ppc_accounts.php');   
			}

		}
	}
}

if (isset($_GET['delete_ppc_network_id'])) { 

	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$mysql['ppc_network_id'] = mysql_real_escape_string($_GET['delete_ppc_network_id']);
	$mysql['ppc_network_time'] = time();
	
	$delete_sql = " UPDATE  `202_ppc_networks`
					SET     `ppc_network_deleted`='1',
							`ppc_network_time`='".$mysql['ppc_network_time']."'
					WHERE   `user_id`='".$mysql['user_id']."'
					AND     `ppc_network_id`='".$mysql['ppc_network_id']."'";
	if ($delete_result = _mysql_query($delete_sql)) { //($delete_result)) {
		$delete_success = true;
	}
}

if (isset($_GET['delete_ppc_account_id'])) { 

	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$mysql['ppc_account_id'] = mysql_real_escape_string($_GET['delete_ppc_account_id']);
	$mysql['ppc_account_time'] = time();
	
	$delete_sql = " UPDATE  `202_ppc_accounts`
					SET     `ppc_account_deleted`='1',
							`ppc_account_time`='".$mysql['ppc_account_time']."'
					WHERE   `user_id`='".$mysql['user_id']."'
					AND     `ppc_account_id`='".$mysql['ppc_account_id']."'";
	if ($delete_result = _mysql_query($delete_sql)) {
		$delete_success = true;
	}
}

if ($_GET['edit_ppc_account_id']) { 
	
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$mysql['ppc_account_id'] = mysql_real_escape_string($_GET['edit_ppc_account_id']);
	
	$ppc_account_sql = "SELECT  * 
						 FROM   `202_ppc_accounts`
						 WHERE  `ppc_account_id`='".$mysql['ppc_account_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
	$ppc_account_result = _mysql_query($ppc_account_sql) ; //($ppc_account_sql);
	$ppc_account_row = mysql_fetch_assoc($ppc_account_result);
	
	$selected['ppc_network_id'] = $ppc_account_row['ppc_network_id'];   
	$html['ppc_account_name'] = htmlentities($ppc_account_row['ppc_account_name'], ENT_QUOTES, 'UTF-8');
	  
}

if ($error) { 
	//if someone happend take the post stuff and add it
	$selected['ppc_network_id'] = $_POST['ppc_network_id'];
	$html['ppc_account_name'] = htmlentities($_POST['ppc_account_name'], ENT_QUOTES, 'UTF-8');

}                           


template_top('Pay Per Click Accounts',NULL,NULL,NULL); ?>


<div id="info">
	<h2>PPC Account Setup</h2>
	Add the Pay-Per-Click (PPC) networks you use, and usernames for each PPC network account you have.
</div>

<table cellspacing="0" cellpadding="0" class="setup">
	<tr valign="top">
		<td>  
			<? if ($error) { ?>
				<div class="warning"><div><h3>There were errors with your submission.</h3></div></div>
			<? } echo $error['token']; ?>

			<? if ($add_success == true) { ?>
				<div class="success"><div><h3>Your submission was successful</h3></div></div>
			<? } ?>

			<? if ($delete_success == true) { ?>
				<div class="success"><div><h3>You deletion was successful</h3>You have succesfully removed an account.</div></div>
			<? } ?>
			<form method="post" action="<? echo $_SERVER['REDIRECT_URL']; ?>">
				<table style="margin: 0px auto;">    
					<tr>
						<td colspan="2" style="width: 400px;">
							<h3 class="green">1st - Add PPC Networks</h3>
							<p style="text-align: justify;">What PPC networks do you use? Some examples include Yahoo SearchMarketing, MSN Adcenter, and Google Adwords.</p>
						</td>
					</tr>
					<tr><td/><br/></tr>
					<tr>
						<td class="left_caption">PPC Network</td>
						<td>
							<input type="text" name="ppc_network_name" style="display: inline;" maxlength="50"/>
							<input type="submit" value="Add" style="display: inline; margin-left: 10px;"/>
						</td>
					</tr>
				</table>
				<? echo $error['ppc_network_name']; ?>
			</form>

			<form method="post" action="<? if ($delete_success == true) { echo $_SERVER['REDIRECT_URL']; }?>" style>
				<table style="margin-top: 35px;">
					<tr>
						<td colspan="2" style="width: 400px;">
							<h3 class="green">2nd - Add PPC Network Accounts</h3>
							<p style="text-align: justify;">What accounts to do you have with each PPC network? For instance, if you have two Yahoo SearchMarketing accounts, you can add them both here. This way you can track how individual accounts on each network are doing.</p>  
						</td>
					</tr>
					<tr><td/><br/></tr>
					<tr>
						<td class="left_caption">PPC Network</td>
						<td>
							<select name="ppc_network_id">
								<option value=""> -- </option>
								<?  $mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
									$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='".$mysql['user_id']."' AND `ppc_network_deleted`='0' ORDER BY `ppc_network_name` ASC";
									$ppc_network_result = _mysql_query($ppc_network_sql) ; //($ppc_network_sql);
									while ($ppc_network_row = mysql_fetch_array($ppc_network_result, MYSQL_ASSOC)) {
										
										$html['ppc_network_name'] = htmlentities($ppc_network_row['ppc_network_name'], ENT_QUOTES, 'UTF-8');
										$html['ppc_network_id'] = htmlentities($ppc_network_row['ppc_network_id'], ENT_QUOTES, 'UTF-8');
										
										
										if ($selected['ppc_network_id'] == $ppc_network_row['ppc_network_id']) {
											printf('<option selected="selected" value="%s">%s</option>', $html['ppc_network_id'],$html['ppc_network_name']);     
										} else {
											printf('<option value="%s">%s</option>', $html['ppc_network_id'],$html['ppc_network_name']);    
										}
										
										
										
									} ?>
							</select>  
									
						</td>
					</tr>
					<tr>
						<td class="left_caption">Account Username</td>
						<td>
							<input type="text" name="ppc_account_name" style="display: inline;" value="<? echo $html['ppc_account_name']; ?>"/>
							<input type="submit" value="<? if ($editing == true) { echo 'Edit'; } else { echo 'Add'; } ?>" style="display: inline; margin-left: 10px;"/>
							<? if ($editing == true) { ?>
								<input type="submit" value="Cancel" style="display: inline; margin-left: 10px;" onclick="window.location='/tracking202/setup/ppc_accounts.php'; return false; "/>   
							<? } ?>
						</td>
					</tr>
				</table>
				<? echo $error['ppc_network_id']; ?> 
				<? echo $error['ppc_account_name']; ?>
				<? echo $error['wrong_user']; ?>   
			</form>
			
			
		</td>
		<td class="setup-right" rowspan="2">    
			<h3 class="green">My PPC Accounts</h3>
			
			<ul>        
			<?  $mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
				$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='".$mysql['user_id']."' AND `ppc_network_deleted`='0' ORDER BY `ppc_network_name` ASC";
				$ppc_network_result = _mysql_query($ppc_network_sql) ; //($ppc_network_sql);
				if (mysql_num_rows($ppc_network_result) == 0 ) { 
					?><li>You have not added any networks.</li><?
				}
				
				while ($ppc_network_row = mysql_fetch_array($ppc_network_result, MYSQL_ASSOC)) {
					
					//print out the PPC networks
					$html['ppc_network_name'] = htmlentities($ppc_network_row['ppc_network_name'], ENT_QUOTES, 'UTF-8');
					$url['ppc_network_id'] = urlencode($ppc_network_row['ppc_network_id']);
					printf('<li>%s - <a href="?delete_ppc_network_id=%s" style="font-size: 9px;">remove</a></li>', $html['ppc_network_name'],$url['ppc_network_id']);
					
					?><ul style="margin-top: 0px;"><?
										
						//print out the individual accounts per each PPC network
						$mysql['ppc_network_id'] = mysql_real_escape_string($ppc_network_row['ppc_network_id']);
						$ppc_account_sql = "SELECT * FROM `202_ppc_accounts` WHERE `ppc_network_id`='".$mysql['ppc_network_id']."' AND `ppc_account_deleted`='0' ORDER BY `ppc_account_name` ASC";
						$ppc_account_result = _mysql_query($ppc_account_sql) ; //($ppc_account_sql);
						 
						while ($ppc_account_row = mysql_fetch_array($ppc_account_result, MYSQL_ASSOC)) {
							
							$html['ppc_account_name'] = htmlentities($ppc_account_row['ppc_account_name'], ENT_QUOTES, 'UTF-8');
							$url['ppc_account_id'] = urlencode($ppc_account_row['ppc_account_id']);
					
							printf('<li>%s - <a href="?edit_ppc_account_id=%s" style="font-size: 9px;">edit</a> - <a href="?delete_ppc_account_id=%s" style="font-size: 9px;">remove</a></li>', $html['ppc_account_name'],$url['ppc_account_id'],$url['ppc_account_id']);
						
						} 
					
					?></ul><?
					
				} ?>
			</ul>
		</td>
	</tr>
</table>
	



<? template_bottom();