<?php
include_once(str_repeat("../", 1).'202-config/connect.php');
include_once(str_repeat("../", 1).'202-config/clickserver_api_management.php');

AUTH::require_user();

$strProtocol = stripos($_SERVER['SERVER_PROTOCOL'],'https') === true ? 'https://' : 'http://';
$mysql['add_dni'] = $db->real_escape_string($_GET['add_dni_network']);
$slack = false;
$mysql['user_own_id'] = $db->real_escape_string($_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url, 2u.install_hash, 2u.p202_customer_api_key, 2up.facebook_ads_linked FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();
$username = $user_row['username'];
$editing_dni_network = false;
$dniNetworks = getAllDniNetworks($user_row['install_hash']);
$dniProcesing = array('host' => getDNIHost(), 'install_hash' => $user_row['install_hash'], 'networks' => array());

if (!empty($user_row['url'])) 
	$slack = new Slack($user_row['url']);

if ($_GET['cb_status'] == 1) {
        $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
        $user_sql = "SELECT cb_verified
             FROM 202_users_pref
             WHERE user_id='".$mysql['user_id']."'";
        $user_results = $db->query($user_sql);
        $user_row = $user_results->fetch_assoc();
        if($user_row['cb_verified']) {
                echo '<span class="label label-primary">Verified</span>';
        } else {
                echo '<span class="label label-important">Unverified</span>';
        }
        die();
}

//get all of the user data
$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
$user_sql = "	SELECT 	*
				 FROM   	`202_users` 
				 LEFT JOIN	`202_users_pref` USING (user_id)
				 WHERE  	`202_users`.`user_id`='".$mysql['user_id']."'";
$user_result = $db->query($user_sql);
$user_row = $user_result->fetch_assoc();
$html = array_map('htmlentities', $user_row);

$cb_verified = $user_row['cb_verified'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if ($_POST['change_cb_key'] == '1') {

        if ($_POST['cb_key'] == '') {
                                
            $error['cb_key'] .= 'Clickbank Secret Key can\'t be empty!';
        }

        if (!$error) {
                                
            $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
            $mysql['cb_key'] = $db->real_escape_string($_POST['cb_key']);
            $mysql['cb_verified'] = $db->real_escape_string(0);

                if ($mysql['cb_key'] != $user_row['cb_key']) {
                                        
                    $user_sql = "
                                UPDATE
                                    `202_users_pref`
                                SET
                                    `cb_key`='".$mysql['cb_key']."',
                                    `cb_verified`='".$mysql['cb_verified']."'
                                WHERE
                                    `user_id`='".$mysql['user_id']."'
                                ";
                    $user_result = $db->query($user_sql);
                    $cb_verified = false;
                }
                                        
                $change_cb_key = true;

                if ($slack) {
                    if ($_POST['cb_key'] != $user_row['cb_key']) {
                    	$slack->push('cb_key_updated', array('user' => $username));
                    }
                }                       
        }
    }

    if ($_POST['change_user_slack_incoming_webhook'] == '1') {
    
        if ($_POST['user_slack_incoming_webhook'] == '') {
    
            $error['user_slack_incoming_webhook'] .= 'Slack Incoming Webhook URL can\'t be empty!';
        }
    
        if (!$error) {
    
            $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
            $mysql['user_slack_incoming_webhook'] = $db->real_escape_string($_POST['user_slack_incoming_webhook']);
            
            if ($mysql['user_slack_incoming_webhook']) {
    
                $user_sql = "
                                UPDATE
                                    `202_users_pref`
                                SET
                                    `user_slack_incoming_webhook`='".$mysql['user_slack_incoming_webhook']."'
                                   
                                WHERE
                                    `user_id`='".$mysql['user_id']."'
                                ";
                $user_result = $db->query($user_sql);
              
            }
    
            $change_user_slack_incoming_webhook = true;

            if ($slack) {
                if ($_POST['user_slack_incoming_webhook'] != $user_row['user_slack_incoming_webhook']) {
                    $slack->push('user_slack_incoming_webhook_updated', array('user' => $username));
                }
            }  
        }
    }

    if ($_POST['change_user_revcontent_settings'] == '1') {
    	if ($_POST['revcontent_user_id'] == '') {
            $error['user_revcontent'] .= 'RevContent user ID can\'t be empty!<br>';
        }

        if ($_POST['revcontent_user_secret'] == '') {
            $error['user_revcontent'] .= 'RevContent user secret can\'t be empty!<br>';
        }

        if (!$error) {
        	$mysql['revcontent_user_id'] = $db->real_escape_string($_POST['revcontent_user_id']);
            $mysql['revcontent_user_secret'] = $db->real_escape_string($_POST['revcontent_user_secret']);

            $validate = validateRevContentCredentials($mysql['revcontent_user_id'], $mysql['revcontent_user_secret']);

            if ($validate['success'] == true) {
            	$user_sql = "
                                UPDATE
                                    `202_users_pref`
                                SET
                                    `revcontent_user_id`='".$mysql['revcontent_user_id']."',
                                    `revcontent_user_secret`='".$mysql['revcontent_user_secret']."'
                                WHERE
                                    `user_id`='".$mysql['user_id']."'
                                ";
                $user_result = $db->query($user_sql);
                $change_user_revcontent_settings = true;
            } else {
            	$error['user_revcontent'] .= 'RevContent credentials are not valid!';
            }
        }
    }

    if ($_POST['change_zaxaa_api_signature'] == '1') {

    	if ($_POST['zaxaa_api_signature'] == '') {
    
            $error['zaxaa_api_signature_error'] .= 'Zaxaa API signature can\'t be empty!';
        }

        if (!$error) {
    
            $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
            $mysql['zaxaa_api_signature'] = $db->real_escape_string($_POST['zaxaa_api_signature']);
            
            if ($mysql['zaxaa_api_signature']) {
    
                $user_sql = "
                                UPDATE
                                    `202_users_pref`
                                SET
                                    `zaxaa_api_signature`='".$mysql['zaxaa_api_signature']."'
                                   
                                WHERE
                                    `user_id`='".$mysql['user_id']."'
                                ";
                $user_result = $db->query($user_sql);
              
            }
    
            $change_zaxaa_api_signature = true;

            if ($slack) {
                if ($_POST['zaxaa_api_signature'] != $user_row['zaxaa_api_signature']) {
                    $slack->push('zaxaa_api_signature_updated', array('user' => $username));
                }
            }  
        }
    }

    if ($_POST['change_jvzoo_secret_key'] == '1') {

    	if ($_POST['jvzoo_ipn_secret_key'] == '') {
    
            $error['jvzoo_secret_key_error'] .= 'JVZoo secret key can\'t be empty!';
        }

        if (!$error) {
    
            $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
            $mysql['jvzoo_ipn_secret_key'] = $db->real_escape_string($_POST['jvzoo_ipn_secret_key']);
            
            if ($mysql['jvzoo_ipn_secret_key']) {
    
                $user_sql = "
                                UPDATE
                                    `202_users_pref`
                                SET
                                    `jvzoo_ipn_secret_key`='".$mysql['jvzoo_ipn_secret_key']."'
                                   
                                WHERE
                                    `user_id`='".$mysql['user_id']."'
                                ";
                $user_result = $db->query($user_sql);
              
            }
    
            $change_jvzoo_secret_key = true;

            if ($slack) {
                if ($_POST['jvzoo_ipn_secret_key'] != $user_row['jvzoo_ipn_secret_key']) {
                    $slack->push('jvzoo_secret_key_updated', array('user' => $username));
                }
            }  
        }
    }    
	
	if ($_POST['change_ipqs_api_key'] == '1') {

    	if ($_POST['ipqs_api_key'] == '') {
    
            $error['ipqs_api_key_error'] .= 'The IPQualityScore API Key can\'t be empty!';
        }

        if (!$error) {
    
            $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
            $mysql['ipqs_api_key'] = $db->real_escape_string($_POST['ipqs_api_key']);
            
            if ($mysql['ipqs_api_key']) {
    
                $user_sql = "
                                UPDATE
                                    `202_users_pref`
                                SET
                                    `ipqs_api_key`='".$mysql['ipqs_api_key']."'
                                   
                                WHERE
                                    `user_id`='".$mysql['user_id']."'
                                ";
                $user_result = $db->query($user_sql);
            }
    
            $change_ipqs_api_key = true;

            if ($slack) {
                if ($_POST['ipqs_api_key'] != $user_row['ipqs_api_key']) {
                    $slack->push('ipqs_api_key_updated', array('user' => $username));
                }
            }  
        }
	} 
	
    if (isset($_POST['dni_network'])) {
    	if (array_search('', $_POST) !== false) {
    		$error['dni_network'] = 'Make sure all fields are selected and filled out!';
    	} else {
    		$mysql['dniNetworkId'] = $db->real_escape_string($_POST['dni_network']);
	    	$mysql['dniNetworkType'] = $db->real_escape_string($_POST['dni_network_type']);
	    	$dniNetworkName = explode(" (", $_POST['dni_network_name'], 2);
	    	$mysql['dniNetworkName'] = $db->real_escape_string($dniNetworkName[0]);
	    	$mysql['dniAffiliateId'] = $db->real_escape_string($_POST['dni_network_affiliate_id']);
	    	$mysql['dniApikey'] = $db->real_escape_string($_POST['dni_network_api_key']);
	    	$dniAuth = authDniNetworks($user_row['install_hash'], $_POST['dni_network'], $_POST['dni_network_api_key'], $_POST['dni_network_affiliate_id']);

	    	if ($dniAuth['auth'] == false) {
	    		$error['dni_network_auth'] = 'Can\'t authenticate with provided credentials. Try again!';
	    	} else {
	    		if(!isset($_POST['editing_dni_network'])) {
	    			$dniShortDescription = '';
	    			$dniFavIcon = '';
	    			foreach ($dniNetworks as $dniNetwork) {
	    				if ($dniNetwork['networkId'] == $_POST['dni_network']) {
	    					$dniShortDescription = $dniNetwork['shortDescription'];
	    					$dniFavIcon = $dniNetwork['favIconUrl'];
	    				}
	    			}

	    			$mysql['dniShortDescription'] = $db->real_escape_string($dniShortDescription);
	    			$mysql['dniFavIcon'] = $db->real_escape_string($dniFavIcon);
	    			$mysql['dniFavIcon'] = $db->real_escape_string($dniFavIcon);

	    			$dniProcessed = $db->real_escape_string($dniAuth['processed']);

		    		$sql = "INSERT INTO 202_dni_networks SET user_id = '".$mysql['user_id']."', networkId = '".$mysql['dniNetworkId']."', name = '".$mysql['dniNetworkName']."', type = '".$mysql['dniNetworkType']."', apiKey = '".$mysql['dniApikey']."', time = '".time()."', processed = '".$dniProcessed."', shortDescription = '".$mysql['dniShortDescription']."', favIcon = '".$mysql['dniFavIcon']."'";
		    		
		    		if ($_POST['dni_network_type'] == 'Cake') {
		    			$sql .= ", affiliateId = '".$mysql['dniAffiliateId']."'";
		    		}

		    		if($db->query($sql)) {
		    			$success['dni_network_added'] = $mysql['dniNetworkName']." network configured. API processing can take up to 5 minutes.";
		    			$sql = "INSERT INTO 202_aff_networks SET dni_network_id = '".$db->insert_id."', user_id = '".$mysql['user_id']."', aff_network_name = '".$mysql['dniNetworkName']." (DNI)"."', aff_network_time = '".time()."'";
		    			$db->query($sql);
		    		}

		    	} else if (isset($_POST['editing_dni_network_id']) && !empty($_POST['editing_dni_network_id'])){
		    		$mysql['editing_dni_network_id'] = $db->real_escape_string($_POST['editing_dni_network_id']);
		    		$sql = "UPDATE 202_dni_networks SET networkId = '".$mysql['dniNetworkId']."', name = '".$mysql['dniNetworkName']."', type = '".$mysql['dniNetworkType']."', apiKey = '".$mysql['dniApikey']."', time = '".time()."'";

		    		if ($_POST['dni_network_type'] == 'Cake') {
		    			$sql .= ", affiliateId = '".$mysql['dniAffiliateId']."'";
		    		}

		    		$sql .= " WHERE id = '".$mysql['editing_dni_network_id']."'";

		    		if($db->query($sql)) {
		    			$sql = "UPDATE 202_aff_networks SET aff_network_name = '".$mysql['dniNetworkName']." (DNI)"."', aff_network_time = '".time()."' WHERE dni_network_id = '".$mysql['editing_dni_network_id']."'";
		    			$db->query($sql);
		    			header('Location: '.get_absolute_url().'202-account/api-integrations.php?dni_network_updated=1');
						die();
		    		}
		    	}

		    	tagUserByNetwork($user_row['install_hash'], 'affiliate-networks', $dniNetworkName[0]);
	    	}	
    	}
    	
    }

    $html = array_merge($html, array_map('htmlentities', $_POST));
}


if (isset($_GET['delete_dni_network']) && !empty($_GET['delete_dni_network'])) {
	$mysql['deleteDniNetworkId'] = $db->real_escape_string($_GET['delete_dni_network']);
	$db->query("DELETE FROM 202_dni_networks WHERE id = '".$mysql['deleteDniNetworkId']."' AND user_id = '".$mysql['user_id']."'");
	$sql = "UPDATE 202_aff_networks SET aff_network_deleted = '1', aff_network_time = '".time()."' WHERE dni_network_id = '".$mysql['deleteDniNetworkId']."'";
	$db->query($sql);
	header('Location: '.get_absolute_url().'202-account/api-integrations.php');
	die();
}

if (isset($_GET['edit_dni_network']) && !empty($_GET['edit_dni_network'])) {
	$mysql['editDniNetworkId'] = $db->real_escape_string($_GET['edit_dni_network']);
	$sql_edit_dni = "SELECT * FROM 202_dni_networks WHERE id = '".$mysql['editDniNetworkId']."' AND user_id = '".$mysql['user_id']."'";
	$edit_dni_result = $db->query($sql_edit_dni);
	if ($edit_dni_result->num_rows > 0) {
		$edit_dni_row = $edit_dni_result->fetch_assoc();
		$editing_dni_network = true;
	}
}

if (isset($_GET['facebook_account_linked'])) {
    $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
    $sql = "UPDATE 202_users_pref SET facebook_ads_linked = '1' WHERE user_id = '".$mysql['user_id']."'";
    $db->query($sql);
    $facebook_success = true;
}

if (isset($_GET['facebook_account_linked_error'])) {
    $error['user_facebook'] = "There was an error. Try again!";
}

$dni_sql = "SELECT * FROM 202_dni_networks WHERE user_id = '1'";
$dni_result = $db->query($dni_sql);

template_top('API Integrations',NULL,NULL,NULL); 

?>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-6">
				<h6><span><img src="<?php echo get_absolute_url();?>202-img/icons/integrations/dni.jpg"></span> Direct Network Integration <?php showHelp("dni"); ?></h6>
			</div>
			<div class="col-xs-6">
			<?php if($error['dni_network']) { ?>
				<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['dni_network'];?></small></div>
			<?php } ?>
			<?php if($error['dni_network_auth']) { ?>
				<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['dni_network_auth'];?></small></div>
			<?php } ?>
			<?php if($success['dni_network_added']) { ?>
				<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> <?php echo $success['dni_network_added'];?></small></div>
			<?php } ?>
			<?php if(isset($_GET['dni_network_updated'])) { ?>
				<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> DNI Network updated successfully. API processing can take up to 5 minutes.</small></div>
			<?php } ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    If you wish to search, apply and setup offers directly from your Prosper202 dashboard, use Direct Network Integration to link Prosper202 and your network together! <a href="http://prosper.tracking202.com/blog/new-super-fast-offer-setup-with-prosper202-pro-dni" target="_blank">Read More...</a>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xs-8">
		<div class="row">
			<div class="col-xs-12">
				<table class="table table-bordered table-hover" id="stats-table">
		        <thead>
			        <tr style="background-color: #f2fbfa;">
				        <th>Network</th>
				        <th>API Key</th>
				        <th>ID</th>
				        <th></th>
			        </tr>
		        </thead>
		        <tbody>
		        <?php if ($dni_result->num_rows > 0) { 
		        	while ($dni_row = $dni_result->fetch_assoc()) { 
		        		if ($dni_row['processed'] == false) {
		        			$dniProcesing['networks'][] = array('id' => $dni_row['id'], 'networkId' => $dni_row['networkId'], 'api_key' => $dni_row['apiKey'], 'type' => $dni_row['type']);
		        		}
		        		?>
		        		<tr>
		        			<td> <img src="<?php echo $dni_row['favIcon']; ?>" width=16>&nbsp;&nbsp;<?php echo $dni_row['name']." (".$dni_row['type'].")";?><span class="fui-info-circle" style="font-size: 12px; margin: -25px 0px 0px 5px;" data-toggle="tooltip" title="" data-original-title="<?php echo $dni_row['shortDescription']; ?>"></span><br>
		        			<?php if ($dni_row['processed'] == false) { ?>
		        			<div id="network-<?php echo $dni_row['id'];?>">
		        				<span style='font-size:10px'>processing... <img src="<?php echo get_absolute_url();?>202-img/loader-small.gif"></span>
		        				<div class="progress" style="margin: 0px 5px;">
								  <div id="<?php echo $dni_row['id'];?>" class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%; color:#34495E">
								    0.00%
								  </div>
								</div>
							<div>	
							<?php } ?>
							</td>
		        			<td><?php echo substr($dni_row['apiKey'], 0, 12)."... ";?><a href="#" class="link showFullDniApikey" data-long="<?php echo $dni_row['apiKey'];?>" data-short="<?php echo substr($dni_row['apiKey'], 0, 12);?>">show</a></td>
		        			<td><?php echo $dni_row['affiliateId'];?></td>
		        			<td><a href="<?php echo get_absolute_url();?>202-account/api-integrations.php?edit_dni_network=<?php echo $dni_row['id'];?>"><i class="glyphicon glyphicon-pencil"></i></a> <a href="<?php echo get_absolute_url();?>202-account/api-integrations.php?delete_dni_network=<?php echo $dni_row['id'];?>" onClick="return confirm('Delete This DNI Network?')"><i class="glyphicon glyphicon-trash"></i></a></td>
		        		</tr>
		        	<?php } 
		        }?>
		        </tbody>
	        </table>
			</div>
			<div class="col-xs-12">
				<form class="form-horizontal" role="form" method="post" action="">
				<input type="hidden" name="dni_network_type" id="dni_network_type" value="<?php echo $edit_dni_row['type'];?>">
				<input type="hidden" name="dni_network_name" id="dni_network_name" value="<?php echo $edit_dni_row['name'];?>">
				<?php if($editing_dni_network) { ?>
				<input type="hidden" name="editing_dni_network" value="1">
				<input type="hidden" name="editing_dni_network_id" value="<?php echo $edit_dni_row['id'];?>">
				<?php } ?>
				<div class="col-xs-3" style="padding: 0px; padding-right: 5px;">
				    <label class="sr-only" for="dni_network">Select Network</label>
				    <select name="dni_network" class="form-control input-sm">
						<option value="">Select network</option>
						<?php foreach ($dniNetworks as $dninetwork) { ?>
							<option value="<?php echo $dninetwork['networkId'];?>" data-type="<?php echo $dninetwork['networkType'];?>" <?php if($edit_dni_row['networkId'] == $dninetwork['networkId'] || $mysql['add_dni'] == $dninetwork['networkId']) echo 'selected';?>><?php echo $dninetwork['name'];?> (<?php echo $dninetwork['networkType'];?>)</option>
						<?php } ?>
					</select>
					
			    </div>
			    <div class="<?php if ($editing_dni_network) { if ($edit_dni_row['type'] == 'HasOffers') echo 'col-xs-7'; else echo 'col-xs-5';} else {echo 'col-xs-7';} ?>" id="dni_api_key_input_group" style="padding: 0px; padding-right: 5px;">
				    <label class="sr-only" for="dni_network_api_key">Add API key</label>
				    <input type="text" name="dni_network_api_key" class="form-control input-sm" placeholder="API Key" value="<?php echo $edit_dni_row['apiKey'];?>"><p><div id="dniInfo"></div>
			    </div>
			    <div class="col-xs-2" id="dni_affiliate_id_input_group" style="<?php if($editing_dni_network) { if ($edit_dni_row['type'] == 'HasOffers') echo 'display:none;'; } else {echo 'display:none;';}?> padding: 0px; padding-right: 5px;">
				    <label class="sr-only" for="dni_network_affiliate_id">Add Affiliate ID</label>
				    <input type="text" name="dni_network_affiliate_id" id="dni_network_affiliate_id" class="form-control input-sm" placeholder="Affiliate ID" value="<?php if ($editing_dni_network) { if ($edit_dni_row['type'] == 'HasOffers') echo 'null';} else { echo $edit_dni_row['affiliateId'];}?>">
			    </div>
			    <div class="col-xs-2" style="padding: 0px;">
				    <button class="btn btn-xs btn-p202 btn-block" type="submit" style="margin-top: 5px;"><?php if ($editing_dni_network) echo 'Edit'; else echo 'Add';?></button>	
			    </div>
				</form>
			</div>	
		</div>
	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6><span><img src="<?php echo get_absolute_url();?>202-img/icons/integrations/ipqs.png"></span> IPQualityScore Integration <?php showHelp("jvzoo"); ?></h6>
			</div>
			<div class="col-xs-8">
			<?php if($change_jvzoo_secret_key) { ?>
				<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> Your IPQualityScore API key was changed successfully.</small></div>
			<?php } ?>
			<?php if($error['ipqs_api_key_error']) { ?>
				<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['ipqs_api_key_error'];?></small></div>
			<?php } ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    If you wish to detect and redirect Fraud in real-time using IPQualityScore, enter your IPQualityScore API Key! <a href='https://202.redirexit.com/tracking202/redirect/dl.php?t202id=12608&t202kw=' target='_blank' rel='noopener'>Click here for your free api key.</a>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xs-8">
		<strong><small>Your IPQualityScore API Key Is:</small></strong><br/>
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">

			<div class="col-xs-9">			

					<input type="hidden" name="change_ipqs_api_key" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="form-group" style="margin-top: 20px;">
						<label for="ipqs_api_key" class="col-xs-12 control-label" style="text-align:left">IPQS API Key:</label>
						<div class="col-xs-12">
						    <input type="text" class="form-control input-sm" id="ipqs_api_key" name="ipqs_api_key" value="<?php echo $html['ipqs_api_key']; ?>">
						</div>
						 <div class="col-xs-6">
						 <br>
						<button class="btn btn-xs btn-p202 btn-block" type="submit">Update IPQS API Key</button>				
					</div>
					</div>
			</div>

			<div class="col-xs-3">
				<div class="form-group">
				
				   
				</div>
			</div>

			</form>
		</div>
	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-6">
				<h6><span><img src="<?php echo get_absolute_url();?>202-img/icons/integrations/clickbank.png"></span> ClickBank Sales Notification <?php showHelp("clickbank"); ?></h6>
			</div>
			<div class="col-xs-6">
			<?php if($change_cb_key) { ?>
				<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> Your Clickbank secret key was changed successfully.</small></div>
			<?php } ?>
			<?php if($error['cb_key']) { ?>
				<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['cb_key'];?></small></div>
			<?php } ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    If you wish to use Clickbank Sales Notification Service, to update conversions, enter your Secret Key!
					</div>
				</div>
			</div>
			 <!--<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					   <iframe width="100%" height="auto" src="//www.youtube.com/embed/M6zo3XuExL0" frameborder="0" allowfullscreen></iframe>
					</div>
				</div>			
			</div> --> 
		</div>
	</div>

	<div class="col-xs-8">
	<?php 
if (extension_loaded('mcrypt') || function_exists("openssl_decrypt")) {
?>
		<strong><small>Your Clickbank Notification URL is:</small></strong><br/>
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">

			<div class="col-xs-9">

				<small>
				<span id="cb_verified">
					<?php if(!$cb_verified) { ?>
			                <span class="label label-important">Unverified</span>
			        <?php } else { ?>
			                <span class="label label-primary">Verified</span>
			        <?php } ?>
			    </span> -
					<em><?php echo $strProtocol.''. getTrackingDomain() . get_absolute_url().'tracking202/static/cb202.php';?></em>
				</small>

					<input type="hidden" name="change_cb_key" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="form-group" style="margin-top: 20px;">
						<label for="cb_key" class="col-xs-5 control-label" style="text-align:left">Clickbank Secret Key:</label>
						<div class="col-xs-7">
						    <input type="text" class="form-control input-sm" id="cb_key" name="cb_key" value="<?php echo $html['cb_key']; ?>">
						</div>
					</div>
			</div>

			<div class="col-xs-3">
				<a id="cb_status" class="btn btn-xs btn-warning btn-block">Check status</a>
				<br/>
				<div class="form-group">
				    <div class="col-xs-12">
						<button class="btn btn-xs btn-p202 btn-block" type="submit">Update Secret Key</button>				
					</div>
				</div>
			</div>

			</form>
		</div>
<?php 
} else {
    ?>
 		<div class="row">

		
			<div class="col-xs-12">

				<small>
				<span id="cb_verified">
				            <span class="label label-important">Mcrypt Extension Missing </span>
			    </span> The mcrypt extension is needed for the ClickBank Sales Notification integration to work. However, it has not been installed. Please install it, or ask your hosting provider for assistance.
				</small>

			</div>

		
		</div>   
    <?php 
}
?>		
		
	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6><span><img src="<?php echo get_absolute_url();?>202-img/icons/integrations/jvzoo.png"></span> JVZoo JVZIPN <?php showHelp("jvzoo"); ?></h6>
			</div>
			<div class="col-xs-8">
			<?php if($change_jvzoo_secret_key) { ?>
				<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> Your JVZoo secret key was changed successfully.</small></div>
			<?php } ?>
			<?php if($error['jvzoo_secret_key_error']) { ?>
				<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['jvzoo_secret_key_error'];?></small></div>
			<?php } ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    If you wish to use JVZoo Instant Payment Notification (JVZIPN) to update conversions, enter your JVZIPN Secret Key!
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xs-8">
		<strong><small>Your JVZIPN URL For JVZoo Is:</small></strong><br/>
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">

			<div class="col-xs-9">

				<small>
					<em><?php echo $strProtocol.''.getTrackingDomain().get_absolute_url().'tracking202/static/jvzoo.php';?></em>
				</small>

					<input type="hidden" name="change_jvzoo_secret_key" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="form-group" style="margin-top: 20px;">
						<label for="jvzoo_ipn_secret_key" class="col-xs-12 control-label" style="text-align:left">JVZoo Secret Key:</label>
						<div class="col-xs-12">
						    <input type="text" class="form-control input-sm" id="jvzoo_ipn_secret_key" name="jvzoo_ipn_secret_key" value="<?php echo $html['jvzoo_ipn_secret_key']; ?>">
						</div>
						 <div class="col-xs-6">
						 <br>
						<button class="btn btn-xs btn-p202 btn-block" type="submit">Update JVZoo Secret Key</button>				
					</div>
					</div>
			</div>

			<div class="col-xs-3">
				<div class="form-group">
				
				   
				</div>
			</div>

			</form>
		</div>
	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6><span><img src="<?php echo get_absolute_url();?>202-img/icons/integrations/zaxaa.png"></span> ZPN Payment Notification (ZPN) <?php showHelp("zaxaa"); ?></h6>
			</div>
			<div class="col-xs-8">
			<?php if($change_zaxaa_api_signature) { ?>
				<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> Your Zaxaa API signature was changed successfully.</small></div>
			<?php } ?>
			<?php if($error['zaxaa_api_signature_error']) { ?>
				<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['zaxaa_api_signature_error'];?></small></div>
			<?php } ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    If you wish to use Zaxaa Payment Notification (ZPN) to update conversions, enter your API Signature!
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xs-8">
		<strong><small>Your ZPN URL For Zaxaa Is:</small></strong><br/>
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">

			<div class="col-xs-9">

				<small>
					<em><?php echo $strProtocol.''.getTrackingDomain().get_absolute_url().'tracking202/static/zpn.php';?></em>
				</small>

					<input type="hidden" name="change_zaxaa_api_signature" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="form-group" style="margin-top: 20px;">
						<label for="zaxaa_api_signature" class="col-xs-12 control-label" style="text-align:left">Zaxaa API signature:</label>
						<div class="col-xs-12">
						    <input type="text" class="form-control input-sm" id="zaxaa_api_signature" name="zaxaa_api_signature" value="<?php echo $html['zaxaa_api_signature']; ?>">
						</div>
						 <div class="col-xs-6">
						 <br>
						<button class="btn btn-xs btn-p202 btn-block" type="submit">Update Zaxaa API Signature</button>				
					</div>
					</div>
			</div>

			<div class="col-xs-3">
				<div class="form-group">
				
				   
				</div>
			</div>

			</form>
		</div>
	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6><span><img src="<?php echo get_absolute_url();?>202-img/icons/integrations/slack.png"></span> Slack Integration <?php showHelp("slack"); ?></h6>
			</div>
			<div class="col-xs-8">
			<?php if($change_user_slack_incoming_webhook) { ?>
				<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> Your Slack Incoming Webhook URL was changed successfully.</small></div>
			<?php } ?>
			<?php if($error['user_slack_incoming_webhook']) { ?>
				<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['user_slack_incoming_webhook'];?></small></div>
			<?php } ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    If you wish to send notifications into Slack enter your <strong>Slack</strong> incoming webhook url. To receive notifications from Slack, use the <strong>Prosper202</strong> Incoming Webhook URL.
					</div>
				</div>
			</div>
		 <!--	<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    <iframe width="100%" height="auto" src="//www.youtube.com/embed/M6zo3XuExL0" frameborder="0" allowfullscreen></iframe>
					</div>
				</div>			
			</div> --> 
		</div>
	</div>

	<div class="col-xs-8">
		<strong><small>Prosper202 Incoming Webhook URL Is:</small></strong><br/>
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">

			<div class="col-xs-9">

				<small>
					<em><?php echo $strProtocol.''.getTrackingDomain().get_absolute_url().'tracking202/static/slack.php';?></em>
				</small>

					<input type="hidden" name="change_user_slack_incoming_webhook" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="form-group" style="margin-top: 20px;">
						<label for="user_slack_incoming_webhook" class="col-xs-12 control-label" style="text-align:left">Slack Incoming Webhook URL:</label>
						<div class="col-xs-12">
						    <input type="text" class="form-control input-sm" id="user_slack_incoming_webhook" name="user_slack_incoming_webhook" value="<?php echo $html['user_slack_incoming_webhook']; ?>">
						</div>
						 <div class="col-xs-6">
						 <br>
						<button class="btn btn-xs btn-p202 btn-block" type="submit">Update Webhook Url</button>				
					</div>
					</div>
			</div>

			<div class="col-xs-3">
				<div class="form-group">
				
				   
				</div>
			</div>

			</form>
		</div>
	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6><span><img src="<?php echo get_absolute_url();?>202-img/icons/integrations/paykickstart.png"></span> PayKickstart Affiliate IPN <?php showHelp("paykickstart"); ?></h6>
			</div>
			<div class="col-xs-8">
		
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    If you wish to use PayKickstart Affiliate Instant Payment Notification (IPN) to update conversions, use the following url as your IPN url.
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xs-8">
		<strong><small>Your URL For PayKickstart Affiliate IPN is:</small></strong><br/>
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">

			<div class="col-xs-9">

				<small>
					<em><?php echo $strProtocol.''.getTrackingDomain().get_absolute_url().'tracking202/static/paykickstart.php';?></em>
				</small>
										
			</div>

			<div class="col-xs-3">
				<div class="form-group">
				
				   
				</div>
			</div>

			</form>
		</div>
	</div>
</div>
<?php /*
<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6><span><img src="<?php echo get_absolute_url();?>202-img/icons/integrations/revcontent.png"></span> Integration <?php showHelp("slack"); ?></h6>
			</div>
			<div class="col-xs-8">
			<?php if($change_user_revcontent_settings) { ?>
				<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> Your RevContent credentials was changed successfully.</small></div>
			<?php } ?>
			<?php if($error['user_revcontent']) { ?>
				<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['user_revcontent'];?></small></div>
			<?php } ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    If you wish to use <strong>RevContent</strong> with <strong>RapidBuilder</strong>, enter your User ID and Secret from your RevContent account!
					</div>
				</div>
			</div>
		 <!--	<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    <iframe width="100%" height="auto" src="//www.youtube.com/embed/M6zo3XuExL0" frameborder="0" allowfullscreen></iframe>
					</div>
				</div>			
			</div> --> 
		</div>
	</div>

	<div class="col-xs-8">
		<strong><small>RevContent credentials:</small></strong><br/>
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">
			<div class="col-xs-9">
					<input type="hidden" name="change_user_revcontent_settings" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="form-group" style="margin-top: 20px;">
						<label for="revcontent_user_id" class="col-xs-12 control-label" style="text-align:left">Client ID:</label>
						<div class="col-xs-12">
						    <input type="text" class="form-control input-sm" id="revcontent_user_id" name="revcontent_user_id" value="<?php echo $html['revcontent_user_id']; ?>">
						</div>
						<label for="revcontent_user_secret" class="col-xs-12 control-label" style="text-align:left">Secret:</label>
						<div class="col-xs-12">
						    <input type="text" class="form-control input-sm" id="revcontent_user_secret" name="revcontent_user_secret" value="<?php echo $html['revcontent_user_secret']; ?>">
						</div>
						 <div class="col-xs-6">
						 <br>
						<button class="btn btn-xs btn-p202 btn-block" type="submit">Update RevContent credentials</button>				
					</div>
					</div>
			</div>

			<div class="col-xs-3">
				<div class="form-group">
				
				   
				</div>
			</div>

			</form>
		</div>
	</div>
</div>

<div class="row account">
    <div class="col-xs-12">
        <div class="row">
            <div class="col-xs-4">
                <h6><span><img src="<?php echo get_absolute_url();?>202-img/icons/integrations/facebook-ads.png"></span> Integration <?php showHelp("slack"); ?></h6>
            </div>
            <div class="col-xs-8">
            <?php if($facebook_success) { ?>
                <div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> Your Facebook account was authenticated successfully.</small></div>
            <?php } ?>
            <?php if($error['user_facebook']) { ?>
                <div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['user_facebook'];?></small></div>
            <?php } ?>
            </div>
        </div>
    </div>
    <div class="col-xs-4">
        <div class="row">
            <div class="col-xs-12">
                <div class="panel panel-default account_left">
                    <div class="panel-body">
                        If you wish to use <strong>Facebook Ads</strong> with <strong>RapidBuilder</strong>, authenticate your account with Prosper202 Facebook app!
                    </div>
                </div>
            </div>
         <!--   <div class="col-xs-12">
                <div class="panel panel-default account_left">
                    <div class="panel-body">
                        <iframe width="100%" height="auto" src="//www.youtube.com/embed/M6zo3XuExL0" frameborder="0" allowfullscreen></iframe>
                    </div>
                </div>          
            </div> --> 
        </div>
    </div>

    <div class="col-xs-8">
        <div class="row">
            <div class="col-xs-9">
                <a href="https://my.tracking202.com/api/v2/premium-p202/facebook-ads/get-access-token/<?php echo base64_encode($user_row['p202_customer_api_key']);?>" class="btn btn-sm btn-p202 btn-block" target="_blank" <?php if (empty($user_row['p202_customer_api_key'])) echo "disabled";?>><?php if($user_row['facebook_ads_linked'] == 1) echo "Re-link your Facebook Account"; else "Link your Facebook account";?></a> 
            </div>
        </div>
    </div>
</div>
*/?>
<?php if(count($dniProcesing['networks']) > 0) { ?>
<script type="text/javascript">
$(document).ready(function() {
	var DNIdata = JSON.stringify(<?php echo json_encode($dniProcesing, true); ?>);
	getDNIProgress(DNIdata);

	window.setInterval(function(){
	  	getDNIProgress(DNIdata);
	}, 3000);

	function getDNIProgress(DNIdata) {
		$.post("<?php echo get_absolute_url();?>202-account/ajax/dni.php?getProgress=true", DNIdata).done(function(response) {
			var json = $.parseJSON(response);
			$.each(json.data, function (index, item) {
				if (item.progress == '100') {
					$.post("<?php echo get_absolute_url();?>202-account/ajax/dni.php?updateStatus=true&dni="+item.id, function(data1) {
					  $("#network-"+item.id).remove();
					});
				}
		        $("#"+item.id).css('width', item.progress+'%').attr('aria-valuenow', item.progress).text(item.progress+'%');
		    });
	  	});
	}
	$('select[name=dni_network]').trigger("change");
});
</script>
<?php } else {?>
<script type="text/javascript">

$(document).ready(function() {
//manually trigger the change function
	$('select[name=dni_network]').trigger("change");

	
	
});
	
</script>
<?php }?>
<script>
dniNetworks= <?php echo json_encode(getAllDniNetworks($user_row['install_hash'])); ?>;
function dni(){
	var selectedNetwork = $('select[name=dni_network] option:selected').val()
	var dniNetwork=$(dniNetworks).filter(function (i,n){return n.networkId===selectedNetwork});
    var dniInfo='<small> <img src="'+dniNetwork[0].favIconUrl+'" width="16"> <strong>'+dniNetwork[0].name+'</strong><br><br>'+dniNetwork[0].shortDescription+' <br><br><a href="'+dniNetwork[0].websiteURL+'" target="_blank" class="btn btn-xs btn-info btn-block">Get An Account with '+dniNetwork[0].name+'</a></small>'
	$("#dniInfo").html(dniInfo);
}	
</script>
<?php template_bottom();