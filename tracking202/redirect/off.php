<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set("display_errors", true);
ob_start();
// only allow numeric acip's

$urlvarslist = $_GET;
$acip = $_GET['acip'] ?? '';

    


// Creat blank #pci and save it with either a pci from the get var or the cookie
// (or its -legacy twin: ClickCookie, loaded by hand, as the autoloader is not yet).
require_once __DIR__ . '/../../202-config/Http/ClickCookie.php';
$pci = '';
if (isset($_GET['pci']))
    $pci = is_string($_GET['pci']) ? $_GET['pci'] : '';
elseif (\Prosper202\Http\ClickCookie::value($_COOKIE, 'tracking202pci') !== null)
    $pci = \Prosper202\Http\ClickCookie::value($_COOKIE, 'tracking202pci');

if (! is_numeric($acip))
    die();
// Goes on when the database is out of reach: it falls back to the URL it keeps for an outage.
$p202OutageFallback = true;
include_once (substr(__DIR__, 0,-21) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-21) . '/202-config/class-dataengine-slim.php');

// Speculative requests (browser prefetch/prerender, link-preview scanners, HEAD
// probes) must not record a click — otherwise the speculative hit plus the real
// navigation double-count the same visit (see p202IsSpeculativeRequest).
if (p202IsSpeculativeRequest()) {
	p202DeclineSpeculativeRequest();
}


// The visitor's click by its subid cookie, read exactly (ClickId).
$offSubidCookie = getCookie202('tracking202subid');
$click_id = $offSubidCookie === null ? '' : (string) (\Prosper202\Click\ClickId::parse($offSubidCookie) ?? '');

$usedCachedRedirect = false;
if (! $db)
    $usedCachedRedirect = true;
    
    // the mysql server is down, use the txt cached redirect
if ($usedCachedRedirect == true) {
    
    $acip = $_GET['acip'];
    
    // if a cached key is found for this acip, redirect to that url
    if ($memcacheWorking) {
        $getUrl = $memcache->get(md5('ac_' . $acip . systemHash()));
        if ($getUrl) {
            
            $new_url = str_replace("[[subid]]", "p202", $getUrl);
            // No click is recorded: the install token expands empty (plan §5.1).
            $new_url = str_ireplace('[[p202_install_token]]', '', $new_url);
            
            // c1-c4 as sent, or p202<name> for one not sent: a list (c1[]=x) is none
            foreach (['c1', 'c2', 'c3', 'c4'] as $token) {
                $sent = \Prosper202\Click\ClickRequest::text($_GET, $token);
                $new_url = str_replace("[[$token]]", $sent !== '' ? rawurlencode($sent) : "p202$token", $new_url);
            }
            
            $urlvars = getPrePopVars($urlvarslist);

            $new_url = setPrePopVars($urlvars, $new_url, false);

            p202NoStore();
            header('location: ' . $new_url);
            die();
        }
    }
    
    p202Unavailable();
}

// The offer the link names, any account's: its account is the one whose
// clicks the address lookup below searches and whose URL this is. It was
// read for the first account only, so another account's offer reached
// without a click went nowhere.
$offOffer = null;
$offOfferId = \Prosper202\Click\PublicId::parse($acip);
if ($offOfferId !== null) {
    try {
        $offOffer = \Prosper202\Repository\LookupRepositoryFactory::reads($db)->offer($offOfferId);
    } catch (\Throwable $offReadError) {
        record_mysql_error($db, 'reading campaign ' . $offOfferId, $offReadError->getMessage());
    }
}

// Nothing named the click: the visitor's last click by address. Only then —
// a click the request names (?pci=, or the pci cookie) is the one below
// resolves; this guess replaced it whenever the subid cookie was missing,
// which is every visitor the privacy setting holds back (no cookies), and
// their stored address is masked, so the guess was another visitor's click
// in the same /24, or none.
if ($offSubidCookie === null && $pci === '' && $offOffer !== null) {
    $mysql['user_id'] = $db->real_escape_string((string) $offOffer['user_id']);
    // The lookup is among the offer's account's clicks, stored under its
    // setting (p202ApplyOwnerPrivacy()).
    p202ApplyOwnerPrivacy($mysql['user_id']);
    $daysago = time() - 86400; // 24 hours
    // The visitor's last click by the address the click path stored
    // (p202StoredVisitorIp), not the proxy's REMOTE_ADDR.
    $click_row1 = \Prosper202\Click\LastClickFromAddress::find(
        new \Prosper202\Database\Connection($db),
        p202StoredVisitorIp(),
        (int) $mysql['user_id'],
        $daysago,
        !trackingEnabled()
    ) ?? [];
    $mysql['click_id'] = $db->real_escape_string((string)($click_row1['click_id'] ?? ''));
    $click_id = $mysql['click_id'];
    $mysql['ppc_account_id'] = $db->real_escape_string((string)($click_row1['ppc_account_id'] ?? ''));
    $mysql['click_id_public'] = $db->real_escape_string((string)($click_row1['click_id_public'] ?? ''));
    $pci = $mysql['click_id_public'];
}

// The click's public id exactly (from the link, the pci cookie, go.php's 202v
// or the address lookup). One that names no click is no click: compared as
// text, MySQL read 12abc as 12, and a click with no public id (0) matched
// any of the account's clicks without one, which was then rewritten.
$offPciId = \Prosper202\Click\ClickId::parse($pci);

/* OK FIRST IF THERE IS NO PUBLIC CLICK_ID, JUST REDIRECT TO THE NORMAL CAMPAIGN */
if ($offPciId === null) {
    $aff_campaign_row = $offOffer;
    // The outage fallback follows this path too (as below, for a click with
    // a public id), including a campaign left without a URL.
    if ($memcacheWorking && is_array($aff_campaign_row)) {
        \Prosper202\Click\FallbackRedirectUrl::refresh(
            md5('ac_' . $acip . systemHash()),
            \Prosper202\Click\FallbackRedirectUrl::withSubid($aff_campaign_row['aff_campaign_url'] ?? ''),
            static fn(string $key): mixed => $memcache->get($key),
            static fn(string $key, string $url): mixed => setCache($key, $url, 0),
        );
    }
    if (empty($aff_campaign_row['aff_campaign_url'])) {
        die();
    } // if there is no aff_url to redirect to DIE!
    
    $redirect_site_url = rotateTrackerUrl($db, $aff_campaign_row);
    
    // The click is read for the URL's tokens only if it is the offer's account's.
    $redirect_site_url = replaceTrackerPlaceholders(
        $db,
        $redirect_site_url,
        $click_id,
        (int) $aff_campaign_row['user_id']
    );
    $urlvars = getPrePopVars($urlvarslist);
    if (isset($urlvars)) {
        $redirect_site_url = setPrePopVars($urlvars, $redirect_site_url, false);
    }
    // ok if there is a url that exists, if redirect php style, or if its cloaked, redirect meta refresh style.
    if ($aff_campaign_row['aff_campaign_cloaking'] == 0) {
        
        // cloaking OFF, so do a php header redirect

        p202NoStore();
        header('location: ' . $redirect_site_url);
        die();
    } else {
        
        // cloaking ON, so do a meta REFRESH
        $html['aff_campaign_name'] = htmlspecialchars((string) $aff_campaign_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
        ?>

<html>
<head>
<title><?php echo $html['aff_campaign_name']; ?></title>
<meta name="robots" content="noindex">
<meta http-equiv="refresh"
	content="1; url=<?php echo htmlspecialchars($redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>

	<form name="form1" id="form1" method="get"
		action="<?php echo htmlspecialchars(p202InstallPath('tracking202/redirect/cl2.php')); ?>">
		<input type="hidden" name="q"
			value="<?php echo htmlspecialchars($redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>" />
	</form>
	<script type="text/javascript">
					document.form1.submit();
				</script>

	<div style="padding: 30px; text-align: center;">
					You are being automatically redirected to <?php echo $html['aff_campaign_name']; ?>.<br />
		<br /> Page Stuck? <a href="<?php echo htmlspecialchars($redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>">Click
			Here</a>.
	</div>
</body>
</html>

<?php
    
}
    
    // terminate this script, this is the end, if there was no public_click_id
    die();
}

/* ------------------------------------------------------- */
/* ------------------------------------------------------- */
/* ------------------------------------------------------- */
//
//
//
// ANYTHING BELOW THIS ASSUMES THERE IS A PUBLIC CLICK ID
//
//
/* ------------------------------------------------------- */
/* ------------------------------------------------------- */
/* ------------------------------------------------------- */

$info_sql = "
	SELECT
		2c.click_id,
		2c.user_id,
		2ac.user_id AS aff_campaign_user_id,
		click_filtered,
		landing_page_id,
		click_cloaking,
		click_cloaking_site_url_id,
		click_redirect_site_url_id,
		2ac.aff_campaign_id,
		aff_campaign_rotate, 
		aff_campaign_url, 
		aff_campaign_url_2, 
		aff_campaign_url_3, 
		aff_campaign_url_4, 
		aff_campaign_url_5, 
		aff_campaign_name, 
		aff_campaign_cloaking,
		aff_campaign_payout
	FROM
		202_aff_campaigns AS 2ac,
		202_clicks_record AS 2cr
		LEFT JOIN 202_clicks AS 2c ON (2c.click_id = 2cr.click_id)
		LEFT JOIN 202_clicks_site AS 2cs ON (2cs.click_id = 2cr.click_id)
		LEFT JOIN 202_clicks_rotator AS cro ON (cro.click_id = 2cr.click_id)
	WHERE
		2ac.aff_campaign_id_public=" . (int) $offOfferId . "
		AND 2cr.click_id_public=" . (int) $offPciId . "
		AND 2ac.user_id = 2c.user_id
	" . \Prosper202\Click\LatestVisit::orderBy('2c', 'cro') . "
	LIMIT 1
";
// The offer and the click are named separately (an advanced landing page
// lets the visitor choose among the account's offers, so the campaign may
// differ from the click's), and the click is then moved into the offer's
// campaign at its payout. Nothing tied the two to one account: any
// account's acip moved any click, whose public id is its id between two
// random digits, into that account's campaign (CLAUDE.md #27). Another
// account's campaign now names no row, the unknown-pair path below.

// Not cached: one click's row, which this page and a rotator re-click rewrite.
$info_row = $offOfferId === null ? null : memcache_mysql_fetch_assoc($db, $info_sql, 0);

// Guard BEFORE dereferencing: the public click id / campaign combo may not
// exist (expired, mistyped, or a DB hiccup). Never crash the redirect — fall
// back to the cached outbound url for this campaign if we have one.
if (!$info_row || !isset($info_row['click_id'])) {
    if ($memcacheWorking) {
        $getUrl = $memcache->get(md5('ac_' . $acip . systemHash()));
        if ($getUrl) {
            $urlvars = getPrePopVars($urlvarslist);
            // No click is recorded: the install token expands empty (plan §5.1).
            $new_url = setPrePopVars($urlvars, str_ireplace('[[p202_install_token]]', '', str_replace('[[subid]]', 'p202', $getUrl)), false);
            p202NoStore();
            header('location: ' . $new_url);
            die();
        }
    }
    die();
}

// The URL a MySQL outage redirects to (read above): kept equal to the
// campaign's current URL, not the one it had at its first click.
if ($memcacheWorking) {
    \Prosper202\Click\FallbackRedirectUrl::refresh(
        md5('ac_' . $acip . systemHash()),
        \Prosper202\Click\FallbackRedirectUrl::withSubid($info_row['aff_campaign_url'] ?? ''),
        static fn(string $key): mixed => $memcache->get($key),
        static fn(string $key, string $url): mixed => setCache($key, $url, 0),
    );
}

$click_id = $info_row['click_id'];

$mysql['click_id'] = $db->real_escape_string((string)$click_id);

$mysql['aff_campaign_id'] = $db->real_escape_string((string)$info_row['aff_campaign_id']);

// The setting the click keeps (record_adv.php: the tracker's, -1 when it
// leaves the decision to the campaign), or the campaign's (ClickCloaking).
$cloaking_on = \Prosper202\Click\ClickCloaking::isOn($info_row);

$locationRepo = \Prosper202\Repository\LookupRepositoryFactory::location($db);
$outbound_site_url = \Prosper202\Click\TrackingBaseUrl::requestUrl($_SERVER);
// Get LP Code's PHP redirect names the click (&pci=, as go.php's 202v does), a
// new URL every click; its go.php?acip= link is the same for all of them.
$click_outbound_site_url_id = \Prosper202\Click\ClickRequest::string($_GET, 'pci') !== ''
    ? $locationRepo->findOrCreateClickSiteUrl($outbound_site_url)
    : $locationRepo->findOrCreateSiteUrl($outbound_site_url);

$redirect_site_url = rotateTrackerUrl($db, $info_row);

// The click leaves its landing page for this offer: it takes the offer's
// campaign and, unless it has converted, its payout, and the redirect URL's
// placeholders are read back from those rows. Written only when the click
// is the offer's account's: the read above asks it too, and the writer asks
// it again of the offer's account, not the click's.
$offExitRecorded = false;
try {
    $offExit = new \Prosper202\Click\ClickExit(
        (int) $info_row['aff_campaign_user_id'],
        (int) $click_id,
        offer: new \Prosper202\Click\OfferRoute(
            (int) $info_row['aff_campaign_id'],
            (string) $info_row['aff_campaign_payout']
        ),
        cloaking: $cloaking_on ? 1 : 0,
        outboundSiteUrlId: (int) $click_outbound_site_url_id,
    );
    $offResolveRedirect = function () use ($db, &$redirect_site_url, $click_id, $info_row, $locationRepo): int {
        $redirect_site_url = replaceTrackerPlaceholders(
            $db,
            $redirect_site_url,
            $click_id,
            (int) $info_row['user_id']
        );
        return $locationRepo->findOrCreateClickSiteUrl((string) $redirect_site_url);
    };
    $offClicks = new \Prosper202\Click\MysqlClickRepository(
        \Prosper202\Repository\LookupRepositoryFactory::connection($db)
    );
    // The site URL lookup caches the ids of the rows it inserts: held until
    // the transaction commits, and dropped with a rollback.
    p202WithHeldCacheWrites(function () use (&$offExitRecorded, $offClicks, $offExit, $offResolveRedirect): void {
        $offExitRecorded = $offClicks->recordExit($offExit, $offResolveRedirect);
    });
} catch (\Throwable $offWriteError) {
    record_mysql_error(
        $db,
        'recording click ' . $mysql['click_id'] . ' leaving for its offer',
        $offWriteError->getMessage()
    );
}
if (!$offExitRecorded) {
    die();
}

$mysql['landing_page_id'] = $db->real_escape_string((string)$info_row['landing_page_id']);
$mysql['user_id'] = $db->real_escape_string((string)$info_row['user_id']);
// The privacy setting in force for this visitor: the stricter of the
// install's and this click's account's (p202ApplyOwnerPrivacy()), before
// the address is stored or a cookie set.
p202ApplyOwnerPrivacy($info_row['user_id'] ?? null);

// set timezone correctly
$user_sql = "SELECT user_timezone FROM 202_users WHERE user_id='" . $mysql['user_id'] . "'";
$user_row = memcache_mysql_fetch_assoc($db, $user_sql);
AUTH::set_timezone($user_row['user_timezone']);

$now = time();

// Integers: this file is strict_types, and date() returns strings, which
// mktime() refused with a TypeError — every advanced landing page's campaign
// link (go.php?acip=, off.php?acip=) answered 500. dl.php and
// record_simple.php cast theirs; StrictTypesMktimeArgumentsTest holds all three.
$today_day = (int) date('j', time());
$today_month = (int) date('n', time());
$today_year = (int) date('Y', time());

// the click_time is recorded in the middle of the day
$click_time = mktime(12, 0, 0, $today_month, $today_day, $today_year);
$mysql['click_time'] = $db->real_escape_string((string)$click_time);
// NOTE: the legacy 202_summary_overview roll-up write was removed here. That
// table is created by no installer in this codebase, so the SELECT/INSERT failed
// and record_mysql_error() (declared `: never`) terminated the offer redirect on a
// fresh install. Aggregation is handled by the DataEngine setDirtyHour() call below,
// so the dead write is simply dropped.

// set the cookie
setClickIdCookie($mysql['click_id'], $mysql['aff_campaign_id']);
// NOW LETS REDIRECT
$urlvars = getPrePopVars($urlvarslist);

$redirect_site_url = setPrePopVars($urlvars, $redirect_site_url, false);


//set dirty hour
$de = new DataEngine();
$data=($de->setDirtyHour($mysql['click_id']));

// Assign before the output below: the earlier assignment lives in the other
// branch, so this path was echoing an undefined key. Escaped like the URL.
$html['aff_campaign_name'] = htmlspecialchars((string) ($info_row['aff_campaign_name'] ?? ''), ENT_QUOTES, 'UTF-8');

if ($cloaking_on == true) {

    // if cloaking is turned on, meta refresh out

    ?>
<html>
<head>
<title><?php echo $html['aff_campaign_name']; ?></title>
<meta name="robots" content="noindex">
<meta http-equiv="refresh"
	content="1; url=<?php echo htmlspecialchars($redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>

	<form name="form1" id="form1" method="get"
		action="<?php echo htmlspecialchars(p202InstallPath('tracking202/redirect/cl2.php')); ?>">
		<input type="hidden" name="q"
			value="<?php echo htmlspecialchars($redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>" />
	</form>
	<script type="text/javascript">
			document.form1.submit();
		</script>

	<div style="padding: 30px; text-align: center;">
			You are being automatically redirected to <?php echo $html['aff_campaign_name']; ?>.<br />
		<br /> Page Stuck? <a href="<?php echo htmlspecialchars($redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>">Click
			Here</a>.
	</div>
</body>
</html>
<?php

} else {
    
    // if cloaking is turned off, php header redirect out
   
    p202NoStore();
    header('location: ' . $redirect_site_url);
    die();
}
