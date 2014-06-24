<?
include_once ($_SERVER ['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user ();

if ($_GET ['edit_aff_campaign_id']) {
	$editing = true;
}

if ($_SERVER ['REQUEST_METHOD'] == 'POST') {
	
	$aff_network_id = trim ( $_POST ['aff_network_id'] );
	if (empty ( $aff_network_id )) {
		$error ['aff_network_id'] = '<div class="error">Select a category.</div>';
	}
	
	$aff_campaign_name = trim ( $_POST ['aff_campaign_name'] );
	if (empty ( $aff_campaign_name )) {
		$error ['aff_campaign_name'] = '<div class="error">What is the name of this campaign.</div>';
	}
	
	$aff_campaign_url = trim ( $_POST ['aff_campaign_url'] );
	if (empty ( $aff_campaign_url )) {
		$error ['aff_campaign_url'] = '<div class="error">What is your affiliate link? Make sure subids can be added to it.</div>';
	}
	

	if ((substr ( $_POST ['aff_campaign_url'], 0, 7 ) != 'http://') and (substr ( $_POST ['aff_campaign_url'], 0, 8 ) != 'https://')) {
		$error ['aff_campaign_url'] .= '<div class="error">Your Landing Page URL must start with http:// or https://</div>';
	}
	
	$aff_campaign_payout = trim ( $_POST ['aff_campaign_payout'] );
	if (! is_numeric ( $aff_campaign_payout )) {
		$error ['aff_campaign_payout'] .= '<div class="error">Please enter in a numeric number for the payout.</div>';
	}
	
	//check to see if they are the owners of this affiliate network
	$mysql ['aff_network_id'] = $db->real_escape_string ( $_POST ['aff_network_id'] );
	$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
	$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='" . $mysql ['user_id'] . "' AND `aff_network_id`='" . $mysql ['aff_network_id'] . "'";
	$aff_network_result = $db->query ( $aff_network_sql ) or record_mysql_error ( $aff_network_sql );
	if ($aff_network_result->num_rows == 0) {
		$error ['wrong_user'] = '<div class="error">You are not authorized to add an campaign to another users network</div>';
	}
	
	//if editing, check to make sure the own the campaign they are editing
	if ($editing == true) {
		$mysql ['aff_campaign_id'] = $db->real_escape_string ( $_POST ['aff_campaign_id'] );
		$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
		$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `user_id`='" . $mysql ['user_id'] . "' AND `aff_campaign_id`='" . $mysql ['aff_campaign_id'] . "'";
		$aff_campaign_result = $db->query ( $aff_campaign_sql ) or record_mysql_error ( $aff_campaign_sql );
		if ($aff_campaign_result->num_rows == 0) {
			$error ['wrong_user'] .= '<div class="error">You are not authorized to modify another users campaign</div>';
		}
	}
	
	if (! $error) {
		$mysql ['aff_campaign_id'] = $db->real_escape_string ( $_POST ['aff_campaign_id'] );
		$mysql ['aff_network_id'] = $db->real_escape_string ( $_POST ['aff_network_id'] );
		$mysql ['aff_campaign_name'] = $db->real_escape_string ( $_POST ['aff_campaign_name'] );
		$mysql ['aff_campaign_url'] = $db->real_escape_string ( $_POST ['aff_campaign_url'] );
		$mysql ['aff_campaign_url_2'] = $db->real_escape_string ( $_POST ['aff_campaign_url_2'] );
		$mysql ['aff_campaign_url_3'] = $db->real_escape_string ( $_POST ['aff_campaign_url_3'] );
		$mysql ['aff_campaign_url_4'] = $db->real_escape_string ( $_POST ['aff_campaign_url_4'] );
		$mysql ['aff_campaign_url_5'] = $db->real_escape_string ( $_POST ['aff_campaign_url_5'] );
		$mysql ['aff_campaign_rotate'] = $db->real_escape_string ( $_POST ['aff_campaign_rotate'] );
		$mysql ['aff_campaign_payout'] = $db->real_escape_string ( $_POST ['aff_campaign_payout'] );
		$mysql ['aff_campaign_cloaking'] = $db->real_escape_string ( $_POST ['aff_campaign_cloaking'] );
		$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
		$mysql ['aff_campaign_time'] = time ();
		
		if ($editing == true) {
			$aff_campaign_sql = "UPDATE `202_aff_campaigns` SET";
		} else {
			$aff_campaign_sql = "INSERT INTO `202_aff_campaigns` SET";
		}
		
		$aff_campaign_sql .= "`aff_network_id`='" . $mysql ['aff_network_id'] . "',
													  `user_id`='" . $mysql ['user_id'] . "',
													  `aff_campaign_name`='" . $mysql ['aff_campaign_name'] . "',
													  `aff_campaign_url`='" . $mysql ['aff_campaign_url'] . "',
													  `aff_campaign_url_2`='" . $mysql ['aff_campaign_url_2'] . "',
													  `aff_campaign_url_3`='" . $mysql ['aff_campaign_url_3'] . "',
													  `aff_campaign_url_4`='" . $mysql ['aff_campaign_url_4'] . "',
													  `aff_campaign_url_5`='" . $mysql ['aff_campaign_url_5'] . "',
													  `aff_campaign_rotate`='" . $mysql ['aff_campaign_rotate'] . "',
													  `aff_campaign_payout`='" . $mysql ['aff_campaign_payout'] . "',
													  `aff_campaign_cloaking`='" . $mysql ['aff_campaign_cloaking'] . "',
													  `aff_campaign_time`='" . $mysql ['aff_campaign_time'] . "'";
		
		if ($editing == true) {
			$aff_campaign_sql .= "WHERE `aff_campaign_id`='" . $mysql ['aff_campaign_id'] . "'";
		}
		$aff_campaign_result = $db->query ( $aff_campaign_sql ) or record_mysql_error ( $aff_campaign_sql );
		$add_success = true;
		
		if ($editing != true) {
			//if this landing page is brand new, add on a landing_page_id_public
			$aff_campaign_row ['aff_campaign_id'] = $db->insert_id;
			$aff_campaign_id_public = rand ( 1, 9 ) . $aff_campaign_row ['aff_campaign_id'] . rand ( 1, 9 );
			$mysql ['aff_campaign_id_public'] = $db->real_escape_string ( $aff_campaign_id_public );
			$mysql ['aff_campaign_id'] = $db->real_escape_string ( $aff_campaign_row ['aff_campaign_id'] );
			
			$aff_campaign_sql = "	UPDATE       `202_aff_campaigns`
								 	SET          	 `aff_campaign_id_public`='" . $mysql ['aff_campaign_id_public'] . "'
								 	WHERE        `aff_campaign_id`='" . $mysql ['aff_campaign_id'] . "'";
			$aff_campaign_result = $db->query ( $aff_campaign_sql ) or record_mysql_error ( $aff_campaign_sql );
		
		}
	
	}
}

if (isset ( $_GET ['delete_aff_campaign_id'] )) {
	
	$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
	$mysql ['aff_campaign_id'] = $db->real_escape_string ( $_GET ['delete_aff_campaign_id'] );
	$mysql ['date_deleted'] = time ();
	
	$delete_sql = " UPDATE  `202_aff_campaigns`
					SET     `aff_campaign_deleted`='1',
							`aff_campaign_time`='" . $mysql ['aff_campaign_time'] . "'
					WHERE   `user_id`='" . $mysql ['user_id'] . "'
					AND     `aff_campaign_id`='" . $mysql ['aff_campaign_id'] . "'";
	if ($delete_result = $db->query ( $delete_sql ) or record_mysql_error ( $delete_result )) {
		$delete_success = true;
	}
}

if ($_GET ['edit_aff_campaign_id']) {
	
	$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
	$mysql ['aff_campaign_id'] = $db->real_escape_string ( $_GET ['edit_aff_campaign_id'] );
	
	$aff_campaign_sql = "SELECT 	* 
						 FROM   	`202_aff_campaigns`
						 WHERE  	`aff_campaign_id`='" . $mysql ['aff_campaign_id'] . "'
						 AND    		`user_id`='" . $mysql ['user_id'] . "'";
	$aff_campaign_result = $db->query ( $aff_campaign_sql ) or record_mysql_error ( $aff_campaign_sql );
	$aff_campaign_row = $aff_campaign_result->fetch_assoc();
	
	$selected ['aff_network_id'] = $aff_campaign_row ['aff_network_id'];
	$html = array_map ( 'htmlentities', $aff_campaign_row );
	$html ['aff_campaign_id'] = htmlentities ( $_GET ['edit_aff_campaign_id'], ENT_QUOTES, 'UTF-8' );

}

//this will override the edit, if posting and edit fail
if (($_SERVER ['REQUEST_METHOD'] == 'POST') and ($add_success != true)) {
	
	$selected ['aff_network_id'] = $_POST ['aff_network_id'];
	$html = array_map ( 'htmlentities', $_POST );
}

template_top ( 'Affiliate Campaigns Setup', NULL, NULL, NULL );
?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6>Campaign Setup</h6>
			</div>
			<div class="col-xs-8">
				<div class="<?php if($error) echo "error"; else echo "success";?> pull-right" style="margin-top: 20px;">
					<small>
						<?php if ($error) { ?> 
							<span class="fui-alert"></span> There were errors with your submission. <?php echo $error['token']; ?>
						<?php } ?>
						<?php if ($add_success == true) { ?>
							<span class="fui-check-inverted"></span> Your submission was successful. Your changes have been saved.
						<?php } ?>
						<?php if ($delete_success == true) { ?>
							<span class="fui-check-inverted"></span> You deletion was successful. You have succesfully removed a campaign.
						<?php } ?>
						
					</small>
				</div>
			</div>
		</div>
	</div>
	<div class="col-xs-12">
		<small>Add the campaigns you want to run. <span class="fui-info" style="cursor:pointer;" id="help-text-trigger"></span></small>
		<span style="display:none" id="help-text"><br/>
			<span class="infotext">
				<em>If you do not understand how subids work at your network, stop, and contact your affiliate manager.<br/>
					Prosper202 supports the ability to cloak your traffic; cloaking will
					prevent your advertisers and the affiliate networks who you work with
					from seeing your keywords. Please note if you are doing direct linking
					with Google Adwords, a cloaked direct linking setup can kill your
					qualitly score. Don't understand cloaking? Leave it off for now and
					learn more about it in our help section later.
				</em>
		</span></span>
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-7">
		<small><strong>Add A Campaign</strong></small><br/>
		<span class="infotext">Here you add each of the campaigns you are running.</span>
				
		<form method="post" class="form-horizontal" action="<?php if ($delete_success == true) { echo $_SERVER ['REDIRECT_URL']; } ?>" role="form" style="margin:15px 0px;">
			<input name="aff_campaign_id" type="hidden" value="<?php echo $html ['aff_campaign_id'];?>" />
			<div class="form-group <?php if($error['aff_network_id']) echo "has-error"; ?>" style="margin-bottom: 0px;">
				<label for="aff_network_id" class="col-xs-4 control-label" style="text-align: left;">Category:</label>
				<div class="col-xs-6">
				    <select class="form-control input-sm" name="aff_network_id" id="aff_network_id">
				    	<option value="">--</option>
				    	<?
								$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
								$aff_network_sql = "
										SELECT *
										FROM `202_aff_networks`
										WHERE `user_id`='" . $mysql ['user_id'] . "'
										AND `aff_network_deleted`='0'
										ORDER BY `aff_network_name` ASC
									";
								$aff_network_result = $db->query ( $aff_network_sql ) or record_mysql_error ( $aff_network_sql );
								
								while ( $aff_network_row = $aff_network_result->fetch_array (MYSQL_ASSOC) ) {
									
									$html ['aff_network_name'] = htmlentities ( $aff_network_row ['aff_network_name'], ENT_QUOTES, 'UTF-8' );
									$html ['aff_network_id'] = htmlentities ( $aff_network_row ['aff_network_id'], ENT_QUOTES, 'UTF-8' );
									
									if ($selected ['aff_network_id'] == $aff_network_row ['aff_network_id']) {
										printf ( '<option selected="selected" value="%s">%s</option>', $html ['aff_network_id'], $html ['aff_network_name'] );
									} else {
										printf ( '<option value="%s">%s</option>', $html ['aff_network_id'], $html ['aff_network_name'] );
									}
								}
								?>
				    </select>
				</div>
			</div>

			<div class="form-group <?php if($error['aff_campaign_name']) echo "has-error";?>" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="aff_campaign_name" style="text-align: left;">Campaign Name:</label>
				<div class="col-xs-6">
					<input type="text" class="form-control input-sm" id="aff_campaign_name" name="aff_campaign_name" value="<?php echo $html['aff_campaign_name']; ?>">
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
			<label class="col-xs-4 control-label" style="text-align: left;">Rotate Urls:</label>

				<div class="col-xs-2" style="margin-top: 10px;">
					<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="aff_campaign_rotate" id="aff_campaign_rotate1" value="0" data-toggle="radio" <?php if ($html ['aff_campaign_rotate'] == 0) echo 'checked';?>>
	            			No
	          		</label>
	          	</div>
	          	<div class="col-xs-2" style="margin-top: 10px;">
		            <label class="radio" style="line-height: 0.5;">
		            	<input type="radio" name="aff_campaign_rotate" id="aff_campaign_rotate2" value="1" data-toggle="radio" <?php if ($html ['aff_campaign_rotate'] == 1) echo 'checked';?>>
		            		Yes
		            </label>
		        </div>
			</div>

			<div class="form-group <?php if($error['aff_campaign_url']) echo "has-error";?>" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="aff_campaign_url" style="text-align: left;">Campaign URL <span class="fui-info" data-toggle="tooltip" title="This is where people will be sent when yout tracking link is clicked. If you are running an affiliate campaign, this will be where to put your affiliate url."></span></label>
				<div class="col-xs-6">
					<textarea name="aff_campaign_url" id="aff_campaign_url"class="form-control input-sm" rows="3" placeholder="http://"><?php echo $html['aff_campaign_url']; ?></textarea>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 10px;">
				<div class="col-xs-6 col-xs-offset-4" id="placeholders">
					<input style="margin-left: 1px;" type="button" class="btn btn-xs btn-default" value="[[subid]]"/>
					<input type="button" class="btn btn-xs btn-default" value="[[c1]]"/> 
				    <input type="button" class="btn btn-xs btn-default" value="[[c2]]"/> 
				    <input type="button" class="btn btn-xs btn-default" value="[[c3]]"/> 
				    <input type="button" class="btn btn-xs btn-default" value="[[c4]]"/>
					<span class="help-block" style="font-size: 12px;">The following tracking placeholders can be used:<br/>[[subid]], [[c1]], [[c2]], [[c3]], [[c4]]</span>
				</div>
			</div>

			<div id="rotateUrls" <?if ($html ['aff_campaign_rotate'] == 0) echo 'style="display:none;"';?> >
				<div id="rotateUrl2" class="form-group <?php if($error['aff_campaign_url_2']) echo "has-error";?>" style="margin-bottom: 0px;">
					<label class="col-xs-4 control-label" for="aff_campaign_url_2" style="text-align: left;">Rotate Url #2:</label>
					<div class="col-xs-6">
						<input type="text" class="form-control input-sm" id="aff_campaign_url_2" name="aff_campaign_url_2" value="<?php echo $html['aff_campaign_url_2']; ?>">
					</div>
				</div>

				<div id="rotateUrl3" class="form-group <?php if($error['aff_campaign_url_3']) echo "has-error";?>" style="margin-bottom: 0px;">
					<label class="col-xs-4 control-label" for="aff_campaign_url_3" style="text-align: left;">Rotate Url #3:</label>
					<div class="col-xs-6">
						<input type="text" class="form-control input-sm" id="aff_campaign_url_3" name="aff_campaign_url_3" value="<?php echo $html['aff_campaign_url_3']; ?>">
					</div>
				</div>

				<div id="rotateUrl4" class="form-group <?php if($error['aff_campaign_url_4']) echo "has-error";?>" style="margin-bottom: 0px;">
					<label class="col-xs-4 control-label" for="aff_campaign_url_4" style="text-align: left;">Rotate Url #4:</label>
					<div class="col-xs-6">
						<input type="text" class="form-control input-sm" id="aff_campaign_url_4" name="aff_campaign_url_4" value="<?php echo $html['aff_campaign_url_4']; ?>">
					</div>
				</div>

				<div id="rotateUrl5" class="form-group <?php if($error['aff_campaign_url_5']) echo "has-error";?>" style="margin-bottom: 0px;">
					<label class="col-xs-4 control-label" for="aff_campaign_url_2" style="text-align: left;">Rotate Url #5:</label>
					<div class="col-xs-6">
						<input type="text" class="form-control input-sm" id="aff_campaign_url_5" name="aff_campaign_url_5" value="<?php echo $html['aff_campaign_url_5']; ?>">
					</div>
				</div>
			</div>

			<div class="form-group <?php if($error['aff_campaign_payout']) echo "has-error";?>" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="aff_campaign_payout" style="text-align: left;">Payout $</label>
				<div class="col-xs-2">
					<input type="text" size="4" class="form-control input-sm" id="aff_campaign_payout" name="aff_campaign_payout" value="<?php echo $html['aff_campaign_payout']; ?>">
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label for="aff_campaign_cloaking" class="col-xs-4 control-label" style="text-align: left;">Cloaking:</label>
				<div class="col-xs-6">
				    <select class="form-control input-sm" name="aff_campaign_cloaking" id="aff_campaign_cloaking">
				    	<option <?php if ($html ['aff_campaign_cloaking'] == '0') { echo 'selected=""'; } ?> value="0">Off by default</option>
						<option <?php if ($html ['aff_campaign_cloaking'] == '1') { echo 'selected=""'; } ?> value="1">On by default</option>
				    </select>
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
								<button type="submit" class="btn btn-sm btn-danger btn-block" onclick="window.location='/tracking202/setup/aff_campaigns.php'; return false;">Cancel</button>					    		</div>
					    	</div>
				    <?php } else { ?>
				    		<button class="btn btn-sm btn-p202 btn-block" type="submit" id="addCampaign">Add</button>					
					<?php } ?>
				</div>
			</div>

		</form>
	</div>
	<div class="col-xs-4 col-xs-offset-1">
		<div class="panel panel-default">
			<div class="panel-heading">My Campaigns</div>
			<div class="panel-body">
				<ul>        
					<?
					$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
					$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='" . $mysql ['user_id'] . "' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";
					$aff_network_result = $db->query ( $aff_network_sql ) or record_mysql_error ( $aff_network_sql );
					if ($aff_network_result->num_rows == 0) {
						?><li>You have not added any networks.</li><?
					}
					
					while ( $aff_network_row = $aff_network_result->fetch_array (MYSQL_ASSOC) ) {
						$html ['aff_network_name'] = htmlentities ( $aff_network_row ['aff_network_name'], ENT_QUOTES, 'UTF-8' );
						$url ['aff_network_id'] = urlencode ( $aff_network_row ['aff_network_id'] );
						
						printf ( '<li><strong>%s</strong></li>', $html ['aff_network_name'] );
						
						?><ul style="margin-top: 0px;"><?
						
						//print out the individual accounts per each PPC network
						$mysql ['aff_network_id'] = $db->real_escape_string ( $aff_network_row ['aff_network_id'] );
						$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `aff_network_id`='" . $mysql ['aff_network_id'] . "' AND `aff_campaign_deleted`='0' ORDER BY `aff_campaign_name` ASC";
						$aff_campaign_result = $db->query ( $aff_campaign_sql ) or record_mysql_error ( $aff_campaign_sql );
						
						while ( $aff_campaign_row = $aff_campaign_result->fetch_array (MYSQL_ASSOC) ) {
							
							$html ['aff_campaign_name'] = htmlentities ( $aff_campaign_row ['aff_campaign_name'], ENT_QUOTES, 'UTF-8' );
							$html ['aff_campaign_payout'] = htmlentities ( $aff_campaign_row ['aff_campaign_payout'], ENT_QUOTES, 'UTF-8' );
							$html ['aff_campaign_url'] = htmlentities ( $aff_campaign_row ['aff_campaign_url'], ENT_QUOTES, 'UTF-8' );
							$html ['aff_campaign_id'] = htmlentities ( $aff_campaign_row ['aff_campaign_id'], ENT_QUOTES, 'UTF-8' );
							$html ['aff_campaign_rotate'] = htmlentities ( $aff_campaign_row ['aff_campaign_rotate'], ENT_QUOTES, 'UTF-8' );
							if($html ['aff_campaign_rotate'])
							printf ( '<li> <span class="glyphicon glyphicon-repeat" style="font-size: 12px;"></span> %s &middot; &#36;%s - <a href="%s" target="_new">link</a> - <a href="?edit_aff_campaign_id=%s">edit</a> - <a href="?delete_aff_campaign_id=%s" onclick="return confirmAlert(\'Are You Sure You Want To Delete This Campaign?\');">remove</a></li>', $html ['aff_campaign_name'], $html ['aff_campaign_payout'], $html ['aff_campaign_url'], $html ['aff_campaign_id'], $html ['aff_campaign_id'] );
							else 
							printf ( '<li>%s &middot; &#36;%s - <a href="%s" target="_new">link</a> - <a href="?edit_aff_campaign_id=%s">edit</a> - <a href="?delete_aff_campaign_id=%s" onclick="return confirmAlert(\'Are You Sure You Want To Delete This Campaign?\');">remove</a></li>', $html ['aff_campaign_name'], $html ['aff_campaign_payout'], $html ['aff_campaign_url'], $html ['aff_campaign_id'], $html ['aff_campaign_id'] );
						
						}
						
						?></ul><?
					
					}
					?>
				</ul>
			</div>
		</div>
	</div>
</div>
<script type="text/javascript" src="/202-js/jquery.caret.js"></script>
<?php template_bottom(); ?>			