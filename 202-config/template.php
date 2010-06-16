<?  function template_top($title = 'Prosper202 Self Hosted Apps') { global $navigation; global $version;  ?>


<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en"> 
<head>

<title><? echo $title; ?></title>
<meta name="description" content="description" />
<meta name="keywords" content="keywords"/>
<meta name="copyright" content="Prosper202, Inc" />
<meta name="author" content="Prosper202, Inc" />
<meta name="MSSmartTagsPreventParsing" content="TRUE"/>

<meta name="robots" content="noindex, nofollow" />
<meta http-equiv="Content-Script-Type" content="text/javascript" />
<meta http-equiv="Content-Style-Type" content="text/css" />
<meta http-equiv="imagetoolbar" content="no"/>
  
<link rel="shortcut icon" href="/202-img/favicon.gif" type="image/ico"/> 
<link href="/202-css/account.css" rel="stylesheet" type="text/css"/>

<? switch ($navigation[1]) { 
	
	case "tracking202": 
	case "stats202":
	case "alerts202": 
	case "offers202":
		?><link href="/202-css/tracking202.css" rel="stylesheet" type="text/css"/>
		<link href="/202-css/reporting.css" rel="stylesheet" type="text/css"/>
		<link href="/202-css/scal.css" rel="stylesheet" type="text/css"/>
		<script type="text/javascript" src="/tracking202/js/tracking202scripts.js"></script>  
		<script type="text/javascript" src="/tracking202/js/call_prefs.js"></script>  
		<script type="text/javascript" src="/tracking202/js/prototype.js"></script>   
		<script type="text/javascript" src="/tracking202/js/scriptaculous/scriptaculous.js"></script>
		<script type="text/javascript" src="/tracking202/js/scal.js"></script>
		<script type="text/javascript" src="/stats202/js/stats202.js"></script>
		<link href="/202-css/offers202.css" rel="stylesheet" type="text/css"/>
		<script type="text/javascript" src="/offers202/js/offers202.js"></script>
		<link href="/202-css/offers202.css" rel="stylesheet" type="text/css"/><?
		break;
} ?>
<body>


<div class="body">


	<div class="body-content">
	
	<table class="header" cellspacing="0" cellpadding="0">
		<tr>
			<td class="shrink-width"><iframe class="advertise-top-left" src="http://prosper.tracking202.com/ads/prosper202/top-left/" scrolling="no" frameborder="0"></iframe></td>
			<td>
				
				<div class="skyline">
		
					<div style="float: left; ">
						<a href="/tracking202/" <? if ($navigation[1] == 'tracking202') { echo 'class="bold";'; } ?>>Tracking202</a>  
						&middot;
						<a href="/stats202/" <? if ($navigation[1] == 'stats202') { echo 'class="bold";'; } ?>>Stats202</a>  
						&middot;
						<a href="/offers202/" <? if ($navigation[1] == 'offers202') { echo 'class="bold";'; } ?>>Offers202</a>  
						&middot;
						<a href="/alerts202/" <? if ($navigation[1] == 'alerts202') { echo 'class="bold";'; } ?>>Alerts202</a>  
					</div>
					
					<a href="/202-account/" <? if (($navigation[1] == '202-account') AND !$navigation[2]) { echo 'class="bold";'; } ?>>Home</a>  
					&middot;
					<a href="/202-account/account.php" <? if ($navigation[2] == 'account.php') { echo 'class="bold";'; } ?>>My Account</a>  
					&middot; 
					<a href="/202-account/administration.php" <? if ($navigation[2] == 'administration.php') { echo 'class="bold";'; } ?>>Administration</a>  
					&middot; 
					<a href="/202-account/help.php" <? if ($navigation[2] == 'help.php') { echo 'class="bold";'; } ?>>Help</a>  
					&middot;
					<a href="/202-account/signout.php">Sign Out</a>  
				</div>
				
				<? if ($_SESSION['update_needed'] == true) { ?>
					<table class="alert">
						<tr>
							<td>A new version of Prosper202 is available! <a href="http://prosper202.com/apps/download/">Please update now</a>.</td>
						</tr> 
					</table>
				<? } ?>
			</td>
		</tr>
	</table>
	
	<div class="content"><? 
		
		if ($navigation[1] == 'tracking202') {  include_once($_SERVER['DOCUMENT_ROOT'] . '/tracking202/_config/top.php'); }
		if ($navigation[1] == 'tracking202api') {  include_once($_SERVER['DOCUMENT_ROOT'] . '/tracking202api/_config/top.php'); }
		
	} function template_bottom() { global $version;?></div>
	
	<div style="clear: both;"></div>
	<div class="footer">
		Thank you for marketing with <a href="http://prosper202.com">Prosper202</a>
		&middot; 
		<a href="/202-account/help.php">Help</a>
		&middot; 
		<a href="http://prosper202.com/apps/docs/">Documentation</a>
		&middot; 
		<a href="http://prosper202.com/apps/donate/">Donate</a>
		&middot; 
		<a href="http://prosper202.com/forum/">Forum</a>
		&middot; 
		
		<? if ($_SESSION['update_needed'] == true) { ?>
		 	<strong>Your Prosper202 <? echo $version; ?> is out of date. <a href="http://prosper202.com/apps/download/">Please update</a>.</strong>
		 <? } else { ?>
		 	Your Prosper202 <? echo $version; ?> is up to date.
		 <? } ?>
		 
		 <p style="margin-top: 10px;">Like our software? &nbsp; You'll love the <a href="http://revolution.tracking202.com" style="padding: 0px;">Revolution202 Partner Network</a>!</p>
		 
		 
	<table style="margin: 20px auto 0px; text-align: left;" cellspacing="0" cellpadding="0">
		<tr valign="top">
			<td><a rel="license" href="http://creativecommons.org/licenses/by-nc-sa/3.0/"><img alt="Creative Commons License" style="border-width:0" src="/202-img/BYNCSA.png" /></a></td>
			<td style="line-height: 1.5em; padding-left: 10px; ">This work (Prosper202 and Tracking202) is licensed under a<br/> <a rel="license" href="http://creativecommons.org/licenses/by-nc-sa/3.0/">Creative Commons Attribution-Noncommercial-Share Alike 3.0 Unported License</a>.</td>
		</tr>
	</table>
		
	</div>



</div>


</body>


<? } ?>
