<?php

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();
AUTH::require_valid_app_key('stats202', $_SESSION['user_api_key'], $_SESSION['user_stats202_app_key']);


//build the get query for the stats202 restful api
$get = array();
$get['apiKey'] = $_SESSION['user_api_key'];
$get['stats202AppKey'] = $_SESSION['user_stats202_app_key'];
$query = http_build_query($get);

//get all of the networks
$url = TRACKING202_API_URL . "/stats202/getNetworks?$query";
#echo "<p>$url</p>";

//grab the url
$xml = getUrl($url);
$getNetworks = convertXmlIntoArray($xml);
$errors = $getNetworks['errors']['error'];
	

//if editing
switch ($_GET['action']) { 
	case "edit":
		
		$editing = true;
		
		//get the account information for this account we're editing
		$get['statAccountId'] = $_GET['statAccountId'];
		$query = http_build_query($get);
		
		//get all of the networks
		$url = TRACKING202_API_URL . "/stats202/getStatAccount?$query";
		#echo "<p>$url</p>";
		
		
		//parse out the array
		$xml = getUrl($url);
		$getStatAccount = convertXmlIntoArray($xml);
		$errors = $getStatAccount['errors']['error'];
	
		$getStatAccount = $getStatAccount['getStatAccount'];
		$statAccount = $getStatAccount['statAccount'][0];
		#print_r_html($statAccount);

		$html = array_map('htmlentities', $statAccount);
		break;
		
	case "delete":
		
		//get the account information for this account we're editing
		$get['statAccountId'] = $_GET['statAccountId'];
		$query = http_build_query($get);
		
		$url = TRACKING202_API_URL. "/stats202/deleteStatAccount?$query";
		
		$xml = getUrl($url);
		$deleteStatAccount = convertXmlIntoArray($xml);
		$errors = $deleteStatAccount['errors']['error'];
	
		if (!$errors) { header('location: /stats202/setup/?delete=1');  die(); }
		
		
		break;
}

	
	

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	
	$get = array( 	'apiKey' => $_SESSION['user_api_key'],
					'stats202AppKey' => $_SESSION['user_stats202_app_key'],
					'statAccountId' => $_GET['statAccountId'], //if it's editing add this variable
					'networkId' => $_POST['networkId'],
					'statAccountNickName' => $_POST['statAccountNickName'],
					'statAccountUser' => $_POST['statAccountUser'],
					'statAccountPass' => $_POST['statAccountPass'],
					'statAccountAffId' => $_POST['statAccountAffId'],
					'statAccountApiKey' => $_POST['statAccountApiKey'],
					'statAccountApiId' => $_POST['statAccountApiId']  );
	
	if ($editing) 	$url = TRACKING202_API_URL . '/stats202/editStatAccount?' . http_build_query($get);
	else 			$url = TRACKING202_API_URL . '/stats202/addStatAccount?' . http_build_query($get);
	
	$addStatAccount = getUrl( $url );
	$addStatAccount = convertXmlIntoArray($addStatAccount);
	
	#print_r_html($_POST);
	#print_r_html($addStatAccount);
	
	$errors = $addStatAccount['errors']['error'];
	if (!$errors) $success = true;	
	
	if ($success) { header('location: /stats202/setup/?success=1');  die(); }
	
	$html = array_map('htmlentities', $_POST);
	
}
	
	

	
	
if (isset($_GET['delete_stat_account_id'])) { 

	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$mysql['stat_account_id'] = mysql_real_escape_string($_GET['delete_stat_account_id']);
	$mysql['stat_account_time'] = time();
	
	$delete_sql = " UPDATE  	api_stat_accounts
					SET     		stat_account_deleted='1',
								stat_account_time='".$mysql['stat_account_time']."'
					WHERE   	user_id='".$mysql['user_id']."'
					AND     		stat_account_id='".$mysql['stat_account_id']."'";
	$delete_result = _mysql_query($delete_sql, $dbStatsLink); //_mysql_error($delete_result)) {
	$delete_success = true;
}



if ($error) { 
	//if someone happend take the post stuff and add it
	$selected['stat_network_id'] = $_POST['stat_network_id'];
	$html = array_map('htmlentities', $_POST);

}                           

template_top('Affiliate Accounts'); 

include_once('../../top.php'); ?>
	
<?   //show api errors if they exist
checkForApiErrors($getNetworks);    
checkForApiErrors($getStatAccount);    ?>


<form method="post" action="<? if ($delete_success == true) { echo $_SERVER['REDIRECT_URL']; }?>" style>

	<table style="margin: 0px auto;">
		<tr>
			<td style="padding: 0px 0px 20px;">
				<? if ($errors) { 
					echo "<div class='warning'><div><h3>There were errors with your submission</h3>Please look at the errors below</div></div>";
					for ($x = 0; $x < count($errors); $x++) { 
						$html = array_merge($html, array_map('htmlentities', $errors[$x]) );
						echo "<div class='error'>ErrorCode: {$html['errorCode']}<br/>";
						echo "ErrorMessage: {$html['errorMessage']}</div>";
					}
				} ?>		
				<h3 class="green">Affiliate Account Setup</h3>
				<p>Enter in your affiliate account information below.</p>
			</td>
		</tr>
		
		<tr>
			<td>
				<table class="setup-table" cellspacing="0" cellpadding="0">
					<tr>
						<th colspan="2">Account Information</th>
					</tr>
					
					<tr>
						<td class="left_caption">Affiliate Network</td>
						<td>
							<select name="networkId" id="networkId" onChange="showAffStatsFields(this.value)">
								<option value=""> -- </option>
								<?  $getNetworks = $getNetworks['getNetworks'];
									$networks = $getNetworks['networks'][0]['network'];
									for ($x = 0; $x < count($networks); $x++) { 
										
										$html2 = array_map('htmlentities', $networks[$x]);
										
										if ($html['networkId'] == $html2['networkId']) {
											printf('<option selected="selected" value="%s">%s</option>', $html2['networkId'],$html2['networkName']);     
										} else {
											printf('<option value="%s">%s</option>', $html2['networkId'],$html2['networkName']);    
										}
									} ?>
							</select>  
							
							<script type="text/javascript">
							
								function showAffStatsFields(networkId) { 
									
									//WHEN THE NETWORK DROP DOWN IS SELECTED, THIS DETERMINES WHETHER OR NOT TO SHOW THE AFFILIATE ID FIELD
									var require_aff_id;
									var require_api_key;
									<? for ($x = 0; $x < count($networks); $x++) { 
									
										if ($networks[$x]['networkRequireAffId'] == 'true') 	$networks[$x]['networkRequireAffId'] = 1;
										else 												$networks[$x]['networkRequireAffId'] = 0;
										
										if ($networks[$x]['networkRequireApiKey'] == 'true') 	$networks[$x]['networkRequireApiKey'] = 1;
										else 												$networks[$x]['networkRequireApiKey'] = 0;
										
										if ($networks[$x]['networkRequireApiId'] == 'true') 	$networks[$x]['networkRequireApiId'] = 1;
										else 												$networks[$x]['networkRequireApiId'] = 0;
										
										$html2 = array_map('htmlentities', $networks[$x]);
										echo ' if (networkId == '.$html2['networkId'].') { require_aff_id = '.$html2['networkRequireAffId'].'; }' . "\n";
										echo ' if (networkId == '.$html2['networkId'].') { require_api_key = '.$html2['networkRequireApiKey'].'; }' . "\n";
										echo ' if (networkId == '.$html2['networkId'].') { require_api_id = '.$html2['networkRequireApiId'].'; }' . "\n";
									}  ?>
									
									if (require_aff_id == 0) 	{ 	document.getElementById('stat_network_require_aff_id').value = 0;
																	document.getElementById('stat_account_aff_id').style.display = 'none'; }
									else			{ 					document.getElementById('stat_network_require_aff_id').value = 1;
																	document.getElementById('stat_account_aff_id').style.display = 'table-row'; }

						
									if (require_api_key == 0) 	{ 	document.getElementById('stat_network_require_api_key').value = 0;
																	document.getElementById('stat_account_api_key').style.display = 'none'; }
									else			{ 					document.getElementById('stat_network_require_api_key').value = 1;
																	document.getElementById('stat_account_api_key').style.display = 'table-row'; }

									if (require_api_id == 0) 	{ 	document.getElementById('stat_network_require_api_id').value = 0;
																	document.getElementById('stat_account_api_id').style.display = 'none'; }
									else			{ 					document.getElementById('stat_network_require_api_id').value = 1;
																	document.getElementById('stat_account_api_id').style.display = 'table-row'; }
									
								}
							
							</script>
						</td>
					</tr>
					<tr>
						<td class="left_caption">Nickname <a href="#" onclick="alert('This is the name your account will be referenced by, this is for your own naming use only.');">[?]</a></td>
						<td><input type="text" name="statAccountNickName" style="display: inline;" value="<? echo $html['statAccountNickName']; ?>"/></td>
					</tr>
					<tr>
						<td class="left_caption">Username</td>
						<td><input type="text" name="statAccountUser" style="display: inline;" value="<? echo $html['statAccountUser']; ?>"/></td>
					</tr>
					<tr>
						<td class="left_caption">Password</td>
						<td><input type="password" name="statAccountPass" style="display: inline;"/></td>
					</tr>
					<input type="hidden" id="stat_network_require_api_id" name="stat_network_require_api_id" value="<? echo $html['stat_network_require_api_id']; ?>"/>
					<tr id="stat_account_api_id" <? if (!$html['statAccountApiId']) { echo 'style="display: none;"'; } ?>>
						<td class="left_caption">API ID</td>
						<td><input type="text" name="statAccountApiId" style="display: inline;" value="<? echo $html['statAccountApiId']; ?>"/></td>
					</tr>
					<input type="hidden" id="stat_network_require_api_key" name="stat_network_require_api_key" value="<? echo $html['stat_network_require_api_key']; ?>"/>
					<tr id="stat_account_api_key" <? if (!$html['statAccountApiKey']) { echo 'style="display: none;"'; } ?>>
						<td class="left_caption">API Key</td>
						<td><input type="text" name="statAccountApiKey" style="display: inline;" value="<? echo $html['statAccountApiKey']; ?>"/></td>
					</tr>
					<input type="hidden" id="stat_network_require_aff_id" name="stat_network_require_aff_id" value="<? echo $html['stat_network_require_aff_id']; ?>"/>
					<tr id="stat_account_aff_id" <? if (!$html['statAccountAffId']) { echo 'style="display: none;"'; } ?>>
						<td class="left_caption" >Affiliate #</td>
						<td><input type="text" name="statAccountAffId" style="display: inline;" value="<? echo $html['statAccountAffId']; ?>"/></td>
					</tr>
					<!-- as it is showing the drop-downs, check to see if the affiliate account id is required -->
					<script type="text/javascript"> showAffStatsFields($('networkId').options[$('networkId').selectedIndex].value); </script>
					<tr>
						<td/>
						<td><input type="submit" value="<? if ($editing == true) { echo 'Edit'; } else { echo 'Add'; } ?>" class="submit" style="display: inline; margin-left: 10px;"/> 
							<? if ($editing == true) { ?>
								<input type="submit" value="Cancel" style="display: inline; margin-left: 10px;" class="submit" onclick="window.location='/stats202/setup/'; return false; "/>
							<? } ?>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</form>

<? template_bottom();