<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

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
	    $mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
		$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `user_id`='".$mysql['user_id']."' AND `aff_campaign_id`='".$mysql['aff_campaign_id']."'";
	    $aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
	    if ($aff_campaign_result->num_rows == 0 ) {
			$error['wrong_user'] = '<div class="error">You are not authorized to add a landing page to another users campaign</div>';    
	    }
    	}
    
    //if editing, check to make sure the own the campaign they are editing
    if ($editing == true) {
		$mysql['landing_page_id'] = $db->real_escape_string($_POST['landing_page_id']);
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
		$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND `landing_page_id`='".$mysql['landing_page_id']."'";
        $landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
		if ($landing_page_result->num_rows == 0 ) {
            $error['wrong_user'] .= '<div class="error">You are not authorized to modify another users campaign</div>';    
        }
    }
	
	if (!$error) { 
	      $mysql['landing_page_id'] = $db->real_escape_string($_POST['landing_page_id']);
	      $mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);
	      $mysql['landing_page_nickname'] = $db->real_escape_string($_POST['landing_page_nickname']);
	      $mysql['landing_page_url'] = $db->real_escape_string($_POST['landing_page_url']);
		$mysql['landing_page_type'] = $db->real_escape_string($_POST['landing_page_type']);
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
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
		$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
		$add_success = true;
		
		if ($editing == true) {
			//if the edit completed, redirect to the page 
			header('location: /tracking202/setup/landing_pages.php');        
		}
		
		if ($editing != true) {
			//if this landing page is brand new, add on a landing_page_id_public
			$landing_page_row['landing_page_id'] = $db->insert_id;
			$landing_page_id_public = rand(1,9) . $landing_page_row['landing_page_id'] . rand(1,9);
			$mysql['landing_page_id_public'] = $db->real_escape_string($landing_page_id_public);
            	$mysql['landing_page_id'] = $db->real_escape_string($landing_page_row['landing_page_id']);                            
			
			$landing_page_sql = "	UPDATE       `202_landing_pages`
								 	SET          	 `landing_page_id_public`='".$mysql['landing_page_id_public']."'
								 	WHERE        `landing_page_id`='".$mysql['landing_page_id']."'";
			$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
			
        }
	}
}

if (isset($_GET['delete_landing_page_id'])) { 

	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
    	$mysql['landing_page_id'] = $db->real_escape_string($_GET['delete_landing_page_id']);
	$mysql['landing_page_time'] = time();
	

	
    $delete_sql = " UPDATE  `202_landing_pages`
					SET     `landing_page_deleted`='1',
							`landing_page_time`='".$mysql['landing_page_time']."'
					WHERE   `user_id`='".$mysql['user_id']."'
					AND     `landing_page_id`='".$mysql['landing_page_id']."'";
    
    if ($delete_result = $db->query($delete_sql) or record_mysql_error($delete_result)) {
        $delete_success = true;
    }
}

if (($_GET['edit_landing_page_id']) and ($_SERVER['REQUEST_METHOD'] != 'POST')) { 
	
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
    $mysql['landing_page_id'] = $db->real_escape_string($_GET['edit_landing_page_id']);
    
	$landing_page_sql = "SELECT * 
                         FROM   `202_landing_pages`
                         WHERE  `landing_page_id`='".$mysql['landing_page_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
    $landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
	$landing_page_row = $landing_page_result->fetch_assoc();
	
	$mysql['aff_campaign_id'] = $db->real_escape_string($landing_page_row['aff_campaign_id']);
	$html['aff_campaign_id'] = htmlentities($landing_page_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');    
	$html['landing_page_id'] = htmlentities($_GET['edit_landing_page_id'], ENT_QUOTES, 'UTF-8');    
	$html['landing_page_type'] = htmlentities($landing_page_row['landing_page_type'], ENT_QUOTES, 'UTF-8');    
	$html['landing_page_nickname'] = htmlentities($landing_page_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
	$html['landing_page_url'] = htmlentities($landing_page_row['landing_page_url'], ENT_QUOTES, 'UTF-8');     

} elseif (($_SERVER['REQUEST_METHOD'] == 'POST') and ($add_success != true)) {
	
    	$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);
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
    $aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
    $aff_campaign_row = $aff_campaign_result->fetch_assoc();

    $mysql['aff_network_id'] = $db->real_escape_string($aff_campaign_row['aff_network_id']);
    $aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `aff_network_id`='".$mysql['aff_network_id']."'";
    $aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
    $aff_network_row = $aff_network_result->fetch_assoc();

    $html['aff_network_id'] = htmlentities($aff_network_row['aff_network_id'], ENT_QUOTES, 'UTF-8');
}

template_top($server_row,'Landing Page Setup',NULL,NULL,NULL);  ?>
		
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-5">
				<h6>Landing Page Setup (optional)</h6>
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
		<small>Please type in the URL addresses of the landing pages you plan on using. </small>
	
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-7">
		<small><strong>Add A Landing Page (optional)</strong></small><br/>
		<span class="infotext">Here you can add different landing pages you might use with your marketing.</span>

		<form method="post" action="<?php if ($delete_success == true) { echo $_SERVER['REDIRECT_URL']; }?>" class="form-horizontal" role="form" style="margin:15px 0px;">
			<input name="landing_page_id" type="hidden" value="<?php echo $html['landing_page_id']; ?>"/>
			<div class="form-group" style="margin-bottom: 0px;" id="radio-select">
				<label class="col-xs-4 control-label" style="text-align: left;" id="width-tooltip">Landing Page Type <span class="fui-info" data-toggle="tooltip" title="A Simple Landing Page is a landing page that only has one offer associated with it. Where as an Advanced Landing Page is a landing page that can run several offers on it. An example would be a retail landing page where you have outgoing links to several different products."></span></label>

				<div class="col-xs-8" style="margin-top: 10px;">
					<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="landing_page_type" id="landing_page_type1" value="0" data-toggle="radio" <?php if ($html['landing_page_type'] == '0' or !$html['landing_page_type']) { echo 'checked'; }?>>
	            			Simple (One Offer on the page)
	          		</label>
	          		<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="landing_page_type" id="landing_page_type2" value="1" data-toggle="radio" <?php if ($html['landing_page_type'] == '1') { echo 'checked'; }?>>
	            			Advanced (Mutiple Offers on the page)
	          		</label>
	          	</div>
	        </div>

	        <div id="aff-campaign-div" <?php if ($html['landing_page_type'] == '1') { echo 'style="display:none;"'; } ?>>
		        <div class="form-group <?php if($error['aff_campaign_id']) echo "has-error";?>" style="margin-bottom: 0px;">
		        	<label for="aff_network_id" class="col-xs-4 control-label" style="text-align: left;">Category:</label>
		        	<div class="col-xs-6" style="margin-top: 10px;">
		        		<img id="aff_network_id_div_loading" class="loading" src="/202-img/loader-small.gif"/>
	                	<div id="aff_network_id_div"></div>
		        	</div>
		        </div>

		        <div id="aff-campaign-group" class="form-group <?php if($error['aff_campaign_id']) echo "has-error";?>" style="margin-bottom: 0px;">
		        	<label for="aff_campaign_id" class="col-xs-4 control-label" style="text-align: left;">Campaign:</label>
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

	        <div class="form-group <?php if($error['landing_page_nickname']) echo "has-error";?>" style="margin-bottom: 0px;">
		        <label for="landing_page_nickname" class="col-xs-4 control-label" style="text-align: left;">LP Nickname:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
	                <input type="text" class="form-control input-sm" id="landing_page_nickname" name="landing_page_nickname" value="<?php echo $html['landing_page_nickname']; ?>">
		        </div>
		    </div>

		    <div class="form-group <?php if($error['landing_page_url']) echo "has-error";?>" style="margin-bottom: 10px;">
		        <label for="landing_page_url" class="col-xs-4 control-label" style="text-align: left;">Landing Page URL:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
	                <input type="text" class="form-control input-sm" id="landing_page_url" name="landing_page_url" value="<?php echo $html['landing_page_url']; ?>">
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
								<button type="submit" class="btn btn-sm btn-danger btn-block" onclick="window.location='/tracking202/setup/landing_pages.php'; return false;">Cancel</button>					    		</div>
					    	</div>
				    <?php } else { ?>
				    		<button class="btn btn-sm btn-p202 btn-block" type="submit" id="addedLp" >Add</button>					
					<?php } ?>
				</div>
			</div>

		</form>
	</div>

	<div class="col-xs-4 col-xs-offset-1">
		<div class="panel panel-default">
			<div class="panel-heading">My Advanced Landing Pages</div>
			<div class="panel-body">
				<ul>
	                <?php $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	                $landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND landing_page_type='1' AND landing_page_deleted='0'";
	                $landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
	                
	                if ($landing_page_result->num_rows == 0 ) { 
		                ?><li>You have no advanced landing page.</li><?
		            }

	                while ($landing_page_row = $landing_page_result->fetch_array(MYSQL_ASSOC)) {
	                    $html['landing_page_nickname'] = htmlentities($landing_page_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
	                              $html['landing_page_id'] = htmlentities($landing_page_row['landing_page_id'], ENT_QUOTES, 'UTF-8');
	                              printf('<li>%s - <a href="?edit_landing_page_id=%s">edit</a> - <a href="?delete_landing_page_id=%s" onclick="return confirmAlert(\'Are You Sure You Want To Delete This Landing Page?\');">remove</a></li>', $html['landing_page_nickname'], $html['landing_page_id'], $html['landing_page_id']);
	                } ?>
            	</ul>
			</div>
		</div>

		<div class="panel panel-default">
			<div class="panel-heading">My Simple Landing Pages</div>
			<div class="panel-body">
				<ul>        
		            <?php  $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
		                $aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='".$mysql['user_id']."' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";
		                $aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
		                if ($aff_network_result->num_rows == 0 ) { 
		                    ?><li>You have no simple landing page.</li><?
		                }
		                
		                while ($aff_network_row = $aff_network_result->fetch_array(MYSQL_ASSOC)) {
		                    $html['aff_network_name'] = htmlentities($aff_network_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
		                    $url['aff_network_id'] = urlencode($aff_network_row['aff_network_id']);
		                    
		                    printf('<li>%s</li>', $html['aff_network_name']);
		                    
		                    ?><ul><?
		                                        
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
		                                $landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `aff_campaign_id`='".$mysql['aff_campaign_id']."' AND `landing_page_deleted`='0' AND landing_page_type='0'";
		                                $landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
		                                
		                                while ($landing_page_row = $landing_page_result->fetch_array(MYSQL_ASSOC)) {
		                                    
		                                    $html['landing_page_nickname'] = htmlentities($landing_page_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
		                                    $html['landing_page_id'] = htmlentities($landing_page_row['landing_page_id'], ENT_QUOTES, 'UTF-8');
		                                    
		                                    printf('<li>%s - <a href="?edit_landing_page_id=%s">edit</a> - <a href="?delete_landing_page_id=%s" onclick="return confirmAlert(\'Are You Sure You Want To Delete This Landing Page?\');">remove</a></li>', $html['landing_page_nickname'], $html['landing_page_id'], $html['landing_page_id']);
		                        
		                                    
		                                }

		                            ?></ul><?php                        
		                        } 
		                    
		                    ?></ul><?
		                    
		                } 
		            ?>
	            </ul>
			</div>
		</div>
	</div>

</div>
<!-- open up the ajax aff network -->
<script type="text/javascript">
$(document).ready(function() {

   	load_aff_network_id('<?php echo $html['aff_network_id']; ?>');
    <?php if ($html['aff_network_id'] != '') { ?>
        load_aff_campaign_id('<?php echo $html['aff_network_id']; ?>','<?php echo $html['aff_campaign_id']; ?>');
    <?php } ?>
});
</script>
<?php template_bottom($server_row);