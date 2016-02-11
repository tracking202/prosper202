<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();

$slack = false;
$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
$mysql['user_own_id'] = $db->real_escape_string($_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url'])) 
	$slack = new Slack($user_row['url']);
	
//check variables
	if(empty($_POST['aff_network_id'])) { $error['aff_network_id'] = '<div class="error"><small><span class="fui-alert"></span> You have not selected an affiliate network.</small></div>'; }
	if(empty($_POST['aff_campaign_id'])) { $error['aff_campaign_id'] = '<div class="error"><small><span class="fui-alert"></span>You have not selected an affiliate campaign.</small></div>'; }
	if(empty($_POST['method_of_promotion'])) { $error['method_of_promotion'] = '<div class="error"><small><span class="fui-alert"></span>You have to select your method of promoting this affiliate link.</small></div>'; }
	
	echo $error['aff_network_id'] . $error['aff_campaign_id'] . $error['method_of_promotion'];
	
	if ($error) { die(); }  
	
//but we'll allow them to choose the following options, can make a tracker link without but they will be notified
	//if they do a landing page, make sure they have one
	if ($_POST['method_of_promotion'] == 'landingpage') { 
		if (empty($_POST['landing_page_id'])) {
			$error['landing_page_id'] = '<div class="error"><small><span class="fui-alert"></span>You have not selected a landing page to use.</small></div>'; 
		}
		
		echo $error['landing_page_id']; 
		if ($error['landing_page_id']) { die(); }    
	}

//echo error
	echo $error['text_ad_id'] . $error['ppc_network_id'] . $error['ppc_account_id'] . $error['cpc'] . $error['click_cloaking'] . $error['cloaking_url'];

//show tracking code

	$mysql['landing_page_id'] = $db->real_escape_string($_POST['landing_page_id']);
	$landing_page_sql = "SELECT * FROM 202_landing_pages LEFT JOIN 202_aff_campaigns USING (aff_campaign_id) LEFT JOIN 202_aff_networks USING (aff_network_id) WHERE landing_page_id='".$mysql['landing_page_id']."'";
	$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
	$landing_page_row = $landing_page_result->fetch_assoc();
	
	if ($slack)
		$slack->push('simple_landing_page_code_generated', array('name' => $landing_page_row['landing_page_nickname'], 'campaign' => $landing_page_row['aff_campaign_name'], 'network' => $landing_page_row['aff_network_name'], 'user' => $user_row['username']));

	$parsed_url = parse_url($landing_page_row['landing_page_url']);
	
	?><small><em><u>Make sure you test out all the links to make sure they work yourself before running them live.</u></em></small><?php 	

	if ($_POST['method_of_promotion'] == 'landingpage') {

	$affiliate_link = 'http://' . getTrackingDomain() . get_absolute_url().'tracking202/redirect/go.php?lpip=' . $landing_page_row['landing_page_id_public'];
	$html['affiliate_link'] = htmlentities($affiliate_link);

	$javascript_code = '<script>
	(function(d, s) {
		var js, upxf = d.getElementsByTagName(s)[0], load = function(url, id) {
			if (d.getElementById(id)) {return;}
			if202 = d.createElement("script");if202.src = url;if202.async = true;if202.id = id;
			upxf.parentNode.insertBefore(if202, upxf);
		};
		load("http://' . getTrackingDomain() . get_absolute_url().'tracking202/static/landing.php?lpip=' . $landing_page_row['landing_page_id_public'] .'", "upxif");
	}(document, "script"));
	</script>';
	
	$html['javascript_code'] = htmlentities($javascript_code);
	printf('<br></br><small><strong>Inbound Javascript Landing Page Code:</strong></small><br/>
            <span class="infotext">This is the javascript code should be put right above your &#60;&#47;body&#62; tag on <u>only</u> the page(s) where your PPC visitors will first arrive to.
			This code is not supposed to be placed on every single page on your website. For example this <u>is not</u> to be placed in a template file that is to be included on everyone of your pages.</span>
            <textarea class="form-control" rows="10" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>', $html['javascript_code']);

	printf('<br/><small><strong>Option 1: Landing Page: Outbound Redirect Link:</strong></small><br/>
			<span class="infotext">Use this link if you don\'t want to manualy upload PHP code to your server<br/>
            </span><br/>
            <textarea class="form-control" rows="1" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>', $html['affiliate_link']);
	
	$affiliate_link = 'http://' . getTrackingDomain() . get_absolute_url().'tracking202/redirect/lp.php?lpip=' . $landing_page_row['landing_page_id_public'];
	$html['affiliate_link'] = htmlentities($affiliate_link);
	
	$outbound_php = '<?php
	
  // -------------------------------------------------------------------
  //
  // Tracking202 PHP Redirection, created on ' . date('D M, Y',time()) .'
  //
  // This PHP code is to be used for the following landing page.
  // ' . $landing_page_row['landing_page_url'] . '
  //
  // -------------------------------------------------------------------
	
  if (isset($_COOKIE[\'tracking202outbound\'])) {
	$tracking202outbound = $_COOKIE[\'tracking202outbound\'];
  } else {
	$tracking202outbound = \''.$html['affiliate_link'].'&pci=\'.$_COOKIE[\'tracking202pci\'];
  }
	
  header(\'location: \'.$tracking202outbound);
	
?>';
	$html['outbound_php'] = htmlentities($outbound_php);
	
	printf('<br/><small><strong>Option 2: Landing Page: Outbound PHP Redirect Code:</strong></small><br/>
			<span class="infotext">This is the php code so you can <u>cloak your affiliate link</u>.
            Instead of having your affiliate link be seen on your outgoing links on your landing page,
			you can have your outgoing links just goto another page on your site,
            which then redirects the visitor to your affiliate link<br/><br/>
            So for example, if you wanted to have yourdomain.com/redirect.php be your cloaked affiliate link,
            on redirect.php you would place our <u>outbound php redirect code</u>.
            When the visitor goes to redirect.php with our outbound php code installed,
            they simply get redirected out to your affiliate link.<br/><br/>
            You must have PHP installed on your server for this to work! </span><br/>
            <p><textarea class="form-control" rows="20" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea></p>', $html['outbound_php']);
	
	$outbound_javascript = '
<!DOCTYPE html>
<html>
<head>
	<title>GO</title>
</head>
<body>

<!-- PLACE OTHER LANDING PAGE CLICK THROUGH CONVERSION TRACKING PIXELS HERE -->
	
<!-- NOW THE TRACKING202 REDIRECTS OUT -->
<script type="text/javascript">
if (readCookie(\'tracking202outbound\') != \'\') {
	window.location=readCookie(\'tracking202outbound\');
} else {
	window.location=\'http://'. getTrackingDomain() . get_absolute_url().'tracking202/redirect/lp.php?lpip=' . $landing_page_row['landing_page_id_public'] .'\';
}
	
function readCookie(name) {
	var nameEQ = name + "=";
	var ca = document.cookie.split(\';\');
	for(var i=0;i < ca.length;i++) {
		var c = ca[i];
		while (c.charAt(0)==\' \') c = c.substring(1,c.length);
		if (c.indexOf(nameEQ) == 0) return urldecode(c.substring(nameEQ.length,c.length));
	}
	return false;
}

function urldecode(url) {
	  return decodeURIComponent(url.replace(/\+/g, \' \'));
}      
</script>
</body>
</html>';
	
	$html['outbound_javascript'] = htmlentities($outbound_javascript);
	printf('<strong><small><br/>Option 3: Landing Page: Outbound Javascript Redirect Code:</strong></small><br/>
			<span class="infotext">This allows you to generate a javascript redirect instead of a PHP redirect. 
			This is useful when you want to use other services like google website optimizers
			 to track the click-through ratios on your landing pages. With the normal PHP redirect
			 you previously could not do this.  With the new Javascript Redirect, you can place
			 other javascript tags to fire before processing the javascript redirect.</span><br></br>
             <textarea class="form-control" rows="12" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>', $html['outbound_javascript']);

	print('<br/><strong><small>Dynamic Content Segment</strong></small><br/>
		   <span class="infotext">Currently Dynamic Content Segments can dynamically display the following information on your landing pages:
		   <ul style="font-size: 12px;">
		   	<li>Visitor\'s Country - <strong>t202Country</strong></li>
			<li>Visitor\'s Country Code - <strong>t202CountryCode</strong></li>
			<li>Visitor\'s Region/State - <strong>t202Region</strong></li>
			<li>Visitor\'s City - <strong>t202City</strong></li>
			<li>Visitor\'s Postal/Zip Code - <strong>t202Postal</strong></li>
			<li>Visitor\'s Browser - <strong>t202Browser</strong></li>
			<li>Visitor\'s Operating System - <strong>t202OS</strong></li>
			<li>Visitor\'s Device Type - <strong>t202Device</strong></li>
			<li>Visitor\'s ISP - <strong>t202ISP</strong></li>
			<li>Value passed in t202kw - <strong>t202kw</strong></li>
			<li>Value passed in C1-C4 - <strong>t202c1, t202c2, t202c3, t202c4</strong></li>
			<li>Value passed in utm_source - <strong>t202utm_source</strong></li>
			<li>Value passed in utm_medium - <strong>t202utm_medium</strong></li>
			<li>Value passed in utm_term - <strong>t202utm_term</strong></li>
			<li>Value passed in utm_content - <strong>t202utm_content</strong></li>
			<li>Value passed in utm_campaign - <strong>t202utm_campaign</strong></li>
		   </ul>
		   So how easy is it to display the visitor\'s country on your landing page? Here\'s the html for it:<br/>
		   <code>Welcome I see you are reading this from &lt;span name=&quot;t202Country&quot; t202Default=&apos;Your Country&apos;&gt;Your Country&lt;/span&gt;</code></span>');

} 
  ?>