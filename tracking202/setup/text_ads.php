<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

if ($_GET['edit_text_ad_id']) { 
	$editing = true; 
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	if ($_POST['text_ad_type'] == 0) { 
		
		//text ad type
		$aff_campaign_id = trim($_POST['aff_campaign_id']);
		if (empty($aff_campaign_id)) { $error['aff_campaign_id'] = '<div class="error">What campaign is this advertisement for?</div>'; }
	
	
		//check to see if they are the owners of this affiliate network
		$mysql['aff_campaign_id'] = mysql_real_escape_string($_POST['aff_campaign_id']);
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `user_id`='".$mysql['user_id']."' AND `aff_campaign_id`='".$mysql['aff_campaign_id']."'";
		$aff_campaign_result = mysql_query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
		if (mysql_num_rows($aff_campaign_result) == 0 ) {
			$error['wrong_user'] = '<div class="error">You are not authorized to add an campaign to another users network</div>';    
		}
	
	}
	
	if ($_POST['text_ad_type'] == 1) { 
		$landing_page_id = trim($_POST['landing_page_id']);
		if (empty($landing_page_id)) { $error['landing_page_id'] = '<div class="error">Please select a landing page.</div>'; }
	}
		
		
	$text_ad_name = trim($_POST['text_ad_name']);
	if (empty($text_ad_name)) { $error['text_ad_name'] = '<div class="error">Give this ad variation a nickname</div>'; }
	
	$text_ad_headline = trim($_POST['text_ad_headline']);
	if (empty($text_ad_headline)) { $error['text_ad_headline'] = '<div class="error">What is your ad headline?</div>'; }
	
	$text_ad_description = trim($_POST['text_ad_description']);
	if (empty($text_ad_description)) { $error['text_ad_description'] = '<div class="error">What is your ad description?</div>'; }
	
	$text_ad_display_url = trim($_POST['text_ad_display_url']);
	if (empty($text_ad_display_url)) { $error['text_ad_display_url'] = '<div class="error">What is your ad display URL?</div>'; }
	

	
	//if editing, check to make sure the own the campaign they are editing
	if ($editing == true) {
		$mysql['text_ad_id'] = mysql_real_escape_string($_POST['text_ad_id']);
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$ad_varation_sql = "SELECT * FROM `202_text_ads` WHERE `user_id`='".$mysql['user_id']."' AND `text_ad_id`='".$mysql['text_ad_id']."'";
		$text_ad_result = mysql_query($ad_varation_sql) or record_mysql_error($ad_varation_sql);
		if (mysql_num_rows($text_ad_result) == 0 ) {
			$error['wrong_user'] .= '<div class="error">You are not authorized to modify another users campaign</div>';    
		}
	}
	
	if (!$error) { 
		$mysql['text_ad_id'] = mysql_real_escape_string($_POST['text_ad_id']);
		$mysql['text_ad_type'] = mysql_real_escape_string($_POST['text_ad_type']);
		$mysql['landing_page_id'] = mysql_real_escape_string($_POST['landing_page_id']);
		$mysql['aff_campaign_id'] = mysql_real_escape_string($_POST['aff_campaign_id']);
		$mysql['text_ad_name'] = mysql_real_escape_string($_POST['text_ad_name']);
		$mysql['text_ad_headline'] = mysql_real_escape_string($_POST['text_ad_headline']);
		$mysql['text_ad_description'] = mysql_real_escape_string($_POST['text_ad_description']);
		$mysql['text_ad_display_url'] = mysql_real_escape_string($_POST['text_ad_display_url']);
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$mysql['text_ad_time'] = mysql_real_escape_string(time());
		
		if ($editing == true) { $text_ad_sql  = "UPDATE `202_text_ads` SET"; } 
		else {                  $text_ad_sql  = "INSERT INTO `202_text_ads` SET"; }
		
								$text_ad_sql .= "     `aff_campaign_id`='".$mysql['aff_campaign_id']."',
													  `text_ad_type`='".$mysql['text_ad_type']."',
													  `landing_page_id`='".$mysql['landing_page_id']."',
													  `text_ad_name`='".$mysql['text_ad_name']."',
													  `text_ad_headline`='".$mysql['text_ad_headline']."',
													  `text_ad_description`='".$mysql['text_ad_description']."',
													  `text_ad_display_url`='".$mysql['text_ad_display_url']."',
													  `user_id`='".$mysql['user_id']."',
													  `text_ad_time`='".$mysql['text_ad_time']."'";
													  
		if ($editing == true) { $text_ad_sql  .= "WHERE `text_ad_id`='".$mysql['text_ad_id']."'"; } 
		$text_ad_result = mysql_query($text_ad_sql) or record_mysql_error($text_ad_sql);
		$add_success = true;

		//if the edit worked ok redirec them
		if ($editing == true) {
			header('location: /tracking202/setup/text_ads.php');   
			
		}
		
		$editing = false;
		
		
	}
}

if (isset($_GET['delete_text_ad_id'])) { 

	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$mysql['text_ad_id'] = mysql_real_escape_string($_GET['delete_text_ad_id']);
	$mysql['text_ad_time'] = time();
	
	$delete_sql = " UPDATE  `202_text_ads`
					SET     `text_ad_deleted`='1',
							`text_ad_time`='".$mysql['text_ad_time']."'
					WHERE   `user_id`='".$mysql['user_id']."'
					AND     `text_ad_id`='".$mysql['text_ad_id']."'";
	if ($delete_result = mysql_query($delete_sql) or record_mysql_error($delete_result)) {
		$delete_success = true;
	}
}

if ($_GET['edit_text_ad_id']) { 
	
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$mysql['text_ad_id'] = mysql_real_escape_string($_GET['edit_text_ad_id']);
	
	$text_ad_sql = "SELECT * 
						 FROM   `202_text_ads`
						 WHERE  `text_ad_id`='".$mysql['text_ad_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
	$text_ad_result = mysql_query($text_ad_sql) or record_mysql_error($text_ad_sql);
	$text_ad_row = mysql_fetch_assoc($text_ad_result);
	

	$mysql['aff_campaign_id'] = mysql_real_escape_string($text_ad_row['aff_campaign_id']);
	$html['landing_page_id'] = htmlentities($text_ad_row['landing_page_id'], ENT_QUOTES, 'UTF-8');    
	$html['text_ad_type'] = htmlentities($text_ad_row['text_ad_type'], ENT_QUOTES, 'UTF-8');    
	$html['aff_campaign_id'] = htmlentities($text_ad_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');    
	$html['text_ad_id'] = htmlentities($_GET['edit_text_ad_id'], ENT_QUOTES, 'UTF-8');    
	$html['text_ad_name'] = htmlentities($text_ad_row['text_ad_name'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_headline'] = htmlentities($text_ad_row['text_ad_headline'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_description'] = htmlentities($text_ad_row['text_ad_description'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_display_url'] = htmlentities($text_ad_row['text_ad_display_url'], ENT_QUOTES, 'UTF-8');
	 

} elseif ($_GET['copy_text_ad_id']) { 
	
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$mysql['text_ad_id'] = mysql_real_escape_string($_GET['copy_text_ad_id']);
	
	$text_ad_sql = "SELECT * 
						 FROM   `202_text_ads`
						 WHERE  `text_ad_id`='".$mysql['text_ad_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
	$text_ad_result = mysql_query($text_ad_sql) or record_mysql_error($text_ad_sql);
	$text_ad_row = mysql_fetch_assoc($text_ad_result);
	
	$html['text_ad_type'] = htmlentities($text_ad_row['text_ad_type'], ENT_QUOTES, 'UTF-8');
	$html['landing_page_id'] = htmlentities($text_ad_row['landing_page_id'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_name'] = htmlentities($text_ad_row['text_ad_name'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_headline'] = htmlentities($text_ad_row['text_ad_headline'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_description'] = htmlentities($text_ad_row['text_ad_description'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_display_url'] = htmlentities($text_ad_row['text_ad_display_url'], ENT_QUOTES, 'UTF-8');
	 

} elseif (($_SERVER['REQUEST_METHOD'] == 'POST') and ($add_success != true)) {
	
	$mysql['aff_campaign_id'] = mysql_real_escape_string($_POST['aff_campaign_id']);
   	$html['aff_campaign_id'] = htmlentities($_POST['aff_campaign_id'], ENT_QUOTES, 'UTF-8');
    
    	$html['text_ad_type'] = htmlentities($_POST['text_ad_type'], ENT_QUOTES, 'UTF-8');   
	$html['landing_page_id'] = htmlentities($_POST['landing_page_id'], ENT_QUOTES, 'UTF-8');   
	$html['aff_network_id'] = htmlentities($_POST['aff_network_id'], ENT_QUOTES, 'UTF-8');   
	$html['text_ad_id'] = htmlentities($_POST['text_ad_id'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_name'] = htmlentities($_POST['text_ad_name'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_headline'] = htmlentities($_POST['text_ad_headline'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_description'] = htmlentities($_POST['text_ad_description'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_display_url'] = htmlentities($_POST['text_ad_display_url'], ENT_QUOTES, 'UTF-8');
	
}

if ((($editing == true) or ($add_success != true)) and ($mysql['aff_campaign_id'])) {
    //now grab the affiliate network id, per that aff campaign id
    $aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `aff_campaign_id`='".$mysql['aff_campaign_id']."'";
    $aff_campaign_result = mysql_query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
    $aff_campaign_row = mysql_fetch_assoc($aff_campaign_result);

    $mysql['aff_network_id'] = mysql_real_escape_string($aff_campaign_row['aff_network_id']);
    $aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `aff_network_id`='".$mysql['aff_network_id']."'";
    $aff_network_result = mysql_query($aff_network_sql) or record_mysql_error($aff_network_sql);
    $aff_network_row = mysql_fetch_assoc($aff_network_result);

    $html['aff_network_id'] = htmlentities($aff_network_row['aff_network_id'], ENT_QUOTES, 'UTF-8');
}

template_top('Text Ads Setup',NULL,NULL,NULL);  ?>
		
<div id="info">
    <h2>Text Ad Setup (optional)</h2>
	Here is where you enter in your text ad information. If you have too many text-ads and do not want to enter them all, you can skip this step.
</div>

<table cellspacing="0" cellpadding="0" class="setup">
    <tr valign="top">
        <td>
			<? if ($error) { ?>
				<div class="warning"><div><h3>There were errors with your submission.</h3></div></div>
			<? } echo $error['token']; ?>

			<? if ($add_success == true) { ?>
				<div class="success"><div><h3>Your submission was successful</h3>Your changes were made succesfully.</div></div>
			<? } ?>

			<? if ($delete_success == true) { ?>
				<div class="success"><div><h3>You deletion was successful</h3>You have succesfully removed a campaign.</div></div>
			<? } ?>
			
			<form method="post" action="<? if ($delete_success == true) { echo $_SERVER['REDIRECT_URL']; }?>" style>
				<input name="text_ad_id" type="hidden" value="<? echo $html['text_ad_id']; ?>"/>	
				<table>
					<tr valign="top">
						<td colspan="2">
							<h2 class="green">Add Your Text Ads</h2>
							<p style="text-align: justify;">Here you can add different text ads you might use with your PPC marketing.</p>  
						</td>
					</tr>
			
			<tr><td/><br/></tr>
					
			<tr valign="top">
				<td class="left_caption">Text Ad For</td>	
				<td>
					<input type="radio" name="text_ad_type" value="0" <? if ($html['text_ad_type'] == '0' or !$html['text_ad_type']) { echo ' CHECKED '; }  ?> onClick="text_ad_select(this.value);"> Direct Link Setup, or Simple Landing Page Setup<br/>
					<input type="radio" name="text_ad_type" value="1" <? if ($html['text_ad_type'] == '1') { echo ' CHECKED '; } ?> onClick="text_ad_select(this.value);"> Advanced Landing Page Setup
					<? echo $error['landing_page_type']; ?>
				</td>
			</tr>
			<tr>
				<td colspan="2"><hr/></td>
			</tr>
						<tr id="lp_landing_page" <? if (($html['text_ad_type'] == '0') or (!$html['text_ad_type'])) { echo ' style="display:none;"'; } ?>>
				<td class="left_caption">Landing Page</td>
				<td>
					<img id="landing_page_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="landing_page_div" style="display: none;"></div>
					<? echo $error['landing_page_id']; ?>
				</td>
			</tr>
			
				<tr id="lp_aff_network" <? if ($html['text_ad_type'] == '1') { echo ' style="display:none;"'; } ?>>
						<td class="left_caption">Aff Network</td>
						<td>
							<img id="aff_network_id_div_loading" src="/202-img/loader-small.gif"/>
							<div id="aff_network_id_div" style="display: inline;"></div>
                        </td>
					</tr>
					<tr id="lp_aff_campaign" <? if ($html['text_ad_type'] == '1') { echo ' style="display:none;"'; } ?>>
						<td class="left_caption">Aff Campaign</td>
                        <td>
							<img id="aff_campaign_id_div_loading" src="/202-img/loader-small.gif" style="display: none;"/>
							<div id="aff_campaign_id_div" style="display: inline;"></div>
							<? echo $error['aff_campaign_id']; ?>
						</td>
					</tr>
					<tr valign="top">
						<td class="left_caption">Ad Nickname <a class="onclick_color" onclick="alert('The ad nickname is the nickname we store for you, this is used for when you have several ads, you can quickly find the ones you are looking for by assigning each ad a unique nickname.');">?</a></td>
						<td>
							<input type="text" name="text_ad_name" value="<? echo $html['text_ad_name']; ?>" style="width: 200px;"/>
							<? echo $error['text_ad_name']; ?>
						</td>
					</tr>
					<tr valign="top">
						<td class="left_caption">Ad Preview</td>
						<td>
							<table class="ad_copy" cellspacing="0" cellpadding="3">
								<tr>
									<td valign="bottom">
										<div id="preview_headline" class="ad_copy_headline"><? if ($html['text_ad_headline']) { echo $html['text_ad_headline']; } else { echo 'Luxury Cruise to Mars'; } ?></div>
										<div id="preview_description" class="ad_copy_description"><? if ($html['text_ad_description']) { echo $html['text_ad_description']; } else { echo 'Visit the Red Planet in style. Low-gravity fun for everyone!'; } ?></div>
										<div id="preview_display_url" class="ad_copy_display_url"><? if ($html['text_ad_display_url']) { echo $html['text_ad_display_url']; } else { echo 'www.example.com'; } ?></div>
									</td>
								</tr>
							</table>
						</td>
					</tr>
					<tr valign="top">
						<td class="left_caption">Ad Headline</td>
						<td>
							<input type="text" name="text_ad_headline" style="width: 200px;" onkeyup="document.getElementById('preview_headline').innerHTML=this.value; if (document.getElementById('preview_headline').innerHTML=='') { document.getElementById('preview_headline').innerHTML='Luxury Cruise to Mars'; }" onchange="document.getElementById('preview_headline').innerHTML=this.value; if (document.getElementById('preview_headline').innerHTML=='') { document.getElementById('preview_headline').innerHTML='Luxury Cruise to Mars'; }" value="<? echo $html['text_ad_headline']; ?>"/>
							<? echo $error['text_ad_headline']; ?>
						</td>  
					</tr>
					<tr valign="top">
						<td class="left_caption">Ad Description</td>
						<td>
							<textarea name="text_ad_description" style="width: 200px; height: 50px;"  onkeyup="document.getElementById('preview_description').innerHTML=this.value; if (document.getElementById('preview_description').innerHTML=='') { document.getElementById('preview_description').innerHTML='Visit the Red Planet in style. Low-gravity fun for everyone!'; }" onchange="document.getElementById('preview_description').innerHTML=this.value; if (document.getElementById('preview_description').innerHTML=='') { document.getElementById('preview_description').innerHTML='Visit the Red Planet in style. Low-gravity fun for everyone!'; }"><? echo $html['text_ad_description']; ?></textarea>
							<? echo $error['text_ad_description']; ?>
						</td>
					</tr>
					<tr valign="top">
						<td class="left_caption">Display URL</td>
						<td>
							<input type="text" name="text_ad_display_url" style="width: 200px; display: inline;" onkeyup="document.getElementById('preview_display_url').innerHTML=this.value; if (document.getElementById('preview_display_url').innerHTML=='') { document.getElementById('preview_display_url').innerHTML='www.example.com'; }" onchange="document.getElementById('preview_display_url').innerHTML=this.value; if (document.getElementById('preview_display_url').innerHTML=='') { document.getElementById('preview_display_url').innerHTML='www.example.com'; }" value="<? echo $html['text_ad_display_url']; ?>"/>
							<? echo $error['text_ad_display_url']; ?>
						</td>
					</tr>                               
					<tr valign="top">
						<td/>
						<td>
							<input type="submit" value="<? if ($editing == true) { echo 'Edit'; } else { echo 'Add'; } ?>"/>
							<? if ($editing == true or $_GET['copy_text_ad_id'] != '') { ?>
								<button  style="display: inline; margin-left: 10px;" onclick="window.location='/tracking202/setup/text_ads.php'; return false; ">Cancel</button>   
							<? } ?> 
						</td>
					</tr>
				</table>
			</form>
			<? echo $error['text_ad_id']; ?>
			<? echo $error['wrong_user']; ?>  
		
		</td>
		<td class="setup-right">   
			<h2 class="green">Advanced Landing Page Text Ads</h2>
			<ul>        
				<? $mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
				$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND landing_page_type='1' AND landing_page_deleted='0'";
				$landing_page_result = mysql_query($landing_page_sql) or record_mysql_error($landing_page_sql);
				
				while ($landing_page_row = mysql_fetch_array($landing_page_result, MYSQL_ASSOC)) {
					$html['landing_page_nickname'] = htmlentities($landing_page_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
						
					printf('<li>%s</li>', $html['landing_page_nickname']);
						
					?><ul style="margin-top: 0px;"><? 
							
						$mysql['landing_page_id'] = mysql_real_escape_string($landing_page_row['landing_page_id']);
						$text_ad_sql = "SELECT * FROM `202_text_ads` WHERE `landing_page_id`='".$mysql['landing_page_id']."' AND `text_ad_deleted`='0' ORDER BY `text_ad_name` ASC";
						$text_ad_result = mysql_query($text_ad_sql) or record_mysql_error($text_ad_sql);
							
						while ($text_ad_row = mysql_fetch_array($text_ad_result, MYSQL_ASSOC)) {
									
							$html['text_ad_name'] = htmlentities($text_ad_row['text_ad_name'], ENT_QUOTES, 'UTF-8');
							$html['text_ad_id'] = htmlentities($text_ad_row['text_ad_id'], ENT_QUOTES, 'UTF-8');
									
							printf('<li>%s - <a href="?copy_text_ad_id=%s" style="font-size: 9px;">copy</a> - <a href="?edit_text_ad_id=%s" style="font-size: 9px;">edit</a> - <a href="?delete_text_ad_id=%s" style="font-size: 9px;">remove</a></li>', $html['text_ad_name'], $html['text_ad_id'], $html['text_ad_id'],  $html['text_ad_id']);
						
									
						}

					?></ul>
				<?	} ?>
				
			</ul>
			<br/><br/>
			<h2 class="green">Direct Link/Simple Landing Page Text Ads</h2>
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
						
							printf('<li>%s &middot; &#36;%s</li>', $html['aff_campaign_name'], $html['aff_campaign_payout']);
						
							?><ul style="margin-top: 0px;"><? 
							
								$mysql['aff_campaign_id'] = mysql_real_escape_string($aff_campaign_row['aff_campaign_id']);
								$text_ad_sql = "SELECT * FROM `202_text_ads` WHERE `aff_campaign_id`='".$mysql['aff_campaign_id']."' AND `text_ad_deleted`='0' ORDER BY `text_ad_name` ASC";
								$text_ad_result = mysql_query($text_ad_sql) or record_mysql_error($text_ad_sql);
								
								while ($text_ad_row = mysql_fetch_array($text_ad_result, MYSQL_ASSOC)) {
									
									$html['text_ad_name'] = htmlentities($text_ad_row['text_ad_name'], ENT_QUOTES, 'UTF-8');
									$html['text_ad_id'] = htmlentities($text_ad_row['text_ad_id'], ENT_QUOTES, 'UTF-8');
									
									printf('<li>%s - <a href="?copy_text_ad_id=%s" style="font-size: 9px;">copy</a> - <a href="?edit_text_ad_id=%s" style="font-size: 9px;">edit</a> - <a href="?delete_text_ad_id=%s" style="font-size: 9px;">remove</a></li>', $html['text_ad_name'], $html['text_ad_id'], $html['text_ad_id'],  $html['text_ad_id']);
						
									
								}

							?></ul><?						
						} 
					
					?></ul><?
					
				} ?>
			</ul>
		</td>
	</tr>				
</table>

<!-- open up the ajax aff network -->
<script type="text/javascript">
    
	load_landing_page(0, <? echo $html['landing_page_id']; if (!$html['landing_page_id']) { echo 0; } ?>, 'advlandingpage');

   load_aff_network_id('<? echo $html['aff_network_id']; ?>');
    <? if ($html['aff_network_id'] != '') { ?>
        load_aff_campaign_id('<? echo $html['aff_network_id']; ?>','<? echo $html['aff_campaign_id']; ?>');
    <? } ?>
    
	function text_ad_select(text_ad_type) {
		if (text_ad_type == '0') { 
			$('lp_landing_page').style.display = 'none';
			load_landing_page(0, 0, '');
			$('lp_aff_network').style.display = 'table-row';
			$('lp_aff_campaign').style.display = 'table-row';
		} else if (text_ad_type == '1') {
			$('lp_landing_page').style.display = 'table-row';
			load_landing_page(0, 0, 'advlandingpage');
			$('lp_aff_network').style.display = 'none';
			$('lp_aff_campaign').style.display = 'none';	
			load_aff_network_id(0);
			load_aff_campaign_id(0,0);
		}	
	}
		
</script>


		
<? template_bottom();