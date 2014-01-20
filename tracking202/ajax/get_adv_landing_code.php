<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


//make sure a landing page is selected
	if (empty($_POST['landing_page_id'])) { $error['landing_page_id'] = '<div class="error">You have not selected a landing page to use.</div>';  }	
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

	if ($success != true){ echo '<div class="error">Please select an affiliate campaign, and make sure no unused ones are there.</div>';  die(); }	

//show tracking code
	$mysql['landing_page_id'] = mysql_real_escape_string($_POST['landing_page_id']);
	$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `landing_page_id`='".$mysql['landing_page_id']."'";
	$landing_page_result = mysql_query($landing_page_sql) or record_mysql_error($landing_page_sql);
	$landing_page_row = mysql_fetch_assoc($landing_page_result);
	
	$parsed_url = parse_url($landing_page_row['landing_page_url']);
	
	?><p><u>Make sure you test out all the links to make sure they work yourself before running them live.</u></p><?

	$javascript_code = '<script language="JavaScript" type="text/javascript"> cbr202=Math.random()*10000000000000000;document.write(\'<scr\'+\'ipt language="JavaScript" src="http://' . getTrackingDomain() . '/tracking202/static/landing.php?lpip=' . $landing_page_row['landing_page_id_public'] .'&202cb=\'+cbr202+\'" type="text/javascript"></scr\' + \'ipt>\'); </script>';
	$html['javascript_code'] = htmlentities($javascript_code);
	printf('<p><b>Inbound Javascript Landing Page Code:</b>
            This is the javascript code should be put right above your &#60;&#47;body&#62; tag on <u>only</u> the page(s) where your PPC visitors will first arrive to.
			This code is not supposed to be placed on every single page on your website. For example this <u>is not</u> to be placed in a template file that is to be included on everyone of your pages.<br/><br/>
            This code is supposed to be only placed on the first page(s), that an incoming PPC visitor would be sent to.  
            Tracking202 is not designed to be a webpage analytics, this is specifically javascript code only to track visitors coming in.</p>
            <p><textarea class="code_snippet">%s</textarea></p>', $html['javascript_code']);

	
	//now print out the each individual redirect code
	echo '<p><b>Landing Page: Outbound PHP Redirect Code (FOR EACH OFFER):</b>
		
		This is the php redirect code, for each individual offer you have placed on your landing page.
		What you would do is if you have 5 offers for instance, you might have when the visitor
		comes into your site, they can click on 5 different offers.  Each offer could be named,
		offer1.php, offer2.php, offer3.php, etc etc etc.  You would have a different page that the
		visitor would click on to goto each offer.<br/><br/>
		
		For offer1.php, you would want to copy and paste the php code for that specific offer.  So
		for instance if you had 3 ringtone offers on your page like, flycell, playphone and ringaza. 
		You would have a link for flycell.php, and on flycell.php you would want the php redirect 
		code for flycell.  For your ringaza links, you would have your visitor click on ringaza.php and
		have the php redirect code for ringaza copy and pasted onto ringaza.php.  <br/><br/>
		
		Basically for each offer has their own php page, and you want to copy the code given below
		for each offer, onto each of their associated pages.  If you have any more questions please
		contact the live support and we can walk you through the process.</p>';
	
	
	$count = 0;
	while ($count < ($_POST['counter']+1)) {
		$count++;
		
		$aff_campaign_id = $_POST['aff_campaign_id_'.$count];
		if ($aff_campaign_id != 0) {
			
			$mysql['aff_campaign_id'] = mysql_real_escape_string($aff_campaign_id);
			$aff_campaign_sql = "SELECT aff_campaign_id_public, aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id='".$mysql['aff_campaign_id']."'";
			$aff_campaign_result = mysql_query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql); 
			$aff_campaign_row = mysql_fetch_assoc($aff_campaign_result);
			
			//for each real campaign selected, display the code to be used for it
			$outbound_php = '<?php
  
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
	printf('<p><textarea class="code_snippet large">%s</textarea></p>', $html['outbound_php']);
			
			
			
			
			
		}
	} 
	
	


  ?>