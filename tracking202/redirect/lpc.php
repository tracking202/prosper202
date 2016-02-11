<?php include_once(substr(dirname( __FILE__ ), 0,-21) . '/202-config/connect2.php'); 
include_once(substr(dirname( __FILE__ ), 0,-21) . '/202-config/class-dataengine-slim.php');

//run script   
$mysql['landing_page_id_public'] = $db->real_escape_string($_GET['lpip']);
if(isset($_GET['202vars'])){
	$mysql['202vars'] = base64_decode($db->real_escape_string($_GET['202vars']));
}

$tracker_sql = "SELECT  aff_campaign_name,
						  aff_campaign_rotate,
						  aff_campaign_url,
						  aff_campaign_url_2,
						  aff_campaign_url_3,
						  aff_campaign_url_4,
						  aff_campaign_url_5
				FROM    202_landing_pages LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)
				WHERE   landing_page_id_public='".$mysql['landing_page_id_public']."'";
$tracker_row = memcache_mysql_fetch_assoc($db, $tracker_sql);

if (!$tracker_row) { die(); }
//DONT ESCAPE THE DESITNATIONL URL IT TOTALLY SCREWS UP
$html['aff_campaign_name'] = htmlentities($tracker_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8'); 

//modify the redirect site url to go through another cloaked link
$redirect_site_url = rotateTrackerUrl($db, $tracker_row);

// get the click id
$mysql['click_id']=$db->real_escape_string($_COOKIE['tracking202subid']);

$redirect_site_url = replaceTrackerPlaceholders($db, $redirect_site_url,$mysql['click_id']);

//set dirty hour
$de = new DataEngine();
$data=($de->setDirtyHour($mysql['click_id']));

if(isset($mysql['202vars'])){
	
	$redirect_site_url = setPrePopVars($mysql['202vars'],$redirect_site_url,false);
}

?>

<html>
	<head>
		<title><?php echo $html['aff_campaign_name']; ?></title>
		<meta name="robots" content="noindex">
		<meta http-equiv="refresh" content="1; url=<?php echo $redirect_site_url; ?>">
	</head>
	<body>
	
		<form name="form1" id="form1" method="get" action="/tracking202/redirect/cl2.php">
			<input type="hidden" name="q" value="<?php echo $redirect_site_url; ?>"/>
		</form>
		<script type="text/javascript">
			document.form1.submit();
		</script>
	
	
		<div style="padding: 30px; text-align: center;">
			You are being automatically redirected.<br/><br/>
			Page Stuck? <a href="<?php echo $redirect_site_url; ?>">Click Here</a>.
		</div>
    </body>
</html> 