<?php
declare(strict_types=1);
use UAParser\Parser;

error_reporting(E_ALL);
ini_set("display_errors", true);
ob_start();

$urlvarslist = $_GET;
$rpi = $_GET['rpi'];
// The click cookie, or its -legacy twin (ClickCookie; the autoloader is not loaded yet).
require_once __DIR__ . '/../../202-config/Http/ClickCookie.php';
$cookieClickId = \Prosper202\Http\ClickCookie::value($_COOKIE, 'tracking202subid');

if($cookieClickId === null || !is_numeric($cookieClickId) || !isset($rpi) || !is_numeric($rpi)) { 
    die();
} 

include_once (substr(__DIR__, 0,-21) . '/202-config/connect2.php');
// It keeps no URL for an outage; go.php can load it past the bootstrap's check.
p202RequireDatabase($db);
include_once(substr(__DIR__, 0,-21) . '/202-config/class-dataengine-slim.php');

// Speculative requests (browser prefetch/prerender, link-preview scanners, HEAD
// probes) must not record a click — otherwise the speculative hit plus the real
// navigation double-count the same visit (see p202IsSpeculativeRequest).
if (p202IsSpeculativeRequest()) {
	p202DeclineSpeculativeRequest();
}


// The cookie's click id, exactly: is_numeric() let '1e3' through, which the
// SQL read as click 1000.
$offrtrClickId = \Prosper202\Click\ClickId::parse($cookieClickId);
if ($offrtrClickId === null) {
    die();
}
$mysql['click_id'] = (string) $offrtrClickId;
// The rotator's public id exactly: compared as text, MySQL read 12abc as 12.
$offrtrRotatorId = \Prosper202\Click\PublicId::parse($rpi);
if ($offrtrRotatorId === null) {
    die();
}

$rotator_sql = "SELECT
					   rt.id,
					   rt.user_id,
					   rt.default_url,
					   rt.default_campaign,
					   rt.default_lp,
					   rt.auto_monetizer,
					   ac.aff_campaign_name,
					   ac.aff_campaign_id,
					   ac.aff_campaign_rotate,
					   ac.aff_campaign_url,
					   ac.aff_campaign_url_2,
					   ac.aff_campaign_url_3,
					   ac.aff_campaign_url_4,
					   ac.aff_campaign_url_5,
					   ac.aff_campaign_payout,
					   ac.aff_campaign_cloaking,
					   up.maxmind_isp,
					   lp.landing_page_url
				FROM 202_rotators AS rt
				LEFT JOIN 202_aff_campaigns AS ac ON ac.aff_campaign_id = rt.default_campaign
                    AND ac.user_id = rt.user_id
				LEFT JOIN 202_landing_pages AS lp ON lp.landing_page_id = rt.default_lp
                    AND lp.user_id = rt.user_id
				LEFT JOIN 202_users_pref AS up ON up.user_id = rt.user_id
				WHERE   rt.public_id=" . $offrtrRotatorId;
$rotator_row = memcache_mysql_fetch_assoc($db, $rotator_sql);
if (!$rotator_row) die();

// recordExit() below refuses a click that is not the rotator's account's;
// asked here first, such a click turns no campaign's rotation, looks up no
// URL and costs no location lookup before it is refused.
try {
    $offrtrClicks = new \Prosper202\Click\MysqlClickRepository(
        \Prosper202\Repository\LookupRepositoryFactory::connection($db)
    );
    $offrtrIsAccountsClick = $offrtrClicks->isAccountsClick((int) $rotator_row['user_id'], $offrtrClickId);
} catch (\Throwable $offrtrReadError) {
    record_mysql_error($db, 'reading click ' . $mysql['click_id'] . "'s account", $offrtrReadError->getMessage());
}
if (!$offrtrIsAccountsClick) {
    die();
}


$mysql['rotator_id'] = $db->real_escape_string((string)$rotator_row['id']);
// The active rules, in the order they are tried (RuleOrder).
$rule_row = \Prosper202\Rotator\RuleOrder::activeRules($db, (int) $rotator_row['id']);

// The visitor's address by the rule every click endpoint uses (VisitorIp),
// so the rotator routes on the address dl.php and the pages recorded.
$ip_address = \Prosper202\Http\VisitorIp::fromServer($_SERVER);

if ($rotator_row['maxmind_isp'] == '1') {
	$IspData = getIspData($ip_address);
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

$default = true;

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

		$values = \Prosper202\Rotator\Criterion::values($criteria['value']);

		if (\Prosper202\Rotator\Criterion::matchesEveryone($values)) {
			
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

	if ($count == count($rotate)) {
		$rule_redirects_sql = "SELECT
					   2c.click_id, 
					   2c.user_id,
					   2c.click_filtered,
					   2c.landing_page_id,
					   2cr.click_cloaking,
					   2cs.click_cloaking_site_url_id,
					   2cs.click_redirect_site_url_id,
					   rur.id as rule_redirect_id,
					   rur.redirect_url,
					   rur.redirect_campaign,
					   rur.redirect_lp,
					   rur.auto_monetizer,
					   rur.weight,
					   ca.aff_campaign_name,
					   ca.aff_campaign_id,
					   ca.aff_campaign_rotate,
					   ca.aff_campaign_url,
					   ca.aff_campaign_url_2,
					   ca.aff_campaign_url_3,
					   ca.aff_campaign_url_4,
					   ca.aff_campaign_url_5,
					   ca.aff_campaign_payout,
					   ca.aff_campaign_cloaking,
					   lp.landing_page_url, lp.landing_page_id
				FROM " . \Prosper202\Click\LatestVisit::of($mysql['click_id']) . " AS 2c
				LEFT JOIN 202_clicks_record AS 2cr ON 2cr.click_id = 2c.click_id
				LEFT JOIN 202_clicks_site AS 2cs ON 2cs.click_id = 2c.click_id	   
				LEFT JOIN 202_rotator_rules_redirects AS rur ON rur.rule_id = '".$mysql['rule_id']."'
				LEFT JOIN 202_aff_campaigns AS ca ON ca.aff_campaign_id = rur.redirect_campaign
                    AND ca.user_id = 2c.user_id
				LEFT JOIN 202_landing_pages AS lp ON lp.landing_page_id = rur.redirect_lp
                    AND lp.user_id = 2c.user_id
				WHERE 2c.click_id='".$mysql['click_id']."'
				ORDER BY rur.id";
        // A rule left with no usable redirect passes the click on, as
        // rtr.php does: to a later rule, or to the default.
        // Not cached: read with one click's row.
        $redirectRows = foreach_memcache_mysql_fetch_assoc($db, $rule_redirects_sql, 0);
        $rule_redirect_rows = [];
        foreach ($redirectRows as $redirectRow) {
            if (p202RuleRedirectIsUsable($redirectRow)) {
                $rule_redirect_rows[] = $redirectRow;
            }
        }
        if ($rule_redirect_rows === []) {
            continue;
        }
		$default = false;
		break;
	}
}	

// No rule matched: the click is the rotator's default, which every report
// reads as rule 0, as rtr.php records it. The loop above leaves
// $mysql['rule_id'] at the last rule it tried (unset when the rotator has
// none), and that rule was credited with every default click.
if ($default) {
	$mysql['rule_id'] = '0';
}

// Every way out records the click's route through the rotator, and the
// offer it leaves for, in one write that refuses a click, a rotator or an
// offer that is not the rotator's account's: the click is the visitor's
// cookie, a sequential number, and named any click on the install
// (CLAUDE.md #27). A refused click goes nowhere, as an unknown rotator does.
// The click row names its rotator as rtr.php's does (GET /clicks serves
// 202_clicks.rotator_id); in rtr.php's rows 202_clicks.rule_id is the chosen
// redirect's id. A rule's redirect is 0 for the rotator's default.
$offrtrRecordExit = function (
    int $ruleRedirectId,
    ?array $offer = null,
    bool $cloaked = false,
    int $outboundSiteUrlId = 0,
    ?\Closure $resolveRedirect = null
) use (
    $db,
    $mysql,
    $rotator_row,
    $offrtrClickId,
    $offrtrClicks
): void {
    $recorded = false;
    try {
        $exit = new \Prosper202\Click\ClickExit(
            (int) $rotator_row['user_id'],
            $offrtrClickId,
            offer: $offer === null ? null : new \Prosper202\Click\OfferRoute(
                (int) $offer['aff_campaign_id'],
                (string) $offer['aff_campaign_payout']
            ),
            rotator: new \Prosper202\Click\RotatorRoute(
                (int) $rotator_row['id'],
                (int) $mysql['rule_id'],
                $ruleRedirectId
            ),
            clickedOut: $offer !== null,
            cloaking: $offer === null ? null : ($cloaked ? 1 : 0),
            outboundSiteUrlId: $outboundSiteUrlId,
        );
        // The site URL lookup caches the ids of the rows it inserts: held
        // until the transaction commits, and dropped with a rollback.
        p202WithHeldCacheWrites(function () use (&$recorded, $offrtrClicks, $exit, $resolveRedirect): void {
            $recorded = $offrtrClicks->recordExit($exit, $resolveRedirect);
        });
    } catch (\Throwable $offrtrWriteError) {
        record_mysql_error(
            $db,
            'recording click ' . $mysql['click_id'] . ' leaving through rotator ' . $mysql['rotator_id'],
            $offrtrWriteError->getMessage()
        );
    }
    if (!$recorded) {
        die();
    }
    // The reports read a click's rotator and rule from its rollup row,
    // whichever kind of redirect it leaves by.
    (new DataEngine())->setDirtyHour($mysql['click_id']);
};

if ($default == false) {

		// One row per redirect of the rule, chosen by weight with rtr.php's
		// chooser, as rtr.php chooses: the first row alone sent every click
		// to the rule's first redirect, a weight of 0 included. (In id order,
		// the order rtr.php's scan of the table reads them in, so a rule with
		// no weight anywhere sends both entry points to the same first one;
		// the changed text also keys a fresh cache entry, so a row the old
		// single-row read cached is never taken for a list.)
		if (count($rule_redirect_rows) > 1) {
			$rule_redirect_row = $rule_redirect_rows[getSplitTestValue($rule_redirect_rows)];
		} else {
			$rule_redirect_row = $rule_redirect_rows[0] ?? null;
		}

			$offrtrRedirectId = is_array($rule_redirect_row) && isset($rule_redirect_row['rule_redirect_id'])
				? (int) $rule_redirect_row['rule_redirect_id']
				: 0;
			if (empty($rule_redirect_row['redirect_campaign'])) {
				$offrtrRecordExit($offrtrRedirectId);
			}

			if (!empty($rule_redirect_row['redirect_campaign'])) {
				// The setting the click keeps, or the redirect campaign's when it
				// leaves the decision to the campaign (ClickCloaking).
				$cloaking_on = \Prosper202\Click\ClickCloaking::isOn($rule_redirect_row);

                $locationRepo = \Prosper202\Repository\LookupRepositoryFactory::location($db);
				$outbound_site_url = \Prosper202\Click\TrackingBaseUrl::requestUrl($_SERVER);
				$click_outbound_site_url_id = $locationRepo->findOrCreateClickSiteUrl($outbound_site_url);

				$redirect_site_url = rotateTrackerUrl($db, $rule_redirect_row);
				// Read back inside the write: the payout token is the offer's.
				$offrtrRecordExit(
					$offrtrRedirectId,
					$rule_redirect_row,
					$cloaking_on,
					(int) $click_outbound_site_url_id,
					function () use ($db, &$redirect_site_url, $mysql, $rotator_row, $locationRepo): int {
						$redirect_site_url = replaceTrackerPlaceholders(
							$db,
							$redirect_site_url,
							$mysql['click_id'],
							(int) $rotator_row['user_id']
						);
						return $locationRepo->findOrCreateClickSiteUrl((string) $redirect_site_url);
					}
				);

				if ($cloaking_on == true) { ?>
				<html>
				<head>
				<title><?php echo htmlspecialchars((string) $rule_redirect_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8'); ?></title>
				<meta name="robots" content="noindex">
				<meta http-equiv="refresh"
					content="1; url=<?php echo htmlspecialchars((string) $redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>">
				</head>
				<body>
					<form name="form1" id="form1" method="get"
						action="<?php echo htmlspecialchars(p202InstallPath('tracking202/redirect/cl2.php')); ?>">
						<input type="hidden" name="q"
							value="<?php echo htmlspecialchars((string) $redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>" />
					</form>
					<script type="text/javascript">
							document.form1.submit();
						</script>

					<div style="padding: 30px; text-align: center;">
							You are being automatically redirected to <?php echo htmlspecialchars((string) $rule_redirect_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8'); ?>.<br />
						<br /> Page Stuck? <a href="<?php echo htmlspecialchars((string) $redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>">Click
							Here</a>.
					</div>
				</body>
				</html>
				<?php } else {

				    header('location: ' . $redirect_site_url);
				    die();
				}

			} else if (!empty($rule_redirect_row['redirect_lp'])) {
				// The chosen redirect's landing page (lp joins on rur.redirect_lp),
				// not the rotator default's, which $rotator_row carries.
				$redirect_site_url = replaceTrackerPlaceholders($db, $rule_redirect_row['landing_page_url'], $mysql['click_id'], (int) $rotator_row['user_id']);
				header('location: ' . $redirect_site_url);
				die();
			} else if(!empty($rule_redirect_row['redirect_url'])) {
				header('location: ' . $rule_redirect_row['redirect_url']);
				die();
			} else if ($rule_redirect_row['auto_monetizer'] != null) {
				header('location: http://prosper202.com');
				die();
			}
} else {

		if (empty($rotator_row['aff_campaign_id'])) {
			$offrtrRecordExit(0);
		}

		if (!empty($rotator_row['aff_campaign_id'])) {
				$click_sql = "SELECT
					   2c.click_id, 
					   2c.user_id,
					   2c.click_filtered,
					   2c.landing_page_id,
					   2cr.click_cloaking,
					   2cs.click_cloaking_site_url_id,
					   2cs.click_redirect_site_url_id
				FROM " . \Prosper202\Click\LatestVisit::of($mysql['click_id']) . " AS 2c
				LEFT JOIN 202_clicks_record AS 2cr ON 2cr.click_id = 2c.click_id
				LEFT JOIN 202_clicks_site AS 2cs ON 2cs.click_id = 2c.click_id
				WHERE 2c.click_id='".$mysql['click_id']."'"; 
			$click_row = memcache_mysql_fetch_assoc($db, $click_sql, 0); // uncached: one click's row

				// The setting the click keeps, or the default campaign's when it
				// leaves the decision to the campaign (ClickCloaking).
				$cloaking_on = \Prosper202\Click\ClickCloaking::isOn([
					'click_cloaking' => $click_row['click_cloaking'] ?? null,
					'aff_campaign_cloaking' => $rotator_row['aff_campaign_cloaking'] ?? null,
				]);

                $locationRepo = \Prosper202\Repository\LookupRepositoryFactory::location($db);
				$outbound_site_url = \Prosper202\Click\TrackingBaseUrl::requestUrl($_SERVER);
				$click_outbound_site_url_id = $locationRepo->findOrCreateClickSiteUrl($outbound_site_url);

				$redirect_site_url = rotateTrackerUrl($db, $rotator_row);
				// Read back inside the write: the payout token is the offer's.
				$offrtrRecordExit(
					0,
					$rotator_row,
					$cloaking_on,
					(int) $click_outbound_site_url_id,
					function () use ($db, &$redirect_site_url, $mysql, $rotator_row, $locationRepo): int {
						$redirect_site_url = replaceTrackerPlaceholders(
							$db,
							$redirect_site_url,
							$mysql['click_id'],
							(int) $rotator_row['user_id']
						);
						return $locationRepo->findOrCreateClickSiteUrl((string) $redirect_site_url);
					}
				);

				if ($cloaking_on == true) { ?>
				<html>
				<head>
				<title><?php echo htmlspecialchars((string) $rotator_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8'); ?></title>
				<meta name="robots" content="noindex">
				<meta http-equiv="refresh"
					content="1; url=<?php echo htmlspecialchars((string) $redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>">
				</head>
				<body>
					<form name="form1" id="form1" method="get"
						action="<?php echo htmlspecialchars(p202InstallPath('tracking202/redirect/cl2.php')); ?>">
						<input type="hidden" name="q"
							value="<?php echo htmlspecialchars((string) $redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>" />
					</form>
					<script type="text/javascript">
							document.form1.submit();
						</script>

					<div style="padding: 30px; text-align: center;">
							You are being automatically redirected to <?php echo htmlspecialchars((string) $rotator_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8'); ?>.<br />
						<br /> Page Stuck? <a href="<?php echo htmlspecialchars((string) $redirect_site_url, ENT_QUOTES, 'UTF-8'); ?>">Click
							Here</a>.
					</div>
				</body>
				</html>
				<?php } else {

				    header('location: ' . $redirect_site_url);
				    die();
				}

		} else if (!empty($rotator_row['landing_page_url'])) {
			$redirect_site_url = replaceTrackerPlaceholders($db, $rotator_row['landing_page_url'], $mysql['click_id'], (int) $rotator_row['user_id']);	
			header('location: ' . $redirect_site_url);
			die();
		} else if(!empty($rotator_row['default_url'])) {
			header('location: ' . $rotator_row['default_url']);
			die();
		} else if ($rotator_row['auto_monetizer'] != null) {
			header('location: http://prosper202.com');
			die();
		}

}
