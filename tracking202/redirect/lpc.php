<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

//run script   
$mysql['landing_page_id_public'] = mysql_real_escape_string($_GET['lpip']);

$tracker_sql = "SELECT  aff_campaign_name,
						  aff_campaign_rotate,
						  aff_campaign_url,
						  aff_campaign_url_2,
						  aff_campaign_url_3,
						  aff_campaign_url_4,
						  aff_campaign_url_5
				FROM    202_landing_pages LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)
				WHERE   landing_page_id_public='".$mysql['landing_page_id_public']."'";
$tracker_row = memcache_mysql_fetch_assoc($tracker_sql);

if (!$tracker_row) { die(); }
//DONT ESCAPE THE DESITNATIONL URL IT TOTALLY SCREWS UP
$html['aff_campaign_name'] = htmlentities($tracker_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8'); 

//modify the redirect site url to go through another cloaked link
$redirect_site_url = rotateTrackerUrl($tracker_row);
$redirect_site_url = replaceTrackerPlaceholders($redirect_site_url,$click_id);
?>

<html>
	<head>
		<title><? echo $html['aff_campaign_name']; ?></title>
		<meta name="robots" content="noindex">
		<meta http-equiv="refresh" content="1; url=<? echo $redirect_site_url; ?>">
	</head>
	<body>
	
		<form name="form1" id="form1" method="get" action="/tracking202/redirect/cl2.php">
			<input type="hidden" name="q" value="<? echo $redirect_site_url; ?>"/>
		</form>
		<script type="text/javascript">
			document.form1.submit();
		</script>
	
	
		<div style="padding: 30px; text-align: center;">
			You are being automatically redirected.<br/><br/>
			Page Stuck? <a href="<? echo $redirect_site_url; ?>">Click Here</a>.
		</div>
    </body>
</html> 