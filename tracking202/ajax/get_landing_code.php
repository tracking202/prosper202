<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

	
//check variables
	if(empty($_POST['aff_network_id'])) { $error['aff_network_id'] = '<div class="error">You have not selected an affiliate network.</div>'; }
	if(empty($_POST['aff_campaign_id'])) { $error['aff_campaign_id'] = '<div class="error">You have not selected an affiliate campaign.</div>'; }
	if(empty($_POST['method_of_promotion'])) { $error['method_of_promotion'] = '<div class="error">You have to select your method of promoting this affiliate link.</div>'; }
	
	echo $error['aff_network_id'] . $error['aff_campaign_id'] . $error['method_of_promotion'];
	
	if ($error) { die(); }  
	
//but we'll allow them to choose the following options, can make a tracker link without but they will be notified
	//if they do a landing page, make sure they have one
	if ($_POST['method_of_promotion'] == 'landingpage') { 
		if (empty($_POST['landing_page_id'])) {
			$error['landing_page_id'] = '<div class="error">You have not selected a landing page to use.</div>'; 
		}
		
		echo $error['landing_page_id']; 
		if ($error['landing_page_id']) { die(); }    
	}

//echo error
	echo $error['text_ad_id'] . $error['ppc_network_id'] . $error['ppc_account_id'] . $error['cpc'] . $error['click_cloaking'] . $error['cloaking_url'];

//show tracking code

	$mysql['landing_page_id'] = mysql_real_escape_string($_POST['landing_page_id']);
	$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `landing_page_id`='".$mysql['landing_page_id']."'";
	$landing_page_result = mysql_query($landing_page_sql) or record_mysql_error($landing_page_sql);
	$landing_page_row = mysql_fetch_assoc($landing_page_result);
	
	$parsed_url = parse_url($landing_page_row['landing_page_url']);
	
	?><p><u>Make sure you test out all the links to make sure they work yourself before running them live.</u></p><?
	

	if ($_POST['method_of_promotion'] == 'landingpage') {

	$affiliate_link = 'http://' . getTrackingDomain() . '/tracking202/redirect/lp.php?lpip=' . $landing_page_row['landing_page_id_public'];
	$html['affiliate_link'] = htmlentities($affiliate_link);

	$javascript_code = '<script language="JavaScript" type="text/javascript"> cbr202=Math.random()*10000000000000000;document.write(\'<scr\'+\'ipt language="JavaScript" src="http://' . getTrackingDomain() . '/tracking202/static/landing.php?lpip=' . $landing_page_row['landing_page_id_public'] .'&202cb=\'+cbr202+\'" type="text/javascript"></scr\' + \'ipt>\'); </script>';
	//$javascript_code = '<script src="http://' . getTrackingDomain() . '/tracking202/static/landing.php?lpip=' . $landing_page_row['landing_page_id_public'] .'" type="text/javascript"></script>';
	$html['javascript_code'] = htmlentities($javascript_code);
printf('<p><b>Inbound Javascript Landing Page Code:</b>
            This is the javascript code should be put right above your &#60;&#47;body&#62; tag on <u>only</u> the page(s) where your PPC visitors will first arrive to.
			This code is not supposed to be placed on every single page on your website. For example this <u>is not</u> to be placed in a template file that is to be included on everyone of your pages.<br/><br/>
            This code is supposed to be only placed on the first page(s), that an incoming PPC visitor would be sent to.  
            Tracking202 is not designed to be a webpage analytics, this is specifically javascript code only to track visitors coming in.</p>
            <p><textarea class="code_snippet">%s</textarea></p>', $html['javascript_code']);

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
	printf('<p><b>Option 1: Landing Page: Outbound PHP Redirect Code:</b>
			This is the php code  so you can <u>cloak your affiliate link</u>.
            Instead of having your affiliate link be seen on your outgoing links on your landing page,
			you can have your outgoing links just goto another page on your site, 
            which then redirects the visitor to your affiliate link<br/><br/>
            So for example, if you wanted to have yourdomain.com/redirect.php be your cloaked affiliate link,
            on redirect.php you would place our <u>outbound php redirect code</u>. 
            When the visitor goes to redirect.php with our outbound php code installed, 
            they simply get redirected out to your affiliate link.<br/><br/>
            You must have PHP installed on your server for this to work! </p>
            <p><textarea class="code_snippet large">%s</textarea></p>', $html['outbound_php']);


	$outbound_javascript = '<!-- PLACE OTHER LANDING PAGE CLICK THROUGH CONVERSION TRACKING PIXELS HERE -->
	
<!-- NOW THE TRACKING202 REDIRECTS OUT -->
<script type="text/javascript">
if (readCookie(\'tracking202outbound\') != \'\') {
	window.location=readCookie(\'tracking202outbound\');
} else {
	window.location=\'http://'. getTrackingDomain() .'/tracking202/redirect/lp.php?lpip=' . $landing_page_row['landing_page_id_public'] .'\';
}
	
function readCookie(name) {
	var nameEQ = name + "=";
	var ca = document.cookie.split(\';\');
	for(var i=0;i < ca.length;i++) {
		var c = ca[i];
		while (c.charAt(0)==\' \') c = c.substring(1,c.length);
		if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
	}
	return null;
}
</script>';
	
	$html['outbound_javascript'] = htmlentities($outbound_javascript);
	printf('<p><b>Option 2: Landing Page: Outbound Javascript Redirect Code:</b>
			This allows you to generate a javascript redirect instead of a PHP redirect. 
			This is useful when you want to use other services like google website optimizers
			 to track the click-through ratios on your landing pages. With the normal PHP redirect
			 you previously could not do this.  With the new Javascript Redirect, you can place
			 other javascript tags to fire before processing the javascript redirect.</p>
            <p><textarea class="code_snippet large">%s</textarea></p>', $html['outbound_javascript']);

} 
  ?>