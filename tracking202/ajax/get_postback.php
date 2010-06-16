<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

	//require authenticated user
	AUTH::require_user(); 
	if ($_POST['aff_campaign_id'] == '') { $error['aff_campaign_id'] = '<div class="error">Please select a campaign</div>'; } 
	if ($error) { echo $error['aff_campaign_id']; die(); }
		
		
	//run the code
	$mysql['aff_campaign_id'] = mysql_real_escape_string($_POST['aff_campaign_id']);
	$aff_campaign_sql = "SELECT * FROM 202_aff_campaigns WHERE aff_campaign_id='".$mysql['aff_campaign_id']."'";
	$aff_campaign_result = mysql_query($aff_campaign_sql);
	$aff_campaign_row = mysql_fetch_assoc($aff_campaign_result);
	
	$html['aff_campaign_id_public'] = htmlentities($aff_campaign_row['aff_campaign_id_public']);
	$html['aff_campaign_name'] = htmlentities($aff_campaign_row['aff_campaign_name']);
	
	//the pixel
	$pixel = '<img height="1" width="1" border="0" style="display: none;" src="http://'.$_SERVER['SERVER_NAME'].'/tracking202/static/px.php?acip='.$html['aff_campaign_id_public'].'"/>';
	
	//post back url
	$postback = 'http://'.$_SERVER['SERVER_NAME'].'/tracking202/static/pb.php?acip='.$html['aff_campaign_id_public'].'&subid=';
	
	
	printf('<b>Tracking Pixel For '. $html['aff_campaign_name'].'</b>
                Here is the tracking pixel for your campaign, give this to the network or advertiser you are working with and ask them to place it on the confirmation page.
                With the pixel installed on the confirmation page, everytime you get a lead or sale, it will fire the pixel and update your leads automatically when this pixel fires.
               <textarea class="code_snippet">%s</textarea>', $pixel);
	
	printf('<b>Post Back URL for '. $html['aff_campaign_name'].'</b>
                If the network you work with supports post back URLS, you can use this URL.  The network should use this post-back URL and call it when a lead or sale takes place
                and they should put the SUBID at the end of the url.  When the post back url is called it should automatically update your subids for you.
               <textarea class="code_snippet">%s</textarea>', $postback);	
	
	