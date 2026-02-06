<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: '.get_absolute_url().'tracking202/');
	die();
}

// Initialize variables to prevent undefined variable warnings
$error = [];
$html = [];
$mysql = [];
$selected = [];
$add_success = false;
$delete_success = false;
$editing = false;
$copying = false;

$slack = false;
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url'])) 
	$slack = new Slack($user_row['url']);

if (!empty($_GET['edit_text_ad_id'])) { 
	$editing = true; 
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	if ($_POST['text_ad_type'] == 0) { 
		
		//text ad type
		$aff_campaign_id = trim((string)($_POST['aff_campaign_id'] ?? ''));
		if (empty($aff_campaign_id)) { $error['aff_campaign_id'] = '<div class="error">What campaign is this advertisement for?</div>'; }
	
	
		//check to see if they are the owners of this affiliate network
		$mysql['aff_campaign_id'] = $db->real_escape_string((string)($_POST['aff_campaign_id'] ?? ''));
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `user_id`='".$mysql['user_id']."' AND `aff_campaign_id`='".$mysql['aff_campaign_id']."'";
		$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
		if ($aff_campaign_result->num_rows == 0 ) {
			$error['wrong_user'] = '<div class="error">You are not authorized to add an campaign to another users network</div>';    
		} else {
			$aff_campaign_row = $aff_campaign_result->fetch_assoc();
		}
	
	}
	
	if ($_POST['text_ad_type'] == 1) { 
		$landing_page_id = trim((string) $_POST['landing_page_id']);
		if (empty($landing_page_id)) { $error['landing_page_id'] = '<div class="error">Please select a landing page.</div>'; }

		$mysql['landing_page_id'] = $db->real_escape_string((string)$_POST['landing_page_id']);
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND `landing_page_id`='".$mysql['landing_page_id']."'";
		$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
		if ($landing_page_result->num_rows == 0 ) {
			$error['wrong_user'] = '<div class="error">You are not authorized to add an text add to another users landing page</div>';    
		} else {
			$landing_page_row = $landing_page_result->fetch_assoc();
		}
	}
		
		
	$text_ad_name = trim((string) $_POST['text_ad_name']);
	if (empty($text_ad_name)) { $error['text_ad_name'] = '<div class="error">Give this ad variation a nickname</div>'; }
	
	$text_ad_headline = trim((string) $_POST['text_ad_headline']);
	if (empty($text_ad_headline)) { $error['text_ad_headline'] = '<div class="error">What is your ad headline?</div>'; }
	
	$text_ad_description = trim((string) $_POST['text_ad_description']);
	if (empty($text_ad_description)) { $error['text_ad_description'] = '<div class="error">What is your ad description?</div>'; }
	
	$text_ad_display_url = trim((string) $_POST['text_ad_display_url']);
	if (empty($text_ad_display_url)) { $error['text_ad_display_url'] = '<div class="error">What is your ad display URL?</div>'; }
	

	
	//if editing, check to make sure the own the campaign they are editing
	if ($editing == true) {
		$mysql['text_ad_id'] = $db->real_escape_string((string)$_POST['text_ad_id']);
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$ad_varation_sql = "SELECT 
							202_text_ads.aff_campaign_id AS text_add_aff_campaign_id,
							202_text_ads.landing_page_id AS text_add_landing_page_id,
							202_text_ads.text_ad_name AS text_ad_name,
							202_text_ads.text_ad_headline AS text_ad_headline,
							202_text_ads.text_ad_description AS text_ad_description,
							202_text_ads.text_ad_display_url AS text_ad_display_url,
							202_aff_campaigns.aff_campaign_name AS text_add_aff_campaign_name,
							202_landing_pages.landing_page_nickname AS text_add_landing_page_nickname  
							FROM 202_text_ads LEFT JOIN 202_aff_campaigns USING (aff_campaign_id) LEFT JOIN 202_landing_pages USING (landing_page_id) WHERE 202_text_ads.user_id='".$mysql['user_id']."' AND text_ad_id='".$mysql['text_ad_id']."'";
		$text_ad_result = $db->query($ad_varation_sql) or record_mysql_error($ad_varation_sql);
		if ($text_ad_result->num_rows == 0 ) {
			$error['wrong_user'] .= '<div class="error">You are not authorized to modify another users campaign</div>';    
		} else {
			$text_ad_row = $text_ad_result->fetch_assoc();
		}
	}

	if (!$error) { 
		$mysql['text_ad_id'] = $db->real_escape_string((string)$_POST['text_ad_id']);
		$mysql['text_ad_type'] = $db->real_escape_string((string)$_POST['text_ad_type']);
		$mysql['landing_page_id'] = $db->real_escape_string((string)$_POST['landing_page_id']);
		$mysql['aff_campaign_id'] = $db->real_escape_string((string)$_POST['aff_campaign_id']);
		$mysql['text_ad_name'] = $db->real_escape_string((string)$_POST['text_ad_name']);
		$mysql['text_ad_headline'] = $db->real_escape_string((string)$_POST['text_ad_headline']);
		$mysql['text_ad_description'] = $db->real_escape_string((string)$_POST['text_ad_description']);
		$mysql['text_ad_display_url'] = $db->real_escape_string((string)$_POST['text_ad_display_url']);
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['text_ad_time'] = $db->real_escape_string((string)time());
		
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
			if ($slack) {
				if ($_POST['text_ad_type'] == 0) {
					if ($text_ad_row['text_add_aff_campaign_id'] != $_POST['aff_campaign_id']) {
						$slack->push('ad_copy_campaign_changed', ['name' => $text_ad_row['text_ad_name'], 'old_campaign' => $text_ad_row['text_add_aff_campaign_name'], 'new_campaign' => $aff_campaign_row['aff_campaign_name'], 'user' => $user_row['username']]);
					}
				}

				if ($_POST['text_ad_type'] == 1) {
					if ($text_ad_row['text_add_landing_page_id'] != $_POST['landing_page_id']) {
						$slack->push('ad_copy_landing_page_changed', ['name' => $text_ad_row['text_ad_name'], 'old_lp' => $text_ad_row['text_add_landing_page_nickname'], 'new_lp' => $landing_page_row['landing_page_nickname'], 'user' => $user_row['username']]);
					}
				}

				if ($text_ad_row['text_ad_name'] != $_POST['text_ad_name']) {
					$slack->push('ad_copy_name_changed', ['old_name' => $text_ad_row['text_ad_name'], 'new_name' => $_POST['text_ad_name'], 'user' => $user_row['username']]);
				}

				if ($text_ad_row['text_ad_headline'] != $_POST['text_ad_headline']) {
					$slack->push('ad_copy_headline_changed', ['name' => $_POST['text_ad_name'], 'old_headline' => $text_ad_row['text_ad_headline'], 'new_headline' => $_POST['text_ad_headline'], 'user' => $user_row['username']]);
				}

				if ($text_ad_row['text_ad_description'] != $_POST['text_ad_description']) {
					$slack->push('ad_copy_description_changed', ['name' => $_POST['text_ad_name'], 'old_description' => $text_ad_row['text_ad_description'], 'new_description' => $_POST['text_ad_description'], 'user' => $user_row['username']]);
				}

				if ($text_ad_row['text_ad_display_url'] != $_POST['text_ad_display_url']) {
					$slack->push('ad_copy_display_url_changed', ['name' => $_POST['text_ad_name'], 'old_url' => $text_ad_row['text_ad_display_url'], 'new_url' => $_POST['text_ad_display_url'], 'user' => $user_row['username']]);
				}
			}
			header('location: '.get_absolute_url().'tracking202/setup/text_ads.php');   
			
		} else {
			if($slack)
				$slack->push('ad_copy_created', ['name' => $_POST['text_ad_name'], 'user' => $user_row['username']]);
		}
		
		$editing = false;
		
		
	}
}

if (isset($_GET['delete_text_ad_id'])) { 

	if ($userObj->hasPermission("remove_text_ad")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['text_ad_id'] = $db->real_escape_string((string)$_GET['delete_text_ad_id']);
		$mysql['text_ad_time'] = time();
		
		$delete_sql = " UPDATE  `202_text_ads`
						SET     `text_ad_deleted`='1',
								`text_ad_time`='".$mysql['text_ad_time']."'
						WHERE   `user_id`='".$mysql['user_id']."'
						AND     `text_ad_id`='".$mysql['text_ad_id']."'";
		if ($delete_result = $db->query($delete_sql) or record_mysql_error($delete_result)) {
			$delete_success = true;
			if($slack)
				$slack->push('ad_copy_deleted', ['name' => $_GET['delete_text_ad_name'], 'user' => $user_row['username']]);
		}
	} else {
		header('location: '.get_absolute_url().'tracking202/setup/text_ads.php');
	}
}

if (!empty($_GET['edit_text_ad_id'])) { 
	
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['text_ad_id'] = $db->real_escape_string((string)$_GET['edit_text_ad_id']);
	
	$text_ad_sql = "SELECT * 
						 FROM   `202_text_ads`
						 WHERE  `text_ad_id`='".$mysql['text_ad_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
	$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
	$text_ad_row = $text_ad_result->fetch_assoc();
	

	$mysql['aff_campaign_id'] = $db->real_escape_string($text_ad_row['aff_campaign_id']);
	$html['landing_page_id'] = htmlentities((string)($text_ad_row['landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');    
	$html['text_ad_type'] = htmlentities((string)($text_ad_row['text_ad_type'] ?? ''), ENT_QUOTES, 'UTF-8');    
	$html['aff_campaign_id'] = htmlentities((string)($text_ad_row['aff_campaign_id'] ?? ''), ENT_QUOTES, 'UTF-8');    
	$html['text_ad_id'] = htmlentities((string)($_GET['edit_text_ad_id'] ?? ''), ENT_QUOTES, 'UTF-8');    
	$html['text_ad_name'] = htmlentities((string)($text_ad_row['text_ad_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_headline'] = htmlentities((string)($text_ad_row['text_ad_headline'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_description'] = htmlentities((string)($text_ad_row['text_ad_description'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_display_url'] = htmlentities((string)($text_ad_row['text_ad_display_url'] ?? ''), ENT_QUOTES, 'UTF-8');
	 

} elseif (!empty($_GET['copy_text_ad_id'])) { 
	
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['text_ad_id'] = $db->real_escape_string((string)$_GET['copy_text_ad_id']);
	
	$text_ad_sql = "SELECT * 
						 FROM   `202_text_ads`
						 WHERE  `text_ad_id`='".$mysql['text_ad_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
	$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
	$text_ad_row = $text_ad_result->fetch_assoc();
	
	$html['text_ad_type'] = htmlentities((string)($text_ad_row['text_ad_type'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['landing_page_id'] = htmlentities((string)($text_ad_row['landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_name'] = htmlentities((string)($text_ad_row['text_ad_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_headline'] = htmlentities((string)($text_ad_row['text_ad_headline'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_description'] = htmlentities((string)($text_ad_row['text_ad_description'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_display_url'] = htmlentities((string)($text_ad_row['text_ad_display_url'] ?? ''), ENT_QUOTES, 'UTF-8');
	 

} elseif (($_SERVER['REQUEST_METHOD'] == 'POST') and ($add_success != true)) {
	
	$mysql['aff_campaign_id'] = $db->real_escape_string((string)$_POST['aff_campaign_id']);
   	$html['aff_campaign_id'] = htmlentities((string)($_POST['aff_campaign_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    
    	$html['text_ad_type'] = htmlentities((string)($_POST['text_ad_type'] ?? ''), ENT_QUOTES, 'UTF-8');   
	$html['landing_page_id'] = htmlentities((string)($_POST['landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');   
	$html['aff_network_id'] = htmlentities((string)($_POST['aff_network_id'] ?? ''), ENT_QUOTES, 'UTF-8');   
	$html['text_ad_id'] = htmlentities((string)($_POST['text_ad_id'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_name'] = htmlentities((string)($_POST['text_ad_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_headline'] = htmlentities((string)($_POST['text_ad_headline'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_description'] = htmlentities((string)($_POST['text_ad_description'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_display_url'] = htmlentities((string)($_POST['text_ad_display_url'] ?? ''), ENT_QUOTES, 'UTF-8');
	
}

if ((($editing === true) || ($add_success !== true)) && !empty($mysql['aff_campaign_id'])) {
    //now grab the affiliate network id, per that aff campaign id
    $aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `aff_campaign_id`='".$mysql['aff_campaign_id']."'";
    $aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
    $aff_campaign_row = $aff_campaign_result->fetch_assoc();

    $mysql['aff_network_id'] = $db->real_escape_string($aff_campaign_row['aff_network_id']);
    $aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `aff_network_id`='".$mysql['aff_network_id']."'";
    $aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
    $aff_network_row = $aff_network_result->fetch_assoc();

    $html['aff_network_id'] = htmlentities((string)($aff_network_row['aff_network_id'] ?? ''), ENT_QUOTES, 'UTF-8');
}

template_top('Text Ads Setup');  ?>
<link rel="stylesheet" href="<?php echo get_absolute_url();?>202-css/design-system.css">

<!-- Page Header - Design System -->
<div class="row" style="margin-bottom: 28px;">
	<div class="col-xs-12">
		<div class="setup-page-header">
			<div class="setup-page-header__icon">
				<span class="glyphicon glyphicon-font"></span>
			</div>
			<div class="setup-page-header__text">
				<h1 class="setup-page-header__title">Text Ads</h1>
				<p class="setup-page-header__subtitle">Store your ad copy variations for tracking headline, description, and display URL performance</p>
			</div>
		</div>
	</div>
</div>

<?php if ($error) { ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="alert alert-danger">
			<i class="fa fa-exclamation-circle"></i> There were errors with your submission. <?php echo $error['token'] ?? ''; ?>
		</div>
	</div>
</div>
<?php } ?>

<?php if ($add_success == true) { ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="alert alert-success">
			<i class="fa fa-check-circle"></i> Your submission was successful. Your changes have been saved.
		</div>
	</div>
</div>
<?php } ?>

<?php if ($delete_success == true) { ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="alert alert-success">
			<i class="fa fa-check-circle"></i> Your deletion was successful. You have successfully removed a text ad.
		</div>
	</div>
</div>
<?php } ?>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-md-6">
		<small><strong>Add Your Text Ads</strong></small><br/>
		<span class="infotext">Here you can add different text ads you might use with your PPC marketing.</span>
		
		<form method="post" action="<?php if ($delete_success == true) { echo $_SERVER['REDIRECT_URL'] ?? ''; }?>" class="form-horizontal" role="form" style="margin:15px 0px;">
			<input name="text_ad_id" type="hidden" value="<?php echo $html['text_ad_id'] ?? ''; ?>"/>

			<div class="form-group" style="margin-bottom: 0px;" id="radio-select">
				<label class="col-xs-4 control-label" style="text-align: left;" id="width-tooltip">Text Ad For: </label>

				<div class="col-xs-8" style="margin-top: 10px;">
					<label class="radio">
	            		<input type="radio" name="text_ad_type" id="text_ad_type1" value="0" data-toggle="radio" <?php if (!isset($html['text_ad_type']) || $html['text_ad_type'] == '0' || !$html['text_ad_type']) { echo 'checked'; }?>>
	            			Direct Link Setup, or Simple Landing Page Setup
	          		</label>
	          		<label class="radio">
	            		<input type="radio" name="text_ad_type" id="text_ad_type2" value="1" data-toggle="radio" <?php if (isset($html['text_ad_type']) && $html['text_ad_type'] == '1') { echo 'checked'; } ?>>
	            			Advanced Landing Page Setup
	          		</label>
	          	</div>
	        </div>

                <div id="aff-campaign-div" <?php if (isset($html['text_ad_type']) && $html['text_ad_type'] == '1') { echo 'style="display:none;"'; } ?>>
                        <div class="form-group <?php if (!empty($error['aff_campaign_id'])) echo 'has-error'; ?>" style="margin-bottom: 0px;">
		        	<label for="aff_network_id" class="col-xs-4 control-label" style="text-align: left;">Category:</label>
		        	<div class="col-xs-6" style="margin-top: 10px;">
		        		<img id="aff_network_id_div_loading" class="loading" src="<?php echo get_absolute_url();?>202-img/loader-small.gif"/>
	                	<div id="aff_network_id_div"></div>
		        	</div>
		        </div>

                        <div id="aff-campaign-group" class="form-group <?php if (!empty($error['aff_campaign_id'])) echo 'has-error'; ?>" style="margin-bottom: 0px;">
		        	<label for="aff_campaign_id" class="col-xs-4 control-label" style="text-align: left;">Campaign:</label>
		        	<div class="col-xs-6" style="margin-top: 10px;">
		        		<img id="aff_campaign_id_div_loading" class="loading" src="<?php echo get_absolute_url();?>202-img/loader-small.gif" style="display: none;"/>
	                    <div id="aff_campaign_id_div">
	                    	<select class="form-control input-sm" id="aff_campaign_id" disabled="">
	                    		<option>--</option>
	                    	</select>
	                    </div>
		        	</div>
		        </div>
	        </div>

                <div id="lp_landing_page" <?php if (isset($html['text_ad_type']) && (($html['text_ad_type'] == '0') || (!$html['text_ad_type']))) { echo ' style="display:none;"'; } ?>>
                        <div class="form-group <?php if (!empty($error['landing_page_id'])) echo 'has-error'; ?>" style="margin-bottom: 0px;">
		        	<label for="landing_page_id" class="col-xs-4 control-label" style="text-align: left;">Landing Page:</label>
		        	<div class="col-xs-6" style="margin-top: 10px;">
		        		<img id="landing_page_div_loading" class="loading" src="<?php echo get_absolute_url();?>202-img/loader-small.gif"/>
						<div id="landing_page_div"></div>
		        	</div>
		        </div>
	        </div>

                <div class="form-group <?php if (!empty($error['text_ad_name'])) echo 'has-error'; ?>" style="margin-bottom: 0px;">
		        <label for="text_ad_name" class="col-xs-4 control-label" style="text-align: left;">Ad Nickname <span class="fui-info-circle" data-toggle="tooltip" title="The ad nickname is the nickname we store for you, this is used for when you have several ads, you can quickly find the ones you are looking for by assigning each ad a unique nickname."></span></label>
		        <div class="col-xs-6" style="margin-top: 10px;">
	                <input type="text" class="form-control input-sm" id="text_ad_name" name="text_ad_name" value="<?php echo $html['text_ad_name'] ?? ''; ?>">
		        </div>
		    </div>

		    <div class="form-group" style="margin-bottom: 0px;">
		        <label class="col-xs-4 control-label" style="text-align: left;">Ad Preview </label>
		        <div class="col-xs-6" style="margin-top: 10px;">
		        	<div class="panel panel-default" style="border-color: #3498db; margin-bottom:0px">
						<div class="panel-body">
							<span id="ad-preview-headline"><?php if (isset($html['text_ad_headline']) && $html['text_ad_headline']) { echo $html['text_ad_headline']; } else { echo 'Luxury Cruise to Mars'; } ?></span><br/>
							<span id="ad-preview-body"><?php if (isset($html['text_ad_description']) && $html['text_ad_description']) { echo $html['text_ad_description']; } else { echo 'Visit the Red Planet in style. Low-gravity fun for everyone!'; } ?></span><br/>
							<span id="ad-preview-url"><?php if (isset($html['text_ad_display_url']) && $html['text_ad_display_url']) { echo $html['text_ad_display_url']; } else { echo 'www.example.com'; } ?></span>
						</div>
					</div>
		        </div>
		    </div>

		    <div class="form-group <?php if(isset($error['text_ad_headline'])) echo "has-error";?>" style="margin-bottom: 0px;">
		        <label for="text_ad_headline" class="col-xs-4 control-label" style="text-align: left;">Ad Headline: </label>
		        <div class="col-xs-6" style="margin-top: 10px;">
	                <input type="text" class="form-control input-sm" id="text_ad_headline" name="text_ad_headline" value="<?php echo $html['text_ad_headline'] ?? ''; ?>">
		        </div>
		    </div>

		    <div class="form-group <?php if(isset($error['text_ad_description'])) echo "has-error";?>" style="margin-bottom: 0px;">
		        <label for="text_ad_description" class="col-xs-4 control-label" style="text-align: left;">Ad Description: </label>
		        <div class="col-xs-6" style="margin-top: 10px;">
					<textarea class="form-control" name="text_ad_description" id="text_ad_description" rows="2"><?php echo $html['text_ad_description'] ?? ''; ?></textarea>
				</div>
		    </div>

		    <div class="form-group <?php if(isset($error['text_ad_display_url'])) echo "has-error";?>" style="margin-bottom: 10px;">
		        <label for="text_ad_display_url" class="col-xs-4 control-label" style="text-align: left;">Display URL: </label>
		        <div class="col-xs-6" style="margin-top: 10px;">
	                <input type="text" class="form-control input-sm" id="text_ad_display_url" name="text_ad_display_url" value="<?php echo $html['text_ad_display_url'] ?? ''; ?>">
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
								<input type="hidden" name="pixel_id" value="<?php echo $selected['pixel_id'] ?? '';?>">
								<button type="submit" class="btn btn-sm btn-danger btn-block" onclick="window.location='<?php echo get_absolute_url();?>tracking202/setup/text_ads.php'; return false;">Cancel</button>					    		</div>
					    	</div>
				    <?php } else { ?>
				    		<button class="btn btn-sm btn-p202 btn-block" type="submit" id="addedTextAd">Add</button>					
					<?php } ?>
				</div>
			</div>

		</form>
	</div>

	<div class="col-md-6">
		<div class="panel panel-default">
			<div class="panel-heading">Advanced Landing Page Text Ads</div>
			<div class="panel-body">
				<ul>        
					<?php $mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
					$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND landing_page_type='1' AND landing_page_deleted='0'";
					$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
					
					while ($landing_page_row = $landing_page_result->fetch_array(MYSQLI_ASSOC)) {
						$html['landing_page_nickname'] = htmlentities((string)($landing_page_row['landing_page_nickname'] ?? ''), ENT_QUOTES, 'UTF-8');
							
						printf('<li>%s</li>', $html['landing_page_nickname']);
							
						?><ul style="margin-top: 0px;"><?php 
								
							$mysql['landing_page_id'] = $db->real_escape_string($landing_page_row['landing_page_id']);
							$text_ad_sql = "SELECT * FROM `202_text_ads` WHERE `landing_page_id`='".$mysql['landing_page_id']."' AND `text_ad_deleted`='0' ORDER BY `text_ad_name` ASC";
							$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
								
							while ($text_ad_row = $text_ad_result->fetch_array(MYSQLI_ASSOC)) {
										
								$html['text_ad_name'] = htmlentities((string)($text_ad_row['text_ad_name'] ?? ''), ENT_QUOTES, 'UTF-8');
								$html['text_ad_id'] = htmlentities((string)($text_ad_row['text_ad_id'] ?? ''), ENT_QUOTES, 'UTF-8');

								if ($userObj->hasPermission("remove_text_ad")) {
								printf('<li><span class="filter_text_ad_name">%s</span> <a href="?copy_text_ad_id=%s" class="list-action">copy</a> <a href="?edit_text_ad_id=%s" class="list-action">edit</a> <a href="?delete_text_ad_id=%s&delete_text_ad_name=%s" class="list-action list-action-danger" onclick="return confirmAlert(\'Are You Sure You Want To Delete This Ad?\');">remove</a></li>', $html['text_ad_name'], $html['text_ad_id'], $html['text_ad_id'], $html['text_ad_id'], $html['text_ad_name']);
							} else {
								printf('<li><span class="filter_text_ad_name">%s</span> <a href="?copy_text_ad_id=%s" class="list-action">copy</a> <a href="?edit_text_ad_id=%s" class="list-action">edit</a></li>', $html['text_ad_name'], $html['text_ad_id'], $html['text_ad_id']);
							}		
							
										
							}

						?></ul>
					<?php	} ?>
				</ul>
			</div>
		</div>

		<div class="panel panel-default">
			<div class="panel-heading">Direct Link/Simple Landing Page Text Ads</div>
			<div class="panel-body">
				<ul>        
				<?php  $mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
					$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='".$mysql['user_id']."' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";
					$aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
					if ($aff_network_result->num_rows == 0 ) { 
						?><li class="empty-state">No categories added yet. Add a category first to organize your text ads.</li><?php
					}
					
					while ($aff_network_row = $aff_network_result->fetch_array(MYSQLI_ASSOC)) {
						$html['aff_network_name'] = htmlentities((string)($aff_network_row['aff_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
						$url['aff_network_id'] = urlencode((string) $aff_network_row['aff_network_id']);
						
						printf('<li>%s</li>', $html['aff_network_name']);
						
						?><ul style="margin-top: 0px;"><?php
											
							//print out the individual accounts per each PPC network
							$mysql['aff_network_id'] = $db->real_escape_string($aff_network_row['aff_network_id']);
							$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `aff_network_id`='".$mysql['aff_network_id']."' AND `aff_campaign_deleted`='0' ORDER BY `aff_campaign_name` ASC";
							$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
							 
							while ($aff_campaign_row = $aff_campaign_result->fetch_array(MYSQLI_ASSOC)) {
								
								$html['aff_campaign_name'] = htmlentities((string)($aff_campaign_row['aff_campaign_name'] ?? ''), ENT_QUOTES, 'UTF-8');
								$html['aff_campaign_payout'] = htmlentities((string)($aff_campaign_row['aff_campaign_payout'] ?? ''), ENT_QUOTES, 'UTF-8');
							
								printf('<li>%s &middot; &#36;%s</li>', $html['aff_campaign_name'], $html['aff_campaign_payout']);
							
								?><ul style="margin-top: 0px;"><?php 
								
									$mysql['aff_campaign_id'] = $db->real_escape_string($aff_campaign_row['aff_campaign_id']);
									$text_ad_sql = "SELECT * FROM `202_text_ads` WHERE `aff_campaign_id`='".$mysql['aff_campaign_id']."' AND `text_ad_deleted`='0' ORDER BY `text_ad_name` ASC";
									$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
									
									while ($text_ad_row = $text_ad_result->fetch_array(MYSQLI_ASSOC)) {
										
										$html['text_ad_name'] = htmlentities((string)($text_ad_row['text_ad_name'] ?? ''), ENT_QUOTES, 'UTF-8');
										$html['text_ad_id'] = htmlentities((string)($text_ad_row['text_ad_id'] ?? ''), ENT_QUOTES, 'UTF-8');
										
										if ($userObj->hasPermission("remove_text_ad")) {
											printf('<li><span class="filter_text_ad_name">%s</span> <a href="?copy_text_ad_id=%s" class="list-action">copy</a> <a href="?edit_text_ad_id=%s" class="list-action">edit</a> <a href="?delete_text_ad_id=%s&delete_text_ad_name=%s" class="list-action list-action-danger" onclick="return confirmAlert(\'Are You Sure You Want To Delete This Ad?\');">remove</a></li>', $html['text_ad_name'], $html['text_ad_id'], $html['text_ad_id'], $html['text_ad_id'], $html['text_ad_name']);
										} else {
											printf('<li><span class="filter_text_ad_name">%s</span> <a href="?copy_text_ad_id=%s" class="list-action">copy</a> <a href="?edit_text_ad_id=%s" class="list-action">edit</a></li>', $html['text_ad_name'], $html['text_ad_id'], $html['text_ad_id']);
										}
							
										
									}

								?></ul><?php						
							} 
						
						?></ul><?php
						
					} ?>
				</ul>
			</div>
		</div>
	</div>

</div>

<!-- open up the ajax aff network -->
<script type="text/javascript">
$(document).ready(function() {

    load_landing_page(0, <?php echo $html['landing_page_id'] ?? 0; if (isset($html['landing_page_id']) && !$html['landing_page_id']) { echo 0; } ?>, 'advlandingpage');

   	load_aff_network_id('<?php echo $html['aff_network_id'] ?? ''; ?>');
    <?php if (isset($html['aff_network_id']) && $html['aff_network_id'] != '') { ?>
        load_aff_campaign_id('<?php echo $html['aff_network_id']; ?>','<?php echo $html['aff_campaign_id'] ?? ''; ?>');
    <?php } ?>
});
</script>

<style>
/* ===========================================
   TEXT ADS - Enhanced Design System Styles
   =========================================== */

/* ---- Page Header ---- */
.setup-page-header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 24px;
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border-radius: 12px;
    color: #fff;
    box-shadow: 0 4px 15px rgba(0, 123, 255, 0.2);
}

.setup-page-header__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
}

.setup-page-header__icon .glyphicon {
    font-size: 24px;
    color: #fff;
    font-weight: 600;
}

.setup-page-header__text { flex: 1; }

.setup-page-header__title {
    margin: 0 0 4px 0;
    font-size: 24px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -0.5px;
}

.setup-page-header__subtitle {
    margin: 0;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.9);
    font-weight: 400;
    line-height: 1.5;
}

/* ---- Panels & Cards ---- */
.panel-default {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    overflow: hidden;
    transition: box-shadow 0.3s ease, border-color 0.3s ease;
}

.panel-default:hover {
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    border-color: #cbd5e1;
}

.panel-heading {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e2e8f0;
    padding: 16px 20px;
    font-weight: 600;
    color: #1e293b;
    font-size: 14px;
    letter-spacing: 0.2px;
}

.panel-body {
    padding: 20px;
    background: #fff;
}

/* ---- Alerts ---- */
.alert {
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    font-weight: 500;
    border: 1px solid transparent;
}

.alert i {
    font-size: 16px;
    flex-shrink: 0;
}

.alert-success {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border-color: #86efac;
    color: #166534;
}

.alert-danger {
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
    border-color: #fca5a5;
    color: #991b1b;
}

.alert-warning {
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border-color: #fcd34d;
    color: #92400e;
}

.alert-info {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border-color: #7dd3fc;
    color: #0c4a6e;
}

/* ---- Form Elements ---- */
.form-group {
    margin-bottom: 20px;
    transition: all 0.3s ease;
}

.form-group.has-error .form-control {
    border-color: #f87171;
    background: #fff5f5;
}

.form-group.has-error .form-control:focus {
    border-color: #dc2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
}

.form-control {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 14px;
    transition: all 0.3s ease;
    background: #fff;
}

.form-control:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    outline: none;
}

.form-control:disabled,
.form-control[disabled] {
    background-color: #f8fafc;
    color: #94a3b8;
    cursor: not-allowed;
    opacity: 0.7;
}

.input-sm {
    padding: 8px 12px;
    font-size: 13px;
    height: auto;
}

/* ---- Buttons ---- */
.btn {
    border-radius: 8px;
    padding: 10px 16px;
    font-size: 14px;
    font-weight: 600;
    border: 1px solid transparent;
    transition: all 0.3s ease;
    cursor: pointer;
    display: inline-block;
    text-align: center;
    white-space: nowrap;
    vertical-align: middle;
    user-select: none;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.btn:active {
    transform: translateY(0);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.btn-sm {
    padding: 8px 12px;
    font-size: 13px;
}

.btn-p202 {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: #fff;
    border: 1px solid transparent;
}

.btn-p202:hover {
    background: linear-gradient(135deg, #0056b3 0%, #003d82 100%);
    color: #fff;
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
}

.btn-p202:disabled {
    background: #cbd5e1;
    color: #64748b;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.btn-danger {
    background: #ef4444;
    color: #fff;
    border: 1px solid transparent;
}

.btn-danger:hover {
    background: #dc2626;
    color: #fff;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-danger:disabled {
    background: #cbd5e1;
    cursor: not-allowed;
    transform: none;
}

.btn-block {
    width: 100%;
}

/* ---- Lists ---- */
ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

li {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    padding: 8px 0;
    color: #1e293b;
    font-size: 14px;
    transition: all 0.2s ease;
}

li:hover {
    color: #007bff;
    padding-left: 4px;
}

.filter_text_ad_name {
    font-weight: 500;
    flex: 1;
    min-width: 100px;
}

li a {
    color: #007bff;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.2s ease;
    border-bottom: 1px solid transparent;
}

li a:hover {
    color: #0056b3;
    border-bottom-color: #007bff;
}

/* ---- Setup List Items (New Pattern) ---- */
.list-action {
    display: inline-block;
    margin-left: 12px;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 13px;
    transition: all 0.2s ease;
    background: transparent;
    color: #007bff;
    text-decoration: none;
    border: 1px solid transparent;
}

.list-action:first-of-type {
    margin-left: 12px;
}

.list-action:hover {
    background: #f0f7ff;
    border-color: #007bff;
    color: #0056b3;
}

.list-action-danger {
    color: #ef4444;
}

.list-action-danger:hover {
    background: #fef2f2;
    border-color: #ef4444;
    color: #991b1b;
}

.empty-state {
    text-align: center;
    padding: 24px 16px;
    color: #9ca3af;
    border: 1px dashed #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
}

/* Deprecated - keeping for backwards compatibility */
.setup-list-name,
.setup-list-actions {
    display: inline-block;
    vertical-align: middle;
}

.setup-list-name {
    flex: 1;
    max-width: 70%;
    word-break: break-word;
}

.setup-list-actions {
    white-space: nowrap;
    margin-left: 12px;
}

.action-copy,
.action-edit,
.action-remove {
    color: #007bff;
    text-decoration: none;
    border: 1px solid transparent;
}

/* ---- Radio & Checkbox Styles ---- */
.radio label,
.checkbox label {
    padding-left: 24px;
    margin-bottom: 8px;
    font-weight: 500;
    color: #1e293b;
    cursor: pointer;
    user-select: none;
}

.radio input[type="radio"],
.checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin-left: -24px;
    margin-right: 8px;
    cursor: pointer;
    accent-color: #007bff;
}

/* ---- Ad Preview Panel ---- */
.panel .panel-body {
    background: #fff;
}

.panel-body span {
    display: block;
    margin-bottom: 12px;
    color: #1e293b;
    line-height: 1.6;
}

.panel-body span:last-child {
    margin-bottom: 0;
}

#ad-preview-headline {
    font-weight: 700;
    font-size: 16px;
    color: #007bff;
    margin-bottom: 8px;
}

#ad-preview-body {
    font-size: 14px;
    color: #475569;
    line-height: 1.5;
}

#ad-preview-url {
    font-size: 13px;
    color: #0d9488;
    font-weight: 500;
}

/* ---- Form Separator ---- */
.form_seperator {
    border-top: 2px solid #e2e8f0;
    margin: 24px 0 !important;
}

/* ---- Info Text ---- */
.infotext {
    display: block;
    margin-bottom: 12px;
    font-size: 13px;
    color: #64748b;
    font-weight: 400;
    line-height: 1.6;
}

/* ---- Loader ---- */
.loading {
    display: inline-block;
    width: 20px;
    height: 20px;
    opacity: 0.7;
}

/* ---- Control Label ---- */
.control-label {
    font-weight: 600;
    color: #1e293b;
    font-size: 14px;
    margin-bottom: 8px;
}

/* ---- Responsive Design ---- */
@media (max-width: 768px) {
    .setup-page-header {
        flex-direction: column;
        text-align: center;
        padding: 20px 16px;
    }

    .setup-page-header__icon {
        width: 48px;
        height: 48px;
    }

    .setup-page-header__icon .glyphicon {
        font-size: 20px;
    }

    .setup-page-header__title {
        font-size: 20px;
    }

    .setup-page-header__subtitle {
        font-size: 13px;
    }

    .panel-heading {
        padding: 14px 16px;
        font-size: 13px;
    }

    .panel-body {
        padding: 16px;
    }

    .btn {
        padding: 8px 12px;
        font-size: 13px;
    }

    .form-control {
        padding: 8px 10px;
        font-size: 14px;
    }

    .control-label {
        font-size: 13px;
    }
}

@media (max-width: 480px) {
    .setup-page-header {
        padding: 16px 12px;
    }

    .setup-page-header__title {
        font-size: 18px;
    }

    .setup-page-header__subtitle {
        font-size: 12px;
    }
}
</style>

<?php template_bottom();