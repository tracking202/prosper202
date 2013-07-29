<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user();

if ($_GET['edit_aff_campaign_id']) { 
	$editing = true; 
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	$aff_network_id = trim($_POST['aff_network_id']);
	if (empty($aff_network_id)) { $error['aff_network_id'] = '<div class="error">Type in the name the ppc network.</div>'; }
	
	$aff_campaign_name = trim($_POST['aff_campaign_name']);
	if (empty($aff_campaign_name)) { $error['aff_campaign_name'] = '<div class="error">What is the name of this campaign.</div>'; }
	
	$aff_campaign_url = trim($_POST['aff_campaign_url']);
	if (empty($aff_campaign_url)) { $error['aff_campaign_url'] = '<div class="error">What is your affiliate link? Make sure subids can be added to it.</div>'; }
	
	if ((substr($_POST['aff_campaign_url'],0,7) != 'http://') and (substr($_POST['aff_campaign_url'],0,8) != 'https://')){
      	$error['aff_campaign_url'] .= '<div class="error">Your Landing Page URL must start with http:// or https://</div>';    
    }
    
    $aff_campaign_payout = trim($_POST['aff_campaign_payout']);
	if (!is_numeric($aff_campaign_payout)) { $error['aff_campaign_payout'] .= '<div class="error">Please enter in a numeric number for the payout.</div>'; }
	
	//check to see if they are the owners of this affiliate network
	$mysql['aff_network_id'] = mysql_real_escape_string($_POST['aff_network_id']);
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='".$mysql['user_id']."' AND `aff_network_id`='".$mysql['aff_network_id']."'";
	$aff_network_result = mysql_query($aff_network_sql) or record_mysql_error($aff_network_sql);
	if (mysql_num_rows($aff_network_result) == 0 ) {
		$error['wrong_user'] = '<div class="error">You are not authorized to add an campaign to another users network</div>';    
	}
	

	//if editing, check to make sure the own the campaign they are editing
	if ($editing == true) {
		$mysql['aff_campaign_id'] = mysql_real_escape_string($_POST['aff_campaign_id']);
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `user_id`='".$mysql['user_id']."' AND `aff_campaign_id`='".$mysql['aff_campaign_id']."'";
		$aff_campaign_result = mysql_query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
		if (mysql_num_rows($aff_campaign_result) == 0 ) {
			$error['wrong_user'] .= '<div class="error">You are not authorized to modify another users campaign</div>';    
		}
	}
	
	if (!$error) {
		$mysql['aff_campaign_id'] = mysql_real_escape_string($_POST['aff_campaign_id']);
		$mysql['aff_network_id'] = mysql_real_escape_string($_POST['aff_network_id']);
		$mysql['aff_campaign_name'] = mysql_real_escape_string($_POST['aff_campaign_name']);
		$mysql['aff_campaign_url'] = mysql_real_escape_string($_POST['aff_campaign_url']);
		$mysql['aff_campaign_url_2'] = mysql_real_escape_string($_POST['aff_campaign_url_2']);
		$mysql['aff_campaign_url_3'] = mysql_real_escape_string($_POST['aff_campaign_url_3']);
		$mysql['aff_campaign_url_4'] = mysql_real_escape_string($_POST['aff_campaign_url_4']);
		$mysql['aff_campaign_url_5'] = mysql_real_escape_string($_POST['aff_campaign_url_5']);
		$mysql['aff_campaign_rotate'] = mysql_real_escape_string($_POST['aff_campaign_rotate']);
		$mysql['aff_campaign_payout'] = mysql_real_escape_string($_POST['aff_campaign_payout']);
		$mysql['aff_campaign_cloaking'] = mysql_real_escape_string($_POST['aff_campaign_cloaking']);
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$mysql['aff_campaign_time'] = time();
		
		if ($editing == true) { $aff_campaign_sql  = "UPDATE `202_aff_campaigns` SET"; } 
		else {                  $aff_campaign_sql  = "INSERT INTO `202_aff_campaigns` SET"; }
		
								$aff_campaign_sql .= "`aff_network_id`='".$mysql['aff_network_id']."',
													  `user_id`='".$mysql['user_id']."',
													  `aff_campaign_name`='".$mysql['aff_campaign_name']."',
													  `aff_campaign_url`='".$mysql['aff_campaign_url']."',
													  `aff_campaign_url_2`='".$mysql['aff_campaign_url_2']."',
													  `aff_campaign_url_3`='".$mysql['aff_campaign_url_3']."',
													  `aff_campaign_url_4`='".$mysql['aff_campaign_url_4']."',
													  `aff_campaign_url_5`='".$mysql['aff_campaign_url_5']."',
													  `aff_campaign_rotate`='".$mysql['aff_campaign_rotate']."',
													  `aff_campaign_payout`='".$mysql['aff_campaign_payout']."',
													  `aff_campaign_cloaking`='".$mysql['aff_campaign_cloaking']."',
													  `aff_campaign_time`='".$mysql['aff_campaign_time']."'";
													  
		if ($editing == true) { $aff_campaign_sql  .= "WHERE `aff_campaign_id`='".$mysql['aff_campaign_id']."'"; } 
		$aff_campaign_result = mysql_query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
		$add_success = true;
		
		if ($editing != true) {
			//if this landing page is brand new, add on a landing_page_id_public
			$aff_campaign_row['aff_campaign_id'] = mysql_insert_id();
			$aff_campaign_id_public = rand(1,9) . $aff_campaign_row['aff_campaign_id'] . rand(1,9);
			$mysql['aff_campaign_id_public'] = mysql_real_escape_string($aff_campaign_id_public);
            	$mysql['aff_campaign_id'] = mysql_real_escape_string($aff_campaign_row['aff_campaign_id']);                            
			
			$aff_campaign_sql = "	UPDATE       `202_aff_campaigns`
								 	SET          	 `aff_campaign_id_public`='".$mysql['aff_campaign_id_public']."'
								 	WHERE        `aff_campaign_id`='".$mysql['aff_campaign_id']."'";
			$aff_campaign_result = mysql_query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
			
        } 
        
	}
}

if (isset($_GET['delete_aff_campaign_id'])) { 

	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$mysql['aff_campaign_id'] = mysql_real_escape_string($_GET['delete_aff_campaign_id']);
	$mysql['date_deleted'] = time();
	
	$delete_sql = " UPDATE  `202_aff_campaigns`
					SET     `aff_campaign_deleted`='1',
							`aff_campaign_time`='".$mysql['aff_campaign_time']."'
					WHERE   `user_id`='".$mysql['user_id']."'
					AND     `aff_campaign_id`='".$mysql['aff_campaign_id']."'";
	if ($delete_result = mysql_query($delete_sql) or record_mysql_error($delete_result)) {
		$delete_success = true;
	}
}

if ($_GET['edit_aff_campaign_id']) { 
	
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$mysql['aff_campaign_id'] = mysql_real_escape_string($_GET['edit_aff_campaign_id']);
	
	$aff_campaign_sql = "SELECT 	* 
						 FROM   	`202_aff_campaigns`
						 WHERE  	`aff_campaign_id`='".$mysql['aff_campaign_id']."'
						 AND    		`user_id`='".$mysql['user_id']."'";
	$aff_campaign_result = mysql_query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
	$aff_campaign_row = mysql_fetch_assoc($aff_campaign_result);
	
	$selected['aff_network_id'] = $aff_campaign_row['aff_network_id'];   
	$html = array_map('htmlentities', $aff_campaign_row);
	$html['aff_campaign_id'] = htmlentities($_GET['edit_aff_campaign_id'], ENT_QUOTES, 'UTF-8');    

} 

//this will override the edit, if posting and edit fail
if (($_SERVER['REQUEST_METHOD'] == 'POST') and ($add_success != true)) {
	
	$selected['aff_network_id'] = $_POST['aff_network_id'];   
	$html = array_map('htmlentities', $_POST);
}

template_top('Affiliate Campaigns Setup',NULL,NULL,NULL); ?>

<div id="info">
    <h2>Affiliate Campaign Setup</h2>
    Add the affiliate network campaigns you want to run.  <a class="onclick_color" onclick="Effect.toggle('helper','appear')">[help]</a>
    
    <div style="display: none;" id="helper">
    <br/>Please make sure to enter the campaign url in so that the subid can be inserted after it. If you do not understand how subids work at your network, stop, and contact your affiliate manager about how to add subids to your affiliate links. You may also contact us and we will help you out as well. <br/><br/>Tracking202 supports the ability to cloak your traffic; cloaking will prevent your advertisers and the affiliate networks who you work with from seeing your keywords. Please note if you are doing direct linking with Google Adwords, a cloaked direct linking setup can kill your qualitly score.
    Don't understand cloaking? Leave it off for now and learn more about it in our help section later.</div>
</div>

<table cellspacing="3" cellpadding="3" class="setup">
	<tr valign="top">
        <td>  
			<?php if ($error) { ?>
				<div class="warning"><div><h3>There were errors with your submission.</h3></div></div>
			<?php } echo $error['token']; ?>

			<?php if ($add_success == true) { ?>
				<div class="success"><div><h3>Your submission was successful</h3>Your changes were made succesfully.</div></div>
			<?php } ?>

			<?php if ($delete_success == true) { ?>
				<div class="success"><div><h3>You deletion was successful</h3>You have succesfully removed a campaign.</div></div>
			<?php } ?>
			<form method="post" action="<?php if ($delete_success == true) { echo $_SERVER['REDIRECT_URL']; }?>" style>
				<input name="aff_campaign_id" type="hidden" value="<?php echo$html['aff_campaign_id']; ?>"/>	
				<table>
					<tr>
						<td colspan="2">
							<h2 class="green">Add A Campaign</h2>
							<p style="text-align: justify;">Here you add each of the affiliate campaigns you are promoting.</p>  
						</td>
					</tr>
					<tr><td/><br/></tr>
					<tr>
						<td class="left_caption">Affiliate Network</td>
						<td>
							<select name="aff_network_id">
								<option value=""> -- </option>
								<?  $mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
									$aff_network_sql = "
										SELECT *
										FROM `202_aff_networks`
										WHERE `user_id`='".$mysql['user_id']."'
										AND `aff_network_deleted`='0'
										ORDER BY `aff_network_name` ASC
									";
									$aff_network_result = mysql_query($aff_network_sql) or record_mysql_error($aff_network_sql);

									while ($aff_network_row = mysql_fetch_array($aff_network_result, MYSQL_ASSOC)) {
										
										$html['aff_network_name'] = htmlentities($aff_network_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
										$html['aff_network_id'] = htmlentities($aff_network_row['aff_network_id'], ENT_QUOTES, 'UTF-8');
										
										if ($selected['aff_network_id'] == $aff_network_row['aff_network_id']) {
											 printf('<option selected="selected" value="%s">%s</option>', $html['aff_network_id'],$html['aff_network_name']);  
										} else {
											printf('<option value="%s">%s</option>', $html['aff_network_id'],$html['aff_network_name']);
										}
									} ?>
							</select>
							<?php echo$error['pcc_network_id']; ?>
						</td>
					</tr>
					<tr>
						<td class="left_caption">Campaign Name</td>
						<td>
							<input type="text" name="aff_campaign_name" value="<?php echo$html['aff_campaign_name']; ?>" style="display: inline;"/>
						</td>
					</tr>
					<tr>
						<td class="left_caption">Rotate Urls</td>
						<td>
							<input type="radio" name="aff_campaign_rotate" value="0" onClick="showAllRotatingUrls('false');" <?php if ($html['aff_campaign_rotate'] == 0) echo ' CHECKED '; ?>> No
							<span style="padding-left: 10px;"><input type="radio" name="aff_campaign_rotate" value="1" onClick="showAllRotatingUrls('true');" <?php if ($html['aff_campaign_rotate'] == 1) echo ' CHECKED '; ?>> Yes</span>
							
							<script type="text/javascript">
								function showAllRotatingUrls( bool ) { 
								
									if ( bool == 'true') { 
									
										document.getElementById('rotateUrl2').style.display = 'table-row';
										document.getElementById('rotateUrl3').style.display = 'table-row';
										document.getElementById('rotateUrl4').style.display = 'table-row';
										document.getElementById('rotateUrl5').style.display = 'table-row';
										
									} else {
										
										document.getElementById('rotateUrl2').style.display = 'none';
										document.getElementById('rotateUrl3').style.display = 'none';
										document.getElementById('rotateUrl4').style.display = 'none';
										document.getElementById('rotateUrl5').style.display = 'none'; 
									}
								}
							</script>
						</td>
					</tr>
					<tr>
						<td class="left_caption" style="vertical-align:top">Affiliate URL <a class="onclick_color" onclick="alert('This your affiliate link for the campaign. If you do not know how to track subids or what a subid is, ask your affiliate manager before moving forward. If you do not set up subids properly, your campaigns will not track!');"> [?] </a></td>
						<td style="white-space: nowrap;">
							<input type="text" name="aff_campaign_url" value="<?php echo$html['aff_campaign_url']; ?>" style="width: 200px; display: inline;"/>
							<div>
								The following tracking placeholders can be used:<br/>
								[[subid]], [[c1]], [[c2]], [[c3]], [[c4]]
							</div>
						</td>
					</tr>
					<tr id="rotateUrl2" <?php if ($html['aff_campaign_rotate'] == 0) echo ' style="display:none;" '; ?>>
						<td class="left_caption" >Rotate Url #2</td>
						<td><input type="text" name="aff_campaign_url_2" value="<?php echo$html['aff_campaign_url_2']; ?>" style="width: 200px; display: inline;"/></td>
					</tr>
					<tr id="rotateUrl3" <?php if ($html['aff_campaign_rotate'] == 0) echo ' style="display:none;" '; ?>>
						<td class="left_caption" >Rotate Url #3</td>
						<td><input type="text" name="aff_campaign_url_3" value="<?php echo$html['aff_campaign_url_3']; ?>" style="width: 200px; display: inline;"/></td>
					</tr>
					<tr id="rotateUrl4" <?php if ($html['aff_campaign_rotate'] == 0) echo ' style="display:none;" '; ?>>
						<td class="left_caption" >Rotate Url #4</td>
						<td><input type="text" name="aff_campaign_url_4" value="<?php echo$html['aff_campaign_url_4']; ?>" style="width: 200px; display: inline;"/></td>
					</tr>
					<tr id="rotateUrl5" <?php if ($html['aff_campaign_rotate'] == 0) echo ' style="display:none;" '; ?>>
						<td class="left_caption" >Rotate Url #5</td>
						<td><input type="text" name="aff_campaign_url_5" value="<?php echo$html['aff_campaign_url_5']; ?>" style="width: 200px; display: inline;"/></td>
					</tr>
	
					<tr>
						<td class="left_caption">Payout $ </td>
						<td>
							<input type="text" name="aff_campaign_payout" size="4" value="<?php echo$html['aff_campaign_payout']; ?>" style="display: inline;"/>
						</td>
					</tr>
					<tr>
                        <td class="left_caption">Cloaking</td>
						<td style="white-space: nowrap;">
							<select name="aff_campaign_cloaking">
								<option <?php if ($html['aff_campaign_cloaking'] == '0') { echo 'selected=""'; } ?> value="0">Off by default</option>
								<option <?php if ($html['aff_campaign_cloaking'] == '1') { echo 'selected=""'; } ?> value="1">On by default</option>
							</select>
						</td>
					</tr>
					<tr>
						<td/>
						<td>
							<input type="submit" value="<?php if ($editing == true) { echo 'Edit'; } else { echo 'Add'; } ?>" style="display: inline;"/>
							<?php if ($editing == true) { ?>
								<input type="submit" value="Cancel" style="display: inline; margin-left: 10px;" onclick="window.location='/tracking202/setup/aff_campaigns.php'; return false; "/>   
							<?php } ?>
						</td>
					</tr>
				</table>
				<?php echo$error['aff_network_id']; ?>
				<?php echo$error['aff_campaign_name']; ?>
				<?php echo$error['aff_campaign_url']; ?>
				<?php echo$error['aff_campaign_payout']; ?>
				<?php echo$error['wrong_user']; ?>
				<?php echo$error['cloaking_url']; ?>  
			</form>
			
			
		</td>
		<td class="setup-right">   
			<h2 class="green">My Campaigns</h2>
			
			<ul>        
			<?  $mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
				$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='".$mysql['user_id']."' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";
				$aff_network_result = mysql_query($aff_network_sql) or record_mysql_error($aff_network_sql);
				if (mysql_num_rows($aff_network_result) == 0 ) { 
					?><li>You have not added any networks.</li><?
				}
				
				while ($aff_network_row = mysql_fetch_array($aff_network_result, MYSQL_ASSOC)) {
					$html['aff_network_name'] = htmlentities($aff_network_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
					$url['aff_network_id'] = urlencode($aff_network_row['aff_network_id']);
					
					printf('<li>%s</li>', $html['aff_network_name']);
					
					?><ul style="margin-top: 0px;"><?
										
						//print out the individual accounts per each PPC network
						$mysql['aff_network_id'] = mysql_real_escape_string($aff_network_row['aff_network_id']);
						$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `aff_network_id`='".$mysql['aff_network_id']."' AND `aff_campaign_deleted`='0' ORDER BY `aff_campaign_name` ASC";
						$aff_campaign_result = mysql_query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
						 
						while ($aff_campaign_row = mysql_fetch_array($aff_campaign_result, MYSQL_ASSOC)) {
							
							$html['aff_campaign_name'] = htmlentities($aff_campaign_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
							$html['aff_campaign_payout'] = htmlentities($aff_campaign_row['aff_campaign_payout'], ENT_QUOTES, 'UTF-8');
							$html['aff_campaign_url'] = htmlentities($aff_campaign_row['aff_campaign_url'], ENT_QUOTES, 'UTF-8');
							$html['aff_campaign_id'] = htmlentities($aff_campaign_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');

							printf('<li>%s &middot; &#36;%s - <a href="%s" target="_new" style="font-size: 9px;">link</a> - <a href="?edit_aff_campaign_id=%s" style="font-size: 9px;">edit</a> - <a href="?delete_aff_campaign_id=%s" style="font-size: 9px;">remove</a></li>', $html['aff_campaign_name'], $html['aff_campaign_payout'], $html['aff_campaign_url'], $html['aff_campaign_id'], $html['aff_campaign_id']);
						
						} 
					
					?></ul><?
					
				} ?>
			</ul>
		</td>
	</tr>
</table>
		
<?php template_bottom();