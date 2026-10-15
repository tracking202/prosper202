<?php
declare(strict_types=1);
$vars=$_GET;
#only allow numeric t202ids
$lpip = $_GET['lpip'] ?? '';
if (!is_numeric($lpip)) die();

# check to see if mysql connection works, if not fail over to cached stored redirect urls
include_once(substr(__DIR__, 0,-21) . '/202-config/connect2.php'); 
include_once(substr(__DIR__, 0,-21) . '/202-config/class-dataengine-slim.php');

// Speculative requests (browser prefetch/prerender, link-preview scanners, HEAD
// probes) must not record a click — otherwise the speculative hit plus the real
// navigation double-count the same visit (see p202IsSpeculativeRequest).
if (p202IsSpeculativeRequest()) {
	p202DeclineSpeculativeRequest();
}


$usedCachedRedirect = false; 
if (!$db) $usedCachedRedirect = true;

#the mysql server is down, use the txt cached redirect
if ($usedCachedRedirect==true) { 

		$lpip = $_GET['lpip'];

		//if a cached key is found for this lpip, redirect to that url
		if ($memcacheWorking) {
			$getUrl = $memcache->get(md5('lp_'.$lpip.systemHash()));
			if ($getUrl) {

				$new_url = str_replace("[[subid]]", "p202", $getUrl);
				// No click is recorded: the install token expands empty (plan §5.1).
				$new_url = str_ireplace('[[p202_install_token]]', '', $new_url);

				// c1-c4 as sent, or p202<name> for one not sent: a list (c1[]=x) is none
				foreach (['c1', 'c2', 'c3', 'c4'] as $token) {
					$sent = \Prosper202\Click\ClickRequest::text($_GET, $token);
					$new_url = str_replace("[[$token]]", $sent !== '' ? rawurlencode($sent) : "p202$token", $new_url);
				}
				
				if (isset ( $urlvars ) && $urlvars != '') {
					// remove & at the end of the string
					$urlvars = rtrim ( (string) $urlvars, '&' );
					if (! parse_url ( $new_url, PHP_URL_QUERY )) {
						
						// if there is no query url the add a ? to url but before doing that remove case where there may be a ? at the end of the url and nothing else
						$new_url = rtrim ( $new_url, '?' );
						
						// remove the & from url and put a ? in fron t of it
						
						$new_url .= "?" . $urlvars;
					} else {
						
						$new_url .= "&" . $urlvars;
				}
			}
			
				p202NoStore();
				header('location: '. $new_url); 
				die();
			}
		}
	
	die("<h2>Error establishing a database connection - please contact the webhost</h2>");
}

// The landing page's id exactly: compared as text, MySQL read 1e3 as 1000.
$lpPublicId = \Prosper202\Click\PublicId::parse($lpip);
if ($lpPublicId === null) {
    die();
}
$lpReads = \Prosper202\Repository\LookupRepositoryFactory::reads($db);
try {
    $tracker_row = $lpReads->landingPageOffer($lpPublicId);
} catch (\Throwable $lpReadError) {
    record_mysql_error($db, 'reading landing page ' . $lpip, $lpReadError->getMessage());
}

if ($tracker_row === null) {
    die();
}

// The URL a MySQL outage redirects to (read above): kept equal to the
// campaign's current URL, not the one it had at its first click.
if ($memcacheWorking) {
	\Prosper202\Click\FallbackRedirectUrl::refresh(
		md5('lp_'.$lpip.systemHash()),
		\Prosper202\Click\FallbackRedirectUrl::withSubid($tracker_row['aff_campaign_url'] ?? ''),
		static fn(string $key): mixed => $memcache->get($key),
		static fn(string $key, string $url): mixed => setCache($key, $url, 0),
	);
}

//grab the GET variables from the LANDING PAGE
// Only overwrite $_GET when the referer actually carries a query string —
// otherwise parse_str('') would wipe every incoming parameter (losing the
// landing page's tracking data) and warn on the missing 'query' key.
$landing_page_site_url_address_parsed = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
$landing_page_query = is_array($landing_page_site_url_address_parsed)
	? ($landing_page_site_url_address_parsed['query'] ?? '')
	: '';
if ($landing_page_query !== '') {
	parse_str($landing_page_query, $_GET);
}

// The tracker the landing page was reached through, when it is the landing
// page's account's: its cloaking setting decides this click's.
$lpTrackerId = \Prosper202\Click\PublicId::parse($_GET['t202id'] ?? null);
if ($lpTrackerId !== null) {
    try {
        $tracker_row2 = $lpReads->accountTracker((int) $tracker_row['user_id'], $lpTrackerId);
    } catch (\Throwable $lpReadError) {
        record_mysql_error($db, 'reading tracker ' . $lpTrackerId, $lpReadError->getMessage());
    }
    if ($tracker_row2 !== null) {
        $tracker_row = array_merge($tracker_row, array_intersect_key(
            $tracker_row2,
            array_flip(['text_ad_id', 'ppc_account_id', 'click_cpc', 'click_cloaking'])
        ));
	}
}

//INSERT THIS CLICK BELOW, if this click doesn't already exisit

//get mysql variables 
$mysql['user_id'] = $db->real_escape_string((string) $tracker_row['user_id']);
$mysql['aff_campaign_id'] = $db->real_escape_string((string) $tracker_row['aff_campaign_id']);
$mysql['click_payout'] = $db->real_escape_string((string) $tracker_row['aff_campaign_payout']);
$mysql['click_time'] = time();

$mysql['landing_page_id'] = $db->real_escape_string((string) $tracker_row['landing_page_id']);

// The tracker's setting, or the campaign's when the landing page was reached
// without one or it leaves the decision to the campaign (ClickCloaking).
$cloaking_on = \Prosper202\Click\ClickCloaking::isOn($tracker_row);

if ($cloaking_on == true) {

	$cloaking_site_url = \Prosper202\Click\TrackingBaseUrl::forRequest($_SERVER) . 'tracking202/redirect/lpc.php?lpip=' . $tracker_row['landing_page_id_public'];
}

$redirect_site_url = rotateTrackerUrl($db, $tracker_row);

// The visitor's click on the landing page's campaign, by its cookie, marked as
// leaving for the offer only when it is the landing page's account's: the
// cookie is the visitor's to set, and named any account's click. Another
// account's click, or none, is no click here, as a missing cookie always was.
$lpClickId = \Prosper202\Click\ClickId::parse(
    getCookie202('tracking202subid_a_' . $tracker_row['aff_campaign_id'])
);
if ($lpClickId !== null) {
    $lpRecorded = false;
    try {
        $lpClicks = new \Prosper202\Click\MysqlClickRepository(
            \Prosper202\Repository\LookupRepositoryFactory::connection($db)
        );
        $lpRecorded = $lpClicks->recordExit(
            new \Prosper202\Click\ClickExit((int) $tracker_row['user_id'], $lpClickId, cloaking: $cloaking_on ? 1 : 0)
        );
    } catch (\Throwable $lpWriteError) {
        record_mysql_error(
            $db,
            'recording click ' . $lpClickId . ' leaving its landing page',
            $lpWriteError->getMessage()
        );
    }
    if ($lpRecorded) {
        (new DataEngine())->setDirtyHour((string) $lpClickId);
    } else {
        $lpClickId = null;
    }
}
$mysql['click_id'] = $lpClickId === null ? '' : (string) $lpClickId;

$redirect_site_url = replaceTrackerPlaceholders(
    $db,
    $redirect_site_url,
    $mysql['click_id'],
    (int) $tracker_row['user_id']
);



//get and prep extra stuff for pre-pop or data passing

$urlvars = getPrePopVars($vars);


if ($cloaking_on == true) {
	//if cloaked, redirect them to the cloaked site. 
	p202NoStore();
	header('location: '.setPrePopVars($urlvars,$cloaking_site_url,true));
} else {
	
	p202NoStore();
	header('location: '.setPrePopVars($urlvars,$redirect_site_url,false));
} 
die();
