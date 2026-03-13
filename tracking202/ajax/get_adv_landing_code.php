<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');

AUTH::require_user();

$slack = false;
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url'])) 
	$slack = new Slack($user_row['url']);

// Initialize variables
$error = [];
$success = false;

//make sure a landing page is selected
	if (empty($_POST['landing_page_id'])) { $error['landing_page_id'] = '<div class="error"><small><span class="fui-alert"></span>You have not selected a landing page to use.</small></div>';  }	
	echo $error['landing_page_id'] ?? ''; 
	
//ok now run through all the offers to make sure they exist, THIS WILL ERROR IF THERE ISN"T A CAMPAIGN SELECTED WHEN RUN
	$count = 0;
	while (($count < ($_POST['counter']+1)) and ($success != true)) {
		$count++;
		$aff_campaign_id = $_POST['aff_campaign_id_'.$count];
		$rotator_id = $_POST['rotator_id_'.$count];
		if ($aff_campaign_id != 0 || $rotator_id != 0) {
			$success = true; 
		}
	} 

	if ($success != true){ echo '<div class="error"><small><span class="fui-alert"></span>Please select an affiliate campaign or rotator, and make sure no unused ones are there.</small></div>';  die(); }	

//show tracking code
	$mysql['landing_page_id'] = $db->real_escape_string((string)$_POST['landing_page_id']);
	$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `landing_page_id`='".$mysql['landing_page_id']."'";
	$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
	$landing_page_row = $landing_page_result->fetch_assoc();
	
	$parsed_url = parse_url((string) $landing_page_row['landing_page_url']);
	
	?><small><em><u>Make sure you test out all the links to make sure they work yourself before running them live.</u></em></small><?php 
	$javascript_code = generateTrackingLoaderSnippet($landing_page_row['landing_page_id_public']);

	$html['javascript_code'] = htmlentities($javascript_code);
	printf('<br></br><small><strong>Inbound Javascript Landing Page Code:</strong></small><br/>
            <span class="infotext">This is the javascript code should be put right above your &#60;&#47;body&#62; tag on <u>only</u> the page(s) where your visitors will first arrive.
			This code is not supposed to be placed on every single page on your website. For example this <u>is not</u> to be placed in a template file that is to be included on everyone of your pages.<br/></span><br></br>
            <textarea class="form-control" rows="10" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>', $html['javascript_code']);

	
	//now print out the each individual redirect code
	echo '<br/><small><strong>Landing Page: Outbound PHP Redirect Link and Code (FOR EACH OFFER):</strong></small><br/>
		
		<span class="infotext">You may link to your offers using either the built in outbound link, or create your own with the php code snippet provided. There\'s a unique link or code snippet for linking out to each offer on your landing page.
       <br/>';
	
	
	$count = 0;
	while ($count < ($_POST['counter']+1)) {
		$count++;
		
		if ($_POST['offer_type'.$count] == 'campaign') {
			$aff_campaign_id = $_POST['aff_campaign_id_'.$count];

			if ($aff_campaign_id != 0) {
			
				$mysql['aff_campaign_id'] = $db->real_escape_string($aff_campaign_id);
				$aff_campaign_sql = "SELECT aff_campaign_id_public, aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id='".$mysql['aff_campaign_id']."'";
				$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql); 
				$aff_campaign_row = $aff_campaign_result->fetch_assoc();
				
				if ($slack) {
					$campaign_slack .= $aff_campaign_row['aff_campaign_name'].'\n';
				}

				//for each real campaign selected, display the code to be used for it
				$outbound_go = '//' . getTrackingDomain() . get_absolute_url(). 'tracking202/redirect/go.php?acip=' . $aff_campaign_row['aff_campaign_id_public'];

				$html['$outbound_go'] = htmlentities($outbound_go);
				printf('</br><textarea class="form-control" rows="1" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>', $html['$outbound_go']);
				echo '<p></p>';
				//for each real campaign selected, display the code to be used for it
				$outbound_php = '
<?php
  
// ------------------------------------------------------------------- 
//
// Tracking202 PHP Redirection, created on ' . date('D M, Y',time()) .'
//
// This PHP code is to be used for the following campaign:
// ' . $aff_campaign_row['aff_campaign_name'] . ' on ' . $landing_page_row['landing_page_url'] . '
//                       
// -------------------------------------------------------------------
			  
$tracking202outbound = \'//'. getTrackingDomain() . get_absolute_url().'tracking202/redirect/off.php?acip='.$aff_campaign_row['aff_campaign_id_public'].'&pci=\'.$_COOKIE[\'tracking202pci\']; 
			 
header(\'location: \'.$tracking202outbound);
			  
?>';

				$html['outbound_php'] = htmlentities($outbound_php);
				printf('<p><textarea class="form-control" rows="16" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea></p>', $html['outbound_php']);
			}

		} else if ($_POST['offer_type'.$count] == 'rotator') {
			$rotator_id = $_POST['rotator_id_'.$count];

			if ($rotator_id != 0) {
				$mysql['rotator_id'] = $db->real_escape_string($rotator_id);
				$rotator_sql = "SELECT public_id, name FROM 202_rotators WHERE id='".$mysql['rotator_id']."'";
				$rotator_result = $db->query($rotator_sql) or record_mysql_error($rotator_sql); 
				$rotator_row = $rotator_result->fetch_assoc();

				//for each real campaign selected, display the code to be used for it
				$outbound_go = '//' . getTrackingDomain() . get_absolute_url().'tracking202/redirect/go.php?rpi=' . $rotator_row['public_id'];

				$html['$outbound_go'] = htmlentities($outbound_go);
				printf('</br><textarea class="form-control" rows="1" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>', $html['$outbound_go']);
				echo '<p></p>';
				//for each real campaign selected, display the code to be used for it
				$outbound_php = '
<?php
  
// ------------------------------------------------------------------- 
//
// Tracking202 PHP Redirection, created on ' . date('D M, Y',time()) .'
//
// This PHP code is to be used for the following campaign:
// ' . $rotator_row['name'] . ' on ' . $landing_page_row['landing_page_url'] . '
//                       
// -------------------------------------------------------------------
			  
$tracking202outbound = \'//'. getTrackingDomain() . get_absolute_url().'tracking202/redirect/offrtr.php?rpi='.$rotator_row['public_id'].'\'; 
			 
header(\'location: \'.$tracking202outbound);
			  
?>';

				$html['outbound_php'] = htmlentities($outbound_php);
				printf('<p><textarea class="form-control" rows="16" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea></p>', $html['outbound_php']);
			}
		}
	} 

renderDynamicContentSegmentHelp();

if ($slack)	
	$slack->push('advanced_landing_page_code_generated', ['name' => $landing_page_row['landing_page_nickname'], 'offers' => $campaign_slack, 'user' => $user_row['username']]);
  ?>