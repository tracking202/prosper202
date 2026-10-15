<?php
declare(strict_types=1);
use Prosper202\Click\ClickRequest;
use Prosper202\Click\DynamicBid;
use UAParser\Parser;

#only allow numeric id, reject 0 as invalid
$tracker_id = $_GET['t202id'] ?? '';
if (!is_numeric($tracker_id) || (int)$tracker_id <= 0) die();

# check to see if mysql connection works, if not fail over to cached stored redirect urls
// Goes on when the database is out of reach: it falls back to the URL it keeps for an outage.
$p202OutageFallback = true;
include_once(substr(__DIR__, 0,-21) . '/202-config/connect2.php'); 

// Speculative requests (browser prefetch/prerender, link-preview scanners, HEAD
// probes) must not record a click — otherwise the speculative hit plus the real
// navigation double-count the same visit (see p202IsSpeculativeRequest).
if (p202IsSpeculativeRequest()) {
	p202DeclineSpeculativeRequest();
}


$usedCachedRedirect = false;
if (!$db) $usedCachedRedirect = true;

#the mysql server is down, use the cached redirect
if ($usedCachedRedirect==true) { 

		//if a cached key is found for this id, redirect to that url
		if ($memcacheWorking) {
			$getUrl = $memcache->get(md5("default_url" . $tracker_id . systemHash()));
			if ($getUrl) {			
				p202NoStore();
				header('location: '. $getUrl); 
				die();
			}
		}

	p202Unavailable();
}

include_once(substr(__DIR__, 0,-21) . '/202-config/class-dataengine-slim.php');

// The tracker, with its rotator and the rotator's defaults when each is the
// tracker's account's: a tracker naming another account's rotator sent the
// click through that account's rules, under this account.
$rtrTrackerId = \Prosper202\Click\PublicId::parse($tracker_id);
$rotator_row = null;
if ($rtrTrackerId !== null) {
    try {
        $rotator_row = \Prosper202\Repository\LookupRepositoryFactory::reads($db)->redirectorTracker($rtrTrackerId);
    } catch (\Throwable $rtrReadError) {
        record_mysql_error($db, 'reading tracker ' . $rtrTrackerId, $rtrReadError->getMessage());
    }
}

// Guard BEFORE dereferencing: a missing row (unknown tracker, DB hiccup, or
// stale cache) must never crash the redirect. Fall back to the cached default
// url so the visitor still lands somewhere instead of losing the click.
if (!$rotator_row) {
	if ($memcacheWorking) {
		$getUrl = $memcache->get(md5("default_url" . $tracker_id . systemHash()));
		if ($getUrl) {
			p202NoStore();
			header('location: ' . $getUrl);
			die();
		}
	}
	die();
}

// A tracker that names no redirector of its account (a direct link's, sent
// here by hand) has nowhere to send the visitor: no click, as for an
// unknown tracker.
if ((int) ($rotator_row['rotator_id'] ?? 0) <= 0) {
	die();
}

$user_id = $db->real_escape_string((string)$rotator_row['user_id']);
// The privacy setting in force for this visitor: the stricter of the
// install's and this tracker's account's (p202ApplyOwnerPrivacy()), before
// the address is stored or a cookie set.
p202ApplyOwnerPrivacy($rotator_row['user_id'] ?? null);
$user_keyword_searched_or_bidded = $db->real_escape_string($rotator_row['user_keyword_searched_or_bidded']);

//grab rules data
$mysql['rotator_id'] = $db->real_escape_string((string)$rotator_row['rotator_id']);
$rule_sql = "SELECT ru.id as rule_id
			 FROM 202_rotator_rules AS ru
			 WHERE rotator_id='".$mysql['rotator_id']."' AND status='1'";
$rule_row = foreach_memcache_mysql_fetch_assoc($db, $rule_sql);

AUTH::set_timezone($rotator_row['user_timezone']);

$ip_address = \Prosper202\Http\VisitorIp::fromServer($_SERVER);

if ($rotator_row['maxmind_isp'] == '1') {
	$IspData = getIspData($ip_address);
	$IspData = explode(',', (string) $IspData);
	$IspData = $IspData[0];
} else {
	$IspData = null;
}

//GEO Lookup
$GeoData = getGeoData($ip_address);

//User-agent parser
$parser = Parser::create();

//Device type
$detect = new DeviceDetect();
$ua = $detect->getUserAgent();
$result = $parser->parse($ua);

if( !$detect->isMobile() && !$detect->isTablet() ){

	switch ($result->device->family) {
		//Is Bot
		case 'Bot':
			$result->device->family = "Bot";
		break;
		//Is Desktop
		case 'Other':
			$result->device->family = "Desktop";
		break;
	}
} else {
	if ($detect->isTablet()) {
		$result->device->family = "Tablet";
		//If mobile	
	} else {
		$result->device->family = "Mobile";
	}
}

// The bid the link names when the account takes dynamic bids, else the tracker's CPC.
$bid = $rotator_row['user_pref_dynamic_bid'] == '1' ? DynamicBid::fromRequest($_GET['t202b'] ?? null) : null;
if ($bid !== null) {
	$rotator_row['click_cpc'] = $bid;
}

// When the rotator has no rules, the loop below never runs and would leave
// $default false, dropping the click with a blank response. Default to the
// rotator's default destination in that case; with rules present the loop
// decides (a non-matching rule sets $default = true).
$default = empty($rule_row);

foreach ($rule_row as $rule) {
	
	$rotate = [];
	$count = 0;

	$mysql['rule_id'] = $db->real_escape_string((string)$rule['rule_id']);
	$criteria_sql = "SELECT type, statement, value
				 FROM 202_rotator_rules_criteria
				 WHERE rule_id='".$mysql['rule_id']."'"; 
	$criteria_row = foreach_memcache_mysql_fetch_assoc($db, $criteria_sql);

	foreach ($criteria_row as $criteria) {
		switch ($criteria['statement']) {
			case 'is':
				$statement = true;		
			break;
					
			case 'is_not':
				$statement = false;	
			break;
		}

		$values = explode(',', (string) $criteria['value']);

		if (in_array('ALL', $values) || in_array('all', $values)) {
			
			$rotate[] = true;

		} else {

			switch ($criteria['type']) {
				case 'country':
					$country = $GeoData['country']."(".$GeoData['country_code'].")";

					if ($statement) {
						if (in_array($country, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($country, $values)) {
							$rotate[] = true;
						}
					}
						
				break;
				
				case 'region':
					$region = $GeoData['region']."(".$GeoData['country_code'].")";
					
					if ($statement) {
						if (in_array($region, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($region, $values)) {
							$rotate[] = true;
						}
					}

				break;

				case 'city':
					$city = $GeoData['city']."(".$GeoData['country_code'].")";
					
					if ($statement) {
						if (in_array($city, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($city, $values)) {
							$rotate[] = true;
						}
					}
				break;

				case 'isp':
					
					if ($statement) {
						if (in_array($IspData, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($IspData, $values)) {
							$rotate[] = true;
						}
					}
				break;

				case 'ip':
					if ($statement) {
						if (\Prosper202\Rotator\IpCriterion::contains($values, $ip_address)) {
							$rotate[] = true;
						}
					} else {
						if (!\Prosper202\Rotator\IpCriterion::contains($values, $ip_address)) {
							$rotate[] = true;
						}
					}

				break;

				case 'platform':
					if ($statement) {
						if (in_array($result->os->family, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($result->os->family, $values)) {
							$rotate[] = true;
						}
					}
				break;

				case 'device':
					if ($statement) {
						if (in_array(strtolower($result->device->family), $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array(strtolower($result->device->family), $values)) {
							$rotate[] = true;
						}
					}
				break;

				case 'browser':
					if ($statement) {
						if (in_array($result->ua->family, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($result->ua->family, $values)) {
							$rotate[] = true;
						}
					}
				break;
			}
		}

		$count++;

	}
	
	//If any of the rules maches, redirect to the redirect type.
	if ($count == count($rotate)) {
		$rule_redirects_sql = "SELECT rur.id,
					   rur.redirect_url,
					   rur.redirect_campaign,
					   rur.redirect_lp,
					   rur.auto_monetizer,
					   rur.weight,
					   ca.aff_campaign_id,
					   ca.aff_campaign_rotate,
					   ca.aff_campaign_url,
					   ca.aff_campaign_url_2,
					   ca.aff_campaign_url_3,
					   ca.aff_campaign_url_4,
					   ca.aff_campaign_url_5,
					   ca.aff_campaign_payout,
					   ca.aff_campaign_cloaking,
					   lp.landing_page_url,
		               lp.landing_page_id
				FROM 202_rotator_rules_redirects AS rur
				LEFT JOIN 202_aff_campaigns AS ca ON ca.aff_campaign_id = rur.redirect_campaign
                    AND ca.user_id = '" . $user_id . "'
				LEFT JOIN 202_landing_pages AS lp ON lp.landing_page_id = rur.redirect_lp
                    AND lp.user_id = '" . $user_id . "'
				WHERE rule_id='".$mysql['rule_id']."'"; 
		$rule_redirects_row = foreach_memcache_mysql_fetch_assoc($db, $rule_redirects_sql);
		$redirects = [];
		$redirect_values = [];

		foreach ($rule_redirects_row as $rule_redirect_row) {
            if (!p202RuleRedirectIsUsable($rule_redirect_row)) {
                continue;
            }
			
			if (!empty($rule_redirect_row['redirect_campaign'])) {
				$redirects[] = ['rule_id' => $mysql['rule_id'], 'redirect_id' => $rule_redirect_row['id'], 'type' => 'campaign', 'aff_campaign_rotate' => $rule_redirect_row['aff_campaign_rotate'], 'aff_campaign_url' => $rule_redirect_row['aff_campaign_url'], 'aff_campaign_url_2' => $rule_redirect_row['aff_campaign_url_2'], 'aff_campaign_url_3' => $rule_redirect_row['aff_campaign_url_3'], 'aff_campaign_url_4' => $rule_redirect_row['aff_campaign_url_4'], 'aff_campaign_url_5' => $rule_redirect_row['aff_campaign_url_5'], 'weight' => $rule_redirect_row['weight'], 'aff_campaign_id' => $rule_redirect_row['aff_campaign_id'], 'aff_campaign_payout' => $rule_redirect_row['aff_campaign_payout'], 'aff_campaign_cloaking' => $rule_redirect_row['aff_campaign_cloaking']];
			} else if(!empty($rule_redirect_row['redirect_url'])) {
				$redirects[] = ['rule_id' => $mysql['rule_id'], 'redirect_id' => $rule_redirect_row['id'], 'type' => 'url', 'redirect_url' => $rule_redirect_row['redirect_url'], 'weight' => $rule_redirect_row['weight'], 'aff_campaign_id' => $rule_redirect_row['aff_campaign_id'], 'aff_campaign_payout' => $rule_redirect_row['aff_campaign_payout'], 'aff_campaign_cloaking' => $rule_redirect_row['aff_campaign_cloaking']];
			} else if (!empty($rule_redirect_row['redirect_lp'])) {
				$redirects[] = ['rule_id' => $mysql['rule_id'], 'redirect_id' => $rule_redirect_row['id'], 'type' => 'lp', 'landing_page_id' => $rule_redirect_row['landing_page_id'],'landing_page_url' => $rule_redirect_row['landing_page_url'], 'weight' => $rule_redirect_row['weight'], 'aff_campaign_id' => $rule_redirect_row['aff_campaign_id'], 'aff_campaign_payout' => $rule_redirect_row['aff_campaign_payout'], 'aff_campaign_cloaking' => $rule_redirect_row['aff_campaign_cloaking']];
			} else if ($rule_redirect_row['auto_monetizer'] != null) {
				$redirects[] = ['rule_id' => $mysql['rule_id'], 'redirect_id' => $rule_redirect_row['id'], 'type' => 'monetizer', 'monetizer_url' => 'http://prosper202.com', 'weight' => $rule_redirect_row['weight'], 'aff_campaign_id' => $rule_redirect_row['aff_campaign_id'], 'aff_campaign_payout' => $rule_redirect_row['aff_campaign_payout'], 'aff_campaign_cloaking' => $rule_redirect_row['aff_campaign_cloaking']];
			}
		}

		if (count($redirects) > 1) {
			$redirect_array = $redirects[getSplitTestValue($redirects)];
		} elseif (!empty($redirects)) {
			$redirect_array = $redirects[0];
		} else {
			$redirect_array = null;
		}

        // A rule left with no redirect passes the click on, as one that did
        // not match does: to a later rule, or to the rotator's default.
        $default = $redirect_array === null;
		if ($redirect_array !== null) {
			$redirect = redirect_process($db, $redirect_array, $rotator_row['ppc_account_id'], $rotator_row['click_cpc'], $rotator_row['rotator_id'], $GeoData, $ip_address, $user_id, $IspData, $user_keyword_searched_or_bidded);
			p202NoStore();
			header('location: '.$redirect);
			die();
		}
	} else {
		$default = true;
	}
	
}

if ($default == true) {
	// The rotator default may be a campaign, a raw URL, or a landing page.
	// redirect_process() resolves a campaign via aff_campaign_id (set by the
	// default_campaign JOIN). For URL/LP defaults aff_campaign_id is null, and
	// without an explicit type its synthetic default (type='url', redirect_url='')
	// would resolve to an empty location and drop the click — so set the fields.
	if (empty($rotator_row['aff_campaign_id'])) {
		if (!empty($rotator_row['default_url'])) {
			$rotator_row['type'] = 'url';
			$rotator_row['redirect_url'] = $rotator_row['default_url'];
		} elseif (!empty($rotator_row['default_lp'])) {
			$rotator_row['type'] = 'lp';
			// landing_page_url is already selected via the default_lp JOIN;
			// the click names the page, as one a rule sends there does.
			$rotator_row['landing_page_id'] = $rotator_row['default_landing_page_id'] ?? 0;
		}
	}
	$default = redirect_process($db, $rotator_row, $rotator_row['ppc_account_id'], $rotator_row['click_cpc'], $rotator_row['rotator_id'], $GeoData, $ip_address, $user_id, $IspData, $user_keyword_searched_or_bidded);
	
		if ($usedCachedRedirect==true) { 

			if ($memcacheWorking) {
				$getUrl = $memcache->get(md5("default_url" . $tracker_id . systemHash()));
				if (!$getUrl) {
						setCache(md5('default_url' . $tracker_id . systemHash()), $default, 0);			
				}
			}
		}
	p202NoStore();
	header('location: '.$default);
	die();
}



//Redirect process function
function redirect_process($db, $rule, $ppc_account, $cpc, $rotator_id, $GeoData, $ip_address, $user_id, $IspData, $keyword_type){
$ruleDefaults = [
	'aff_campaign_id' => 0,
	'aff_campaign_url' => '',
	'aff_campaign_url_2' => '',
	'aff_campaign_url_3' => '',
	'aff_campaign_url_4' => '',
	'aff_campaign_url_5' => '',
	'aff_campaign_payout' => '0',
	'aff_campaign_cloaking' => 0,
	'landing_page_id' => 0,
	'landing_page_url' => '',
	'text_ad_id' => 0,
	'weight' => 0,
	'redirect_url' => '',
	'default_url' => '',
	'default_lp' => null,
	'click_cpc' => $cpc,
	'rule_id' => 0,
	'redirect_id' => 0,
	'auto_monetizer' => null,
	'type' => 'url',
	'monetizer_url' => 'http://prosper202.com'
];

$rule = array_merge($ruleDefaults, is_array($rule) ? $rule : []);

$escape = static fn($value) => $db->real_escape_string((string)($value ?? ''));

$uaParam = ClickRequest::text($_GET, 'ua');
$detectInstance = new DeviceDetect();
if ($uaParam !== '') {
	$detectInstance->setUserAgent($uaParam);
}
$device_id = PLATFORMS::get_device_info($db, $detectInstance, $uaParam);
if (!is_array($device_id)) {
	$device_id = ['platform' => '', 'browser' => '', 'device' => '', 'type' => '1'];
}

$mysql['click_time'] = time();
$mysql['aff_campaign_id'] = $escape($rule['aff_campaign_id']);
$mysql['click_cpc'] = $escape($cpc);
$mysql['click_payout'] = $escape($rule['aff_campaign_payout']);
$mysql['rule_id'] = $escape($rule['rule_id']);
$mysql['rule_redirect_id'] = $escape($rule['redirect_id']);
$mysql['ppc_account_id'] = $escape($ppc_account);
$mysql['landing_page_id'] = $escape($rule['landing_page_id']);

// The referring page's query: its q is a searched keyword (ClickRequest::keyword()), its url the referer.
$referer_url_parsed = @parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
$referer_url_query = $referer_url_parsed['query'] ?? '';
$referer_query = [];
if ($referer_url_query !== '') {
    @parse_str($referer_url_query, $referer_query);
}

$keyword = ClickRequest::keyword($_GET, $referer_query, $keyword_type);

// Raw values: the repositories bind them as parameters, so a value escaped
// here would be stored with its backslashes.
$trackingRepo = \Prosper202\Repository\LookupRepositoryFactory::tracking($db);
$locationRepo = \Prosper202\Repository\LookupRepositoryFactory::location($db);

$keyword_id = $trackingRepo->findOrCreateKeyword($keyword);
$mysql['keyword_id'] = (string) $keyword_id;

$c1 = ClickRequest::text($_GET, 'c1');
$c1 = str_replace('%20',' ',$c1);  
$c1_id = $trackingRepo->findOrCreateC1($c1);
$mysql['c1_id'] = (string) $c1_id;

$c2 = ClickRequest::text($_GET, 'c2');
$c2 = str_replace('%20',' ',$c2);
$c2_id = $trackingRepo->findOrCreateC2($c2);
$mysql['c2_id'] = (string) $c2_id;

$c3 = ClickRequest::text($_GET, 'c3');
$c3 = str_replace('%20',' ',$c3);  
$c3_id = $trackingRepo->findOrCreateC3($c3);
$mysql['c3_id'] = (string) $c3_id;

$c4 = ClickRequest::text($_GET, 'c4');
$c4 = str_replace('%20',' ',$c4);
$c4_id = $trackingRepo->findOrCreateC4($c4);
$mysql['c4_id'] = (string) $c4_id;

$mysql['platform_id'] = $db->real_escape_string((string)($device_id['platform'] ?? ''));
$mysql['browser_id'] = $db->real_escape_string((string)($device_id['browser'] ?? ''));
$mysql['device_id'] = $db->real_escape_string((string)($device_id['device'] ?? ''));
$mysql['text_ad_id'] = $db->real_escape_string((string)($rule['text_ad_id'] ?? 0));
$mysql['isp_id'] = $db->real_escape_string('0');

// Bots are redirected like anyone else; only the recorded flags differ.
$clickIsBot = \Prosper202\Click\ClickBotDetector::isBot($detectInstance->getUserAgent(), $device_id);
$mysql['click_bot'] = $clickIsBot ? '1' : '0';

$mysql['click_in'] = 1;
$mysql['click_out'] = 1; 

// Stored masked under the owner's privacy setting (p202StoredVisitorIp).
$ip_id = $locationRepo->findOrCreateIp(p202StoredVisitorIp());
$mysql['ip_id'] = (string) $ip_id;

$countryName = (string) ($GeoData['country'] ?? '');
$countryCode = (string) ($GeoData['country_code'] ?? '');
$country_id = $locationRepo->findOrCreateCountry($countryName, $countryCode);
$mysql['country_id'] = (string) $country_id;

$regionName = (string) ($GeoData['region'] ?? '');
$region_id = $locationRepo->findOrCreateRegion($regionName, $country_id);
$mysql['region_id'] = (string) $region_id;

$cityName = (string) ($GeoData['city'] ?? '');
$city_id = $locationRepo->findOrCreateCity($cityName, $country_id);
$mysql['city_id'] = (string) $city_id;

if ($IspData != null) {
	$isp_id = $locationRepo->findOrCreateIsp((string) $IspData);
	$mysql['isp_id'] = (string) $isp_id;
}

if ($clickIsBot) {
	$mysql['click_filtered'] = '1';
} else {
	// Initialize click_id as 0 for the filter (will be updated after insert)
	$click_id_temp = 0;
	$click_filtered = FILTER::startFilter($db, $click_id_temp, $ip_id, $user_id);
$mysql['click_filtered'] = $db->real_escape_string((string)$click_filtered);
}

$clickRepo = new \Prosper202\Click\MysqlClickRepository(\Prosper202\Repository\LookupRepositoryFactory::connection($db));

// A re-click (?lpr=) rewrites the visitor's last click rather than recording
// a new one: that click by the address the click path stored
// (p202StoredVisitorIp), within the last 30 days as the pixels' fallback
// looks. With none, the visit is a new click.
$reClick = null;
if (ClickRequest::string($_GET, 'lpr') !== '') {
	$reClick = \Prosper202\Click\LastClickFromAddress::find(
		\Prosper202\Repository\LookupRepositoryFactory::connection($db),
		p202StoredVisitorIp(),
		(int) $user_id,
		time() - 2592000,
		!trackingEnabled()
	);
}
if ($reClick !== null) {
	// The click keeps its keyword. Its id belongs to an earlier click, so it
	// is not noted for the redirect to sign (RecordedClicks).
	$click_id = $reClick['click_id'];
	$keyword_id = $reClick['keyword_id'];
	$mysql['keyword_id'] = (string) $keyword_id;
} else {
	try {
		$click_id = $clickRepo->allocateClickId();
	} catch (\Throwable $rtrAllocateError) {
		record_mysql_error($db, 'allocating a rotator click id', $rtrAllocateError->getMessage());
	}
}
$mysql['click_id'] = (string) $click_id;
$mysql['click_alp'] = 0;

$mysql['rotator_id'] = $db->real_escape_string((string)$rotator_id); 
$mysql['user_id'] = $db->real_escape_string((string)$user_id);

//lets determine if cloaking is on
$cloaking_on = false;
$mysql['click_id_public'] = '';
if ($rule['aff_campaign_cloaking'] == 1) {
	$cloaking_on = true;
	$mysql['click_cloaking'] = 1;
	//if cloaking is on, add in a click_id_public, because we will be forwarding them to a cloaked /cl/xxxx link.
	// A re-click keeps the one its cl.php and pci.php links were handed out with.
	$click_id_public = $reClick !== null && $reClick['click_id_public'] !== '' && $reClick['click_id_public'] !== '0'
		? $reClick['click_id_public']
		: random_int(1,9) . $click_id . random_int(1,9);
	$mysql['click_id_public'] = $click_id_public;
} else { 
	$mysql['click_cloaking'] = 0; 
}

//now lets get variables for clicks site
//so this is going to check the REFERER URL, for a ?url=, which is the ACUTAL URL, instead of the google content, pagead2.google.... 
if (is_string($referer_query['url'] ?? null) && $referer_query['url'] !== '') {
    $click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl($referer_query['url']);
} else {
    $click_referer_site_url_id = $locationRepo->findOrCreateSiteUrl((string) ($_SERVER['HTTP_REFERER'] ?? ''));
}

$mysql['click_referer_site_url_id'] = (string) $click_referer_site_url_id;

$outbound_site_url = \Prosper202\Click\TrackingBaseUrl::requestUrl($_SERVER);
$click_outbound_site_url_id = $locationRepo->findOrCreateSiteUrl($outbound_site_url);
$mysql['click_outbound_site_url_id'] = (string) $click_outbound_site_url_id;

if ($cloaking_on == true) {
	// The request's own scheme, host and port, and the install directory.
	$cloaking_site_url = \Prosper202\Click\TrackingBaseUrl::forRequest($_SERVER) . 'tracking202/redirect/cl.php?pci=' . $click_id_public;
	// Where pci.php sends a cloaked click, as dl.php stores it: without it a
	// cloaked click's pci.php link led nowhere.
	$mysql['click_cloaking_site_url_id'] = (string) $locationRepo->findOrCreateClickSiteUrl($cloaking_site_url);
}
// Landing Page Optimizer: the t202ctx token is minted ONLY when the
// destination comes from the type='lp' branch below (p202-edge-sync §3.3) —
// never for campaign/url/auto_monetizer targets (offer URLs must not carry it).
$t202ctx_lp_destination = false;
// Whether the visitor goes to the operator's own landing page (a rule or the
// rotator's default pointing at one) rather than an offer: only then do the
// customer id and the consent flag ride along (see getPrePopVars()).
$rtrToOwnLandingPage = false;
if ($rule['aff_campaign_id'] != null) {
	//rotate the urls
	$redirect_site_url = rotateTrackerUrl($db, $rule);
} else {
	if ($rule['type'] == 'url') {
		$redirect_site_url = $rule['redirect_url'];
	} else if($rule['type'] == 'campaign') {
		$redirect_site_url = $rule['aff_campaign_url'];
	} else if ($rule['type'] == 'lp') {
		$redirect_site_url = $rule['landing_page_url'];
		$t202ctx_lp_destination = true;
		$rtrToOwnLandingPage = true;
	} else if ($rule['type'] == 'auto_monetizer') {
		$redirect_site_url = "http://prosper202.com";
	} else if ($rule['default_url'] != null) {
		$redirect_site_url = $rule['default_url'];
	} else if ($rule['default_lp'] != null) {
		$redirect_site_url = $rule['landing_page_url'];
		$rtrToOwnLandingPage = true;
	}
}

// Identity signals (plan §6.2): the p202vid cookie, the p202lpid a
// landing page sends, a signed customer id, linked once the click's rows
// are committed. A rule that points at a bare URL has no campaign and
// leaves capture on; a campaign whose setting cannot be read captures
// nothing.
$rtrCampaignAllows = true;
if ((int) $rule['aff_campaign_id'] > 0) {
	$rtrCampaignRow = memcache_mysql_fetch_assoc(
		$db,
		"SELECT identity_signals FROM 202_aff_campaigns WHERE aff_campaign_id='" . $mysql['aff_campaign_id'] . "'"
	);
	// `?? '0'` turns a NULL setting into capture off. Today it never sees
	// one: the column is NOT NULL DEFAULT '1' (CampaignTables). dl.php
	// passes NULL through to campaignAllows(), which reads it as ON,
	// because there NULL means "no campaign" (a LEFT JOIN miss). If the
	// column is ever made nullable, this line and dl.php's must change
	// together, or a campaign's NULL captures on one redirect path and
	// not on the other.
	$rtrCampaignAllows = is_array($rtrCampaignRow)
		&& \Prosper202\Identity\RequestSignals::campaignAllows($rtrCampaignRow['identity_signals'] ?? '0');
}
$rtrIdentity = p202ClickIdentity($_GET, $rtrCampaignAllows);

// Inside the transaction: the placeholders are read back from the rows just
// written. A plain closure: it hands the resolved URL out.
$rtrResolveRedirect = function () use ($db, &$redirect_site_url, $click_id, $user_id, $locationRepo): int {
	$redirect_site_url = replaceTrackerPlaceholders($db, $redirect_site_url, $click_id, (int) $user_id);
	return $locationRepo->findOrCreateClickSiteUrl((string) $redirect_site_url);
};
// Built outside the try: a record the builder refuses is a bug, not a
// database failure, and the uncaught handler logs it as one.
$rtrClick = \Prosper202\Click\ClickRecordBuilder::forRedirector($mysql, (int) $click_id, $rtrIdentity);
try {
	// The site URL lookup caches the ids of the rows it inserts: held until
	// the transaction commits, and dropped with a rollback.
	p202WithHeldCacheWrites(static fn () => $reClick !== null
		? $clickRepo->recordReClick($rtrClick, $rtrResolveRedirect)
		: $clickRepo->recordClick($rtrClick, $rtrResolveRedirect));
} catch (\Throwable $rtrWriteError) {
	record_mysql_error($db, 'recording rotator click ' . $mysql['click_id'], $rtrWriteError->getMessage());
}

	if ($rule['aff_campaign_id'] != null) {
		//set the cookie
		setClickIdCookie($mysql['click_id'],$rule['aff_campaign_id']);
	}
	$rtrIdentity->sendCookie($_SERVER);

	//set dirty hour
	$de = new DataEngine();
	$data = $de->setDirtyHour($mysql['click_id']);

	$urlvars = getPrePopVars($_GET, $rtrToOwnLandingPage);

	// Landing Page Optimizer: on rotator→LP destinations only, append the
	// signed per-click context token (t202ctx, p202-edge-sync §3.2/§3.3) so
	// the paired edge can resolve traffic-source segments at assign time.
	// Gated on an active Landing Page Optimizer pairing whose derived key is already cached
	// in the lpo_bridge_config pref — installs paired before ctx_token
	// support skip minting until 202-cronjobs/bridge_config.php backfills
	// the key. All payload claims are already in scope: zero extra queries
	// beyond the memcached pref row. Failure-open: ANY problem in this block
	// (missing Landing Page Optimizer columns pre-upgrade, bad JSON, mint error) leaves the
	// redirect exactly as it was.
	$t202ctx_token = '';
	if ($t202ctx_lp_destination) {
		try {
			$t202ctx_pref = false;
			$t202ctx_sql = "SELECT lpo_status, lpo_bridge_config, lpo_ctx_kw FROM 202_users_pref WHERE user_id='".$mysql['user_id']."'";
			// key mirrored by lpo_ctx_pref_cache_bust() (202-account/
			// api-integrations.php) — keep the SELECT text in sync
			$t202ctx_cache_key = md5($t202ctx_sql . systemHash());
			if (!empty($GLOBALS['memcacheWorking'])) {
				$t202ctx_cached = $GLOBALS['memcache']->get($t202ctx_cache_key);
				if ($t202ctx_cached !== false) {
					$t202ctx_pref = unserialize((string)$t202ctx_cached, ['allowed_classes' => false]);
				}
			}
			if ($t202ctx_pref === false) {
				// Plain query on purpose (not _mysqli_query): a missing Landing
				// Page Optimizer column on a pre-upgrade schema must soft-skip the token,
				// never kill the redirect.
				$t202ctx_result = $db->query($t202ctx_sql);
				if ($t202ctx_result instanceof mysqli_result) {
					$t202ctx_pref = $t202ctx_result->fetch_assoc();
					if (!empty($GLOBALS['memcacheWorking']) && is_array($t202ctx_pref)) {
						setCache($t202ctx_cache_key, serialize($t202ctx_pref), 60 * 3);
					}
				}
			}
			if (is_array($t202ctx_pref) && ($t202ctx_pref['lpo_status'] ?? '') === 'active') {
				$t202ctx_state = json_decode((string)($t202ctx_pref['lpo_bridge_config'] ?? ''), true);
				$t202ctx_key_hex = is_array($t202ctx_state) ? (string)($t202ctx_state['ctx_key'] ?? '') : '';
				if (preg_match('/^[0-9a-f]{64}$/', $t202ctx_key_hex) === 1) {
					$t202ctx_claims = ['v' => 1, 't' => time(), 'sid' => (string)$mysql['click_id']];
					if ((int)$ppc_account > 0) {
						$t202ctx_claims['acc'] = (int)$ppc_account;
					}
					if ((int)$rule['aff_campaign_id'] > 0) {
						$t202ctx_claims['cmp'] = (int)$rule['aff_campaign_id'];
					}
					$t202ctx_lp_id = (int)($rule['landing_page_id'] ?: ($rule['default_lp'] ?? 0));
					if ($t202ctx_lp_id > 0) {
						$t202ctx_claims['lp'] = $t202ctx_lp_id;
					}
					if ((string)($t202ctx_pref['lpo_ctx_kw'] ?? '1') !== '0' && $keyword !== '') {
						$t202ctx_claims['kw'] = $keyword;
					}
					foreach (['c1' => $c1, 'c2' => $c2, 'c3' => $c3, 'c4' => $c4] as $t202ctx_ck => $t202ctx_cv) {
						if ($t202ctx_cv !== '') {
							$t202ctx_claims[$t202ctx_ck] = $t202ctx_cv;
						}
					}
					if ((string)$countryCode !== '') {
						$t202ctx_claims['cc'] = (string)$countryCode;
					}
					$t202ctx_token = \Prosper202\Lpo\CtxToken::mint($t202ctx_claims, (string)hex2bin($t202ctx_key_hex));
				}
			}
		} catch (\Throwable $t202ctx_error) {
			// A mint failure must never break the redirect.
			$t202ctx_token = '';
		}
	}

	//now we've recorded, now lets redirect them
	if ($cloaking_on == true) {
		//if cloaked, redirect them to the cloaked site.
		// cl.php forwards only pci + 202vars to the landing page, so the token
		// must ride INSIDE the packed vars — appended to the cl.php URL itself
		// it would never reach the final destination (review finding).
		if ($t202ctx_token !== '') {
			$urlvars .= 't202ctx=' . $t202ctx_token . '&';
		}
		$t202ctx_final_url = setPrePopVars($urlvars,$cloaking_site_url,true);
	} else {
		$t202ctx_final_url = setPrePopVars($urlvars,$redirect_site_url,false);
		if ($t202ctx_token !== '') {
			// fragment-safe append: the token must join the query string BEFORE
			// any #fragment, or it stays client-side and never reaches the LP
			// server/loader (review finding).
			$t202ctx_final_url = \Prosper202\Lpo\CtxToken::appendToUrl((string)$t202ctx_final_url, $t202ctx_token);
		}
	}
	return $t202ctx_final_url;

}
