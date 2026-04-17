<?php
declare(strict_types=1);

use Tracking202\Redirect\RedirectHelper;

require_once substr(__DIR__, 0, -21) . '/202-config/connect2.php';

// Validate required parameter
$clickIdPublic = RedirectHelper::getIntParam('pci');
if ($clickIdPublic === null) {
    RedirectHelper::redirect('/202-404.php');
}

$mysql['click_id_public'] = $db->real_escape_string((string)$clickIdPublic);

$varsParam = RedirectHelper::getStringParam('202vars');
if ($varsParam !== null) {
    $mysql['202vars'] = base64_decode((string) $db->real_escape_string($varsParam));
}
$tracker_sql = "
	SELECT
		aff_campaign_name,
		site_url_address,
		user_pref_cloak_referer
	FROM
		202_clicks, 202_clicks_record, 202_clicks_site, 202_site_urls, 202_aff_campaigns,202_users_pref
	WHERE
		202_clicks.aff_campaign_id = 202_aff_campaigns.aff_campaign_id
		AND 202_users_pref.user_id = 1
		AND 202_clicks.click_id = 202_clicks_record.click_id
		AND 202_clicks_record.click_id_public='".$mysql['click_id_public']."'
		AND 202_clicks_record.click_id = 202_clicks_site.click_id 
		AND 202_clicks_site.click_redirect_site_url_id = 202_site_urls.site_url_id
";

$tracker_row = memcache_mysql_fetch_assoc($db, $tracker_sql);

if (!$tracker_row) {
	$action_site_url = "/202-404.php";
	$redirect_site_url = "/202-404.php";
	$referrer = '';
	$html['aff_campaign_name'] = '';
} else {
	$action_site_url = "/tracking202/redirect/cl2.php";
	//modify the redirect site url to go through another cloaked link
	$redirect_site_url = $tracker_row['site_url_address'];
	$referrer = $tracker_row['user_pref_cloak_referer'] ?? '';
	$html['aff_campaign_name'] = $tracker_row['aff_campaign_name'] ?? '';
} 

if(isset($mysql['202vars'])&&$mysql['202vars']!=''){
	//remove & at the end of the string

	if(!parse_url ((string) $redirect_site_url,PHP_URL_QUERY)){
		
		//if there is no query url the add a ? to thecVars but before doing that remove case where there may be a ? at the end of the url and nothing else
		$redirect_site_url = rtrim((string) $redirect_site_url,'?');

		//remove the & from thecVars and put a ? in fron t of it

		$redirect_site_url .="?".$mysql['202vars'];

	}
	else {

		$redirect_site_url .="&".$mysql['202vars'];

	}}
	
	?>

<html>
	<head>
		<title><?php echo htmlspecialchars((string) $html['aff_campaign_name'], ENT_QUOTES, 'UTF-8'); ?></title>
		<meta name="robots" content="noindex">
		<meta name="referrer" content="<?php echo htmlspecialchars((string) $referrer, ENT_QUOTES, 'UTF-8'); ?>">
		<meta http-equiv="refresh" content="0; url=<?php echo htmlspecialchars((string) $redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>">
	</head>
	<body>
	
		<form name="form1" id="form1" method="get" action="<?php echo htmlspecialchars((string) $action_site_url, ENT_QUOTES, 'UTF-8'); ?>">
			<input type="hidden" name="q" value="<?php echo htmlspecialchars((string) $redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>"/>
			<input type="hidden" name="r" value="<?php echo htmlspecialchars((string) $referrer, ENT_QUOTES, 'UTF-8'); ?>"/>
		</form>
		<script type="text/javascript">
			document.form1.submit();
		</script>
		
		
		<div style="padding: 30px; text-align: center;">
			You are being automatically redirected to <?php echo htmlspecialchars((string) $html['aff_campaign_name'], ENT_QUOTES, 'UTF-8'); ?>.<br/><br/>
			Page Stuck? <a href="<?php echo htmlspecialchars((string) $redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>">Click Here</a>.
		</div>
	</body> 
</html> 
