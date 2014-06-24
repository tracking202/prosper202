<?php

$version = '1.8.4';

@ini_set('auto_detect_line_endings', TRUE);
@ini_set('register_globals', 0);
@ini_set('display_errors', 'On');
@ini_set('error_reporting', 6135);
@ini_set('safe_mode', 'Off');

mysqli_report(MYSQLI_REPORT_STRICT);

$_SERVER['HTTP_X_FORWARDED_FOR'] = $_SERVER['REMOTE_ADDR'];

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions-auth.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/geo/inc/geoipcity.inc');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/geo/inc/geoipregionvars.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/vendor/autoload.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/Mobile_Detect.php');
use UAParser\Parser;

//try to connect to memcache server
if ( ini_get('memcache.default_port') ) { 

	$memcacheInstalled = true;
	$memcache = new Memcache;
	if ( @$memcache->connect($mchost, 11211) ) $memcacheWorking = true;
	else $memcacheWorking = false;
	
}

function _mysqli_query($db, $sql) {

	$result = $db->query($sql) or die($db->error . '<br/><br/>' . $sql);
	return $result;
}

//our own die, that will display the them around the error message
function _die($message) { 

	//info_top();
	echo $message;
	//info_bottom();
	die();
}

//this funciton delays an SQL statement, puts in in a mysql table, to be cronjobed out every 5 minutes
function delay_sql($db, $delayed_sql) {

	$mysql['delayed_sql'] = str_replace("'","''",$delayed_sql);
	$mysql['delayed_time'] = time();
	
	$delayed_sql="INSERT INTO  202_delayed_sqls 
					
					(
						delayed_sql ,
						delayed_time
					)
					
					VALUES 
					(
						'".$mysql['delayed_sql'] ."',
						'".$mysql['delayed_time']."'
					);";
	
	$delayed_result = _mysqli_query($db, $delayed_sql) ; //($delayed_sql);
}

class FILTER {
	
	function startFilter($db, $click_id, $ip_id, $ip_address, $user_id) {
		 
		//we only do the other checks, if the first ones have failed.
		//we will return the variable filter, if the $filter returns TRUE, when the click is inserted and recorded we will insert the new click already inserted,
		//what was lagign this query is before it would insert a click, then scan it and then update the click, the updating later on was lagging, now we will just insert and it will not stop the clicks from being redirected becuase of a slow update.
			
		//check the user
		$filter = FILTER::checkUserIP($db, $click_id, $ip_id, $user_id);
		if ($filter == false) {
			
			//check the netrange  
			$filter = FILTER::checkNetrange($click_id, $ip_address);
			if ($filter == false) {  
			
				$filter = FILTER::checkLastIps($db, $user_id, $ip_id);
				
			}
		}
		
		if ($filter == true) { 
			return 1;    
		} else { 
			return 0;    
		}
	}
	
	function checkUserIP($db, $click_id, $ip_id, $user_id) {

		$mysql['ip_id'] = $db->real_escape_string($ip_id);      
		$mysql['user_id'] = $db->real_escape_string($user_id);    
		
		$count_sql = "SELECT    *
					  FROM      202_users 
					  WHERE     user_id='".$mysql['user_id']."' 
					  AND       user_last_login_ip_id='".$mysql['ip_id']."'";
		$count_result = _mysqli_query($db, $count_sql) ; //($count_sql);
	
		//if the click_id's ip address, is the same ip adddress of the click_id's owner's last logged in ip, filter this.  This means if the ip hit on the page was the same as the owner of the click affiliate program, we want to filter out the clicks by the owner when he/she  is trying to test 
		if ($count_result->num_rows > 0) { 
			
			return true;  
		}
		return false;    
	}
	
	function checkNetrange($click_id, $ip_address) {
	
		$ip_address = ip2long($ip_address);
		
		//check each netrange
		/*google1 */ if (($ip_address >= 1208926208) and ($ip_address <= 1208942591)) { return true;  }
		/*google2 */ if (($ip_address >= 3512041472) and ($ip_address <= 3512074239)) { return true;  }
		/*google3 */ if (($ip_address >= 1123631104) and ($ip_address <= 1123639295)) { return true;  }
		/*Google4 */ if (($ip_address >= 1089052672) and ($ip_address <= 1089060863)) { return true;  }
		/*google5 */ if (($ip_address >= -782925824) and ($ip_address <= -782893057)) { return true;  }

		/*level 3 communications */ if (($ip_address >= 1094189056) and ($ip_address <= 1094451199)) { return true;  }
		
		/*yahoo1 */ if (($ip_address >= 3515031552) and ($ip_address <= 3515039743)) { return true;  }
		/*Yahoo2 */ if (($ip_address >= 3633393664) and ($ip_address <= 3633397759)) { return true;  }
		/*Yahoo3 */ if (($ip_address >= 3640418304) and ($ip_address <= 3640426495)) { return true;  }
		/*Yahoo4 */ if (($ip_address >= 1209925632) and ($ip_address <= 1209991167)) { return true;  }
		/*Yahoo5 */ if (($ip_address >= 1241907200) and ($ip_address <= 1241972735)) { return true;  }

		/*Performance Systems International Inc. */ if (($ip_address >= 637534208) and ($ip_address <= 654311423)) { return true;  }
		/*Microsoft */ if (($ip_address >= 3475898368) and ($ip_address <= 3475963903)) { return true;  }
		/*MSN */ if (($ip_address >= 1093926912) and ($ip_address <= 1094189055)) { return true;  }
		
		//if it was none of theses, return false
		return false;           
	}  
	
	//this will filter out a click if it the IP WAS RECORDED, for a particular user within the last 24 hours, if it existed before, filter out this click.
	function checkLastIps($db, $user_id, $ip_id) {

		$mysql['user_id'] = $db->real_escape_string($user_id);
		$mysql['ip_id'] = $db->real_escape_string($ip_id);
		
		$check_sql = "SELECT * FROM 202_last_ips WHERE user_id='".$mysql['user_id']."' AND ip_id='".$mysql['ip_id']."'";
		$check_result = _mysqli_query($db, $check_sql) ; //($check_sql);
		$check_row = $check_result->fetch_assoc();
		$count = $check_result->num_rows;
		 
		if ($count > 0) {
			//if this ip has been seen within the last 24 hours, filter it out. 
			return true;
		} else {
			
			//else if this ip has not been recorded, record it now
			$mysql['time'] = time();
			$insert_sql = "INSERT INTO 202_last_ips SET user_id='".$mysql['user_id']."', ip_id='".$mysql['ip_id']."', time='".$mysql['time']."'";
			$insert_result = _mysqli_query($db, $insert_sql) ; //($insert_sql);
			return false;	
		}
		
	}
	
}

function rotateTrackerUrl($db, $tracker_row) { 

	if (!$tracker_row['aff_campaign_rotate']) return $tracker_row['aff_campaign_url'];
	
	$mysql['aff_campaign_id'] = $db->real_escape_string($tracker_row['aff_campaign_id']);
	$urls = array();
	array_push($urls, $tracker_row['aff_campaign_url']);

	
	if ($tracker_row['aff_campaign_url_2']) array_push($urls, $tracker_row['aff_campaign_url_2']);
	if ($tracker_row['aff_campaign_url_3']) array_push($urls, $tracker_row['aff_campaign_url_3']);
	if ($tracker_row['aff_campaign_url_4']) array_push($urls, $tracker_row['aff_campaign_url_4']);
	if ($tracker_row['aff_campaign_url_5']) array_push($urls, $tracker_row['aff_campaign_url_5']);
	
	$count = count($urls);
	
	$sql5 = "SELECT rotation_num FROM 202_rotations WHERE aff_campaign_id='".$mysql['aff_campaign_id']."'";
	$result5 = _mysqli_query($db, $sql5);
	$row5 = $result5->fetch_assoc();
	if ($row5) { 
		
		$old_num = $row5['rotation_num'];
		if ($old_num >= ($count - 1))		$num = 0;
		else 						$num = $old_num + 1;
		
		$mysql['num'] = $db->real_escape_string($num);
		$sql5 = " UPDATE 202_rotations SET rotation_num='".$mysql['num']."' WHERE aff_campaign_id='".$mysql['aff_campaign_id']."'";
		$result5 = _mysqli_query($db, $sql5);
	
	} else {
		//insert the rotation
		$num = 0;
		$mysql['num'] = $db->real_escape_string($num);
		$sql5 = " INSERT INTO 202_rotations SET aff_campaign_id='".$mysql['aff_campaign_id']."',  rotation_num='".$mysql['num']."' ";
		$result5 = _mysqli_query($db, $sql5);
		$rotation_num = 0;
	}
	
	$url = $urls[$num];
	return $url;
}

function replaceTrackerPlaceholders($db, $url,$click_id) {

	//get the tracker placeholder values
	$mysql['click_id'] = $db->real_escape_string($click_id);
	
	if(preg_match('/\[\[c1\]\]/', $url) || preg_match('/\[\[c2\]\]/', $url) || preg_match('/\[\[c3\]\]/', $url) || preg_match('/\[\[c4\]\]/', $url)) {
		$click_sql = "
			SELECT
				2c.click_id,
				2tc1.c1,
				2tc2.c2,
				2tc3.c3,
				2tc4.c4
			FROM
				202_clicks AS 2c
				LEFT OUTER JOIN 202_clicks_tracking AS 2ct ON (2ct.click_id = 2c.click_id)
				LEFT OUTER JOIN 202_tracking_c1 AS 2tc1 ON (2ct.c1_id = 2tc1.c1_id)
				LEFT OUTER JOIN 202_tracking_c2 AS 2tc2 ON (2ct.c2_id = 2tc2.c2_id)
				LEFT OUTER JOIN 202_tracking_c3 AS 2tc3 ON (2ct.c3_id = 2tc3.c3_id)
				LEFT OUTER JOIN 202_tracking_c4 AS 2tc4 ON (2ct.c4_id = 2tc4.c4_id)
			WHERE
				2c.click_id='".$mysql['click_id']."'
		";
		$click_result = _mysqli_query($db, $click_sql);
		$click_row = $click_result->fetch_assoc();
		
		$url = preg_replace('/\[\[c1\]\]/', $click_row['c1'], $url);
		$url = preg_replace('/\[\[c2\]\]/', $click_row['c2'], $url);
		$url = preg_replace('/\[\[c3\]\]/', $click_row['c3'], $url);
		$url = preg_replace('/\[\[c4\]\]/', $click_row['c4'], $url);
	}
	
	$url = preg_replace('/\[\[subid\]\]/', $mysql['click_id'], $url);
	
	return $url;
}

function setClickIdCookie($click_id,$campaign_id=0) {
	//set the cookie for the PIXEL to fire, expire in 30 days
	$expire = time() + 2592000;
	setcookie('tracking202subid',$click_id,$expire,'/', $_SERVER['SERVER_NAME']);
	setcookie('tracking202subid_a_' . $campaign_id,$click_id,$expire,'/', $_SERVER['SERVER_NAME']);
}

class PLATFORMS {
		
	    function get_device_info($db){

	    	global $memcacheWorking, $memcache;
	    	$detect = new Mobile_Detect;
	    	$ua = $detect->getUserAgent();

			//If Cache working
			if ($memcacheWorking) {
				$device_info = $memcache->get(md5("user-agent" . $ua . systemHash()));

				if (!$device_info) {

					$parse_info = PLATFORMS::parseUserAgentInfo($db, $detect);
					$memcache->set( md5("user-agent" . $ua . systemHash()), $parse_info, false);
					return $parse_info;

				} else {
					return $device_info;
				}

			}

			//If Cache is not working
			else {

				return PLATFORMS::parseUserAgentInfo($db, $detect);
			}

	    }

	    function parseUserAgentInfo($db, $detect){

	    		$parser = Parser::create();
	    		$result = $parser->parse($detect->getUserAgent());
				//If is not mobile or tablet
				if( !$detect->isMobile() && !$detect->isTablet() ){

					switch ($result->device->family) {
						//Is Bot
						case 'Bot':
							$type = "4";
							$result->device->family = "Bot";
							break;
						//Is Desktop
						case 'Other':
							$type = "1";
							$result->device->family = "Desktop";
							break;
					}
				} else {
					//If tablet
					if ($detect->isTablet()) {
						$type = "3";
					//If mobile	
					} else {
						$type = "2";
					}
				}

				//Select from DB and return ID's
				$mysql['browser'] = $db->real_escape_string($result->ua->family);
				$mysql['platform'] = $db->real_escape_string($result->os->family);
				$mysql['device'] = $db->real_escape_string($result->device->family);
				$mysql['device_type'] = $db->real_escape_string($type);

				//Get browser ID
				$browser_sql = "SELECT browser_id FROM 202_browsers WHERE browser_name='".$mysql['browser']."'";
				$browser_result = _mysqli_query($db, $browser_sql);
				$browser_row = $browser_result->fetch_assoc();
					if ($browser_row) {
						 $browser_id = $browser_row['browser_id'];
					} else {
						$browser_sql = "INSERT INTO 202_browsers SET browser_name='".$mysql['browser']."'";
						$browser_result = _mysqli_query($db, $browser_sql) ;
						$browser_id = $db->insert_id;
					}

				//Get platform ID
				$platform_sql = "SELECT platform_id FROM 202_platforms WHERE platform_name='".$mysql['platform']."'";
				$platform_result = _mysqli_query($db, $platform_sql);
				$platform_row = $platform_result->fetch_assoc();
					if ($platform_row) {
						 $platform_id = $platform_row['platform_id'];
					} else {
						$platform_sql = "INSERT INTO 202_platforms SET platform_name='".$mysql['platform']."'";
						$platform_result = _mysqli_query($db, $platform_sql) ;
						$platform_id = $db->insert_id;
					}

				//Get device model ID
				$device_sql = "SELECT device_id, device_type FROM 202_device_models WHERE device_name='".$mysql['device']."'";
				$device_result = _mysqli_query($db, $device_sql);
				$device_row = $device_result->fetch_assoc();
					if ($device_row) {
						 $device_id = $device_row['device_id'];
						 $device_type = $device_row['device_type'];
					} else {
						$device_sql = "INSERT INTO 202_device_models SET device_name='".$mysql['device']."', device_type='".$mysql['device_type']."'";
						$device_result = _mysqli_query($db, $device_sql) ;
						$device_id = $db->insert_id;
						$device_type = $type;
					}

				$data = array('browser' => $browser_id, 'platform' => $platform_id, 'device' => $device_id, 'type' => $device_type);
				return $data;
	    }
    }

class INDEXES {
	
		
	//this returns the location_country_id, when a Country Code is given
	function get_country_id($db, $country_name, $country_code) {
		
		global $memcacheWorking, $memcache;
		
		$mysql['country_name'] = $db->real_escape_string($country_name);
		$mysql['country_code'] = $db->real_escape_string($country_code);

		if ($memcacheWorking) {
			$time = 2592000; //30 days in sec
			//get from memcached
			$getID = $memcache->get(md5("country-id" . $country_name . systemHash()));

			if ($getID) {
				$country_id = $getID;
				return $country_id;
			} else {

				$country_sql = "SELECT country_id FROM 202_locations_country WHERE country_code='".$mysql['country_code']."'";
				$country_result = _mysqli_query($db, $country_sql);
				$country_row = $country_result->fetch_assoc(); 
				if ($country_row) {
					//if this ip_id already exists, return the ip_id for it.
					$country_id = $country_row['country_id'];
					//add to memcached
					$setID = $memcache->set( md5("country-id" . $country_name . systemHash()), $country_id, false, $time );
					return $country_id;
				} else {
					//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
					$country_sql = "INSERT INTO 202_locations_country SET country_code='".$mysql['country_code']."', country_name='".$mysql['country_name']."'";
					$country_result = _mysqli_query($db, $country_sql) ; //($ip_sql);
					$country_id = $db->insert_id;
					//add to memcached
					$setID = $memcache->set( md5("country-id" . $country_name . systemHash()), $country_id, false, $time );
					return $country_id;
				}    
			}
			
		} else {

			$country_sql = "SELECT country_id FROM 202_locations_country WHERE country_code='".$mysql['country_code']."'";
			$country_result = _mysqli_query($db, $country_sql);
			$country_row = $country_result->fetch_assoc(); 
			if ($country_row) {
				//if this country already exists, return the location_country_id for it.
				$country_id = $country_row['country_id'];
				
				return $country_id;    
			} else {
				//else if this  doesn't exist, insert the new countryrow, and return the_id for this new row we found 
				$country_sql = "INSERT INTO 202_locations_country SET country_code='".$mysql['country_code']."', country_name='".$mysql['country_name']."'";
				$country_result = _mysqli_query($db, $country_sql) ; //($ip_sql);
				$country_id = $db->insert_id;
				
				return $country_id;    
			}
		}
	}

	//this returns the location_city_id, when a City name is given
	function get_city_id($db, $city_name, $country_id) {
		
		global $memcacheWorking, $memcache;
		
		$mysql['city_name'] = $db->real_escape_string($city_name);
		$mysql['country_id'] = $db->real_escape_string($country_id);

		if ($memcacheWorking) {
			$time = 2592000; //30 days in sec
			//get from memcached
			$getID = $memcache->get(md5( "city-id" . $city_name . $country_id . systemHash()));

			if ($getID) {
				$city_id = $getID;
				return $city_id;
			} else {

				$city_sql = "SELECT city_id FROM 202_locations_city WHERE city_name='".$mysql['city_name']."' AND main_country_id='".$mysql['country_id']."'";
				$city_result = _mysqli_query($db, $city_sql);
				$city_row = $city_result->fetch_assoc(); 
				if ($city_row) {
					//if this ip_id already exists, return the ip_id for it.
					$city_id = $city_row['city_id'];
					//add to memcached
					$setID = $memcache->set( md5("city-id" . $city_name . $country_id . systemHash()), $city_id, false, $time );
					return $city_id;
				} else {
					//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
					$city_sql = "INSERT INTO 202_locations_city SET city_name='".$mysql['city_name']."', main_country_id='".$mysql['country_id']."'";
					$city_result = _mysqli_query($db, $city_sql) ; //($ip_sql);
					$city_id = $db->insert_id;
					//add to memcached
					$setID = $memcache->set( md5("city-id" . $city_name . $country_id . systemHash()), $city_id, false, $time );
					return $city_id;
				}    
			}
			
		} else {

			$city_sql = "SELECT city_id FROM 202_locations_city WHERE city_name='".$mysql['city_name']."' AND main_country_id='".$mysql['country_id']."'";
			$city_result = _mysqli_query($db, $city_sql);
			$city_row = $city_result->fetch_assoc(); 
			if ($city_row) {
				//if this country already exists, return the location_country_id for it.
				$city_id = $city_row['city_id'];
				
				return $city_id;    
			} else {
				//else if this  doesn't exist, insert the new cityrow, and return the_id for this new row we found 
				$city_sql = "INSERT INTO 202_locations_city SET city_name='".$mysql['city_name']."', main_country_id='".$mysql['country_id']."'";
				$city_result = _mysqli_query($db, $city_sql) ; //($ip_sql);
				$city_id = $db->insert_id;
				
				return $city_id;    
			}
		}
	}  

	//this returns the location_region_id, when a Region name is given
	function get_region_id($db, $region_name, $country_id) {
		
		global $memcacheWorking, $memcache;
		
		$mysql['region_name'] = $db->real_escape_string($region_name);
		$mysql['country_id'] = $db->real_escape_string($country_id);

		if ($memcacheWorking) {
			$time = 2592000; //30 days in sec
			//get from memcached
			$getID = $memcache->get(md5( "region-id" . $region_name . $country_id . systemHash()));

			if ($getID) {
				$region_id = $getID;
				return $region_id;
			} else {

				$region_sql = "SELECT region_id FROM 202_locations_region WHERE region_name='".$mysql['region_name']."' AND main_country_id='".$mysql['country_id']."'";
				$region_result = _mysqli_query($db, $region_sql);
				$region_row = $region_result->fetch_assoc(); 
				if ($region_row) {
					//if this ip_id already exists, return the ip_id for it.
					$region_id = $region_row['region_id'];
					//add to memcached
					$setID = $memcache->set( md5("region-id" . $region_name . $country_id . systemHash()), $region_id, false, $time );
					return $region_id;
				} else {
					//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
					$region_sql = "INSERT INTO 202_locations_region SET region_name='".$mysql['region_name']."', main_country_id='".$mysql['country_id']."'";
					$region_result = _mysqli_query($db, $region_sql) ; //($ip_sql);
					$region_id = $db->insert_id;
					//add to memcached
					$setID = $memcache->set( md5("region-id" . $region_name . $country_id . systemHash()), $region_id, false, $time );
					return $region_id;
				}    
			}
			
		} else {

			$region_sql = "SELECT region_id FROM 202_locations_region WHERE region_name='".$mysql['region_name']."' AND main_country_id='".$mysql['country_id']."'";
			$region_result = _mysqli_query($db, $region_sql);
			$region_row = $region_result->fetch_assoc(); 
			if ($region_row) {
				//if this country already exists, return the location_country_id for it.
				$region_id = $region_row['region_id'];
				
				return $region_id;    
			} else {
				//else if this  doesn't exist, insert the new cityrow, and return the_id for this new row we found 
				$region_sql = "INSERT INTO 202_locations_region SET region_name='".$mysql['region_name']."', main_country_id='".$mysql['country_id']."'";
				$region_result = _mysqli_query($db, $region_sql) ; //($ip_sql);
				$region_id = $db->insert_id;
				
				return $region_id;    
			}
		}
	}  

	//this returns the isp_id, when a isp name is given
	function get_isp_id($db, $isp) {
		
		global $memcacheWorking, $memcache;

		$mysql['isp'] = $db->real_escape_string($isp);

		if ($memcacheWorking) {
			$time = 604800; //7 days in sec
			//get from memcached
			$getID = $memcache->get(md5( "isp-id" . $isp . systemHash()));

			if ($getID) {
				$isp_id = $getID;
				return $isp_id;
			} else {

				$isp_sql = "SELECT isp_id FROM 202_locations_isp WHERE isp_name='".$mysql['isp']."'";
				$isp_result = _mysqli_query($db, $isp_sql);
				$isp_row = $isp_result->fetch_assoc(); 
				if ($isp_row) {
					//if this ip_id already exists, return the ip_id for it.
					$isp_id = $isp_row['isp_id'];
					//add to memcached
					$setID = $memcache->set( md5("isp-id" . $isp . systemHash()), $isp_id, false, $time );
					return $isp_id;
				} else {
					//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
					$isp_sql = "INSERT INTO 202_locations_isp SET isp_name='".$mysql['isp']."'";
					$isp_result = _mysqli_query($db, $isp_sql) ; //($isp_sql);
					$isp_id = $db->insert_id;
					//add to memcached
					$setID = $memcache->set( md5("isp-id" . $isp . systemHash()), $isp_id, false, $time );
					return $isp_id;
				}    
			}
			
		} else {

			$isp_sql = "SELECT isp_id FROM 202_locations_isp WHERE isp_name='".$mysql['isp']."'";
			$isp_result = _mysqli_query($db, $isp_sql);
			$isp_row = $isp_result->fetch_assoc(); 
			if ($isp_row) {
				//if this isp already exists, return the isp_id for it.
				$isp_id = $isp_row['isp_id'];
				
				return $isp_id;    
			} else {
				//else if this  doesn't exist, insert the new isp row, and return the_id for this new row we found 
				$isp_sql = "INSERT INTO 202_locations_isp SET isp_name='".$mysql['isp']."'";
				$isp_result = _mysqli_query($db, $isp_sql) ; //($isp_sql);
				$isp_id = $db->insert_id;
				
				return $isp_id;    
			}
		}
	}

	//this returns the ip_id, when a ip_address is given
	function get_ip_id($db, $ip_address) {
		
		global $memcacheWorking, $memcache;

		$mysql['ip_address'] = $db->real_escape_string($ip_address);

		if ($memcacheWorking) {
			$time = 604800; //7 days in sec
			//get from memcached
			$getID = $memcache->get(md5("ip-id" . $ip_address . systemHash()));

			if ($getID) {
				$ip_id = $getID;
				return $ip_id;
			} else {

				$ip_sql = "SELECT ip_id FROM 202_ips WHERE ip_address='".$mysql['ip_address']."'";
				$ip_result = _mysqli_query($db, $ip_sql);
				$ip_row = $ip_result->fetch_assoc(); 
				if ($ip_row) {
					//if this ip_id already exists, return the ip_id for it.
					$ip_id = $ip_row['ip_id'];
					//add to memcached
					$setID = $memcache->set( md5( "ip-id" . $ip_address . systemHash()), $ip_id, false, $time );
					return $ip_id;
				} else {
					//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
					$ip_sql = "INSERT INTO 202_ips SET ip_address='".$mysql['ip_address']."'";
					$ip_result = _mysqli_query($db, $ip_sql) ; //($ip_sql);
					$ip_id = $db->insert_id;
					//add to memcached
					$setID = $memcache->set( md5( "ip-id" . $ip_address . systemHash()), $ip_id, false, $time );
					return $ip_id;
				}    
			}
			
		} else {

			$ip_sql = "SELECT ip_id FROM 202_ips WHERE ip_address='".$mysql['ip_address']."'";
			$ip_result = _mysqli_query($db, $ip_sql);
			$ip_row = $ip_result->fetch_assoc(); 
			if ($ip_row) {
				//if this ip already exists, return the ip_id for it.
				$ip_id = $ip_row['ip_id'];
				
				return $ip_id;    
			} else {
				//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
				$ip_sql = "INSERT INTO 202_ips SET ip_address='".$mysql['ip_address']."'";
				$ip_result = _mysqli_query($db, $ip_sql) ; //($ip_sql);
				$ip_id = $db->insert_id;
				
				return $ip_id;   
			}
		}
	}   
	
	
	//this returns the site_domain_id, when a site_url_address is given
	function get_site_domain_id($db, $site_url_address) {

		global $memcacheWorking, $memcache;

		$parsed_url = @parse_url($site_url_address);
		$site_domain_host = $parsed_url['host'];
		$site_domain_host = str_replace('www.','',$site_domain_host);

		$mysql['site_domain_host'] = $db->real_escape_string($site_domain_host);

		//if a cached key is found for this lpip, redirect to that url
		if ($memcacheWorking) {
			$time = 2592000; //30 days in sec
			//get from memcached
			$getID = $memcache->get( md5("domain-id" . $site_domain_host . systemHash()));

			if ($getID) {
				$site_domain_id = $getID;
				return $site_domain_id;
			} else {

				$site_domain_sql = "SELECT site_domain_id FROM 202_site_domains WHERE site_domain_host='".$mysql['site_domain_host']."'"; 
				$site_domain_result = _mysqli_query($db, $site_domain_sql);
				$site_domain_row = $site_domain_result->fetch_assoc();
				if ($site_domain_row) {
					//if this site_domain_id already exists, return the site_domain_id for it.
					$site_domain_id = $site_domain_row['site_domain_id'];
					//add to memcached
					$setID = $memcache->set( md5("domain-id" . $site_domain_host . systemHash()), $site_domain_id, false, $time );
					return $site_domain_id;
				} else {
					//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
					$site_domain_sql = "INSERT INTO 202_site_domains SET site_domain_host='".$mysql['site_domain_host']."'"; 
					$site_domain_result = _mysqli_query($db, $site_domain_sql) ; //($site_domain_sql);
					$site_domain_id = $db->insert_id;
					//add to memcached
					$setID = $memcache->set( md5("domain-id" . $site_domain_host . systemHash()), $site_domain_id, false, $time );
					return $site_domain_id;
				}    
			}
			
		} else {

			$site_domain_sql = "SELECT site_domain_id FROM 202_site_domains WHERE site_domain_host='".$mysql['site_domain_host']."'"; 
			$site_domain_result = _mysqli_query($db, $site_domain_sql);
			$site_domain_row = $site_domain_result->fetch_assoc();
			if ($site_domain_row) {
				//if this site_domain_id already exists, return the site_domain_id for it.
				$site_domain_id = $site_domain_row['site_domain_id'];
				//add to memcached
				return $site_domain_id;    
			} else {
				//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
				$site_domain_sql = "INSERT INTO 202_site_domains SET site_domain_host='".$mysql['site_domain_host']."'"; 
				$site_domain_result = _mysqli_query($db, $site_domain_sql) ; //($site_domain_sql);
				$site_domain_id = $db->insert_id;
				return $site_domain_id;    
			}    

		}
	}

	//this returns the site_url_id, when a site_url_address is given
	function get_site_url_id($db, $site_url_address) { 
		
		global $memcacheWorking, $memcache;

		$site_domain_id = INDEXES::get_site_domain_id($db, $site_url_address); 

		$mysql['site_url_address'] = $db->real_escape_string($site_url_address);
		$mysql['site_domain_id'] = $db->real_escape_string($site_domain_id);

			if ($memcacheWorking) {
				$time = 604800; //7 days in sec
				//get from memcached
				$getURL = $memcache->get( md5("url-id" . $site_url_address . systemHash()));
				if ($getURL) {
					return $getURL;

				} else {

					$site_url_sql = "SELECT site_url_id FROM 202_site_urls WHERE site_url_address='".$mysql['site_url_address']."'";
					$site_url_result = _mysqli_query($db, $site_url_sql);
					$site_url_row = $site_url_result->fetch_assoc();
					if ($site_url_row) {
						//if this site_url_id already exists, return the site_url_id for it.
						$site_url_id = $site_url_row['site_url_id'];
						$setID = $memcache->set(md5("url-id" . $site_url_address . systemHash()), $site_url_id, false, $time);
						return $site_url_id;
					} else{

						$site_url_sql = "INSERT INTO 202_site_urls SET site_domain_id='".$mysql['site_domain_id']."', site_url_address='".$mysql['site_url_address']."'"; 
						$site_url_result = _mysqli_query($db, $site_url_sql) ; //($site_url_sql);
						$site_url_id = $db->insert_id;
						$setID = $memcache->set(md5("url-id" . $site_url_address . systemHash()), $site_url_id, false, $time);
						return $site_url_id;
					} 

				}

			} else {

				$site_url_sql = "SELECT site_url_id FROM 202_site_urls WHERE site_url_address='".$mysql['site_url_address']."'";
				$site_url_result = _mysqli_query($db, $site_url_sql);
				$site_url_row = $site_url_result->fetch_assoc();

				if ($site_url_row) {
					//if this site_url_id already exists, return the site_url_id for it.
					$site_url_id = $site_url_row['site_url_id'];
					return $site_url_id;
				} else{

					$site_url_sql = "INSERT INTO 202_site_urls SET site_domain_id='".$mysql['site_domain_id']."', site_url_address='".$mysql['site_url_address']."'"; 
					$site_url_result = _mysqli_query($db, $site_url_sql) ; //($site_url_sql);
					$site_url_id = $db->insert_id;
					return $site_url_id;

				}

			}
	}       
	
	
	//this returns the keyword_id
	function get_keyword_id($db, $keyword) {
		
		global $memcacheWorking, $memcache;

		//only grab the first 255 charactesr of keyword
		$keyword = substr($keyword, 0, 255);

		$mysql['keyword'] = $db->real_escape_string($keyword);
		
		if ($memcacheWorking) {
				//get from memcached
				$getKeyword = $memcache->get( md5("keyword-id" . $keyword . systemHash()));
				if ($getKeyword) {
					return $getKeyword;

				} else {

					$keyword_sql = "SELECT keyword_id FROM 202_keywords WHERE keyword='".$mysql['keyword']."'";
					$keyword_result = _mysqli_query($db, $keyword_sql);
					$keyword_row = $keyword_result->fetch_assoc();
					if ($keyword_row) {
						//if this already exists, return the id for it
						$keyword_id = $keyword_row['keyword_id'];
						$setID = $memcache->set(md5("keyword-id" . $keyword . systemHash()), $keyword_id, false);
						return $keyword_id;
					} else{

						$keyword_sql = "INSERT INTO 202_keywords SET keyword='".$mysql['keyword']."'";
						$keyword_result = _mysqli_query($db, $keyword_sql) ; //($keyword_sql);
						$keyword_id = $db->insert_id;
						$setID = $memcache->set(md5("keyword-id" . $keyword . systemHash()), $keyword_id, false);
						return $keyword_id;
					} 

				}

		} else {

				$keyword_sql = "SELECT keyword_id FROM 202_keywords WHERE keyword='".$mysql['keyword']."'";
				$keyword_result = _mysqli_query($db, $keyword_sql);
				$keyword_row = $keyword_result->fetch_assoc();
				if ($keyword_row) {
					//if this already exists, return the id for it
					$keyword_id = $keyword_row['keyword_id'];
					return $keyword_id;    
				} else {
					//else if this ip doesn't exist, insert the row and grab the id for it
					$keyword_sql = "INSERT INTO 202_keywords SET keyword='".$mysql['keyword']."'";
					$keyword_result = _mysqli_query($db, $keyword_sql) ; //($keyword_sql);
					$keyword_id = $db->insert_id;
					return $keyword_id;    
				}

		}
	}
	
	//this returns the c1 id
	function get_c1_id($db, $c1) {
		
		global $memcacheWorking, $memcache;

		//only grab the first 350 charactesr of c1
		$c1 = substr($c1, 0, 350);

		$mysql['c1'] = $db->real_escape_string($c1);
		
		if ($memcacheWorking) {
				//get from memcached
				$getc1 = $memcache->get( md5("c1-id" . $c1 . systemHash()));
				if ($getc1) {
					return $getc1;

				} else {

					$c1_sql = "SELECT c1_id FROM 202_tracking_c1 WHERE c1='".$mysql['c1']."'";
					$c1_result = _mysqli_query($db, $c1_sql);
					$c1_row = $c1_result->fetch_assoc();
					if ($c1_row) {
						//if this already exists, return the id for it
						$c1_id = $c1_row['c1_id'];
						$setID = $memcache->set(md5("c1-id" . $c1 . systemHash()), $c1_id, false);
						return $c1_id;
					} else{

						$c1_sql = "INSERT INTO 202_tracking_c1 SET c1='".$mysql['c1']."'";
						$c1_result = _mysqli_query($db, $c1_sql) ; //($c1_sql);
						$c1_id = $db->insert_id;
						$setID = $memcache->set(md5("c1-id" . $c1 . systemHash()), $c1_id, false);
						return $c1_id;
					} 

				}

		} else {

				$c1_sql = "SELECT c1_id FROM 202_tracking_c1 WHERE c1='".$mysql['c1']."'";
				$c1_result = _mysqli_query($db, $c1_sql);
				$c1_row = $c1_result->fetch_assoc();
				if ($c1_row) {
					//if this already exists, return the id for it
					$c1_id = $c1_row['c1_id'];
					return $c1_id;    
				} else {
					//else if this ip doesn't exist, insert the row and grab the id for it
					$c1_sql = "INSERT INTO 202_tracking_c1 SET c1='".$mysql['c1']."'";
					$c1_result = _mysqli_query($db, $c1_sql) ; //($c1_sql);
					$c1_id = $db->insert_id;
					return $c1_id;     
				}

		}
	}

	//this returns the c2 id
	function get_c2_id($db, $c2) {
		
		global $memcacheWorking, $memcache;

		//only grab the first 350 charactesr of c2
		$c2 = substr($c2, 0, 350);

		$mysql['c2'] = $db->real_escape_string($c2);

		if ($memcacheWorking) {
				//get from memcached
				$getc2 = $memcache->get( md5("c2-id" . $c2 . systemHash()));
				if ($getc2) {
					return $getc2;

				} else {

					$c2_sql = "SELECT c2_id FROM 202_tracking_c2 WHERE c2='".$mysql['c2']."'";
					$c2_result = _mysqli_query($db, $c2_sql);
					$c2_row = $c2_result->fetch_assoc();
					if ($c2_row) {
						//if this already exists, return the id for it
						$c2_id = $c2_row['c2_id'];
						$setID = $memcache->set(md5("c2-id" . $c2 . systemHash()), $c2_id, false);
						return $c2_id;
					} else{

						$c2_sql = "INSERT INTO 202_tracking_c2 SET c2='".$mysql['c2']."'";
						$c2_result = _mysqli_query($db, $c2_sql) ; //($c2_sql);
						$c2_id = $db->insert_id;
						$setID = $memcache->set(md5("c2-id" . $c2 . systemHash()), $c2_id, false);
						return $c2_id;
					} 

				}

		} else {

				$c2_sql = "SELECT c2_id FROM 202_tracking_c2 WHERE c2='".$mysql['c2']."'";
				$c2_result = _mysqli_query($db, $c2_sql);
				$c2_row = $c2_result->fetch_assoc();
				if ($c2_row) {
					//if this already exists, return the id for it
					$c2_id = $c2_row['c2_id'];
					return $c2_id;    
				} else {
					//else if this ip doesn't exist, insert the row and grab the id for it
					$c2_sql = "INSERT INTO 202_tracking_c2 SET c2='".$mysql['c2']."'";
					$c2_result = _mysqli_query($db, $c2_sql) ; //($c2_sql);
					$c2_id = $db->insert_id;
					return $c2_id;    
				}

		}
	}

	//this returns the c3 id
	function get_c3_id($db, $c3) {
		
		global $memcacheWorking, $memcache;

		//only grab the first 350 charactesr of c3
		$c3 = substr($c3, 0, 350);

		$mysql['c3'] = $db->real_escape_string($c3);
		
		if ($memcacheWorking) {
				//get from memcached
				$getc3 = $memcache->get( md5("c3-id" . $c3 . systemHash()));
				if ($getc3) {
					return $getc3;

				} else {

					$c3_sql = "SELECT c3_id FROM 202_tracking_c3 WHERE c3='".$mysql['c3']."'";
					$c3_result = _mysqli_query($db, $c3_sql);
					$c3_row = $c3_result->fetch_assoc();
					if ($c3_row) {
						//if this already exists, return the id for it
						$c3_id = $c3_row['c3_id'];
						$setID = $memcache->set(md5("c3-id" . $c3 . systemHash()), $c3_id, false);
						return $c3_id;
					} else{

						$c3_sql = "INSERT INTO 202_tracking_c3 SET c3='".$mysql['c3']."'";
						$c3_result = _mysqli_query($db, $c3_sql) ; //($c3_sql);
						$c3_id = $db->insert_id;
						$setID = $memcache->set(md5("c3-id" . $c3 . systemHash()), $c3_id, false);
						return $c3_id;
					} 

				}

		} else {

				$c3_sql = "SELECT c3_id FROM 202_tracking_c3 WHERE c3='".$mysql['c3']."'";
				$c3_result = _mysqli_query($db, $c3_sql);
				$c3_row = $c3_result->fetch_assoc();
				if ($c3_row) {
					//if this already exists, return the id for it
					$c3_id = $c3_row['c3_id'];
					return $c3_id;    
				} else {
					//else if this ip doesn't exist, insert the row and grab the id for it
					$c3_sql = "INSERT INTO 202_tracking_c3 SET c3='".$mysql['c3']."'";
					$c3_result = _mysqli_query($db, $c3_sql) ; //($c3_sql);
					$c3_id = $db->insert_id;
					return $c3_id;    
				}

		}
	}
	
	//this returns the c4 id
	function get_c4_id($db, $c4) {
		
		global $memcacheWorking, $memcache;

		//only grab the first 350 charactesr of c4
		$c4 = substr($c4, 0, 350);

		$mysql['c4'] = $db->real_escape_string($c4);
		
		if ($memcacheWorking) {
				//get from memcached
				$getc4 = $memcache->get( md5("c4-id" . $c4 . systemHash()));
				if ($getc4) {
					return $getc4;

				} else {

					$c4_sql = "SELECT c4_id FROM 202_tracking_c4 WHERE c4='".$mysql['c4']."'";
					$c4_result = _mysqli_query($db, $c4_sql);
					$c4_row = $c4_result->fetch_assoc();
					if ($c4_row) {
						//if this already exists, return the id for it
						$c4_id = $c4_row['c4_id'];
						$setID = $memcache->set(md5("c4-id" . $c4 . systemHash()), $c4_id, false);
						return $c4_id;
					} else{

						$c4_sql = "INSERT INTO 202_tracking_c4 SET c4='".$mysql['c4']."'";
						$c4_result = _mysqli_query($db, $c4_sql) ; //($c4_sql);
						$c4_id = $db->insert_id;
						$setID = $memcache->set(md5("c4-id" . $c4 . systemHash()), $c4_id, false);
						return $c4_id;
					} 

				}

		} else {

				$c4_sql = "SELECT c4_id FROM 202_tracking_c4 WHERE c4='".$mysql['c4']."'";
				$c4_result = _mysqli_query($db, $c4_sql);
				$c4_row = $c4_result->fetch_assoc();
				if ($c4_row) {
					//if this already exists, return the id for it
					$c4_id = $c4_row['c4_id'];
					return $c4_id;    
				} else {
					//else if this ip doesn't exist, insert the row and grab the id for it
					$c4_sql = "INSERT INTO 202_tracking_c4 SET c4='".$mysql['c4']."'";
					$c4_result = _mysqli_query($db, $c4_sql) ; //($c4_sql);
					$c4_id = $db->insert_id;
					return $c4_id;    
				}

		}
	}
	
}


//for the memcache functions, we want to make a function that will be able to store al the memcache keys for a specific user, so when they update it, we can clear out all the associated memcache keys for that user, so we need two functions one to record all the use memcache keys, and another to delete all those user memcahces keys, will associate it in an array and use the main user_id for the identifier.


function memcache_set_user_key($sql) { 

	if (AUTH::logged_in() == true) { 
	
		global $memcache;
	
		$sql = md5($sql);
		$user_id = $_SESSION['user_id'];
		
		$getCache = $memcache -> get(md5($user_id . systemHash()));
		
		$queries = explode(",",$getCache);
		
		if (!in_array( $sql, $queries ) ) {
		
			$queries[] = $sql;
		
		}
		
		$queries = implode(",", $queries);
		
		$setCache = $memcache -> set (md5($user_id, $queries . systemHash()), false);
		
	}	

}


function memcache_delete_user_keys() {

	/*global $memcache;

	$user_id = $_SESSION['user_id'];
	
	$queryKeys = explode(",", $memcache -> get($user_id));
	
	foreach ($queryKeys as $deletedKey) {
		if ($deletedKey != '') { 
			$memcache -> delete($deletedKey);
		}
	}*/

}


function memcache_mysql_fetch_assoc($db, $sql, $allowCaching = 1, $minutes = 5 ) {
	
	global $memcacheWorking, $memcache;

	if ($memcacheWorking == false) { 
		
		$result = _mysqli_query($db, $sql);
		$row = $result->fetch_assoc();
		return $row;
	} else {

		if( $allowCaching == 0 ) {
			$result = _mysqli_query($db, $sql);
			$row = $result->fetch_assoc();
			return $row;
		} else {

			// Check if its set
			$getCache = $memcache->get( md5( $sql . systemHash()));
			
			if( $getCache === false ) {
				// cache this data
				$result = _mysqli_query($db, $sql );
				$fetchArray = $result->fetch_assoc();
				$setCache = $memcache->set( md5( $sql . systemHash()), serialize( $fetchArray ), false, 60*$minutes  );
				
				//store all this users memcache keys, so we can delete them fast later on
				memcache_set_user_key($sql);
				
				return $fetchArray;
			
			} else {
			
				// Data Cached
				return unserialize( $getCache );
			}
		}
	}
}

function foreach_memcache_mysql_fetch_assoc($db, $sql, $allowCaching = 1 ) {
	 
	global $memcacheWorking, $memcache;
	
	if ($memcacheWorking == false) { 
		$row = array();
		$result = _mysqli_query($db, $sql) ; //($sql);
		while ($fetch = $result->fetch_assoc()) {
			$row[] = $fetch;
		}
		return $row;
	} else {

		if( $allowCaching == 0 ) {
			$row = array();
			$result = _mysqli_query($db, $sql) ; //($sql);
			while ($fetch = $result->fetch_assoc()) {
				$row[] = $fetch;
			}
			return $row;
		} else {
			
			$getCache = $memcache->get( md5( $sql . systemHash()));
			if( $getCache === false ) { 
				//if data is NOT cache, cache this data
				$row = array();     
				$result = _mysqli_query($db, $sql) ; //($sql);
				while ($fetch = $result->fetch_assoc()) {
					$row[] = $fetch;
				}
				$setCache = $memcache->set( md5( $sql . systemHash()), serialize( $row ), false, 60*5 );  
				
				//store all this users memcache keys, so we can delete them fast later on
				memcache_set_user_key($sql);
				
				return $row;
			} else {
				//if data is cached, returned the cache data Data Cached
				return unserialize( $getCache );
			}
		}
	}
}

function replaceTokens($url,$tokens= Array()) {
	
	$tokens = array_map('urlencode',$tokens);

    $url = preg_replace('/\[\[c1\]\]/i', $tokens['c1'], $url);
    $url = preg_replace('/\[\[c2\]\]/i', $tokens['c2'], $url);
    $url = preg_replace('/\[\[c3\]\]/i', $tokens['c3'], $url);
    $url = preg_replace('/\[\[c4\]\]/i', $tokens['c4'], $url);

	$url = preg_replace('/\[\[subid\]\]/i', $tokens['subid'], $url);
	$url = preg_replace('/\[\[t202kw\]\]/i', $tokens['t202kw'], $url);
	$url = preg_replace('/\[\[payout\]\]/i', $tokens['payout'], $url);
	$url = preg_replace('/\[\[random\]\]/i', $tokens['random'], $url);
	$url = preg_replace('/\[\[cpc\]\]/i', $tokens['cpc'], $url);
	$url = preg_replace('/\[\[cpc2\]\]/i', $tokens['cpc2'], $url);

	return $url;
}

function getGeoData($ip){

	global $GEOIP_REGION_NAME;
	
	$gi = geoip_open($_SERVER['DOCUMENT_ROOT']. "/202-config/geo/GeoLite.dat", GEOIP_STANDARD);

	$record = geoip_record_by_addr($gi, $ip);

	$country = $record->country_name;
	$country_code = $record->country_code;
	$city = $record->city;
	$region = $GEOIP_REGION_NAME[$record->country_code][$record->region];

	if ($record != "null") {
		if ($country == null) {
			$country = "Unknown country";
			$country_code = "non";
		}

		if ($city == null) {
			$city = "Unknown city";
		}

		if ($region == null) {
			$region = "Unknown region";
		}
	}

	$geoData = array('country' => $country, 'country_code' => $country_code, 'region' => $region, 'city' => $city);

	geoip_close($gi);
	
	return $geoData;
}

function getIspData($ip){
	$giisp = geoip_open($_SERVER['DOCUMENT_ROOT']. "/202-config/geo/GeoIPISP.dat", GEOIP_STANDARD);
	$isp = geoip_org_by_addr($giisp, $ip);

	if (!$isp) {
		$isp = "Unknown ISP/Carrier";
	}

	geoip_close($giisp);

	return $isp;
}

function systemHash(){
  $hash = hash('ripemd160', $_SERVER['HTTP_HOST'] . $_SERVER['SERVER_ADDR']);
  return $hash;
}

function setPCIdCookie($click_id_public) {
	setcookie('tracking202pci',$click_id_public,0,'/', $_SERVER['SERVER_NAME']);
}

function setOutboundCookie($outbound_site_url) {
	setcookie('tracking202outbound',$outbound_site_url,0,'/', $_SERVER['SERVER_NAME']);
}

function record_mysql_error($db, $sql) {

	global $server_row;
										 
	//record the mysql error
		$clean['mysql_error_text'] = mysqli_error($db); 

	//if on dev server, echo the error
		
			echo $sql . '<br/><br/>' .$clean['mysql_error_text'] .'<br/><br/>';   die();
	
		
		 
		$ip_id = INDEXES::get_ip_id($_SERVER['HTTP_X_FORWARDED_FOR']);
		$mysql['ip_id'] = $db->real_escape_string($ip_id);     
		
		$site_url = 'http://'.$_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		$site_id = INDEXES::get_site_url_id($site_url); 
		$mysql['site_id'] = $db->real_escape_string($site_id); 

		$mysql['user_id'] = $db->real_escape_string(strip_tags($_SESSION['user_id']));
		$mysql['mysql_error_text'] = $db->real_escape_string($clean['mysql_error_text']);
		$mysql['mysql_error_sql'] = $db->real_escape_string($sql);
		$mysql['script_url'] = $db->real_escape_string(strip_tags($_SERVER['SCRIPT_URL'])); 
		$mysql['server_name'] = $db->real_escape_string(strip_tags($_SERVER['SERVER_NAME'])); 
		$mysql['mysql_error_time'] = time();
									 
		$report_sql = "INSERT     INTO  202_mysql_errors
								SET     mysql_error_text='".$mysql['mysql_error_text']."',
										mysql_error_sql='".$mysql['mysql_error_sql']."',
										user_id='".$mysql['user_id']."',
										ip_id='".$mysql['ip_id']."',
										site_id='".$mysql['site_id']."',
										mysql_error_time='".$mysql['mysql_error_time']."'"; 
		$report_query = _mysqli_query($report_sql);
											 
	//email administration of the error
		$to = $_SERVER['SERVER_ADMIN'];
		$subject = 'mysql error reported - ' . $site_url;
		$message = '<b>A mysql error has been reported</b><br/><br/>
		
					time: '. date('r',time()) . '<br/>
					server_name: ' . $_SERVER['SERVER_NAME'] . '<br/><br/>
					
					user_id: ' . $_SESSION['user_id'] . '<br/>
					script_url: ' . $site_url . '<br/>
					$_SERVER: ' . serialize($_SERVER) . '<br/><br/>
					
					. . . . . . . . <br/><br/>
												 
					_mysqli_query: ' . $sql . '<br/><br/>
					 
					mysql_error: ' . $clean['mysql_error_text'];
		$from = $_SERVER['SERVER_ADMIN'];
		$type = 3; //type 3 is mysql_error
	
		//send_email($to,$subject,$message,$from,$type);
	
	//report error to user and end page ?>
		<div class="warning" style="margin: 40px auto; width: 450px;">
			<div>
				<h3>A database error has occured, the webmaster has been notified</h3>
				<p>If this error persists, you may email us directly: <?php printf('<a href="mailto:%s">%s</a>',$_SERVER['SERVER_ADMIN'],$_SERVER['SERVER_ADMIN']); ?></p>
			</div>
		</div>
		
	   
		<?php template_bottom($server_row);  die();
}
