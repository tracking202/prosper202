<?php
declare(strict_types=1);

use Tracking202\Redirect\RedirectHelper;

require_once substr(__DIR__, 0, -21) . '/202-config/connect2.php';

// The click's public id exactly: FILTER_VALIDATE_INT took 0 (every click
// stored without one), +12 and padded whitespace.
$clickIdPublic = \Prosper202\Click\ClickId::parse($_GET['pci'] ?? null);
if ($clickIdPublic === null) {
    RedirectHelper::redirect(p202InstallPath('202-404.php'));
}

$varsParam = RedirectHelper::getStringParam('202vars');
if ($varsParam !== null) {
    $mysql['202vars'] = base64_decode((string) $db->real_escape_string($varsParam));
}

// The click's destination, its campaign's name when the campaign is the
// click's account's, and that account's referer setting (it was the first
// account's for every click). Not cached: a rotator re-click keeps the
// public id and changes where the click goes.
// With MySQL down there is nothing to read: the not-found page, as before.
$tracker_row = null;
if ($db instanceof \mysqli) {
    try {
        $tracker_row = \Prosper202\Repository\LookupRepositoryFactory::reads($db)->cloakedClick($clickIdPublic);
    } catch (\Throwable $clReadError) {
        record_mysql_error($db, 'reading cloaked click ' . $clickIdPublic, $clReadError->getMessage());
    }
}

if ($tracker_row === null) {
	$action_site_url = p202InstallPath('202-404.php');
	$redirect_site_url = p202InstallPath('202-404.php');
	$referrer = '';
	$html['aff_campaign_name'] = '';
} else {
	$action_site_url = p202InstallPath('tracking202/redirect/cl2.php');
	//modify the redirect site url to go through another cloaked link
	$redirect_site_url = $tracker_row['site_url_address'];
	$referrer = $tracker_row['user_pref_cloak_referer'] ?? 'origin'; // no preferences row: the column's default
	$html['aff_campaign_name'] = $tracker_row['aff_campaign_name'] ?? '';
} 

if(isset($mysql['202vars'])&&$mysql['202vars']!=''){
	// split any #fragment off first: the vars must join the QUERY string —
	// appended after a fragment they stay client-side and never reach the
	// landing page (drops prepop vars and the t202ctx token; review finding)
	$cl_fragment = '';
	$cl_hash_pos = strpos((string) $redirect_site_url, '#');
	if ($cl_hash_pos !== false) {
		$cl_fragment = substr((string) $redirect_site_url, $cl_hash_pos);
		$redirect_site_url = substr((string) $redirect_site_url, 0, $cl_hash_pos);
	}

	if(!parse_url ((string) $redirect_site_url,PHP_URL_QUERY)){
		
		//if there is no query url the add a ? to thecVars but before doing that remove case where there may be a ? at the end of the url and nothing else
		$redirect_site_url = rtrim((string) $redirect_site_url,'?');

		//remove the & from thecVars and put a ? in fron t of it

		$redirect_site_url .="?".$mysql['202vars'];

	}
	else {

		$redirect_site_url .="&".$mysql['202vars'];

	}

	$redirect_site_url .= $cl_fragment;
}
	
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
