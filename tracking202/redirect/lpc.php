<?php
declare(strict_types=1);

use Tracking202\Redirect\RedirectHelper;

// Goes on when the database is out of reach: it answers without the database.
$p202OutageFallback = true;
require_once substr(__DIR__, 0, -21) . '/202-config/connect2.php';
require_once substr(__DIR__, 0, -21) . '/202-config/class-dataengine-slim.php';

// Speculative requests (browser prefetch/prerender, link-preview scanners, HEAD
// probes) must not record a click — otherwise the speculative hit plus the real
// navigation double-count the same visit (see p202IsSpeculativeRequest).
if (p202IsSpeculativeRequest()) {
	p202DeclineSpeculativeRequest();
}


// The landing page's id exactly: FILTER_VALIDATE_INT took 0, +12 and padded
// whitespace.
$landingId = \Prosper202\Click\PublicId::parse($_GET['lpip'] ?? null);
if ($landingId === null) {
    RedirectHelper::redirect(p202InstallPath('202-404.php'));
}

$varsParam = RedirectHelper::getStringParam('202vars');
if ($varsParam !== null) {
    // Decoded as sent: it joins a URL, never a statement, and escaping
    // base64 text changed nothing but needed a connection.
    $mysql['202vars'] = base64_decode($varsParam);
}

// The landing page and its campaign, when the campaign is the page's
// account's. The row carries the campaign's id, which a rotating campaign's
// URL rotation is kept under: without it every such campaign turned one
// shared rotation.
if (!$db instanceof \mysqli) {
    die();
}
try {
    $tracker_row = \Prosper202\Repository\LookupRepositoryFactory::reads($db)->landingPageOffer($landingId);
} catch (\Throwable $lpcReadError) {
    record_mysql_error($db, 'reading landing page ' . $landingId, $lpcReadError->getMessage());
}

if ($tracker_row === null) {
    die();
}
//DONT ESCAPE THE DESITNATIONL URL IT TOTALLY SCREWS UP
$html['aff_campaign_name'] = htmlentities((string)($tracker_row['aff_campaign_name'] ?? ''), ENT_QUOTES, 'UTF-8');
// The second cloaked hop, under this install's directory (p202InstallPath()).
$html['cloaked_hop'] = htmlspecialchars(p202InstallPath('tracking202/redirect/cl2.php'), ENT_QUOTES, 'UTF-8');

//modify the redirect site url to go through another cloaked link
$redirect_site_url = rotateTrackerUrl($db, $tracker_row);

// The visitor's click, by its cookie, read exactly (ClickId): anything else
// is no click, as no cookie was.
$lpcClickId = \Prosper202\Click\ClickId::parse(getCookie202('tracking202subid'));
$mysql['click_id'] = $lpcClickId === null ? '' : (string) $lpcClickId;

$redirect_site_url = replaceTrackerPlaceholders(
    $db,
    $redirect_site_url,
    $mysql['click_id'],
    (int) $tracker_row['user_id']
);

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
		<meta http-equiv="refresh" content="1; url=<?php echo htmlspecialchars($redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>">
	</head>
	<body>
	
		<form name="form1" id="form1" method="get" action="<?php echo $html['cloaked_hop']; ?>">
			<input type="hidden" name="q" value="<?php echo htmlspecialchars($redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>"/>
		</form>
		<script type="text/javascript">
			document.form1.submit();
		</script>
	
	
		<div style="padding: 30px; text-align: center;">
			You are being automatically redirected.<br/><br/>
			Page Stuck? <a href="<?php echo htmlspecialchars($redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>">Click Here</a>.
		</div>
    </body>
</html> 
