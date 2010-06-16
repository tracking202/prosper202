<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

if ($_GET['edit_landing_page_id']) { 
	$editing = true; 
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
   	if (($_POST['landing_page_type'] != '0') and ($_POST['landing_page_type'] != '1')) { $error['landing_page_type'] = '<div class="error">What type of landing page is this?</div>'; }

   	//if this is a simple landing page
   	if ($_POST['landing_page_type'] == '0') {
   		$aff_campaign_id = trim($_POST['aff_campaign_id']);
		if (empty($aff_campaign_id)) { $error['aff_campaign_id'] = '<div class="error">What campaign is this landingpage for?</div>'; }
   	}
   	
    	$landing_page_nickname = trim($_POST['landing_page_nickname']);
    	if (empty($landing_page_nickname)) { $error['landing_page_nickname'] = '<div class="error">Give this landing page a nickname</div>'; }
    
   	$landing_page_url = trim($_POST['landing_page_url']);
    	if (empty($landing_page_url)) { $error['landing_page_url'] = '<div class="error">What is the URL of your landing page?</div>'; }
    
    	if ((substr($_POST['landing_page_url'],0,7) != 'http://') and (substr($_POST['landing_page_url'],0,8) != 'https://')){
        	$error['landing_page_url'] .= '<div class="error">Your Landing Page URL must start with http:// or https://</div>';    
    	}
	
    	//if this is a simple landing page
    	if ($_POST['landing_page_type'] == '0') {
	    //check to see if they are the owners of this affiliate network
	    $mysql['aff_campaign_id'] = mysql_real_escape_string($_POST['aff_campaign_id']);
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `user_id`='".$mysql['user_id']."' AND `aff_campaign_id`='".$mysql['aff_campaign_id']."'";
	    $aff_campaign_result = mysql_query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
	    if (mysql_num_rows($aff_campaign_result) == 0 ) {
			$error['wrong_user'] = '<div class="error">You are not authorized to add a landing page to another users campaign</div>';    
	    }
    	}
    
    //if editing, check to make sure the own the campaign they are editing
    if ($editing == true) {
		$mysql['landing_page_id'] = mysql_real_escape_string($_POST['landing_page_id']);
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND `landing_page_id`='".$mysql['landing_page_id']."'";
        $landing_page_result = mysql_query($landing_page_sql) or record_mysql_error($landing_page_sql);
		if (mysql_num_rows($landing_page_result) == 0 ) {
            $error['wrong_user'] .= '<div class="error">You are not authorized to modify another users campaign</div>';    
        }
    }
	
	if (!$error) { 
	      $mysql['landing_page_id'] = mysql_real_escape_string($_POST['landing_page_id']);
	      $mysql['aff_campaign_id'] = mysql_real_escape_string($_POST['aff_campaign_id']);
	      $mysql['landing_page_nickname'] = mysql_real_escape_string($_POST['landing_page_nickname']);
	      $mysql['landing_page_url'] = mysql_real_escape_string($_POST['landing_page_url']);
		$mysql['landing_page_type'] = mysql_real_escape_string($_POST['landing_page_type']);
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$mysql['landing_page_time'] = time();
		
		if ($editing == true) { $landing_page_sql  = "UPDATE `202_landing_pages` SET"; } 
        	else {           $landing_page_sql  = "INSERT INTO `202_landing_pages` SET"; }
        
                                $landing_page_sql .= "`aff_campaign_id`='".$mysql['aff_campaign_id']."',
			                                                  `landing_page_nickname`='".$mysql['landing_page_nickname']."',
			                                                  `landing_page_url`='".$mysql['landing_page_url']."',
											  `landing_page_type`='".$mysql['landing_page_type']."',
											  `user_id`='".$mysql['user_id']."',
											  `landing_page_time`='".$mysql['landing_page_time']."'";
													  
		if ($editing == true) { $landing_page_sql  .= "WHERE `landing_page_id`='".$mysql['landing_page_id']."'"; } 
		$landing_page_result = mysql_query($landing_page_sql) or record_mysql_error($landing_page_sql);
		$add_success = true;
		
		if ($editing == true) {
			//if the edit completed, redirect to the page 
			header('location: /tracking202/setup/landing_pages.php');        
		}
		
		if ($editing != true) {
			//if this landing page is brand new, add on a landing_page_id_public
			$landing_page_row['landing_page_id'] = mysql_insert_id();
			$landing_page_id_public = rand(1,9) . $landing_page_row['landing_page_id'] . rand(1,9);
			$mysql['landing_page_id_public'] = mysql_real_escape_string($landing_page_id_public);
            	$mysql['landing_page_id'] = mysql_real_escape_string($landing_page_row['landing_page_id']);                            
			
			$landing_page_sql = "	UPDATE       `202_landing_pages`
								 	SET          	 `landing_page_id_public`='".$mysql['landing_page_id_public']."'
								 	WHERE        `landing_page_id`='".$mysql['landing_page_id']."'";
			$landing_page_result = mysql_query($landing_page_sql) or record_mysql_error($landing_page_sql);
			
        }
	}
}

if (isset($_GET['delete_landing_page_id'])) { 

	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
    	$mysql['landing_page_id'] = mysql_real_escape_string($_GET['delete_landing_page_id']);
	$mysql['landing_page_time'] = time();
	

	
    $delete_sql = " UPDATE  `202_landing_pages`
					SET     `landing_page_deleted`='1',
							`landing_page_time`='".$mysql['landing_page_time']."'
					WHERE   `user_id`='".$mysql['user_id']."'
					AND     `landing_page_id`='".$mysql['landing_page_id']."'";
    
    if ($delete_result = mysql_query($delete_sql) or record_mysql_error($delete_result)) {
        $delete_success = true;
    }
}

if (($_GET['edit_landing_page_id']) and ($_SERVER['REQUEST_METHOD'] != 'POST')) { 
	
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
    $mysql['landing_page_id'] = mysql_real_escape_string($_GET['edit_landing_page_id']);
    
	$landing_page_sql = "SELECT * 
                         FROM   `202_landing_pages`
                         WHERE  `landing_page_id`='".$mysql['landing_page_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
    $landing_page_result = mysql_query($landing_page_sql) or record_mysql_error($landing_page_sql);
	$landing_page_row = mysql_fetch_assoc($landing_page_result);
	
	$mysql['aff_campaign_id'] = mysql_real_escape_string($landing_page_row['aff_campaign_id']);
	$html['aff_campaign_id'] = htmlentities($landing_page_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');    
	$html['landing_page_id'] = htmlentities($_GET['edit_landing_page_id'], ENT_QUOTES, 'UTF-8');    
	$html['landing_page_type'] = htmlentities($landing_page_row['landing_page_type'], ENT_QUOTES, 'UTF-8');    
	$html['landing_page_nickname'] = htmlentities($landing_page_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
	$html['landing_page_url'] = htmlentities($landing_page_row['landing_page_url'], ENT_QUOTES, 'UTF-8');     

} elseif (($_SERVER['REQUEST_METHOD'] == 'POST') and ($add_success != true)) {
	
    	$mysql['aff_campaign_id'] = mysql_real_escape_string($_POST['aff_campaign_id']);
	$html['aff_network_id'] = htmlentities($_POST['aff_network_id'], ENT_QUOTES, 'UTF-8');
	$html['aff_network_id'] = htmlentities($_POST['aff_network_id'], ENT_QUOTES, 'UTF-8');
	$html['landing_page_type'] = htmlentities($_POST['landing_page_type'], ENT_QUOTES, 'UTF-8');
    	$html['landing_page_id'] = htmlentities($_POST['landing_page_id'], ENT_QUOTES, 'UTF-8');
    	$html['landing_page_nickname'] = htmlentities($_POST['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
    	$html['landing_page_url'] = htmlentities($_POST['landing_page_url'], ENT_QUOTES, 'UTF-8');
    
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

template_top($server_row,'Landing Page Setup',NULL,NULL,NULL);  ?>
		
<div id="info">
	<h2>Landing Page Setup (optional)</h2>
	Please type in the URL addresses of the landing pages you plan on using.  <a class="onclick_color" onclick="Effect.toggle('helper','appear')">[help]</a>
     <div style="display: none;" id="helper">
	<br/><br/><strong>A Simple Landing Page</strong> is a landing page that only has one offer associated with it.<br/><br/>
	Where as an <strong>Advanced Landing Page</strong> is a landing page that can run several offers on it, an example being a ringtone landing page.  Where you have outgoing links to several different carriers, which are really linked to different affiliate campaigns because some ringtone programs only payout on specific carriers.  So you have mutiple offers that someone can click through on your landing page.
	</div>
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
			<form method="post" action="<? if ($delete_success == true) { echo $_SERVER['REDIRECT_URL']; }?>">
                <input name="landing_page_id" type="hidden" value="<? echo $html['landing_page_id']; ?>"/>    
                <table>
                    <tr>
                        <td colspan="2">
					<h2 class="green">Add A Landing Page (optional)</h2>
                            	<p style="text-align: justify;">Here you can add different landing pages you might use with your ppc marketing.</p>  
				</td>
			</tr>
			<tr><td/><br/></tr>
					<tr valign="top">
				<td class="left_caption">Landing Page Type <a class="onclick_color" style="font-weight: normal; " onclick="alert('A Simple Landing Page is a landing page that only has one offer associated with it. Where as an Advanced Landing Page is a landing page that can run several offers on it, an example being a ringtone landing page.  Where you have outgoing links to several different carriers, which are really linked to different affiliate campaigns because some ringtone programs only payout on specific carriers.  So you have mutiple offers that someone can click through on your landing page.');">[?]</a></td>	
				<td>
					<input type="radio" name="landing_page_type" value="0" <? if ($html['landing_page_type'] == '0' or !$html['landing_page_type']) { echo ' CHECKED '; }  ?> onClick="landing_page_select(this.value);"> Simple (One Offer on the page)<br/>
					<input type="radio" name="landing_page_type" value="1" <? if ($html['landing_page_type'] == '1') { echo ' CHECKED '; } ?> onClick="landing_page_select(this.value);"> Advanced (Mutiple Offers on the page)	
					<? echo $error['landing_page_type']; ?>
				</td>
			</tr>
			<tr>
				<td colspan="2"><hr/></td>
			</tr>

			<tr id="lp_aff_network" <? if ($html['landing_page_type'] == '1') { echo ' style="display:none;"'; } ?>>
				<td class="left_caption">Aff Network</td>
				<td>
					<img id="aff_network_id_div_loading" src="/202-img/loader-small.gif"/>
					<div id="aff_network_id_div" style="display: inline;"></div>
				</td>
			</tr>
			<tr id="lp_aff_campaign" <? if ($html['landing_page_type'] == '1') { echo ' style="display:none;"'; } ?>>
				<td class="left_caption">Aff Campaign</td>
				<td>
					<img id="aff_campaign_id_div_loading" src="/202-img/loader-small.gif" style="display: none;"/>
					<div id="aff_campaign_id_div" style="display: inline;"></div>
				</td>
			</tr>

			<tr>
				<td class="left_caption">LP Nickname</td>
				<td>
					<input type="text" name="landing_page_nickname" value="<? echo $html['landing_page_nickname']; ?>" style="width: 200px;"/>
				</td>
			</tr>
			<tr>
				<td class="left_caption">Landing Page URL</td>
				<td><input type="text" name="landing_page_url" style="width: 200px; display: inline;" value="<? echo $html['landing_page_url']; ?>"/></td>
			</tr>                               
			<tr>
				<td/>
				<td>
					<input type="submit" value="<? if ($editing == true) { echo 'Edit'; } else { echo 'Add'; } ?>"/>
					<? if ($editing == true) { ?>
						<input type="submit" value="Cancel" style="display: inline; margin-left: 10px;" onclick="window.location='/tracking202/setup/landing_pages.php'; return false; "/>   
					<? } ?>
				</td>
			</tr>
		</table>
		<? echo $error['aff_campaign_id']; ?>
		<? echo $error['landing_page_id']; ?>
		<? echo $error['landing_page_nickname']; ?>
		<? echo $error['landing_page_url']; ?>
		<? echo $error['wrong_user']; ?>
	</form>
		
		</td>
		<td class="setup-right">  
			<h2 class="green">My Advanced Landing Pages</h2> 
			<ul style="margin-top: 0px;">
			 	<? $mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
				$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND landing_page_type='1' AND landing_page_deleted='0'";
				$landing_page_result = mysql_query($landing_page_sql) or record_mysql_error($landing_page_sql);
				
				while ($landing_page_row = mysql_fetch_array($landing_page_result, MYSQL_ASSOC)) {
					$html['landing_page_nickname'] = htmlentities($landing_page_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
                              $html['landing_page_id'] = htmlentities($landing_page_row['landing_page_id'], ENT_QUOTES, 'UTF-8');
                              printf('<li>%s - <a href="?edit_landing_page_id=%s" style="font-size: 9px;">edit</a> - <a href="?delete_landing_page_id=%s" style="font-size: 9px;">remove</a></li>', $html['landing_page_nickname'], $html['landing_page_id'], $html['landing_page_id']);
				} ?>
			</ul>
			<br/><br/>
			<h2 class="green">My Simple Landing Pages</h2>
			<ul>        
			<?  $mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
				$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='".$mysql['user_id']."' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";
                $aff_network_result = mysql_query($aff_network_sql) or record_mysql_error($aff_network_sql);
                if (mysql_num_rows($aff_network_result) == 0 ) { 
                    ?><li>You have not landing_pageded any networks.</li><?
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
								$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `aff_campaign_id`='".$mysql['aff_campaign_id']."' AND `landing_page_deleted`='0' AND landing_page_type='0'";
								$landing_page_result = mysql_query($landing_page_sql) or record_mysql_error($landing_page_sql);
								
                                while ($landing_page_row = mysql_fetch_array($landing_page_result, MYSQL_ASSOC)) {
									
									$html['landing_page_nickname'] = htmlentities($landing_page_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
                                    $html['landing_page_id'] = htmlentities($landing_page_row['landing_page_id'], ENT_QUOTES, 'UTF-8');
                                    
                                    printf('<li>%s - <a href="?edit_landing_page_id=%s" style="font-size: 9px;">edit</a> - <a href="?delete_landing_page_id=%s" style="font-size: 9px;">remove</a></li>', $html['landing_page_nickname'], $html['landing_page_id'], $html['landing_page_id']);
						
                                    
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
    
   load_aff_network_id('<? echo $html['aff_network_id']; ?>');
    <? if ($html['aff_network_id'] != '') { ?>
        load_aff_campaign_id('<? echo $html['aff_network_id']; ?>','<? echo $html['aff_campaign_id']; ?>');
    <? } ?>
</script>

				
					<script type="text/javascript">
						function landing_page_select(landing_page_type) {
							if (landing_page_type == '0') { 
								$('lp_aff_network').style.display = 'table-row';
								$('lp_aff_campaign').style.display = 'table-row';
							} else if (landing_page_type == '1') {
								$('lp_aff_network').style.display = 'none';
								$('lp_aff_campaign').style.display = 'none';	
								load_aff_network_id(0);
								load_aff_campaign_id(0,0);
							}	
						}
					</script>	
        
<? template_bottom($server_row);