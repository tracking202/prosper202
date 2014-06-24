<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

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
		$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
		$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `user_id`='".$mysql['user_id']."' AND `aff_campaign_id`='".$mysql['aff_campaign_id']."'";
		$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
		if ($aff_campaign_result->num_rows == 0 ) {
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
		$mysql['text_ad_id'] = $db->real_escape_string($_POST['text_ad_id']);
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
		$ad_varation_sql = "SELECT * FROM `202_text_ads` WHERE `user_id`='".$mysql['user_id']."' AND `text_ad_id`='".$mysql['text_ad_id']."'";
		$text_ad_result = $db->query($ad_varation_sql) or record_mysql_error($ad_varation_sql);
		if ($text_ad_result->num_rows == 0 ) {
			$error['wrong_user'] .= '<div class="error">You are not authorized to modify another users campaign</div>';    
		}
	}
	
	if (!$error) { 
		$mysql['text_ad_id'] = $db->real_escape_string($_POST['text_ad_id']);
		$mysql['text_ad_type'] = $db->real_escape_string($_POST['text_ad_type']);
		$mysql['landing_page_id'] = $db->real_escape_string($_POST['landing_page_id']);
		$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);
		$mysql['text_ad_name'] = $db->real_escape_string($_POST['text_ad_name']);
		$mysql['text_ad_headline'] = $db->real_escape_string($_POST['text_ad_headline']);
		$mysql['text_ad_description'] = $db->real_escape_string($_POST['text_ad_description']);
		$mysql['text_ad_display_url'] = $db->real_escape_string($_POST['text_ad_display_url']);
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
		$mysql['text_ad_time'] = $db->real_escape_string(time());
		
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
		$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
		$add_success = true;

		//if the edit worked ok redirec them
		if ($editing == true) {
			header('location: /tracking202/setup/text_ads.php');   
			
		}
		
		$editing = false;
		
		
	}
}

if (isset($_GET['delete_text_ad_id'])) { 

	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['text_ad_id'] = $db->real_escape_string($_GET['delete_text_ad_id']);
	$mysql['text_ad_time'] = time();
	
	$delete_sql = " UPDATE  `202_text_ads`
					SET     `text_ad_deleted`='1',
							`text_ad_time`='".$mysql['text_ad_time']."'
					WHERE   `user_id`='".$mysql['user_id']."'
					AND     `text_ad_id`='".$mysql['text_ad_id']."'";
	if ($delete_result = $db->query($delete_sql) or record_mysql_error($delete_result)) {
		$delete_success = true;
	}
}

if ($_GET['edit_text_ad_id']) { 
	
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['text_ad_id'] = $db->real_escape_string($_GET['edit_text_ad_id']);
	
	$text_ad_sql = "SELECT * 
						 FROM   `202_text_ads`
						 WHERE  `text_ad_id`='".$mysql['text_ad_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
	$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
	$text_ad_row = $text_ad_result->fetch_assoc();
	

	$mysql['aff_campaign_id'] = $db->real_escape_string($text_ad_row['aff_campaign_id']);
	$html['landing_page_id'] = htmlentities($text_ad_row['landing_page_id'], ENT_QUOTES, 'UTF-8');    
	$html['text_ad_type'] = htmlentities($text_ad_row['text_ad_type'], ENT_QUOTES, 'UTF-8');    
	$html['aff_campaign_id'] = htmlentities($text_ad_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');    
	$html['text_ad_id'] = htmlentities($_GET['edit_text_ad_id'], ENT_QUOTES, 'UTF-8');    
	$html['text_ad_name'] = htmlentities($text_ad_row['text_ad_name'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_headline'] = htmlentities($text_ad_row['text_ad_headline'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_description'] = htmlentities($text_ad_row['text_ad_description'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_display_url'] = htmlentities($text_ad_row['text_ad_display_url'], ENT_QUOTES, 'UTF-8');
	 

} elseif ($_GET['copy_text_ad_id']) { 
	
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['text_ad_id'] = $db->real_escape_string($_GET['copy_text_ad_id']);
	
	$text_ad_sql = "SELECT * 
						 FROM   `202_text_ads`
						 WHERE  `text_ad_id`='".$mysql['text_ad_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
	$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
	$text_ad_row = $text_ad_result->fetch_assoc();
	
	$html['text_ad_type'] = htmlentities($text_ad_row['text_ad_type'], ENT_QUOTES, 'UTF-8');
	$html['landing_page_id'] = htmlentities($text_ad_row['landing_page_id'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_name'] = htmlentities($text_ad_row['text_ad_name'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_headline'] = htmlentities($text_ad_row['text_ad_headline'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_description'] = htmlentities($text_ad_row['text_ad_description'], ENT_QUOTES, 'UTF-8');
	$html['text_ad_display_url'] = htmlentities($text_ad_row['text_ad_display_url'], ENT_QUOTES, 'UTF-8');
	 

} elseif (($_SERVER['REQUEST_METHOD'] == 'POST') and ($add_success != true)) {
	
	$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);
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
    $aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
    $aff_campaign_row = $aff_campaign_result->fetch_assoc();

    $mysql['aff_network_id'] = $db->real_escape_string($aff_campaign_row['aff_network_id']);
    $aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `aff_network_id`='".$mysql['aff_network_id']."'";
    $aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
    $aff_network_row = $aff_network_result->fetch_assoc();

    $html['aff_network_id'] = htmlentities($aff_network_row['aff_network_id'], ENT_QUOTES, 'UTF-8');
}

template_top('Text Ads Setup',NULL,NULL,NULL);  ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-5">
				<h6>Text Ad Setup (optional)</h6>
			</div>
			<div class="col-xs-7">
				<div class="<?php if($error) echo "error"; else echo "success";?> pull-right" style="margin-top: 20px;">
					<small>
						<?php if ($error) { ?> 
							<span class="fui-alert"></span> There were errors with your submission. <?php echo $error['token']; ?>
						<?php } ?>
						<?php if ($add_success == true) { ?>
							<span class="fui-check-inverted"></span> Your submission was successful. Your changes have been saved.
						<?php } ?>
						<?php if ($delete_success == true) { ?>
							<span class="fui-check-inverted"></span> You deletion was successful. You have succesfully removed a landing page.
						<?php } ?>
						
					</small>
				</div>
			</div>
		</div>
	</div>
	<div class="col-xs-12">
		<small>Here is where you enter in your text ad information. If you have too many text-ads and do not want to enter them all, you can skip this step.</small>
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-7">
		<small><strong>Add Your Text Ads</strong></small><br/>
		<span class="infotext">Here you can add different text ads you might use with your PPC marketing.</span>
		
		<form method="post" action="<?php if ($delete_success == true) { echo $_SERVER['REDIRECT_URL']; }?>" class="form-horizontal" role="form" style="margin:15px 0px;">
			<input name="text_ad_id" type="hidden" value="<?php echo $html['text_ad_id']; ?>"/>

			<div class="form-group" style="margin-bottom: 0px;" id="radio-select">
				<label class="col-xs-4 control-label" style="text-align: left;" id="width-tooltip">Text Ad For: </label>

				<div class="col-xs-8" style="margin-top: 10px;">
					<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="text_ad_type" id="text_ad_type1" value="0" data-toggle="radio" <?php if ($html['text_ad_type'] == '0' or !$html['text_ad_type']) { echo 'checked'; }?>>
	            			Direct Link Setup, or Simple Landing Page Setup
	          		</label>
	          		<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="text_ad_type" id="text_ad_type2" value="1" data-toggle="radio" <?php if ($html['text_ad_type'] == '1') { echo 'checked'; } ?>>
	            			Advanced Landing Page Setup
	          		</label>
	          	</div>
	        </div>

	        <div id="aff-campaign-div" <?php if ($html['text_ad_type'] == '1') { echo 'style="display:none;"'; } ?>>
		        <div class="form-group <?php if($error['aff_campaign_id']) echo "has-error";?>" style="margin-bottom: 0px;">
		        	<label for="aff_network_id" class="col-xs-4 control-label" style="text-align: left;">Affiliate Network:</label>
		        	<div class="col-xs-6" style="margin-top: 10px;">
		        		<img id="aff_network_id_div_loading" class="loading" src="/202-img/loader-small.gif"/>
	                	<div id="aff_network_id_div"></div>
		        	</div>
		        </div>

		        <div id="aff-campaign-group" class="form-group <?php if($error['aff_campaign_id']) echo "has-error";?>" style="margin-bottom: 0px;">
		        	<label for="aff_campaign_id" class="col-xs-4 control-label" style="text-align: left;">Affiliate Campaign:</label>
		        	<div class="col-xs-6" style="margin-top: 10px;">
		        		<img id="aff_campaign_id_div_loading" class="loading" src="/202-img/loader-small.gif" style="display: none;"/>
	                    <div id="aff_campaign_id_div">
	                    	<select class="form-control input-sm" id="aff_campaign_id" disabled="">
	                    		<option>--</option>
	                    	</select>
	                    </div>
		        	</div>
		        </div>
	        </div>

	        <div id="lp_landing_page" <?php if (($html['text_ad_type'] == '0') or (!$html['text_ad_type'])) { echo ' style="display:none;"'; } ?>>
		        <div class="form-group <?php if($error['landing_page_id']) echo "has-error";?>" style="margin-bottom: 0px;">
		        	<label for="landing_page_id" class="col-xs-4 control-label" style="text-align: left;">Landing Page:</label>
		        	<div class="col-xs-6" style="margin-top: 10px;">
		        		<img id="landing_page_div_loading" class="loading" src="/202-img/loader-small.gif"/>
						<div id="landing_page_div"></div>
		        	</div>
		        </div>
	        </div>

	        <div class="form-group <?php if($error['text_ad_name']) echo "has-error";?>" style="margin-bottom: 0px;">
		        <label for="text_ad_name" class="col-xs-4 control-label" style="text-align: left;">Ad Nickname <span class="fui-info" data-toggle="tooltip" title="The ad nickname is the nickname we store for you, this is used for when you have several ads, you can quickly find the ones you are looking for by assigning each ad a unique nickname."></span></label>
		        <div class="col-xs-6" style="margin-top: 10px;">
	                <input type="text" class="form-control input-sm" id="text_ad_name" name="text_ad_name" value="<?php echo $html['text_ad_name']; ?>">
		        </div>
		    </div>

		    <div class="form-group" style="margin-bottom: 0px;">
		        <label class="col-xs-4 control-label" style="text-align: left;">Ad Preview </label>
		        <div class="col-xs-6" style="margin-top: 10px;">
		        	<div class="panel panel-default" style="border-color: #3498db; margin-bottom:0px">
						<div class="panel-body">
							<span id="ad-preview-headline"><?php if ($html['text_ad_headline']) { echo $html['text_ad_headline']; } else { echo 'Luxury Cruise to Mars'; } ?></span><br/>
							<span id="ad-preview-body"><?php if ($html['text_ad_description']) { echo $html['text_ad_description']; } else { echo 'Visit the Red Planet in style. Low-gravity fun for everyone!'; } ?></span><br/>
							<span id="ad-preview-url"><?php if ($html['text_ad_display_url']) { echo $html['text_ad_display_url']; } else { echo 'www.example.com'; } ?></span>
						</div>
					</div>
		        </div>
		    </div>

		    <div class="form-group <?php if($error['>text_ad_headline']) echo "has-error";?>" style="margin-bottom: 0px;">
		        <label for="text_ad_headline" class="col-xs-4 control-label" style="text-align: left;">Ad Headline: </label>
		        <div class="col-xs-6" style="margin-top: 10px;">
	                <input type="text" class="form-control input-sm" id="text_ad_headline" name="text_ad_headline" value="<?php echo $html['text_ad_headline']; ?>">
		        </div>
		    </div>

		    <div class="form-group <?php if($error['>text_ad_description']) echo "has-error";?>" style="margin-bottom: 0px;">
		        <label for="text_ad_description" class="col-xs-4 control-label" style="text-align: left;">Ad Description: </label>
		        <div class="col-xs-6" style="margin-top: 10px;">
					<textarea class="form-control" name="text_ad_description" id="text_ad_description" rows="2"><?php echo $html['text_ad_description']; ?></textarea>
				</div>
		    </div>

		    <div class="form-group <?php if($error['>text_ad_display_url']) echo "has-error";?>" style="margin-bottom: 10px;">
		        <label for="text_ad_display_url" class="col-xs-4 control-label" style="text-align: left;">Display URL: </label>
		        <div class="col-xs-6" style="margin-top: 10px;">
	                <input type="text" class="form-control input-sm" id="text_ad_display_url" name="text_ad_display_url" value="<?php echo $html['text_ad_display_url']; ?>">
		        </div>
		    </div>

		    <div class="form-group">
				<div class="col-xs-6 col-xs-offset-4">
				    <?php if ($editing == true) { ?>
					    <div class="row">
					    	<div class="col-xs-6">
					    		<button class="btn btn-sm btn-p202 btn-block" type="submit">Edit</button>					
					    	</div>
					    	<div class="col-xs-6">
								<input type="hidden" name="pixel_id" value="<?php echo $selected['pixel_id'];?>">
								<button type="submit" class="btn btn-sm btn-danger btn-block" onclick="window.location='/tracking202/setup/text_ads.php'; return false;">Cancel</button>					    		</div>
					    	</div>
				    <?php } else { ?>
				    		<button class="btn btn-sm btn-p202 btn-block" type="submit" id="addedTextAd">Add</button>					
					<?php } ?>
				</div>
			</div>

		</form>
	</div>

	<div class="col-xs-4 col-xs-offset-1">
		<div class="panel panel-default">
			<div class="panel-heading">Advanced Landing Page Text Ads</div>
			<div class="panel-body">
				<ul>        
					<?php $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
					$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND landing_page_type='1' AND landing_page_deleted='0'";
					$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
					
					while ($landing_page_row = $landing_page_result->fetch_array(MYSQL_ASSOC)) {
						$html['landing_page_nickname'] = htmlentities($landing_page_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
							
						printf('<li>%s</li>', $html['landing_page_nickname']);
							
						?><ul style="margin-top: 0px;"><?php 
								
							$mysql['landing_page_id'] = $db->real_escape_string($landing_page_row['landing_page_id']);
							$text_ad_sql = "SELECT * FROM `202_text_ads` WHERE `landing_page_id`='".$mysql['landing_page_id']."' AND `text_ad_deleted`='0' ORDER BY `text_ad_name` ASC";
							$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
								
							while ($text_ad_row = $text_ad_result->fetch_array(MYSQL_ASSOC)) {
										
								$html['text_ad_name'] = htmlentities($text_ad_row['text_ad_name'], ENT_QUOTES, 'UTF-8');
								$html['text_ad_id'] = htmlentities($text_ad_row['text_ad_id'], ENT_QUOTES, 'UTF-8');
										
								printf('<li>%s - <a href="?copy_text_ad_id=%s">copy</a> - <a href="?edit_text_ad_id=%s">edit</a> - <a href="?delete_text_ad_id=%s" onclick="return confirmAlert(\'Are You Sure You Want To Delete This Ad?\');">remove</a></li>', $html['text_ad_name'], $html['text_ad_id'], $html['text_ad_id'],  $html['text_ad_id']);
							
										
							}

						?></ul>
					<?	} ?>
				</ul>
			</div>
		</div>

		<div class="panel panel-default">
			<div class="panel-heading">Direct Link/Simple Landing Page Text Ads</div>
			<div class="panel-body">
				<ul>        
				<?php  $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
					$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='".$mysql['user_id']."' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";
					$aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
					if ($aff_network_result->num_rows == 0 ) { 
						?><li>You have not added any networks.</li><?
					}
					
					while ($aff_network_row = $aff_network_result->fetch_array(MYSQL_ASSOC)) {
						$html['aff_network_name'] = htmlentities($aff_network_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
						$url['aff_network_id'] = urlencode($aff_network_row['aff_network_id']);
						
						printf('<li>%s</li>', $html['aff_network_name']);
						
						?><ul style="margin-top: 0px;"><?
											
							//print out the individual accounts per each PPC network
							$mysql['aff_network_id'] = $db->real_escape_string($aff_network_row['aff_network_id']);
							$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `aff_network_id`='".$mysql['aff_network_id']."' AND `aff_campaign_deleted`='0' ORDER BY `aff_campaign_name` ASC";
							$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
							 
							while ($aff_campaign_row = $aff_campaign_result->fetch_array(MYSQL_ASSOC)) {
								
								$html['aff_campaign_name'] = htmlentities($aff_campaign_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
								$html['aff_campaign_payout'] = htmlentities($aff_campaign_row['aff_campaign_payout'], ENT_QUOTES, 'UTF-8');
							
								printf('<li>%s &middot; &#36;%s</li>', $html['aff_campaign_name'], $html['aff_campaign_payout']);
							
								?><ul style="margin-top: 0px;"><?php 
								
									$mysql['aff_campaign_id'] = $db->real_escape_string($aff_campaign_row['aff_campaign_id']);
									$text_ad_sql = "SELECT * FROM `202_text_ads` WHERE `aff_campaign_id`='".$mysql['aff_campaign_id']."' AND `text_ad_deleted`='0' ORDER BY `text_ad_name` ASC";
									$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
									
									while ($text_ad_row = $text_ad_result->fetch_array(MYSQL_ASSOC)) {
										
										$html['text_ad_name'] = htmlentities($text_ad_row['text_ad_name'], ENT_QUOTES, 'UTF-8');
										$html['text_ad_id'] = htmlentities($text_ad_row['text_ad_id'], ENT_QUOTES, 'UTF-8');
										
										printf('<li>%s - <a href="?copy_text_ad_id=%s">copy</a> - <a href="?edit_text_ad_id=%s">edit</a> - <a href="?delete_text_ad_id=%s" onclick="return confirmAlert(\'Are You Sure You Want To Delete This Ad?\');">remove</a></li>', $html['text_ad_name'], $html['text_ad_id'], $html['text_ad_id'],  $html['text_ad_id']);
							
										
									}

								?></ul><?						
							} 
						
						?></ul><?
						
					} ?>
				</ul>
			</div>
		</div>
	</div>

</div>

<!-- open up the ajax aff network -->
<script type="text/javascript">
$(document).ready(function() {

    load_landing_page(0, <?php echo $html['landing_page_id']; if (!$html['landing_page_id']) { echo 0; } ?>, 'advlandingpage');

   	load_aff_network_id('<?php echo $html['aff_network_id']; ?>');
    <?php if ($html['aff_network_id'] != '') { ?>
        load_aff_campaign_id('<?php echo $html['aff_network_id']; ?>','<?php echo $html['aff_campaign_id']; ?>');
    <?php } ?>
});
</script>

<?php template_bottom();