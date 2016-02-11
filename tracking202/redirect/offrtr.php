<?php
use UAParser\Parser;

error_reporting(E_ALL);
ini_set("display_errors", true);
ob_start();

$urlvarslist = $_GET;
$rpi = $_GET['rpi'];

if(!isset($_COOKIE['tracking202subid']) || !is_numeric($_COOKIE['tracking202subid']) || !isset($rpi) || !is_numeric($rpi)) { 
    die();
} 

include_once (substr(dirname( __FILE__ ), 0,-21) . '/202-config/connect2.php');
include_once(substr(dirname( __FILE__ ), 0,-21) . '/202-config/class-dataengine-slim.php');

$mysql['click_id'] = $db->real_escape_string($_COOKIE['tracking202subid']);
$mysql['rpi'] = $db->real_escape_string($_GET['rpi']);


/*$usedCachedRedirect = false;
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
            
            // c1 sring replace for cached redirect
            if (isset($_GET['c1']) && $_GET['c1'] != '') {
                $new_url = str_replace("[[c1]]", $_GET['c1'], $new_url);
            } else {
                $new_url = str_replace("[[c1]]", "p202c1", $new_url);
            }
            
            // c2 sring replace for cached redirect
            if (isset($_GET['c2']) && $_GET['c2'] != '') {
                $new_url = str_replace("[[c2]]", $_GET['c2'], $new_url);
            } else {
                $new_url = str_replace("[[c2]]", "p202c2", $new_url);
            }
            
            // c3 sring replace for cached redirect
            if (isset($_GET['c3']) && $_GET['c3'] != '') {
                $new_url = str_replace("[[c3]]", $_GET['c3'], $new_url);
            } else {
                $new_url = str_replace("[[c3]]", "p202c3", $new_url);
            }
            
            // c4 sring replace for cached redirect
            if (isset($_GET['c4']) && $_GET['c4'] != '') {
                $new_url = str_replace("[[c4]]", $_GET['c4'], $new_url);
            } else {
                $new_url = str_replace("[[c4]]", "p202c4", $new_url);
            }
            
            $urlvars = getPrePopVars($urlvarslist);
            
            $new_url = setPrePopVars($urlvars, $redirect_site_url, false);
       
            header('location: ' . $new_url);
            die();
        }
    }
    
    die("<h2>Error establishing a database connection - please contact the webhost</h2>");
}*/

$rotator_sql = "SELECT 
					   rt.id,
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
					   lp.landing_page_url 	
				FROM 202_rotators AS rt
				LEFT JOIN 202_aff_campaigns AS ac ON ac.aff_campaign_id = rt.default_campaign
				LEFT JOIN 202_landing_pages AS lp ON lp.landing_page_id = rt.default_lp
				LEFT JOIN 202_users_pref AS up ON up.user_id = rt.user_id
				WHERE   rt.public_id='".$mysql['rpi']."'"; 
$rotator_row = memcache_mysql_fetch_assoc($db, $rotator_sql);
if (!$rotator_row) die();

$mysql['rotator_id'] = $db->real_escape_string($rotator_row['id']);
$rule_sql = "SELECT ru.id as rule_id
			 FROM 202_rotator_rules AS ru
			 WHERE rotator_id='".$mysql['rotator_id']."' AND status='1'"; 
$rule_row = foreach_memcache_mysql_fetch_assoc($db, $rule_sql);

$ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];

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
$detect = new Mobile_Detect;
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
	
	$rotate = array();
	$count = 0;

	$mysql['rule_id'] = $db->real_escape_string($rule['rule_id']);
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

		$values = explode(',', $criteria['value']);

		if (in_array('ALL', $values)) {
			
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
						if (in_array($ip_address, $values)) {
							$rotate[] = true;
						}
					} else {
						if (!in_array($ip_address, $values)) {
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
		$default = false;
		$mysql['rule_id'] = $mysql['rule_id'];
		break;
	}
}	

$mysql['click_out'] = 1;

$mysql['rule_redirect_id'] = $db->real_escape_string($rule_redirect_row['rule_redirect_id']);
$click_sql = "
	REPLACE INTO
		202_clicks_rotator
	SET
		click_id='".$mysql['click_id']."',
		rotator_id='".$mysql['rotator_id']."',
		rule_id='".$mysql['rule_id']."',
		rule_redirect_id = '".$mysql['rule_redirect_id']."'";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);

if ($default == false) {

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
					   lp.landing_page_url
				FROM 202_clicks AS 2c
				LEFT JOIN 202_clicks_record AS 2cr ON 2cr.click_id = 2c.click_id
				LEFT JOIN 202_clicks_site AS 2cs ON 2cs.click_id = 2c.click_id	   
				LEFT JOIN 202_rotator_rules_redirects AS rur ON rur.rule_id = '".$mysql['rule_id']."'
				LEFT JOIN 202_aff_campaigns AS ca ON ca.aff_campaign_id = rur.redirect_campaign
				LEFT JOIN 202_landing_pages AS lp ON lp.landing_page_id = rur.redirect_lp
				WHERE 2c.click_id='".$mysql['click_id']."'"; 
		$rule_redirect_row = memcache_mysql_fetch_assoc($db, $rule_redirects_sql);

			if ($rule_redirect_row['redirect_campaign'] != null) {
				$mysql['aff_campaign_id'] = $db->real_escape_string($rule_redirect_row['aff_campaign_id']);
				$mysql['click_payout'] = $db->real_escape_string($rule_redirect_row['aff_campaign_payout']);

				$update_sql = "
					UPDATE
						202_clicks AS 2c
						LEFT JOIN 202_clicks_spy AS 2cs ON (2c.click_id = 2cs.click_id)
					SET
						2c.aff_campaign_id='" . $mysql['aff_campaign_id'] . "',
						2cs.aff_campaign_id='" . $mysql['aff_campaign_id'] . "',
						2c.click_payout='" . $mysql['click_payout'] . "',
						2cs.click_payout='" . $mysql['click_payout'] . "'
					WHERE
						2c.click_id='" . $mysql['click_id'] . "'
				";
				$click_result = $db->query($update_sql) or record_mysql_error($db, $update_sql);

				if (($rule_redirect_row['click_cloaking'] == 1) or // if tracker has overrided cloaking on
				(($rule_redirect_row['click_cloaking'] == - 1) and ($rule_redirect_row['aff_campaign_cloaking'] == 1)) or ((! isset($rule_redirect_row['click_cloaking'])) and ($rule_redirect_row['aff_campaign_cloaking'] == 1))) // if no tracker but but by default campaign has cloaking on
				{
				    $cloaking_on = true;
				    $mysql['click_cloaking'] = 1;
				    // if cloaking is on, add in a click_id_public, because we will be forwarding them to a cloaked /cl/xxxx link
				} else {
				    $mysql['click_cloaking'] = 0;
				}

				$update_sql = "
					UPDATE
						202_clicks_record
					SET
						click_out='" . $mysql['click_out'] . "',
						click_cloaking='" . $mysql['click_cloaking'] . "'
					WHERE
						click_id='" . $mysql['click_id'] . "'
				";
				$click_result = $db->query($update_sql) or record_mysql_error($db, $update_sql);

				$outbound_site_url = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
				$click_outbound_site_url_id = INDEXES::get_site_url_id($db, $outbound_site_url);
				$mysql['click_outbound_site_url_id'] = $db->real_escape_string($click_outbound_site_url_id);

				if ($cloaking_on == true) {
				    $cloaking_site_url = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
				}

				$redirect_site_url = rotateTrackerUrl($db, $rule_redirect_row);
				$redirect_site_url = replaceTrackerPlaceholders($db, $redirect_site_url, $mysql['click_id']);

				$click_redirect_site_url_id = INDEXES::get_site_url_id($db, $redirect_site_url);
				$mysql['click_redirect_site_url_id'] = $db->real_escape_string($click_redirect_site_url_id);

				$update_sql = "
					UPDATE
						202_clicks_site
					SET
						click_outbound_site_url_id='" . $mysql['click_outbound_site_url_id'] . "',
						click_redirect_site_url_id='" . $mysql['click_redirect_site_url_id'] . "'
					WHERE
						click_id='" . $mysql['click_id'] . "'
				";
				$click_result = $db->query($update_sql) or record_mysql_error($db, $update_sql);

				$de = new DataEngine();
				$data = $de->setDirtyHour($mysql['click_id']);

				if ($cloaking_on == true) { ?>
				<html>
				<head>
				<title><?php echo $rule_redirect_row['aff_campaign_name']; ?></title>
				<meta name="robots" content="noindex">
				<meta http-equiv="refresh"
					content="1; url=<?php echo $redirect_site_url; ?>">
				</head>
				<body>
					<form name="form1" id="form1" method="get"
						action="/tracking202/redirect/cl2.php">
						<input type="hidden" name="q"
							value="<?php echo $redirect_site_url; ?>" />
					</form>
					<script type="text/javascript">
							document.form1.submit();
						</script>

					<div style="padding: 30px; text-align: center;">
							You are being automatically redirected to <?php echo $rule_redirect_row['aff_campaign_name']; ?>.<br />
						<br /> Page Stuck? <a href="<?php echo $redirect_site_url; ?>">Click
							Here</a>.
					</div>
				</body>
				</html>
				<?php } else {

				    header('location: ' . $redirect_site_url);
				    die();
				}

			} else if ($rule_redirect_row['redirect_lp'] != null) {
				$redirect_site_url = replaceTrackerPlaceholders($db, $rotator_row['landing_page_url'], $mysql['click_id']);	
				header('location: ' . $redirect_site_url);
				die();
			} else if($rule_redirect_row['redirect_url'] != null) {
				header('location: ' . $rule_redirect_row['redirect_url']);
				die();
			} else if ($rule_redirect_row['auto_monetizer'] != null) {
				header('location: http://prosper202.com');
				die();
			}
} else {

		if ($rotator_row['default_campaign'] != null) {
				$click_sql = "SELECT
					   2c.click_id, 
					   2c.user_id,
					   2c.click_filtered,
					   2c.landing_page_id,
					   2cr.click_cloaking,
					   2cs.click_cloaking_site_url_id,
					   2cs.click_redirect_site_url_id
				FROM 202_clicks AS 2c
				LEFT JOIN 202_clicks_record AS 2cr ON 2cr.click_id = 2c.click_id
				LEFT JOIN 202_clicks_site AS 2cs ON 2cs.click_id = 2c.click_id
				WHERE 2c.click_id='".$mysql['click_id']."'"; 
				$click_row = memcache_mysql_fetch_assoc($db, $click_sql);

				$mysql['aff_campaign_id'] = $db->real_escape_string($rotator_row['aff_campaign_id']);
				$mysql['click_payout'] = $db->real_escape_string($rotator_row['aff_campaign_payout']);

				$update_sql = "
					UPDATE
						202_clicks AS 2c
						LEFT JOIN 202_clicks_spy AS 2cs ON (2c.click_id = 2cs.click_id)
					SET
						2c.aff_campaign_id='" . $mysql['aff_campaign_id'] . "',
						2cs.aff_campaign_id='" . $mysql['aff_campaign_id'] . "',
						2c.click_payout='" . $mysql['click_payout'] . "',
						2cs.click_payout='" . $mysql['click_payout'] . "'
					WHERE
						2c.click_id='" . $mysql['click_id'] . "'
				";
				$click_result = $db->query($update_sql) or record_mysql_error($db, $update_sql);

				if (($click_row['click_cloaking'] == 1) or // if tracker has overrided cloaking on
				(($click_row['click_cloaking'] == - 1) and ($rotator_row['aff_campaign_cloaking'] == 1)) or ((! isset($click_row['click_cloaking'])) and ($rotator_row['aff_campaign_cloaking'] == 1))) // if no tracker but but by default campaign has cloaking on
				{
				    $cloaking_on = true;
				    $mysql['click_cloaking'] = 1;
				    // if cloaking is on, add in a click_id_public, because we will be forwarding them to a cloaked /cl/xxxx link
				} else {
				    $mysql['click_cloaking'] = 0;
				}

				$update_sql = "
					UPDATE
						202_clicks_record
					SET
						click_out='" . $mysql['click_out'] . "',
						click_cloaking='" . $mysql['click_cloaking'] . "'
					WHERE
						click_id='" . $mysql['click_id'] . "'
				";
				$click_result = $db->query($update_sql) or record_mysql_error($db, $update_sql);

				$outbound_site_url = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
				$click_outbound_site_url_id = INDEXES::get_site_url_id($db, $outbound_site_url);
				$mysql['click_outbound_site_url_id'] = $db->real_escape_string($click_outbound_site_url_id);

				if ($cloaking_on == true) {
				    $cloaking_site_url = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
				}

				$redirect_site_url = rotateTrackerUrl($db, $rotator_row);
				$redirect_site_url = replaceTrackerPlaceholders($db, $redirect_site_url, $mysql['click_id']);

				$click_redirect_site_url_id = INDEXES::get_site_url_id($db, $redirect_site_url);
				$mysql['click_redirect_site_url_id'] = $db->real_escape_string($click_redirect_site_url_id);

				$update_sql = "
					UPDATE
						202_clicks_site
					SET
						click_outbound_site_url_id='" . $mysql['click_outbound_site_url_id'] . "',
						click_redirect_site_url_id='" . $mysql['click_redirect_site_url_id'] . "'
					WHERE
						click_id='" . $mysql['click_id'] . "'
				";
				$click_result = $db->query($update_sql) or record_mysql_error($db, $update_sql);

				$de = new DataEngine();
				$data = $de->setDirtyHour($mysql['click_id']);

				if ($cloaking_on == true) { ?>
				<html>
				<head>
				<title><?php echo $rotator_row['aff_campaign_name']; ?></title>
				<meta name="robots" content="noindex">
				<meta http-equiv="refresh"
					content="1; url=<?php echo $redirect_site_url; ?>">
				</head>
				<body>
					<form name="form1" id="form1" method="get"
						action="/tracking202/redirect/cl2.php">
						<input type="hidden" name="q"
							value="<?php echo $redirect_site_url; ?>" />
					</form>
					<script type="text/javascript">
							document.form1.submit();
						</script>

					<div style="padding: 30px; text-align: center;">
							You are being automatically redirected to <?php echo $rotator_row['aff_campaign_name']; ?>.<br />
						<br /> Page Stuck? <a href="<?php echo $redirect_site_url; ?>">Click
							Here</a>.
					</div>
				</body>
				</html>
				<?php } else {

				    header('location: ' . $redirect_site_url);
				    die();
				}

		} else if ($rotator_row['default_lp'] != null) {
			$redirect_site_url = replaceTrackerPlaceholders($db, $rotator_row['landing_page_url'], $mysql['click_id']);	
			header('location: ' . $redirect_site_url);
			die();
		} else if($rotator_row['default_url'] != null) {
			header('location: ' . $rotator_row['default_url']);
			die();
		} else if ($rotator_row['auto_monetizer'] != null) {
			header('location: http://prosper202.com');
			die();
		}

}
