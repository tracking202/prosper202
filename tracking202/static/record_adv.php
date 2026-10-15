<?php

declare(strict_types=1);

use Prosper202\Click\ClickRequest;
use Prosper202\Click\DynamicBid;

header("Pragma: no-cache");
header("Expires: -1");

include_once(substr(__DIR__, 0, -19) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/static-endpoint-helpers.php');

$locationRepo = \Prosper202\Repository\LookupRepositoryFactory::location($db);
$trackingRepo = \Prosper202\Repository\LookupRepositoryFactory::tracking($db);

$_GET += [
	'lpip' => '',
	't202id' => '',
	't202b' => '',
	't202kw' => '',
	't202ref' => '',
	'OVKEY' => '',
	'OVRAW' => '',
	'target_passthrough' => '',
	'keyword' => '',
	'gclid' => '',
	'c1' => '',
	'c2' => '',
	'c3' => '',
	'c4' => '',
	'utm_source' => '',
	'utm_medium' => '',
	'utm_campaign' => '',
	'utm_term' => '',
	'utm_content' => '',
	'referer' => '',
	'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];

// The landing page, by its id exactly: compared as text, MySQL read 12abc as 12.
$raLandingPageId = \Prosper202\Click\PublicId::parse(ClickRequest::string($_GET, 'lpip'));
$tracker_row = null;
if ($raLandingPageId !== null) {
    try {
        $raLandingPage = \Prosper202\Repository\LookupRepositoryFactory::reads($db)->landingPage($raLandingPageId);
    } catch (\Throwable $raReadError) {
        record_mysql_error($db, 'reading landing page ' . $raLandingPageId, $raReadError->getMessage());
    }
    if ($raLandingPage !== null) {
        $tracker_row = [
            'user_id' => $raLandingPage['user_id'],
            'landing_page_id' => $raLandingPage['landing_page_id'],
        ];
    }
}

if ($tracker_row === null) {
    die();
}

// The privacy setting in force for this visitor: the stricter of the
// install's and this landing page's account's (p202ApplyOwnerPrivacy()), before
// the address is stored or a cookie set.
p202ApplyOwnerPrivacy($tracker_row['user_id'] ?? null);

//set the timezone to the users timezone
$mysql['user_id'] = $db->real_escape_string((string) ($tracker_row['user_id'] ?? '0'));
$user_sql = "SELECT 		user_timezone, 
							user_keyword_searched_or_bidded,
                            user_pref_referer_data,
                            user_pref_dynamic_bid,
							maxmind_isp 
			   FROM 		202_users 
			   LEFT JOIN	202_users_pref USING (user_id)
			   WHERE 		202_users.user_id='" . $mysql['user_id'] . "'";
$user_row = memcache_mysql_fetch_assoc($db, $user_sql);
$mysql['user_pref_dynamic_bid'] = $db->real_escape_string((string) ($user_row['user_pref_dynamic_bid'] ?? '0'));

//now this sets it
AUTH::set_timezone((string) ($user_row['user_timezone'] ?? 'UTC'));

// The tracker the visit names, when it is the landing page's account's. An
// advanced landing page's click takes its campaign and payout from the
// tracker, so another account's tracker recorded this account's click under
// that account's campaign, at its payout.
$raTrackerId = \Prosper202\Click\PublicId::parse(ClickRequest::string($_GET, 't202id'));
if ($raTrackerId !== null) {
    try {
        $tracker_row2 = \Prosper202\Repository\LookupRepositoryFactory::reads($db)
            ->accountTracker((int) $tracker_row['user_id'], $raTrackerId);
    } catch (\Throwable $raReadError) {
        record_mysql_error($db, 'reading tracker ' . $raTrackerId, $raReadError->getMessage());
    }
    if ($tracker_row2 !== null) {
		$tracker_row = array_merge($tracker_row, $tracker_row2);
	}
}


//INSERT THIS CLICK BELOW, if this click doesn't already exisit

//get mysql variables 
$mysql['user_id'] = $db->real_escape_string((string) ($tracker_row['user_id'] ?? '0'));
$mysql['aff_campaign_id'] = $db->real_escape_string((string) ($tracker_row['aff_campaign_id'] ?? '0'));
$mysql['ppc_account_id'] = $db->real_escape_string((string) ($tracker_row['ppc_account_id'] ?? '0'));
// The bid the link names when the account takes dynamic bids, else the tracker's CPC.
$bid = $mysql['user_pref_dynamic_bid'] == '1' ? DynamicBid::fromRequest($_GET['t202b'] ?? null) : null;
$mysql['click_cpc'] = $bid ?? $db->real_escape_string((string) ($tracker_row['click_cpc'] ?? '0'));
$mysql['click_payout'] = $db->real_escape_string((string) ($tracker_row['aff_campaign_payout'] ?? '0'));
$mysql['click_time'] = time();
$mysql['landing_page_id'] = $db->real_escape_string((string) ($tracker_row['landing_page_id'] ?? '0'));
$mysql['text_ad_id'] = $db->real_escape_string((string) ($tracker_row['text_ad_id'] ?? '0'));

// The referring page's query: its q is a searched keyword (ClickRequest::keyword()), its url the referer.
$referer_url_parsed = @parse_url(ClickRequest::string($_GET, 'referer'));
$referer_url_query = $referer_url_parsed['query'] ?? '';

@parse_str($referer_url_query, $referer_query);

$keyword = ClickRequest::keyword($_GET, $referer_query, $user_row['user_keyword_searched_or_bidded'] ?? null);
$keyword_id = $trackingRepo->findOrCreateKeyword($keyword);
$mysql['keyword_id'] = $db->real_escape_string((string) $keyword_id);

$c1 = ClickRequest::text($_GET, 'c1');
$c1 = str_replace('%20', ' ', $c1);
$c1_id = $trackingRepo->findOrCreateC1($c1);
$mysql['c1_id'] = $db->real_escape_string((string) $c1_id);

$c2 = ClickRequest::text($_GET, 'c2');
$c2 = str_replace('%20', ' ', $c2);
$c2_id = $trackingRepo->findOrCreateC2($c2);
$mysql['c2_id'] = $db->real_escape_string((string) $c2_id);

$c3 = ClickRequest::text($_GET, 'c3');
$c3 = str_replace('%20', ' ', $c3);
$c3_id = $trackingRepo->findOrCreateC3($c3);
$mysql['c3_id'] = $db->real_escape_string((string) $c3_id);

$c4 = ClickRequest::text($_GET, 'c4');
$c4 = str_replace('%20', ' ', $c4);
$c4_id = $trackingRepo->findOrCreateC4($c4);
$mysql['c4_id'] = $db->real_escape_string((string) $c4_id);

// Raw: the writer binds it, so an escaped value was stored with its backslashes.
$gclid = ClickRequest::text($_GET, 'gclid');
$mysql['gclid'] = $gclid;


$custom_var_ids = [];

// The traffic source's variables; none for a click with no tracker (no
// t202id, or one that names none), as for a source that has none.
foreach (\Prosper202\Click\TrackerVariables::pairs($tracker_row) as [$value, $ppcVariableId]) {
	$variable = ClickRequest::text($_GET, $value);

	if (isset($variable) && $variable != '') {
		$variable = str_replace('%20', ' ', $variable);
		$variable_id = $trackingRepo->findOrCreateVariable($variable, $ppcVariableId);
		$custom_var_ids[] = $variable_id;
	}
}

//utm_source
$utm_source = ClickRequest::text($_GET, 'utm_source');
if (isset($utm_source) && $utm_source != '') {
	$utm_source = str_replace('%20', ' ', $utm_source);
	$utm_source_id = $trackingRepo->findOrCreateUtm($utm_source, 'utm_source');
} else {
	$utm_source_id = 0;
}
$mysql['utm_source_id'] = $db->real_escape_string((string) $utm_source_id);

//utm_medium
$utm_medium = ClickRequest::text($_GET, 'utm_medium');
if (isset($utm_medium) && $utm_medium != '') {
	$utm_medium = str_replace('%20', ' ', $utm_medium);
	$utm_medium_id = $trackingRepo->findOrCreateUtm($utm_medium, 'utm_medium');
} else {
	$utm_medium_id = 0;
}
$mysql['utm_medium_id'] = $db->real_escape_string((string) $utm_medium_id);

//utm_campaign
$utm_campaign = ClickRequest::text($_GET, 'utm_campaign');
if (isset($utm_campaign) && $utm_campaign != '') {
	$utm_campaign = str_replace('%20', ' ', $utm_campaign);
	$utm_campaign_id = $trackingRepo->findOrCreateUtm($utm_campaign, 'utm_campaign');
} else {
	$utm_campaign_id = 0;
}
$mysql['utm_campaign_id'] = $db->real_escape_string((string) $utm_campaign_id);

//utm_term
$utm_term = ClickRequest::text($_GET, 'utm_term');
if (isset($utm_term) && $utm_term != '') {
	$utm_term = str_replace('%20', ' ', $utm_term);
	$utm_term_id = $trackingRepo->findOrCreateUtm($utm_term, 'utm_term');
} else {
	$utm_term_id = 0;
}
$mysql['utm_term_id'] = $db->real_escape_string((string) $utm_term_id);

//utm_content
$utm_content = ClickRequest::text($_GET, 'utm_content');
if (isset($utm_content) && $utm_content != '') {
	$utm_content = str_replace('%20', ' ', $utm_content);
	$utm_content_id = $trackingRepo->findOrCreateUtm($utm_content, 'utm_content');
} else {
	$utm_content_id = 0;
}
$mysql['utm_content_id'] = $db->real_escape_string((string) $utm_content_id);

$ip = \Prosper202\Http\VisitorIp::fromServer($_SERVER);
// Stored masked under the owner's privacy setting (p202StoredVisitorIp).
$ip_id = $locationRepo->findOrCreateIp(p202StoredVisitorIp());
$mysql['ip_id'] = $db->real_escape_string((string) $ip_id);

//$device_id = PLATFORMS::get_device_info($db);
$detect = new DeviceDetect();
$device_id = PLATFORMS::get_device_info($db, $detect, ClickRequest::text($_GET, 'ua'));
$mysql['platform_id'] = $db->real_escape_string((string) $device_id['platform']);
$mysql['browser_id'] = $db->real_escape_string((string) $device_id['browser']);
$mysql['device_id'] = $db->real_escape_string((string) $device_id['device']);

// Bots are recorded like anyone else; only the flags differ.
$clickIsBot = \Prosper202\Click\ClickBotDetector::isBot($detect->getUserAgent(), $device_id);
$mysql['click_bot'] = $clickIsBot ? '1' : '0';

$mysql['click_in'] = 1;
$mysql['click_out'] = 0;

// if user wants to use t202ref from url variable use that first if it's not set try and get it from the ref url
$_referer_site_url = '';
if (($user_row['user_pref_referer_data'] ?? '') == 't202ref') {
	if (is_string($_GET['t202ref'] ?? null) && $_GET['t202ref'] !== '') { //check for t202ref value
		$mysql['t202ref'] = $db->real_escape_string((string)$_GET['t202ref']);
		$_referer_site_url = (string) $_GET['t202ref'];
	} else { //if not found revert to what we usually do
		if (is_string($referer_query['url'] ?? null) && $referer_query['url'] !== '') {
			$_referer_site_url = (string) $referer_query['url'];
		} else {
			$_referer_site_url = ClickRequest::string($_GET, 'referer');
		}
	}
} else { //user wants the real referer first

	// now lets get variables for clicks site
	// so this is going to check the REFERER URL, for a ?url=, which is the ACUTAL URL, instead of the google content, pagead2.google....
	if (is_string($referer_query['url'] ?? null) && $referer_query['url'] !== '') {
		$_referer_site_url = (string) $referer_query['url'];
	} else {
		$_referer_site_url = ClickRequest::string($_GET, 'referer');
	}
}
$click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl($_referer_site_url);

$mysql['click_referer_site_url_id'] = $db->real_escape_string((string) $click_referer_site_url_id);


//see if this click should be filtered
$ip_address = $ip;
$user_id = $tracker_row['user_id'];

//GEO Lookup
$GeoData = getGeoData($ip_address);

$countryName = $GeoData['country'] ?? '';
$countryCode = $GeoData['country_code'] ?? '';
$country_id = $locationRepo->findOrCreateCountry($countryName, $countryCode);
$mysql['country_id'] = $db->real_escape_string((string) $country_id);

$regionName = $GeoData['region'] ?? '';
$region_id = $locationRepo->findOrCreateRegion($regionName, $country_id);
$mysql['region_id'] = $db->real_escape_string((string) $region_id);

$cityName = $GeoData['city'] ?? '';
$city_id = $locationRepo->findOrCreateCity($cityName, $country_id);
$mysql['city_id'] = $db->real_escape_string((string) $city_id);


if ($user_row['maxmind_isp'] == '1') {
	$IspData = getIspData($ip_address);
	if (is_string($IspData)) {
		$IspDataParts = explode(',', $IspData);
		$IspData = $IspDataParts[0];
	}
	$isp_id = $locationRepo->findOrCreateIsp($IspData);
	$mysql['isp_id'] = $db->real_escape_string((string) $isp_id);
} else {
	$mysql['isp_id'] = '0';
}

if ($clickIsBot) {
	$mysql['click_filtered'] = '1';
} else {
	$click_filtered = FILTER::startFilter($db, 0, $ip_id, $user_id);
	$mysql['click_filtered'] = $db->real_escape_string((string) $click_filtered);
}



// Pre-allocate click_id so we can compute click_id_public and site URLs
$conn = \Prosper202\Repository\LookupRepositoryFactory::connection($db);
$clickRepo = new \Prosper202\Click\MysqlClickRepository($conn);
$click_id = $clickRepo->allocateClickId();
$click_id_public = random_int(1, 9) . $click_id . random_int(1, 9);
$mysql['click_id'] = (string) $click_id;
$mysql['click_id_public'] = (string) $click_id_public;

//because this is an advanced landing page, set click_alp = 1
$mysql['click_alp'] = 1;

// Compute variable_set_id
$total_vars = count($custom_var_ids);
if ($total_vars > 0) {
	$variables = implode(",", $custom_var_ids);
	$variable_set_id = $trackingRepo->findOrCreateVariableSet($variables);
	$mysql['variable_set_id'] = (string) $variable_set_id;
} else {
	$mysql['variable_set_id'] = '0';
}

// Cloaking is decided when the visitor leaves for the offer (off.php): the
// click keeps the tracker's setting, -1 (the campaign decides) when there is
// no tracker, and 0 when the link turns it off (ClickCloaking).
$mysql['click_cloaking'] = \Prosper202\Click\ClickCloaking::trackerSetting($tracker_row);

// Compute site URLs
$landing_site_url = $_SERVER['HTTP_REFERER'] ?? ClickRequest::string($_GET, 'referer');
$click_landing_site_url_id = $locationRepo->findOrCreateSiteUrl($landing_site_url);
$mysql['click_landing_site_url_id'] = (string) $click_landing_site_url_id;
$mysql['click_outbound_site_url_id'] = '0';
$mysql['click_cloaking_site_url_id'] = '0';
$mysql['click_redirect_site_url_id'] = '0';

// Identity signals (plan §6.2): the tracking domain's p202vid cookie, the
// p202lpid the landing page's script sends, a signed customer id — linked
// after the click is stored; nothing when consent is withheld or the
// campaign's identity capture is off.
$clickIdentity = p202ClickIdentity(
	$_GET,
	\Prosper202\Identity\RequestSignals::campaignAllows(array_key_exists('identity_signals', $tracker_row) ? $tracker_row['identity_signals'] : null),
	// Minted only when the landing page is on the tracker's own site; a
	// cross-site script request links by the page's p202lpid instead.
	(string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') !== 'cross-site'
);
// Every row in one transaction (MysqlClickRepository), its cache writes held
// until it commits; a failure is the error page, as rtr.php's is.
// Built outside the try: a record the builder refuses is a bug, not a
// database failure, and the uncaught handler logs it as one.
$raClick = \Prosper202\Click\ClickRecordBuilder::fromLegacyArray($mysql, $click_id, $clickIdentity);
try {
    p202WithHeldCacheWrites(static fn () => $clickRepo->recordClick($raClick));
} catch (\Throwable $raWriteError) {
    record_mysql_error($db, 'recording click ' . $click_id, $raWriteError->getMessage());
}


//set the cookie
setClickIdCookie($mysql['click_id'], $mysql['aff_campaign_id']);
$clickIdentity->sendCookie($_SERVER);
//set the PCI Cookie
setPCIdCookie($mysql['click_id_public']);

//set dirty hour
$de = new DataEngine();
$data = ($de->setDirtyHour($mysql['click_id']));

header('Content-Type: application/javascript; charset=UTF-8');
?>


function t202initB() {

var subid =<?php echo json_encode((string) $click_id); ?>;
var pci = <?php echo json_encode((string) $click_id_public); ?>;

<?php
// The click cookies on the landing page's own site: none for a visitor the
// privacy setting holds back (p202ClickCookieJs()). The variables above are
// what the script has always answered with (the live passes read the click
// id from them); they set nothing.
echo p202ClickCookieJs(['tracking202subid' => (string) $click_id, 'tracking202pci' => (string) $click_id_public]);
$p13nOwner = (int) ($tracker_row['user_id'] ?? 0);
echo p202MintPersonalizationCookieJs($db, $p13nOwner, $_GET, (int) $click_id, trackingEnabled());
?>

}

t202initB();
