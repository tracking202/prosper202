<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


//make sure a landing page is selected
	if (empty($_POST['landing_page_id'])) { $error['landing_page_id'] = '<div class="error"><small><span class="fui-alert"></span>You have not selected a landing page to use.</small></div>';  }	
	echo $error['landing_page_id']; 
	
//ok now run through all the offers to make sure they exist, THIS WILL ERROR IF THERE ISN"T A CAMPAIGN SELECTED WHEN RUN
	$count = 0;
	while (($count < ($_POST['counter']+1)) and ($success != true)) {
		$count++;
		$aff_campaign_id = $_POST['aff_campaign_id_'.$count];
		if ($aff_campaign_id != 0) {
			$success = true; 
		}
	} 

	if ($success != true){ echo '<div class="error"><small><span class="fui-alert"></span>Please select an affiliate campaign, and make sure no unused ones are there.</small></div>';  die(); }	

//show tracking code
	$mysql['landing_page_id'] = $db->real_escape_string($_POST['landing_page_id']);
	$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `landing_page_id`='".$mysql['landing_page_id']."'";
	$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
	$landing_page_row = $landing_page_result->fetch_assoc();
	
	$parsed_url = parse_url($landing_page_row['landing_page_url']);
	
	?><small><em><u>Make sure you test out all the links to make sure they work yourself before running them live.</u></em></small><?

	$javascript_code = '<script>
	(function(d, s) {
		var js, upxf = d.getElementsByTagName(s)[0], load = function(url, id) {
			if (d.getElementById(id)) {return;}
			if202 = d.createElement("script");if202.src = url;if202.async = true;if202.id = id;
			upxf.parentNode.insertBefore(if202, upxf);
		};
		load("http://' . getTrackingDomain() . '/tracking202/static/landing.php?lpip=' . $landing_page_row['landing_page_id_public'] .'", "upxif");
	}(document, "script"));
	</script>';

	$html['javascript_code'] = htmlentities($javascript_code);
	printf('<br></br><small><strong>Inbound Javascript Landing Page Code:</strong></small><br/>
            <span class="infotext">This is the javascript code should be put right above your &#60;&#47;body&#62; tag on <u>only</u> the page(s) where your PPC visitors will first arrive to.
			This code is not supposed to be placed on every single page on your website. For example this <u>is not</u> to be placed in a template file that is to be included on everyone of your pages.<br/>
            This code is supposed to be only placed on the first page(s), that an incoming PPC visitor would be sent to.  
            Tracking202 is not designed to be a webpage analytics, this is specifically javascript code only to track visitors coming in.</span><br></br>
            <textarea class="form-control" rows="10" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>', $html['javascript_code']);

	
	//now print out the each individual redirect code
	echo '<br/><small><strong>Landing Page: Outbound PHP Redirect Code (FOR EACH OFFER):</strong></small><br/>
		
		<span class="infotext">This is the php redirect code, for each individual offer you have placed on your landing page.
		What you would do is if you have 5 offers for instance, you might have when the visitor
		comes into your site, they can click on 5 different offers.  Each offer could be named,
		offer1.php, offer2.php, offer3.php, etc etc etc.  You would have a different page that the
		visitor would click on to goto each offer.<br></br>
		
		For offer1.php, you would want to copy and paste the php code for that specific offer.  So
		for instance if you had 3 ringtone offers on your page like, flycell, playphone and ringaza. 
		You would have a link for flycell.php, and on flycell.php you would want the php redirect 
		code for flycell.  For your ringaza links, you would have your visitor click on ringaza.php and
		have the php redirect code for ringaza copy and pasted onto ringaza.php.  <br></br>
		
		Basically for each offer has their own php page, and you want to copy the code given below
		for each offer, onto each of their associated pages.  If you have any more questions please
		contact the live support and we can walk you through the process.</span><br/>

		<span class="infotext">You can use also built-in redirect link, if you dont want to host PHP code on your landing page.</span><br/>';
	
	
	$count = 0;
	while ($count < ($_POST['counter']+1)) {
		$count++;
		
		$aff_campaign_id = $_POST['aff_campaign_id_'.$count];
		if ($aff_campaign_id != 0) {
			
			$mysql['aff_campaign_id'] = $db->real_escape_string($aff_campaign_id);
			$aff_campaign_sql = "SELECT aff_campaign_id_public, aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id='".$mysql['aff_campaign_id']."'";
			$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql); 
			$aff_campaign_row = $aff_campaign_result->fetch_assoc();
			
			//for each real campaign selected, display the code to be used for it
			$outbound_go = 'http://' . getTrackingDomain() . '/tracking202/redirect/go.php?acip=' . $aff_campaign_row['aff_campaign_id_public'];

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
// This PHP code is to be used for the following setup:
// ' . $aff_campaign_row['aff_campaign_name'] . ' on ' . $landing_page_row['landing_page_url'] . '
//                       
// -------------------------------------------------------------------
			  
$tracking202outbound = \'http://'. getTrackingDomain() .'/tracking202/redirect/off.php?acip='.$aff_campaign_row['aff_campaign_id_public'].'&pci=\'.$_COOKIE[\'tracking202pci\']; 
			 
header(\'location: \'.$tracking202outbound);
			  
?>';

			$html['outbound_php'] = htmlentities($outbound_php);
			printf('<p><textarea class="form-control" rows="16" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea></p>', $html['outbound_php']);
			
			
			
		}
	} 
	
	


  ?>