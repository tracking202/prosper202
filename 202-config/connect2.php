<?php
use UAParser\Parser;
use GeoIp2\Database\Reader;

$version = '1.9.55';

$_GET = array_change_key_case($_GET, CASE_LOWER);
//fix for nginx with no server name set
if($_SERVER['SERVER_NAME']=='_'){
    $_SERVER['SERVER_NAME']=$_SERVER['HTTP_HOST'];
}

DEFINE('ROOT_PATH', substr(dirname( __FILE__ ), 0,-10));
DEFINE('CONFIG_PATH', dirname( __FILE__ ));
@ini_set('auto_detect_line_endings', TRUE);
@ini_set('register_globals', 0);
@ini_set('display_errors', 'On');
@ini_set('error_reporting', 6135);
@ini_set('safe_mode', 'Off');

mysqli_report(MYSQLI_REPORT_STRICT); 
include_once (ROOT_PATH . '/202-config.php');

$whatCache = false;

// try to connect to memcache server
if (extension_loaded('memcache')) {
    $whatCache = 'memcache';
    $memcacheInstalled = true;
    $memcache = new Memcache();
    if (@$memcache->connect($mchost, 11211))
        $memcacheWorking = true;
    else
        $memcacheWorking = false;
}
else
{
    if (extension_loaded('memcached')) {
        $whatCache = 'memcached';
        $memcacheInstalled = true;
        $memcache = new Memcached();
        if (@$memcache->addserver($mchost, 11211))
            $memcacheWorking = true;
        else
            $memcacheWorking = false;
    }
}

function setCache($key, $value, $exp = null) {
    global $whatCache, $memcache;
    switch ($whatCache) {
        case 'memcache':
            return $memcache->set($key, $value, false, $exp);
            break;

        case 'memcached':
            return $memcache->set($key, $value, $exp);
            break;
    }
}


include_once (CONFIG_PATH . '/geo/inc/geoipcity.inc');
include_once (CONFIG_PATH . '/geo/inc/geoipregionvars.php');
include_once (CONFIG_PATH . '/Mobile_Detect.php');
include_once (CONFIG_PATH . '/FraudDetectionIPQS.class.php');
require ROOT_PATH.'vendor/autoload.php';

//determine privacy mode
if($memcacheWorking){
    $_SESSION['privacy']=$memcache->get(md5('user_pref_privacy_'.$tid.systemHash()));
}

//exit strict mode
$user_sql = "SET session sql_mode= ''";
$user_results = $db->query($user_sql);


if(!isset($_SESSION['privacy'])){
    
    $user_sql = "	SELECT 	user_pref_privacy
				 FROM   	`202_users_pref`
				 WHERE  	`202_users_pref`.`user_id`='1'";
    
    $privacy= memcache_mysql_fetch_assoc($db, $user_sql);
    if (isset($privacy['user_pref_privacy'])){
        $_SESSION['privacy']=$privacy['user_pref_privacy'];
    }
    else{
        $_SESSION['privacy']='disabled'; //default to disabled
    }

}


// get the real ip
 switch(true){
      case (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) : $_SERVER['HTTP_X_FORWARDED_FOR'] = $_SERVER['HTTP_CF_CONNECTING_IP']; break;  
      case (!empty($_SERVER['HTTP_X_CLUSTER_CLIENT_IP'])) : $_SERVER['HTTP_X_FORWARDED_FOR'] = $_SERVER['HTTP_X_CLUSTER_CLIENT_IP']; break;
      case (!empty($_SERVER['HTTP_X_SUCURI_CLIENTIP'])) : $_SERVER['HTTP_X_FORWARDED_FOR'] = $_SERVER['HTTP_X_SUCURI_CLIENTIP']; break;
      case (!empty($_SERVER['HTTP_X_REAL_IP'])) : $_SERVER['HTTP_X_FORWARDED_FOR'] = $_SERVER['HTTP_X_REAL_IP']; break;
      case (!empty($_SERVER['HTTP_CLIENT_IP'])) : $_SERVER['HTTP_X_FORWARDED_FOR'] = $_SERVER['HTTP_CLIENT_IP']; break;
      case (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && ($_SERVER['SERVER_ADDR'] != $_SERVER['HTTP_X_FORWARDED_FOR'])) : $_SERVER['HTTP_X_FORWARDED_FOR'] = $_SERVER['HTTP_X_FORWARDED_FOR']; break;
            default : $_SERVER['HTTP_X_FORWARDED_FOR'] = $_SERVER['REMOTE_ADDR'];
    }
    
    $tempip= explode(",",$_SERVER['HTTP_X_FORWARDED_FOR']);
    $_SERVER['HTTP_X_FORWARDED_FOR']=trim($tempip[0]);
    $ip_address=ipAddress($_SERVER['HTTP_X_FORWARDED_FOR']);

function trackingEnabled(){
        $trackingEnabled=true;
    
        if($_SESSION['privacy']==='all' || ($_SESSION['privacy']==='eu' && $_SESSION['is_european_union'] )){
            $trackingEnabled = false;
        }
        
        return $trackingEnabled;    
}    
 
function _mysqli_query($db, $sql)
{
    $result = $db->query($sql) or record_mysql_error($db, $sql); //or die($db->error . '<br/><br/>' . $sql);
    return $result;
}

// our own die, that will display the them around the error message
function _die($message)
{
    echo $message;
    die();
}

// this funciton delays an SQL statement, puts in in a mysql table, to be cron jobbed out every 5 minutes
function delay_sql($db, $delayed_sql)
{
    $mysql['delayed_sql'] = str_replace("'", "''", $delayed_sql);
    $mysql['delayed_time'] = time();
    
    $delayed_sql = "INSERT INTO  202_delayed_sqls 
					
					(
						delayed_sql ,
						delayed_time
					)
					
					VALUES 
					(
						'" . $mysql['delayed_sql'] . "',
						'" . $mysql['delayed_time'] . "'
					);";
    
    $delayed_result = _mysqli_query($db, $delayed_sql); // ($delayed_sql);
}

class FILTER
{

    public static function startFilter($db, $click_id, $ip_id, $ip_address, $user_id)
    {
        
        // we only do the other checks, if the first ones have failed.
        // we will return the variable filter, if the $filter returns TRUE, when the click is inserted and recorded we will insert the new click already inserted,
        // what was lagign this query is before it would insert a click, then scan it and then update the click, the updating later on was lagging, now we will just insert and it will not stop the clicks from being redirected becuase of a slow update.
        
        // check the user
        $filter = FILTER::checkUserIP($db, $click_id, $ip_id, $user_id);
        if ($filter == false) {
            
            // check the netrange
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

    public static function checkUserIP($db, $click_id, $ip_id, $user_id)
    {
        // $user_id no longer needed
        
        $mysql['ip_id'] = $db->real_escape_string($ip_id);
        $mysql['user_id'] = $db->real_escape_string($user_id);
        
        $count_sql = "SELECT    user_id
					  FROM      202_users 
					  WHERE     user_last_login_ip_id='" . $mysql['ip_id'] . "'";
        $count_result = _mysqli_query($db, $count_sql); // ($count_sql);
                                                         
        // if the click_id's ip address, is the same ip adddress of the click_id's owner's last logged in ip, filter this. 
        if ($count_result->num_rows > 0) {
            
            return true;
        }
        return false;
    }

    public static function checkNetrange($click_id, $ip)
    {
        $ip_address = ip2long($ip->address);
        
        // check each netrange
        /* google1 */
        if (($ip_address >= 1208926208) and ($ip_address <= 1208942591)) {
            return true;
        }
        /* google2 */
        if (($ip_address >= 3512041472) and ($ip_address <= 3512074239)) {
            return true;
        }
        /* google3 */
        if (($ip_address >= 1123631104) and ($ip_address <= 1123639295)) {
            return true;
        }
        /* Google4 */
        if (($ip_address >= 1089052672) and ($ip_address <= 1089060863)) {
            return true;
        }
        /* google5 */
        if (($ip_address >= - 782925824) and ($ip_address <= - 782893057)) {
            return true;
        }
        
        /* level 3 communications */
        if (($ip_address >= 1094189056) and ($ip_address <= 1094451199)) {
            return true;
        }
        
        /* yahoo1 */
        if (($ip_address >= 3515031552) and ($ip_address <= 3515039743)) {
            return true;
        }
        /* Yahoo2 */
        if (($ip_address >= 3633393664) and ($ip_address <= 3633397759)) {
            return true;
        }
        /* Yahoo3 */
        if (($ip_address >= 3640418304) and ($ip_address <= 3640426495)) {
            return true;
        }
        /* Yahoo4 */
        if (($ip_address >= 1209925632) and ($ip_address <= 1209991167)) {
            return true;
        }
        /* Yahoo5 */
        if (($ip_address >= 1241907200) and ($ip_address <= 1241972735)) {
            return true;
        }
        
        /* Performance Systems International Inc. */
        if (($ip_address >= 637534208) and ($ip_address <= 654311423)) {
            return true;
        }
        /* Microsoft */
        if (($ip_address >= 3475898368) and ($ip_address <= 3475963903)) {
            return true;
        }
        /* MSN */
        if (($ip_address >= 1093926912) and ($ip_address <= 1094189055)) {
            return true;
        }
        
        // if it was none of theses, return false
        return false;
    }
    
    // this will filter out a click if it the IP WAS RECORDED, for a particular user within the last 24 hours, if it existed before, filter out this click.
    public static function checkLastIps($db, $user_id, $ip_id)
    {
        
        
        $mysql['user_id'] = $db->real_escape_string($user_id);
        $mysql['ip_id'] = $db->real_escape_string($ip_id);
        
        $check_sql = "SELECT * FROM 202_last_ips WHERE user_id='" . $mysql['user_id'] . "' AND ip_id='" . $mysql['ip_id'] . "'";
        $check_result = _mysqli_query($db, $check_sql); // ($check_sql);
        $check_row = $check_result->fetch_assoc();
        $count = $check_result->num_rows;
        
        if ($count > 0) {
            // if this ip has been seen within the last 24 hours, filter it out.
            return true;
        } else {
            
            // else if this ip has not been recorded, record it now
            $mysql['time'] = time();
            $insert_sql = "INSERT INTO 202_last_ips SET user_id='" . $mysql['user_id'] . "', ip_id='" . $mysql['ip_id'] . "', time='" . $mysql['time'] . "'";
            $insert_result = _mysqli_query($db, $insert_sql); // ($insert_sql);
            return false;
        }
    }
}

function rotateTrackerUrl($db, $tracker_row)
{
    
    
    if (! $tracker_row['aff_campaign_rotate'])
        return $tracker_row['aff_campaign_url'];
    
    $mysql['aff_campaign_id'] = $db->real_escape_string($tracker_row['aff_campaign_id']);
    $urls = array();
    array_push($urls, $tracker_row['aff_campaign_url']);
    
    if ($tracker_row['aff_campaign_url_2'])
        array_push($urls, $tracker_row['aff_campaign_url_2']);
    if ($tracker_row['aff_campaign_url_3'])
        array_push($urls, $tracker_row['aff_campaign_url_3']);
    if ($tracker_row['aff_campaign_url_4'])
        array_push($urls, $tracker_row['aff_campaign_url_4']);
    if ($tracker_row['aff_campaign_url_5'])
        array_push($urls, $tracker_row['aff_campaign_url_5']);
    
    $count = count($urls);
    
    $sql5 = "SELECT rotation_num FROM 202_rotations WHERE aff_campaign_id='" . $mysql['aff_campaign_id'] . "'";
    $result5 = _mysqli_query($db, $sql5);
    $row5 = $result5->fetch_assoc();
    if ($row5) {
        
        $old_num = $row5['rotation_num'];
        if ($old_num >= ($count - 1))
            $num = 0;
        else
            $num = $old_num + 1;
        
        $mysql['num'] = $db->real_escape_string($num);
        $sql5 = " UPDATE 202_rotations SET rotation_num='" . $mysql['num'] . "' WHERE aff_campaign_id='" . $mysql['aff_campaign_id'] . "'";
        $result5 = _mysqli_query($db, $sql5);
    } else {
        // insert the rotation
        $num = 0;
        $mysql['num'] = $db->real_escape_string($num);
        $sql5 = " INSERT INTO 202_rotations SET aff_campaign_id='" . $mysql['aff_campaign_id'] . "',  rotation_num='" . $mysql['num'] . "' ";
        $result5 = _mysqli_query($db, $sql5);
        $rotation_num = 0;
    }
    
    $url = $urls[$num];
    return $url;
}

function replaceTrackerPlaceholdersOpt($db, $url, $click_id, $mysql=array())
{
    
    // get the tracker placeholder values
    $mysql['click_id'] = $db->real_escape_string($click_id);
    //$url = preg_replace('/\[\[subid\]\]/i', $mysql['click_id'], $url);
    $tokens = array(
        "subid" => $mysql['click_id'],
        "t202kw" => $mysql['keyword'],
        "t202pubid" => $mysql['t202pubid'],
        "c1" => $mysql['c1'],
        "c2" => $mysql['c2'],
        "c3" => $mysql['c3'],
        "c4" => $mysql['c4'],
        "gclid" => $mysql['gclid'],
        "msclkid" => $mysql['msclkid'],
        "fbclid" => $mysql['fbclid'],
        "utm_source" => $mysql['utm_source'],
        "utm_medium" => $mysql['utm_medium'],
        "utm_campaign" => $mysql['utm_campaign'],
        "utm_term" => $mysql['utm_term'],
        "utm_content" => $mysql['utm_content'],
        "country" => $mysql['country'],
        "country_code" => $mysql['country_code'],
        "region" => $mysql['region'],
        "city" => $mysql['city'],
        "cpc" => round((float) $mysql['click_cpc'], 2),
        "cpc2" => $mysql['click_cpc'],
        "timestamp" => time(),
        "payout" => $mysql['click_payout'],
        "random" => mt_rand(1000000, 9999999),
        "referer" => $mysql['referer'],
        "sourceid" => $mysql['ppc_account']
    );

    $url = (replaceTokens($url, $tokens));

   
    return $url;
}

function replaceTrackerPlaceholders($db, $url, $click_id='', $mysql=array())
{

    // get the tracker placeholder values
   
    //$url = preg_replace('/\[\[subid\]\]/i', $mysql['click_id'], $url);
    
    if(isset($mysql) && $mysql != ''){
        $mysql['click_id'] = $db->real_escape_string($click_id);
        $tokens = @array(
        "subid" => $mysql['click_id'],
        "t202kw" => $mysql['keyword'],
        "t202pubid" => $mysql['public_pub_id'],
        "c1" => $mysql['c1'],
        "c2" => $mysql['c2'],
        "c3" => $mysql['c3'],
        "c4" => $mysql['c4'],
        "gclid" => $mysql['gclid'],
        "msclkid" => $mysql['msclkid'],
        "fbclid" => $mysql['fbclid'],
        "utm_source" => $mysql['utm_source'],
        "utm_medium" => $mysql['utm_medium'],
        "utm_campaign" => $mysql['utm_campaign'],
        "utm_term" => $mysql['utm_term'],
        "utm_content" => $mysql['utm_content'],
        "country" => $mysql['country'],
        "country_code" => $mysql['country_code'],
        "region" => $mysql['region'],
        "city" => $mysql['city'],
        "cpc" => round((float) $mysql['click_cpc'], 2),
        "cpc2" => $mysql['click_cpc'],
        'cpa' => round((float) $mysql['click_cpa'], 2),
        "timestamp" => time(),
        "payout" => $mysql['click_payout'],
        "random" => mt_rand(1000000, 9999999),
        "referer" => $mysql['referer'],
        "sourceid" => $mysql['ppc_account']
    );
        $url = (replaceTokens($url, $tokens));
    }

    
    if (preg_match('/\[\[(.*)\]\]/', $url)) {
        $click_sql = "
			SELECT 2c.click_id, 
                2tc1.c1, 
                2tc2.c2, 
                2tc3.c3, 
                2tc4.c4,	
                2kw.keyword,
            	2c.click_payout,
            	2c.click_cpc,
            	2trk.click_cpa,
                2c.ppc_account_id,
            	2g.gclid,
                2b.msclkid,
                2f.fbclid,
            	2us.utm_source,
            	2um.utm_medium,
            	2uca.utm_campaign,
            	2ut.utm_term,
            	2uco.utm_content,
		        2lc.country_name,
		        2lc.country_code,
		        2lr.region_name,
                2u.user_public_publisher_id,
		        2lc2.city_name FROM 202_clicks AS 2c 
            	LEFT JOIN `202_clicks_advance` AS 2ca USING (`click_id`) 
            	LEFT OUTER JOIN 202_clicks_tracking AS 2ct ON (2ct.click_id = 2c.click_id) 
            	LEFT OUTER JOIN 202_tracking_c1 AS 2tc1 ON (2ct.c1_id = 2tc1.c1_id) 
            	LEFT OUTER JOIN 202_tracking_c2 AS 2tc2 ON (2ct.c2_id = 2tc2.c2_id) 
            	LEFT OUTER JOIN 202_tracking_c3 AS 2tc3 ON (2ct.c3_id = 2tc3.c3_id) 
            	LEFT OUTER JOIN 202_tracking_c4 AS 2tc4 ON (2ct.c4_id = 2tc4.c4_id) 
                LEFT OUTER JOIN 202_cpa_trackers AS 2cpa ON (2cpa.click_id = 2c.click_id)
                LEFT OUTER JOIN 202_trackers AS 2trk ON (2trk.tracker_id_public = 2cpa.tracker_id_public)
            	LEFT JOIN `202_google` AS 2g on (2g.click_id=2c.click_id)
                LEFT JOIN `202_bing` AS 2b on (2b.click_id=2c.click_id)
                LEFT JOIN `202_facebook` AS 2f on (2f.click_id=2c.click_id) 
                LEFT JOIN `202_utm_source` AS 2us ON (2g.utm_source_id = 2us.utm_source_id)
                LEFT JOIN `202_utm_medium` AS 2um ON (2g.utm_medium_id = 2um.utm_medium_id)
                LEFT JOIN `202_utm_campaign` AS 2uca ON (2g.utm_campaign_id = 2uca.utm_campaign_id) 
                LEFT JOIN `202_utm_term` AS 2ut ON (2g.utm_term_id = 2ut.utm_term_id)
                LEFT JOIN `202_utm_content` AS 2uco ON (2g.utm_content_id = 2uco.utm_content_id)
                LEFT JOIN `202_keywords` AS 2kw ON (2ca.`keyword_id` = 2kw.`keyword_id`) 
		        LEFT JOIN `202_locations_country` AS 2lc ON (2ca.`country_id` = 2lc.`country_id`)
		        LEFT JOIN `202_locations_region` AS 2lr ON (2ca.`region_id` = 2lr.`region_id`)    
		        LEFT JOIN `202_locations_city` AS 2lc2 ON (2ca.`city_id` = 2lc2.`city_id`)
                LEFT JOIN `202_users` AS 2u ON (2u.`user_id` = 2c.`user_id`)
		    WHERE
				2c.click_id='" . $mysql['click_id'] . "'
		";
        
        $click_result = _mysqli_query($db, $click_sql);
        $click_row = $click_result->fetch_assoc();

        $mysql['t202kw'] = $db->real_escape_string($click_row['keyword']);
        $mysql['t202pubid'] = $db->real_escape_string($click_row['user_public_publisher_id']);
        $mysql['c1'] = $db->real_escape_string($click_row['c1']);
        $mysql['c2'] = $db->real_escape_string($click_row['c2']);
        $mysql['c3'] = $db->real_escape_string($click_row['c3']);
        $mysql['c4'] = $db->real_escape_string($click_row['c4']);
        $mysql['gclid'] = $db->real_escape_string($click_row['gclid']);
        $mysql['msclkid'] = $db->real_escape_string($click_row['msclkid']);
        $mysql['fbclid'] = $db->real_escape_string($click_row['fbclid']);
        $mysql['utm_source'] = $db->real_escape_string($click_row['utm_source']);
        $mysql['utm_medium'] = $db->real_escape_string($click_row['utm_medium']);
        $mysql['utm_campaign'] = $db->real_escape_string($click_row['utm_campaign']);
        $mysql['utm_term'] = $db->real_escape_string($click_row['utm_term']);
        $mysql['utm_content'] = $db->real_escape_string($click_row['utm_content']);
        $mysql['payout'] = $db->real_escape_string($click_row['click_payout']);
        $mysql['cpc'] = $db->real_escape_string($click_row['click_cpc']);
        $mysql['cpa'] = $db->real_escape_string($click_row['click_cpa']);
        $mysql['click_cpc'] = $db->real_escape_string($click_row['click_cpc']);
        $mysql['country'] = $db->real_escape_string($click_row['country_name']);
        $mysql['country_code'] = $db->real_escape_string($click_row['country_code']);
        $mysql['region'] = $db->real_escape_string($click_row['region_name']);
        $mysql['city'] = $db->real_escape_string($click_row['city_name']);
        $mysql['referer'] = urlencode($db->real_escape_string($_SERVER['HTTP_REFERER']));
        if( $db->real_escape_string($click_row['ppc_account_id']) == '0'){
            $mysql['ppc_account'] = '';    
        }
        else{
            $mysql['ppc_account'] = $db->real_escape_string($click_row['ppc_account_id']);
        }
        
        //prepare $mysql to make sure none of the keys are unset

        $_202keys = Array('click_id','t202kw','t202pubid','c1','c2','c3','c4','gclid','msclkid','fbclid','utm_source','utm_medium','utm_campaign','utm_term','utm_content','country','country_code','region','city','cpc','cpc','cpa','click_payout','referer','ppc_account');

        foreach ($_202keys as $key) {
            if (!isset( $mysql[$key])){
                $mysql[$key]=''; 
            }
        }
        
        $tokens = array(
            "subid" => $mysql['click_id'],
            "t202kw" => $mysql['t202kw'],
            "t202pubid" => $mysql['t202pubid'],
            "c1" => $mysql['c1'],
            "c2" => $mysql['c2'],
            "c3" => $mysql['c3'],
            "c4" => $mysql['c4'],
            "gclid" => $mysql['gclid'],
            "msclkid" => $mysql['msclkid'],
            "fbclid" => $mysql['fbclid'],
            "utm_source" => $mysql['utm_source'],
            "utm_medium" => $mysql['utm_medium'],
            "utm_campaign" => $mysql['utm_campaign'],
            "utm_term" => $mysql['utm_term'],
            "utm_content" => $mysql['utm_content'],
            "country" => $mysql['country'],
            "country_code" => $mysql['country_code'],
            "region" => $mysql['region'],
            "city" => $mysql['city'],
            "cpc" => round($mysql['cpc'], 2),
            "cpc2" => $mysql['cpc'],
            "cpa" => round($mysql['cpa'], 2),
           // "timestamp" => time(), don't change the time it was already set
            "payout" => $mysql['click_payout'],
            "random" => mt_rand(1000000, 9999999),
            "referer" => $mysql['referer'],
            "sourceid" => $mysql['ppc_account']
        );
        
        $url = (replaceTokens($url, $tokens, 1)); //call replace tokens and allow it to fill all unset tokens with blanks
    }
    return $url;
}

function setClickIdCookie($click_id, $campaign_id = 0)
{
    if(trackingEnabled()){
        //set the cookie for the PIXEL to fire, expire in 30 days
        $expire = time() + (60 *  60 * 24 * 30);
        $expire_header = 60 *  60 * 24 * 30;
        $path = '/';
        $domain = $_SERVER['HTTP_HOST'];
        $secure = TRUE;
        $httponly = FALSE;
        
        //legacy cookies
        
        setcookie('tracking202subid-legacy', $click_id, $expire, '/', $domain);
        setcookie('tracking202subid_a_' . $campaign_id.'-legacy', $click_id, $expire, '/', $domain);
        
        //samesite=none secure cookies
        if (PHP_VERSION_ID < 70300) {
            header('Set-Cookie: tracking202subid='.$click_id.';max-age='.$expire_header.';Path=/;Domain='.$domain.';SameSite=None; Secure');
           header('Set-Cookie: tracking202subid_a_' . $campaign_id.'='.$click_id.'; max-age='.$expire_header.';Path=/;Domain='.$domain.';SameSite=None; Secure'); 
        }
        else {
            setcookie('tracking202subid', $click_id,  ['expires' => $expire,'path' => '/','domain' => $domain,'secure' => $secure,'httponly' => $httponly,'samesite' => 'None']);
            setcookie('tracking202subid_a_' . $campaign_id, $click_id,   ['expires' => $expire,'path' => '/','domain' => $domain,'secure' => $secure,'httponly' => $httponly,'samesite' => 'None']);
        }
        
    }    
}

function setClickIdCookieForLp($click_id_public, $lp_public_id)
{   
    if(trackingEnabled()){
         //set the cookie for the PIXEL to fire, expire in 30 days
         $expire = time() + (60 *  60 * 24 * 30);
         $expire_header = 60 *  60 * 24 * 30;
         $path = '/';
         $domain = $_SERVER['HTTP_HOST'];
         $secure = TRUE;
         $httponly = FALSE;
         
        
         //legacy cookies
        setcookie('tracking202rlp_' . $lp_public_id.'-legacy', $click_id_public, $expire, '/', $domain);
  
        //samesite=none secure cookies
        if (PHP_VERSION_ID < 70300) {
            header('Set-Cookie: tracking202rlp_' . $lp_public_id.'='.$click_id_public.';max-age='.$expire_header.';Path=/;Domain='.$domain.';SameSite=None; Secure');        
        }
        else {
            setcookie('tracking202rlp_' . $lp_public_id, $click_id_public, ['expires' => $expire,'path' => '/','domain' => $domain,'secure' => $secure,'httponly' => $httponly,'samesite' => 'None']);
        }
    }        
}

class PLATFORMS
{

    public static function get_device_info($db, $detect, $ua_string = '')
    {
        global $memcacheWorking, $memcache;
        $detect = new Mobile_Detect();
        
        if ($ua_string != '')
            $ua = $detect->setUserAgent($ua_string);
        else
            $ua = $detect->getUserAgent();
            
            // If Cache working
        if ($memcacheWorking) {
            
            $device_info = $memcache->get(md5("user-agent" . $ua . systemHash()));
            
            if (! $device_info) {
                
                $parse_info = PLATFORMS::parseUserAgentInfo($db, $detect);
                setCache(md5("user-agent" . $ua . systemHash()), $parse_info);
                return $parse_info;
            } else {
                return $device_info;
            }
        }         

        // If Cache is not working
        else {
            
            return PLATFORMS::parseUserAgentInfo($db, $detect);
        }
    }

    public static function parseUserAgentInfo($db, $detect)
    {
        
        
        $parser = Parser::create();
        $result = $parser->parse($detect->getUserAgent());
        
        // If is not mobile or tablet
        if (! $detect->isMobile() && ! $detect->isTablet()) {
            
            switch ($result->device->family) {
                // Is Bot
                case 'Bot':
                    $type = "4";
                    $result->device->family = "Bot";
                    break;
                // Is Desktop
                case 'Other':
                    $type = "1";
                    $result->device->family = "Desktop";
                    break;
            }
        } else {
            // If tablet
            if ($detect->isTablet()) {
                $type = "3";
                // If mobile
            } else {
                $type = "2";
            }
        }
        
        if (PLATFORMS::botCheck($ip_address)) {
            $type = "4";
            $result->device->family = "Bot";
        }
        
        // Select from DB and return ID's
        $mysql['browser'] = $db->real_escape_string($result->ua->family);
        $mysql['platform'] = $db->real_escape_string($result->os->family);
        $mysql['device'] = $db->real_escape_string($result->device->family);
        $mysql['device_type'] = $db->real_escape_string($type);
        
        
        
        // Get browser ID
        $browser_sql = "SELECT browser_id FROM 202_browsers WHERE browser_name='" . $mysql['browser'] . "'";
        $browser_result = _mysqli_query($db, $browser_sql);
        $browser_row = $browser_result->fetch_assoc();
        if ($browser_row) {
            $browser_id = $browser_row['browser_id'];
        } else {
            $browser_sql = "INSERT INTO 202_browsers SET browser_name='" . $mysql['browser'] . "'";
            $browser_result = _mysqli_query($db, $browser_sql);
            $browser_id = $db->insert_id;
        }
        
        // Get platform ID
        $platform_sql = "SELECT platform_id FROM 202_platforms WHERE platform_name='" . $mysql['platform'] . "'";
        $platform_result = _mysqli_query($db, $platform_sql);
        $platform_row = $platform_result->fetch_assoc();
        if ($platform_row) {
            $platform_id = $platform_row['platform_id'];
        } else {
            $platform_sql = "INSERT INTO 202_platforms SET platform_name='" . $mysql['platform'] . "'";
            $platform_result = _mysqli_query($db, $platform_sql);
            $platform_id = $db->insert_id;
        }
        
        // Get device model ID
        $device_sql = "SELECT device_id, device_type FROM 202_device_models WHERE device_name='" . $mysql['device'] . "'";
        $device_result = _mysqli_query($db, $device_sql);
        $device_row = $device_result->fetch_assoc();
        if ($device_row) {
            $device_id = $device_row['device_id'];
            $device_type = $device_row['device_type'];
        } else {
            $device_sql = "INSERT INTO 202_device_models SET device_name='" . $mysql['device'] . "', device_type='" . $mysql['device_type'] . "'";
            $device_result = _mysqli_query($db, $device_sql);
            $device_id = $db->insert_id;
            $device_type = $type;
        }
        
        $data = array(
            'browser' => $browser_id,
            'platform' => $platform_id,
            'device' => $device_id,
            'type' => $device_type
        );
  
        return $data;
    }

    public static function botCheck($ip)
    {
        global $memcacheWorking, $memcache;
        
        if ($memcacheWorking) {
            $getFromCache = $memcache->get(md5("ip-bot" . $ip->address . systemHash()));
        } else {
            $getFromCache = false;
        }
        
        if (!$getFromCache) {

            $ranges = array(
                '199.60.28.0/24',
                '199.103.122.0/24',
                '192.197.157.0/24',
                '207.68.128.0/18',
                '157.54.0.0/15',
                '157.56.0.0/14',
                '157.60.0.0/16',
                '70.32.128.0/19',
                '172.253.0.0/16',
                '173.194.0.0/16',
                '209.85.128.0/17',
                '72.14.192.0/18',
                '66.249.64.0/19',
                '108.177.0.0/17',
                '64.233.160.0/19',
                '66.102.0.0/20',
                '216.239.32.0/19',
                '203.208.60.0/24',
                '66.249.64.0/19',
                '72.14.199.0/24',
                '209.85.238.0/24',
                '204.236.235.245',
                '75.101.186.145',
                '31.13.97.0/24',
                '31.13.99.0/24',
                '31.13.100.0/24',
                '66.220.144.0/20',
                '69.63.189.0/24',
                '69.63.190.0/24',
                '69.171.224.0/20',
                '69.171.240.0/21',
                '69.171.248.0/24',
                '173.252.73.0/24',
                '173.252.74.0/24',
                '173.252.77.0/24',
                '173.252.100.0/22',
                '173.252.104.0/21',
                '173.252.112.0/24',
                '17.0.0.0/8',
                '157.55.39.0/24'
            );
            
            foreach ($ranges as $key => $value) {
                if (PLATFORMS::check_ip_range($ip, $value)) {
                    if ($memcacheWorking) {
                        setCache(md5("ip-bot" . $ip->address . systemHash()), true);
                    }
                    
                    return true;
                }
            }

            return false;
        }
        
        return true;
    }

    public static function check_ip_range($ip, $range) {
        if (strpos($range, '/') == false) {
            $range .= '/32';
        }
        list($range, $netmask) = explode('/', $range, 2);
        $range_decimal = ip2long($range);
        $ip_decimal = ip2long($ip->address);
        $wildcard_decimal = pow(2, (32 - $netmask)) - 1;
        $netmask_decimal = ~ $wildcard_decimal;
        return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
    }
}

class INDEXES
{
    
    // this returns the location_country_id, when a Country Code is given
    public static function get_country_id($db, $country_name, $country_code)
    {
        global $memcacheWorking, $memcache;
        
        $mysql['country_name'] = $db->real_escape_string($country_name);
        $mysql['country_code'] = $db->real_escape_string($country_code);
        
        if ($memcacheWorking) {
            $time = 2592000; // 30 days in sec
                             // get from memcached
            $getID = $memcache->get(md5("country-id" . $country_name . systemHash()));
            
            if ($getID) {
                $country_id = $getID;
                return $country_id;
            } else {
                
                $country_sql = "SELECT country_id FROM 202_locations_country WHERE country_code='" . $mysql['country_code'] . "'";
                $country_result = _mysqli_query($db, $country_sql);
                $country_row = $country_result->fetch_assoc();
                if ($country_row) {
                    // if this ip_id already exists, return the ip_id for it.
                    $country_id = $country_row['country_id'];
                    // add to memcached
                    $setID = setCache(md5("country-id" . $country_name . systemHash()), $country_id, $time);
                    return $country_id;
                } else {
                    // else if this doesn't exist, insert the new iprow, and return the_id for this new row we found
                    $country_sql = "INSERT INTO 202_locations_country SET country_code='" . $mysql['country_code'] . "', country_name='" . $mysql['country_name'] . "'";
                    $country_result = _mysqli_query($db, $country_sql); // ($ip_sql);
                    $country_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("country-id" . $country_name . systemHash()), $country_id, $time);
                    return $country_id;
                }
            }
        } else {
            
            $country_sql = "SELECT country_id FROM 202_locations_country WHERE country_code='" . $mysql['country_code'] . "'";
            
            $country_result = _mysqli_query($db, $country_sql);
            $country_row = $country_result->fetch_assoc();
            if ($country_row) {
                // if this country already exists, return the location_country_id for it.
                $country_id = $country_row['country_id'];
                
                return $country_id;
            } else {
                // else if this doesn't exist, insert the new countryrow, and return the_id for this new row we found
                $country_sql = "INSERT INTO 202_locations_country SET country_code='" . $mysql['country_code'] . "', country_name='" . $mysql['country_name'] . "'";
                $country_result = _mysqli_query($db, $country_sql); // ($ip_sql);
                $country_id = $db->insert_id;
                
                return $country_id;
            }
        }
    }
    
    // this returns the location_city_id, when a City name is given
    public static function get_city_id($db, $city_name, $country_id)
    {
        global $memcacheWorking, $memcache;
        
        $mysql['city_name'] = $db->real_escape_string($city_name);
        $mysql['country_id'] = $db->real_escape_string($country_id);
        
        if ($memcacheWorking) {
            $time = 2592000; // 30 days in sec
                             // get from memcached
            $getID = $memcache->get(md5("city-id" . $city_name . $country_id . systemHash()));
            
            if ($getID) {
                $city_id = $getID;
                return $city_id;
            } else {
                
                $city_sql = "SELECT city_id FROM 202_locations_city WHERE city_name='" . $mysql['city_name'] . "' AND main_country_id='" . $mysql['country_id'] . "'";
                $city_result = _mysqli_query($db, $city_sql);
                $city_row = $city_result->fetch_assoc();
                if ($city_row) {
                    // if this ip_id already exists, return the ip_id for it.
                    $city_id = $city_row['city_id'];
                    // add to memcached
                    $setID = setCache(md5("city-id" . $city_name . $country_id . systemHash()), $city_id, $time);
                    return $city_id;
                } else {
                    // else if this doesn't exist, insert the new iprow, and return the_id for this new row we found
                    $city_sql = "INSERT INTO 202_locations_city SET city_name='" . $mysql['city_name'] . "', main_country_id='" . $mysql['country_id'] . "'";
                    $city_result = _mysqli_query($db, $city_sql); // ($ip_sql);
                    $city_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("city-id" . $city_name . $country_id . systemHash()), $city_id, $time);
                    return $city_id;
                }
            }
        } else {
            
            $city_sql = "SELECT city_id FROM 202_locations_city WHERE city_name='" . $mysql['city_name'] . "' AND main_country_id='" . $mysql['country_id'] . "'";
            $city_result = _mysqli_query($db, $city_sql);
            $city_row = $city_result->fetch_assoc();
            if ($city_row) {
                // if this country already exists, return the location_country_id for it.
                $city_id = $city_row['city_id'];
                
                return $city_id;
            } else {
                // else if this doesn't exist, insert the new cityrow, and return the_id for this new row we found
                $city_sql = "INSERT INTO 202_locations_city SET city_name='" . $mysql['city_name'] . "', main_country_id='" . $mysql['country_id'] . "'";
                $city_result = _mysqli_query($db, $city_sql); // ($ip_sql);
                $city_id = $db->insert_id;
                
                return $city_id;
            }
        }
    }
    
    // this returns the location_region_id, when a Region name is given
    public static function get_region_id($db, $region_name, $country_id)
    {
        global $memcacheWorking, $memcache;
        
        $mysql['region_name'] = $db->real_escape_string($region_name);
        $mysql['country_id'] = $db->real_escape_string($country_id);
        
        if ($memcacheWorking) {
            $time = 2592000; // 30 days in sec
                             // get from memcached
            $getID = $memcache->get(md5("region-id" . $region_name . $country_id . systemHash()));
            
            if ($getID) {
                $region_id = $getID;
                return $region_id;
            } else {
                
                $region_sql = "SELECT region_id FROM 202_locations_region WHERE region_name='" . $mysql['region_name'] . "' AND main_country_id='" . $mysql['country_id'] . "'";
                $region_result = _mysqli_query($db, $region_sql);
                $region_row = $region_result->fetch_assoc();
                if ($region_row) {
                    // if this ip_id already exists, return the ip_id for it.
                    $region_id = $region_row['region_id'];
                    // add to memcached
                    $setID = setCache(md5("region-id" . $region_name . $country_id . systemHash()), $region_id, $time);
                    return $region_id;
                } else {
                    // else if this doesn't exist, insert the new iprow, and return the_id for this new row we found
                    $region_sql = "INSERT INTO 202_locations_region SET region_name='" . $mysql['region_name'] . "', main_country_id='" . $mysql['country_id'] . "'";
                    $region_result = _mysqli_query($db, $region_sql); // ($ip_sql);
                    $region_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("region-id" . $region_name . $country_id . systemHash()), $region_id, $time);
                    return $region_id;
                }
            }
        } else {
            
            $region_sql = "SELECT region_id FROM 202_locations_region WHERE region_name='" . $mysql['region_name'] . "' AND main_country_id='" . $mysql['country_id'] . "'";
            $region_result = _mysqli_query($db, $region_sql);
            $region_row = $region_result->fetch_assoc();
            if ($region_row) {
                // if this country already exists, return the location_country_id for it.
                $region_id = $region_row['region_id'];
                
                return $region_id;
            } else {
                // else if this doesn't exist, insert the new cityrow, and return the_id for this new row we found
                $region_sql = "INSERT INTO 202_locations_region SET region_name='" . $mysql['region_name'] . "', main_country_id='" . $mysql['country_id'] . "'";
                $region_result = _mysqli_query($db, $region_sql); // ($ip_sql);
                $region_id = $db->insert_id;
                
                return $region_id;
            }
        }
    }
    
    // this returns the isp_id, when a isp name is given
    public static function get_isp_id($db, $isp)
    {
        global $memcacheWorking, $memcache;
        
        $mysql['isp'] = $db->real_escape_string($isp);
        
        if ($memcacheWorking) {
            $time = 2592000; // 30 days in sec
                            // get from memcached
            $getID = $memcache->get(md5("isp-id" . $isp . systemHash()));
            
            if ($getID) {
                $isp_id = $getID;
                return $isp_id;
            } else {
                
                $isp_sql = "SELECT isp_id FROM 202_locations_isp WHERE isp_name='" . $mysql['isp'] . "'";
                $isp_result = _mysqli_query($db, $isp_sql);
                $isp_row = $isp_result->fetch_assoc();
                if ($isp_row) {
                    // if this ip_id already exists, return the ip_id for it.
                    $isp_id = $isp_row['isp_id'];
                    // add to memcached
                    $setID = setCache(md5("isp-id" . $isp . systemHash()), $isp_id, $time);
                    return $isp_id;
                } else {
                    // else if this doesn't exist, insert the new iprow, and return the_id for this new row we found
                    $isp_sql = "INSERT INTO 202_locations_isp SET isp_name='" . $mysql['isp'] . "'";
                    $isp_result = _mysqli_query($db, $isp_sql); // ($isp_sql);
                    $isp_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("isp-id" . $isp . systemHash()), $isp_id, $time);
                    return $isp_id;
                }
            }
        } else {
            
            $isp_sql = "SELECT isp_id FROM 202_locations_isp WHERE isp_name='" . $mysql['isp'] . "'";
            $isp_result = _mysqli_query($db, $isp_sql);
            $isp_row = $isp_result->fetch_assoc();
            if ($isp_row) {
                // if this isp already exists, return the isp_id for it.
                $isp_id = $isp_row['isp_id'];
                
                return $isp_id;
            } else {
                // else if this doesn't exist, insert the new isp row, and return the_id for this new row we found
                $isp_sql = "INSERT INTO 202_locations_isp SET isp_name='" . $mysql['isp'] . "'";
                $isp_result = _mysqli_query($db, $isp_sql); // ($isp_sql);
                $isp_id = $db->insert_id;
                
                return $isp_id;
            }
        }
    }
    
    // this returns the ip_id, when a ip_address is given
    public static function get_ip_id($db, $ip)
    {
        global $memcacheWorking, $memcache;
        
        $mysql['ip_address'] = $db->real_escape_string($ip->address);
        
        if($ip->type == 'ipv6'){
            $mysql['ip_address'] = $db->real_escape_string(inet6_aton($mysql['ip_address'])); //encode ipv6 for db insert
        }
        
        if ($ip->type==='ipv6') {
            $ip_sql = 'SELECT 202_ips.ip_id FROM 202_ips_v6  INNER JOIN 202_ips on (202_ips_v6.ip_id = 202_ips.ip_address COLLATE utf8mb4_general_ci) WHERE 202_ips_v6.ip_address=("' . $mysql['ip_address'] . '") order by 202_ips.ip_id DESC limit 1';
            
        }
        else{
            $ip_sql = "SELECT ip_id FROM 202_ips WHERE ip_address='" . $mysql['ip_address'] . "'";
        }
                
        if ($memcacheWorking) {
            $time = 2592000; // 7 days in sec
                            // get from memcached
            $getID = $memcache->get(md5("ip-id" . $mysql['ip_address'] . systemHash()));
            
            if ($getID) {
                $ip_id = $getID;
            } else {
                
                $ip_result = _mysqli_query($db, $ip_sql);
                $ip_row = $ip_result->fetch_assoc();
                if ($ip_row) {
                    // if this ip_id already exists, return the ip_id for it.
                    $ip_id = $ip_row['ip_id'];
                    // add to memcached
                    $setID = setCache(md5("ip-id" . $mysql['ip_address'] . systemHash()), $ip_id, $time);
                } else {
                    //insert ip
                    $ip_id = INDEXES::insert_ip($db,$ip);
                    // add to memcached
                    $setID = setCache(md5("ip-id" . $mysql['ip_address'] . systemHash()), $ip_id, $time);
                }
            }
        } else {
            $ip_result = _mysqli_query($db, $ip_sql);
            $ip_row = $ip_result->fetch_assoc();
            if ($ip_row['ip_id']) {
                // if this ip already exists, return the ip_id for it.
                $ip_id = $ip_row['ip_id'];
            } else {
                //insert ip
                $ip_id = INDEXES::insert_ip($db,$ip);
            }
        }
        
        //return the ip_id
        return $ip_id;
    }
    
    public static function insert_ip($db,$ip){
        
        $mysql['ip_address'] = $db->real_escape_string($ip->address);
        
        //e
        if($ip->type == 'ipv6'){
            $mysql['ip_address'] = inet6_aton($mysql['ip_address']); //encode ipv6 for db insert
        }
        
        if ($ip->type==='ipv6') {
            //insert the ipv6 ip address and get the ipv6_id
            $ip_sql = 'INSERT INTO 202_ips_v6 SET ip_address=("'.$mysql['ip_address'].'")';
           // $ip_sql = 'INSERT INTO 202_ips_v6 SET ip_address='.$inet6_aton.'("'.$mysql['ip_address'].'")';
            $ip_result = _mysqli_query($db, $ip_sql); // ($ip_sql);
            $ipv6_id = $db->insert_id;
            
            //insert the ipv6_id as the ipv4 address for referencing later on
            $ip_sql = "INSERT INTO 202_ips SET ip_address='" . $ipv6_id . "', location_id='0'";
            $ip_result = _mysqli_query($db, $ip_sql); // ($ip_sql);
            $ip_id = $db->insert_id;
            return $ip_id;
        }
        else{
            $ip_sql = "INSERT INTO 202_ips SET ip_address='" . $mysql['ip_address'] . "', location_id='0'";
            $ip_result = _mysqli_query($db, $ip_sql); // ($ip_sql);
            $ip_id = $db->insert_id;
            return $ip_id;
        }
        
        
    }
    // this returns the site_domain_id, when a site_url_address is given
    public static function get_site_domain_id($db, $site_url_address)
    {
        global $memcacheWorking, $memcache;
        
        $parsed_url = @parse_url(trim($db->real_escape_string($site_url_address)));
        
        if(isset($parsed_url)){
            if(isset($parsed_url['host'])){
                $site_domain_host = trim($parsed_url['host']);
            }else{
                $site_domain_host = explode('/', $parsed_url['path'], 2);
                $site_domain_host = trim(array_shift($site_domain_host));
                //$site_domain_host = trim();
            }            
            $site_domain_host = str_replace('www.', '', $site_domain_host);
        }else{
            $site_domain_host = '';
            $site_domain_host = '';         
        }
        
        $mysql['site_domain_host'] = $db->real_escape_string($site_domain_host);
        
        // if a cached key is found for this lpip, redirect to that url
        if ($memcacheWorking) {
            $time = 2592000; // 30 days in sec
                             // get from memcached
            $getID = $memcache->get(md5("domain-id" . $site_domain_host . systemHash()));
            
            if ($getID) {
                $site_domain_id = $getID;
                return $site_domain_id;
            } else {
                
                $site_domain_sql = "SELECT site_domain_id FROM 202_site_domains WHERE site_domain_host='" . $mysql['site_domain_host'] . "'";
                $site_domain_result = _mysqli_query($db, $site_domain_sql);
                $site_domain_row = $site_domain_result->fetch_assoc();
                if ($site_domain_row) {
                    // if this site_domain_id already exists, return the site_domain_id for it.
                    $site_domain_id = $site_domain_row['site_domain_id'];
                    // add to memcached
                    $setID = setCache(md5("domain-id" . $site_domain_host . systemHash()), $site_domain_id, $time);
                    return $site_domain_id;
                } else {
                    // else if this doesn't exist, insert the new iprow, and return the_id for this new row we found
                    $site_domain_sql = "INSERT INTO 202_site_domains SET site_domain_host='" . $mysql['site_domain_host'] . "'";
                    $site_domain_result = _mysqli_query($db, $site_domain_sql); // ($site_domain_sql);
                    $site_domain_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("domain-id" . $site_domain_host . systemHash()), $site_domain_id, $time);
                    return $site_domain_id;
                }
            }
        } else {
            
            $site_domain_sql = "SELECT site_domain_id FROM 202_site_domains WHERE site_domain_host='" . $mysql['site_domain_host'] . "'";
            $site_domain_result = _mysqli_query($db, $site_domain_sql);
            $site_domain_row = $site_domain_result->fetch_assoc();
            if ($site_domain_row) {
                // if this site_domain_id already exists, return the site_domain_id for it.
                $site_domain_id = $site_domain_row['site_domain_id'];
                // add to memcached
                return $site_domain_id;
            } else {
                // else if this doesn't exist, insert the new iprow, and return the_id for this new row we found
                $site_domain_sql = "INSERT INTO 202_site_domains SET site_domain_host='" . $mysql['site_domain_host'] . "'";
                $site_domain_result = _mysqli_query($db, $site_domain_sql); // ($site_domain_sql);
                $site_domain_id = $db->insert_id;
                return $site_domain_id;
            }
        }
    }
    
    // this returns the site_url_id, when a site_url_address is given
    public static function get_site_url_id($db, $site_url_address)
    {
        global $memcacheWorking, $memcache;
        $time = 2592000; // 30 days in sec
        $site_domain_id = INDEXES::get_site_domain_id($db, $site_url_address);
        
        $mysql['site_url_address'] = $db->real_escape_string($site_url_address);
        $mysql['site_domain_id'] = $db->real_escape_string($site_domain_id);
        
        if ($memcacheWorking) {
            $time = 604800; // 7 days in sec
                            // get from memcached
            $getURL = $memcache->get(md5("url-id" . $site_url_address . systemHash()));
            if ($getURL) {
                return $getURL;
            } else {
                
                $site_url_sql = "SELECT site_url_id FROM 202_site_urls WHERE site_domain_id='" . $mysql['site_domain_id'] . "' and site_url_address='" . $mysql['site_url_address'] . "' limit 1";
                $site_url_result = _mysqli_query($db, $site_url_sql);
                $site_url_row = $site_url_result->fetch_assoc();
                if ($site_url_row) {
                    // if this site_url_id already exists, return the site_url_id for it.
                    $site_url_id = $site_url_row['site_url_id'];
                    $setID = setCache(md5("url-id" . $site_url_address . systemHash()), $site_url_id, $time);
                    return $site_url_id;
                } else {
                    
                    $site_url_sql = "INSERT INTO 202_site_urls SET site_domain_id='" . $mysql['site_domain_id'] . "', site_url_address='" . $mysql['site_url_address'] . "'";
                    $site_url_result = _mysqli_query($db, $site_url_sql); // ($site_url_sql);
                    $site_url_id = $db->insert_id;
                    $setID = setCache(md5("url-id" . $site_url_address . systemHash()), $site_url_id, $time);
                    return $site_url_id;
                }
            }
        } else {
            
            $site_url_sql = "SELECT site_url_id FROM 202_site_urls WHERE site_domain_id='" . $mysql['site_domain_id'] . "' and site_url_address='" . $mysql['site_url_address'] . "' limit 1";
            $site_url_result = _mysqli_query($db, $site_url_sql);
            $site_url_row = $site_url_result->fetch_assoc();
            
            if ($site_url_row) {
                // if this site_url_id already exists, return the site_url_id for it.
                $site_url_id = $site_url_row['site_url_id'];
                return $site_url_id;
            } else {
                
                $site_url_sql = "INSERT INTO 202_site_urls SET site_domain_id='" . $mysql['site_domain_id'] . "', site_url_address='" . $mysql['site_url_address'] . "'";
                $site_url_result = _mysqli_query($db, $site_url_sql); // ($site_url_sql);
                $site_url_id = $db->insert_id;
                return $site_url_id;
            }
        }
    }
    
    // this returns the keyword_id
    public static function get_utm_id($db, $utm_var, $utm_type)
    {
        global $memcacheWorking, $memcache;
        
        // only grab the first 350 characters of the utm variable
        $utm_var = substr($utm_var, 0, 350);
        
        $mysql['utm_var'] = $db->real_escape_string($utm_var);
        $mysql['utm_type'] = $db->real_escape_string($utm_type);
        
        if ($memcacheWorking) {
            // get from memcached
            $getUtm = $memcache->get(md5($mysql['utm_type'] . "_id" . $utm_var . systemHash()));
            if ($getUtm) {
                return $getUtm;
            } else {
                
                $utm_sql = "SELECT " . $mysql['utm_type'] . "_id FROM 202_" . $mysql['utm_type'] . " WHERE " . $mysql['utm_type'] . "='" . $mysql['utm_var'] . "'";
                $utm_result = _mysqli_query($db, $utm_sql);
                $utm_row = $utm_result->fetch_assoc();
                if ($utm_row) {
                    // if this already exists, return the id for it
                    $utm_id_name = $mysql['utm_type'] . "_id";
                    $utm_id = $utm_row[$utm_id_name];
                    $setID = setCache(md5($mysql['utm_type'] . "_id" . $utm_var . systemHash()), $utm_id, $time);
                    return $utm_id;
                } else {
                    
                    $utm_sql = "INSERT INTO 202_" . $mysql['utm_type'] . " SET " . $mysql['utm_type'] . "='" . $mysql['utm_var'] . "'";
                    $utm_result = _mysqli_query($db, $utm_sql);
                    $utm_id = $db->insert_id;
                    $setID = setCache(md5($mysql['utm_type'] . "_id" . $utm_var . systemHash()), $utm_id, $time);
                    return $utm_id;
                }
            }
        } else {
            
            $utm_sql = "SELECT " . $mysql['utm_type'] . "_id FROM 202_" . $mysql['utm_type'] . " WHERE " . $mysql['utm_type'] . "='" . $mysql['utm_var'] . "'";
            $utm_result = _mysqli_query($db, $utm_sql);
            $utm_row = $utm_result->fetch_assoc();
            if ($utm_row) {
                // if this already exists, return the id for it
                $utm_id_name = $mysql['utm_type'] . "_id";
                $utm_id = $utm_row[$utm_id_name];
                return $utm_id;
            } else {
                
                $utm_sql = "INSERT INTO 202_" . $mysql['utm_type'] . " SET " . $mysql['utm_type'] . "='" . $mysql['utm_var'] . "'";
                $utm_result = _mysqli_query($db, $utm_sql);
                $utm_id = $db->insert_id;
                return $utm_id;
            }
        }
    }

    public static function get_variable_id($db, $variable, $ppc_variable_id)
    {
        global $memcacheWorking, $memcache;
        
        // only grab the first 350 characters of the variable
        $variable = substr($variable, 0, 350);
        
        $mysql['var'] = $db->real_escape_string($variable);
        $mysql['ppc_variable_id'] = $db->real_escape_string($ppc_variable_id);
        
        if ($memcacheWorking) {
            // get from memcached
            $var_id = $memcache->get(md5($mysql['ppc_variable_id'] . $mysql['var'] . systemHash()));
            if (!$var_id) {
                
                $var_sql = "SELECT custom_variable_id FROM 202_custom_variables WHERE ppc_variable_id = '" . $mysql['ppc_variable_id'] . "' AND variable = '" . $mysql['var'] . "'";
                $var_result = _mysqli_query($db, $var_sql);
                $var_row = $var_result->fetch_assoc();
                if ($var_row) {
                    // if this already exists, return the id for it
                    $var_id = $var_row['custom_variable_id'];
                    $setID = setCache(md5($ppc_variable_id . $variable . systemHash()), $var_id, $time);
                   
                } else {
                    
                    $var_sql = "INSERT INTO 202_custom_variables SET ppc_variable_id = '" . $mysql['ppc_variable_id'] . "', variable = '" . $mysql['var'] . "'";
                    $var_result = _mysqli_query($db, $var_sql);
                    $var_id = $db->insert_id;
                    $setID = setCache(md5($ppc_variable_id . $variable . systemHash()), $var_id, $time);
                    
                }
            }
        } else {
            
            $var_sql = "SELECT custom_variable_id FROM 202_custom_variables WHERE ppc_variable_id = '" . $mysql['ppc_variable_id'] . "' AND variable = '" . $mysql['var'] . "'";
            $var_result = _mysqli_query($db, $var_sql);
            $var_row = $var_result->fetch_assoc();
            
            if ($var_row) {
                // if this already exists, return the id for it
                $var_id = $var_row['custom_variable_id'];
                
            } else {
                
                $var_sql = "INSERT INTO 202_custom_variables SET ppc_variable_id = '" . $mysql['ppc_variable_id'] . "', variable = '" . $mysql['var'] . "'";
                $var_result = _mysqli_query($db, $var_sql);
                $var_id = $db->insert_id;
                
            }
        }

        return $var_id;
    }

    public static function get_variable_set_id($db, $variables)
    {
        global $memcacheWorking, $memcache;
        
        $mysql['variables'] = $db->real_escape_string($variables);

        if ($memcacheWorking) {
            // get from memcached
            $getSet = $memcache->get(md5('variable_set' . $variables . systemHash()));
            if ($getSet) {
                return $getSet;
            } else {
                $var_sql = "SELECT variable_set_id FROM 202_variable_sets WHERE variables = '" . $mysql['variables'] . "'";
                $var_result = _mysqli_query($db, $var_sql);
                $var_row = $var_result->fetch_assoc();
                if ($var_row) {
                    // if this already exists, return the id for it
                    $var_id = $var_row['variable_set_id'];
                    $setID = setCache(md5('variable_set' . $variables . systemHash()), $var_id, $time);
                    return $var_id;
                } else {
                    
                    $var_sql = "INSERT INTO 202_variable_sets SET variables = '" . $mysql['variables'] . "'";
                    $var_result = _mysqli_query($db, $var_sql);
                    $var_id = $db->insert_id;
                    $setID = setCache(md5('variable_set' . $variables . systemHash()), $var_id, $time);
                    
                    $var_sets = explode (",", $mysql['variables']);
                    foreach ($var_sets as $var){
                        $row.= "(".  $var_id.",".$var."),";
                    }
                    $row="insert into `202_variable_sets2` (`variable_set_id`, `variables`) values ".rtrim($row,',').";";
                    
                    _mysqli_query($db, $row);
                    return $var_id;
                }
            }
        } else {
            $var_sql = "SELECT variable_set_id FROM 202_variable_sets WHERE variables = '" . $mysql['variables'] . "'";
            $var_result = _mysqli_query($db, $var_sql);
            $var_row = $var_result->fetch_assoc();
            
            if ($var_row) {

                // if this already exists, return the id for it
                $var_id = $var_row['variable_set_id'];
                return $var_id;
            } else {
                $var_sql = "INSERT INTO 202_variable_sets SET variables = '" . $mysql['variables'] . "'";
                $var_result = _mysqli_query($db, $var_sql);
                $var_id = $db->insert_id;
                $var_sets = explode (",", $mysql['variables']);
                foreach ($var_sets as $var){
                    $row.= "(".  $var_id.",".$var."),";
                }

                $row="insert into `202_variable_sets2` (`variable_set_id`, `variables`) values ".rtrim($row,',').";";
                
                _mysqli_query($db, $row);
                return $var_id;
            }
        }
    }
    
    // this returns the keyword_id
    public static function get_keyword_id($db, $keyword)
    {
        global $memcacheWorking, $memcache;
        $time = 2592000; // 30 days in sec
        // only grab the first 255 characters of keyword
        // $keyword = substr($keyword, 0, 255);
        
        $mysql['keyword'] = $db->real_escape_string($keyword);
        
        if ($memcacheWorking) {
            // get from memcached
            $getKeyword = $memcache->get(md5("keyword-id" . $keyword . systemHash()));
            if ($getKeyword) {
                return $getKeyword;
            } else {
                
                $keyword_sql = "SELECT keyword_id FROM 202_keywords WHERE keyword='" . $mysql['keyword'] . "'";
                $keyword_result = _mysqli_query($db, $keyword_sql);
                $keyword_row = $keyword_result->fetch_assoc();
                if ($keyword_row) {
                    // if this already exists, return the id for it
                    $keyword_id = $keyword_row['keyword_id'];
                    $setID = setCache(md5("keyword-id" . $keyword . systemHash()), $keyword_id, $time);
                    return $keyword_id;
                } else {
                    
                    $keyword_sql = "INSERT INTO 202_keywords SET keyword='" . $mysql['keyword'] . "'";
                    $keyword_result = _mysqli_query($db, $keyword_sql); // ($keyword_sql);
                    $keyword_id = $db->insert_id;
                    $setID = setCache(md5("keyword-id" . $keyword . systemHash()), $keyword_id, $time);
                    return $keyword_id;
                }
            }
        } else {
            
            $keyword_sql = "SELECT keyword_id FROM 202_keywords WHERE keyword='" . $mysql['keyword'] . "'";
            $keyword_result = _mysqli_query($db, $keyword_sql);
            $keyword_row = $keyword_result->fetch_assoc();
            if ($keyword_row) {
                // if this already exists, return the id for it
                $keyword_id = $keyword_row['keyword_id'];
                return $keyword_id;
            } else {
                // else if this ip doesn't exist, insert the row and grab the id for it
                $keyword_sql = "INSERT INTO 202_keywords SET keyword='" . $mysql['keyword'] . "'";
                $keyword_result = _mysqli_query($db, $keyword_sql); // ($keyword_sql);
                $keyword_id = $db->insert_id;
                return $keyword_id;
            }
        }
    }

    // this returns the c1 id
    public static function get_custom_var_id($db, $custom_var_name, $custom_var_data)
    {
        global $memcacheWorking, $memcache;
     
        // only grab the first 350 charactesr of custom_var
        $custom_var_data = substr($custom_var_data, 0, 350);
        $mysql[$custom_var_name] = $db->real_escape_string($custom_var_data);
    
        if ($memcacheWorking) {
            // get from memcached
            $getcustomvar = $memcache->get(md5($custom_var_name."-id" . $custom_var_data . systemHash()));
            if ($getcustomvar) {
                return $getcustomvar;
            } else {
    
                $custom_sql = "SELECT ".$custom_var_name."_id FROM 202_tracking_".$custom_var_name." WHERE ".$custom_var_name."='" . $mysql[$custom_var_name] . "'";
                $custom_result = _mysqli_query($db, $custom_sql);
                $custom_row = $custom_result->fetch_assoc();
                if ($custom_row) {
                    // if this already exists, return the id for it
                    $custom_id = $custom_row[$custom_var_name."_id"];
                    $setID = setCache(md5($custom_var_name."-id" . $custom_var_data . systemHash()), $custom_id);
                    return $custom_id;
                } else {
    
                    $custom_sql = "INSERT INTO 202_tracking_".$custom_var_name." SET ".$custom_var_name."='" . $mysql[$custom_var_name] . "'";
                    $custom_result = _mysqli_query($db, $custom_sql); // ($c1_sql);
                    $custom_id = $db->insert_id;
                    $setID = setCache(md5($custom_var_name."-id" . $custom_var_data . systemHash()), $custom_id);
                    return $custom_id;
                }
            }
        } else {
    
            $custom_sql = "SELECT ".$custom_var_name."_id FROM 202_tracking_".$custom_var_name." WHERE ".$custom_var_name."='" . $mysql[$custom_var_name] . "'";

            $custom_result = _mysqli_query($db, $custom_sql);
            $custom_row = $custom_result->fetch_assoc();

            if ($custom_row) {
                // if this already exists, return the id for it
                $custom_id = $custom_row[$custom_var_name."_id"];
                return $custom_id;
            } else {
                // else if this id doesn't exist, insert the row and grab the id for it
                $custom_sql = "INSERT INTO 202_tracking_".$custom_var_name." SET ".$custom_var_name."='" . $mysql[$custom_var_name] . "'";
                $custom_result = _mysqli_query($db, $custom_sql); 
                $custom_id = $db->insert_id;
                return $custom_id;
            }
        }
    }
    
    // this returns the c1 id
    public static function get_c1_id($db, $c1)
    {
        global $memcacheWorking, $memcache;
        
        // only grab the first 350 charactesr of c1
        $c1 = substr($c1, 0, 350);
        
        $mysql['c1'] = $db->real_escape_string($c1);
        
        if ($memcacheWorking) {
            // get from memcached
            $getc1 = $memcache->get(md5("c1-id" . $c1 . systemHash()));
            if ($getc1) {
                return $getc1;
            } else {
                
                $c1_sql = "SELECT c1_id FROM 202_tracking_c1 WHERE c1='" . $mysql['c1'] . "'";
                $c1_result = _mysqli_query($db, $c1_sql);
                $c1_row = $c1_result->fetch_assoc();
                if ($c1_row) {
                    // if this already exists, return the id for it
                    $c1_id = $c1_row['c1_id'];
                    $setID = setCache(md5("c1-id" . $c1 . systemHash()), $c1_id);
                    return $c1_id;
                } else {
                    
                    $c1_sql = "INSERT INTO 202_tracking_c1 SET c1='" . $mysql['c1'] . "'";
                    $c1_result = _mysqli_query($db, $c1_sql); // ($c1_sql);
                    $c1_id = $db->insert_id;
                    $setID = setCache(md5("c1-id" . $c1 . systemHash()), $c1_id);
                    return $c1_id;
                }
            }
        } else {
            
            $c1_sql = "SELECT c1_id FROM 202_tracking_c1 WHERE c1='" . $mysql['c1'] . "'";
            $c1_result = _mysqli_query($db, $c1_sql);
            $c1_row = $c1_result->fetch_assoc();
            if ($c1_row) {
                // if this already exists, return the id for it
                $c1_id = $c1_row['c1_id'];
                return $c1_id;
            } else {
                // else if this ip doesn't exist, insert the row and grab the id for it
                $c1_sql = "INSERT INTO 202_tracking_c1 SET c1='" . $mysql['c1'] . "'";
                $c1_result = _mysqli_query($db, $c1_sql); // ($c1_sql);
                $c1_id = $db->insert_id;
                return $c1_id;
            }
        }
    }
    
    // this returns the c2 id
    public static function get_c2_id($db, $c2)
    {
        global $memcacheWorking, $memcache;
        
        // only grab the first 350 charactesr of c2
        $c2 = substr($c2, 0, 350);
        
        $mysql['c2'] = $db->real_escape_string($c2);
        
        if ($memcacheWorking) {
            // get from memcached
            $getc2 = $memcache->get(md5("c2-id" . $c2 . systemHash()));
            if ($getc2) {
                return $getc2;
            } else {
                
                $c2_sql = "SELECT c2_id FROM 202_tracking_c2 WHERE c2='" . $mysql['c2'] . "'";
                $c2_result = _mysqli_query($db, $c2_sql);
                $c2_row = $c2_result->fetch_assoc();
                if ($c2_row) {
                    // if this already exists, return the id for it
                    $c2_id = $c2_row['c2_id'];
                    $setID = setCache(md5("c2-id" . $c2 . systemHash()), $c2_id);
                    return $c2_id;
                } else {
                    
                    $c2_sql = "INSERT INTO 202_tracking_c2 SET c2='" . $mysql['c2'] . "'";
                    $c2_result = _mysqli_query($db, $c2_sql); // ($c2_sql);
                    $c2_id = $db->insert_id;
                    $setID = setCache(md5("c2-id" . $c2 . systemHash()), $c2_id);
                    return $c2_id;
                }
            }
        } else {
            
            $c2_sql = "SELECT c2_id FROM 202_tracking_c2 WHERE c2='" . $mysql['c2'] . "'";
            $c2_result = _mysqli_query($db, $c2_sql);
            $c2_row = $c2_result->fetch_assoc();
            if ($c2_row) {
                // if this already exists, return the id for it
                $c2_id = $c2_row['c2_id'];
                return $c2_id;
            } else {
                // else if this ip doesn't exist, insert the row and grab the id for it
                $c2_sql = "INSERT INTO 202_tracking_c2 SET c2='" . $mysql['c2'] . "'";
                $c2_result = _mysqli_query($db, $c2_sql); // ($c2_sql);
                $c2_id = $db->insert_id;
                return $c2_id;
            }
        }
    }
    
    // this returns the c3 id
    public static function get_c3_id($db, $c3)
    {
        global $memcacheWorking, $memcache;
        
        // only grab the first 350 charactesr of c3
        $c3 = substr($c3, 0, 350);
        
        $mysql['c3'] = $db->real_escape_string($c3);
        
        if ($memcacheWorking) {
            // get from memcached
            $getc3 = $memcache->get(md5("c3-id" . $c3 . systemHash()));
            if ($getc3) {
                return $getc3;
            } else {
                
                $c3_sql = "SELECT c3_id FROM 202_tracking_c3 WHERE c3='" . $mysql['c3'] . "'";
                $c3_result = _mysqli_query($db, $c3_sql);
                $c3_row = $c3_result->fetch_assoc();
                if ($c3_row) {
                    // if this already exists, return the id for it
                    $c3_id = $c3_row['c3_id'];
                    $setID = setCache(md5("c3-id" . $c3 . systemHash()), $c3_id);
                    return $c3_id;
                } else {
                    
                    $c3_sql = "INSERT INTO 202_tracking_c3 SET c3='" . $mysql['c3'] . "'";
                    $c3_result = _mysqli_query($db, $c3_sql); // ($c3_sql);
                    $c3_id = $db->insert_id;
                    $setID = setCache(md5("c3-id" . $c3 . systemHash()), $c3_id);
                    return $c3_id;
                }
            }
        } else {
            
            $c3_sql = "SELECT c3_id FROM 202_tracking_c3 WHERE c3='" . $mysql['c3'] . "'";
            $c3_result = _mysqli_query($db, $c3_sql);
            $c3_row = $c3_result->fetch_assoc();
            if ($c3_row) {
                // if this already exists, return the id for it
                $c3_id = $c3_row['c3_id'];
                return $c3_id;
            } else {
                // else if this ip doesn't exist, insert the row and grab the id for it
                $c3_sql = "INSERT INTO 202_tracking_c3 SET c3='" . $mysql['c3'] . "'";
                $c3_result = _mysqli_query($db, $c3_sql); // ($c3_sql);
                $c3_id = $db->insert_id;
                return $c3_id;
            }
        }
    }
    
    // this returns the c4 id
    public static function get_c4_id($db, $c4)
    {
        global $memcacheWorking, $memcache;
        
        // only grab the first 350 charactesr of c4
        $c4 = substr($c4, 0, 350);
        
        $mysql['c4'] = $db->real_escape_string($c4);
        
        if ($memcacheWorking) {
            // get from memcached
            $getc4 = $memcache->get(md5("c4-id" . $c4 . systemHash()));
            if ($getc4) {
                return $getc4;
            } else {
                
                $c4_sql = "SELECT c4_id FROM 202_tracking_c4 WHERE c4='" . $mysql['c4'] . "'";
                $c4_result = _mysqli_query($db, $c4_sql);
                $c4_row = $c4_result->fetch_assoc();
                if ($c4_row) {
                    // if this already exists, return the id for it
                    $c4_id = $c4_row['c4_id'];
                    $setID = setCache(md5("c4-id" . $c4 . systemHash()), $c4_id);
                    return $c4_id;
                } else {
                    
                    $c4_sql = "INSERT INTO 202_tracking_c4 SET c4='" . $mysql['c4'] . "'";
                    $c4_result = _mysqli_query($db, $c4_sql); // ($c4_sql);
                    $c4_id = $db->insert_id;
                    $setID = setCache(md5("c4-id" . $c4 . systemHash()), $c4_id);
                    return $c4_id;
                }
            }
        } else {
            
            $c4_sql = "SELECT c4_id FROM 202_tracking_c4 WHERE c4='" . $mysql['c4'] . "'";
            $c4_result = _mysqli_query($db, $c4_sql);
            $c4_row = $c4_result->fetch_assoc();
            if ($c4_row) {
                // if this already exists, return the id for it
                $c4_id = $c4_row['c4_id'];
                return $c4_id;
            } else {
                // else if this ip doesn't exist, insert the row and grab the id for it
                $c4_sql = "INSERT INTO 202_tracking_c4 SET c4='" . $mysql['c4'] . "'";
                $c4_result = _mysqli_query($db, $c4_sql); // ($c4_sql);
                $c4_id = $db->insert_id;
                return $c4_id;
            }
        }
    }
}

function memcache_mysql_fetch_assoc($db, $sql, $allowCaching = 1, $minutes = 3)
{
    global $memcacheWorking, $memcache;
    
    if ($memcacheWorking == false) {
        $result = _mysqli_query($db, $sql);
        $row = $result->fetch_assoc();
        return $row;
    } else {
        
        if ($allowCaching == 0) {
            $result = _mysqli_query($db, $sql);
            $row = $result->fetch_assoc();
            return $row;
        } else {
            // Check if its set
            $getCache = $memcache->get(md5($sql . systemHash()));
            
            if ($getCache === false) {
                // cache this data
                $result = _mysqli_query($db, $sql);
                $fetchArray = $result->fetch_assoc();
                $setCache = setCache(md5($sql . systemHash()), serialize($fetchArray), 60 * $minutes);
                
                // store all this users memcache keys, so we can delete them fast later on
                
                
                return $fetchArray;
            } else {
                
                // Data Cached
                return unserialize($getCache);
            }
        }
    }
}

function foreach_memcache_mysql_fetch_assoc($db, $sql, $allowCaching = 1)
{
    global $memcacheWorking, $memcache;
    
    if ($memcacheWorking == false) {
        $row = array();
        $result = _mysqli_query($db, $sql); // ($sql);
        while ($fetch = $result->fetch_assoc()) {
            $row[] = $fetch;
        }
        return $row;
    } else {
        
        if ($allowCaching == 0) {
            $row = array();
            $result = _mysqli_query($db, $sql); // ($sql);
            while ($fetch = $result->fetch_assoc()) {
                $row[] = $fetch;
            }
            return $row;
        } else {
            
            $getCache = $memcache->get(md5($sql . systemHash()));
            if ($getCache === false) {
                // if data is NOT cache, cache this data
                $row = array();
                $result = _mysqli_query($db, $sql); // ($sql);
                while ($fetch = $result->fetch_assoc()) {
                    $row[] = $fetch;
                }

                $setCache = setCache(md5($sql . systemHash()), serialize($row), 60 * 1);
               
                
                return $row;
            } else {
                // if data is cached, returned the cache data Data Cached
                return unserialize($getCache);
            }
        }
    }
}

function replaceTokens($url, $tokens = Array(), $fillblanks=0)
{
    $tokens = array_map('rawurlencode202', $tokens);

    if (isset($tokens['c1']) || $fillblanks)
        $url = preg_replace('/\[\[c1\]\]/i', $tokens['c1'], $url);
    if (isset($tokens['c2']) || $fillblanks)
        $url = preg_replace('/\[\[c2\]\]/i', $tokens['c2'], $url);
    if (isset($tokens['c3']) || $fillblanks)
        $url = preg_replace('/\[\[c3\]\]/i', $tokens['c3'], $url);
    if (isset($tokens['c4']) || $fillblanks)
        $url = preg_replace('/\[\[c4\]\]/i', $tokens['c4'], $url);
    if (isset($tokens['t202pubid']) || $fillblanks)
        $url = preg_replace('/\[\[t202pubid\]\]/i', $tokens['t202pubid'], $url);
    if (isset($tokens['gclid']) || $fillblanks)
        $url = preg_replace('/\[\[gclid\]\]/i', $tokens['gclid'], $url);
    if (isset($tokens['msclkid']) || $fillblanks)
        $url = preg_replace('/\[\[msclkid\]\]/i', $tokens['msclkid'], $url);
    if (isset($tokens['fbclid']) || $fillblanks)
        $url = preg_replace('/\[\[fbclid\]\]/i', $tokens['fbclid'], $url);
    if (isset($tokens['utm_source']) || $fillblanks)
        $url = preg_replace('/\[\[utm_source\]\]/i', $tokens['utm_source'], $url);
    if (isset($tokens['utm_medium']) || $fillblanks)
        $url = preg_replace('/\[\[utm_medium\]\]/i', $tokens['utm_medium'], $url);
    if (isset($tokens['utm_campaign']) || $fillblanks)
        $url = preg_replace('/\[\[utm_campaign\]\]/i', $tokens['utm_campaign'], $url);
    if (isset($tokens['utm_term']) || $fillblanks)
        $url = preg_replace('/\[\[utm_term\]\]/i', $tokens['utm_term'], $url);
    if (isset($tokens['utm_content']) || $fillblanks)
        $url = preg_replace('/\[\[utm_content\]\]/i', $tokens['utm_content'], $url);
    if (isset($tokens['subid']) || $fillblanks)
        $url = preg_replace('/\[\[subid\]\]/i', $tokens['subid'], $url);
    if (isset($tokens['t202kw']) || $fillblanks)
        $url = preg_replace('/\[\[t202kw\]\]/i', $tokens['t202kw'], $url);
    if (isset($tokens['payout']) || $fillblanks)
        $url = preg_replace('/\[\[payout\]\]/i', $tokens['payout'], $url);
    if (isset($tokens['random']) || $fillblanks)
        $url = preg_replace('/\[\[random\]\]/i', $tokens['random'], $url);
    if (isset($tokens['cpc']) || $fillblanks)
        $url = preg_replace('/\[\[cpc\]\]/i', $tokens['cpc'], $url);
    if (isset($tokens['cpc2']) || $fillblanks)
        $url = preg_replace('/\[\[cpc2\]\]/i', $tokens['cpc2'], $url);
    if (isset($tokens['cpa']) || $fillblanks)
        $url = preg_replace('/\[\[cpa\]\]/i', $tokens['cpa'], $url);
    if (isset($tokens['timestamp']) || $fillblanks)
        $url = preg_replace('/\[\[timestamp\]\]/i', $tokens['timestamp'], $url);
    if (isset($tokens['country']) || $fillblanks)
        $url = preg_replace('/\[\[country\]\]/i', $tokens['country'], $url);
    if (isset($tokens['country_code']) || $fillblanks)
        $url = preg_replace('/\[\[country_code\]\]/i', $tokens['country_code'], $url);
    if (isset($tokens['region']) || $fillblanks)
        $url = preg_replace('/\[\[region\]\]/i', $tokens['region'], $url);
    if (isset($tokens['city']) || $fillblanks)
        $url = preg_replace('/\[\[city\]\]/i', $tokens['city'], $url);
    if (isset($tokens['referer']) || $fillblanks) {
        $url = preg_replace('/\[\[referer\]\]/i', $tokens['referer'], $url);
        $url = preg_replace('/\[\[referrer\]\]/i', $tokens['referer'], $url);
    }
    if (isset($tokens['sourceid']) || $fillblanks)
        $url = preg_replace('/\[\[sourceid\]\]/i', $tokens['sourceid'], $url);
    if (isset($tokens['transactionid']) || $fillblanks)
        $url = preg_replace('/\[\[(transactionid|t202txid)\]\]/i', $tokens['transactionid'], $url);    
    return $url;
}

function rawurlencode202($token){
    if(isset($token)){
        $token = str_replace('%40', '@',rawurlencode($token));
        return $token;
    }
    else{
        return NULL;
    }
}

function getGeoData($ip)
{
    
    $reader = new Reader(CONFIG_PATH . '/geo/GeoLite2-City.mmdb');
    
    $ip_address=$ip->address;
    try {
    $record = $reader->city($ip_address);
    $country = $record->country->name;
    $country_code = $record->country->isoCode;
    $is_european_union = $record->country->isInEuropeanUnion;
    $continent = $record->continent->name;
    $city = $record->city->name;
    $region = $record->mostSpecificSubdivision->name;
    $region_code = $record->mostSpecificSubdivision->isoCode;
    $postal = $record->postal->code;
    }
    catch (\Exception $e){
     $record = '';
     $country = '';
     $country_code = '';
     $is_european_union = '';
     $continent = '';
     $city = '';
     $region = '';
     $region_code = '';
     $postal = '';
    }


    
    if ($record != "null") {
        if ($country == null) {
            $country = "Unknown country";
            $country_code = "non";
        }

        if ($is_european_union == null) {
            $is_european_union = "Unknown";
            
            //sometime continent is known as not Europe but EU status is still set as unknown
            if($continent!= null && $continent != 'Europe'){
                $is_european_union = false;
            }
        }

        if ($continent == null) {
            $continent = "Unknown continent";
        }
        
        if ($city == null) {
            $city = "Unknown city";
        }
        
        if ($region == null) {
            $region = "Unknown region";
            $region_code = 'non';
        }
        
        if ($postal == null) {
            $postal = "Unknown postal code";
        }
    }
    
    $geoData = array(
        'country' => $country,
        'country_code' => $country_code,
        'is_european_union' => $is_european_union,
        'continent' => $continent,
        'region' => $region,
        'region_code' => $region_code,
        'city' => $city,
        'postal_code' => $postal
    );

    $reader->close();

    return $geoData;
}

function getIspData($ip)
{
    $isp_file = substr(dirname( __FILE__ ), 0,-10) . "/202-config/geo/GeoIPISP.dat";

    
    if (file_exists($isp_file)) {
        $giisp = geoip_open(substr(dirname( __FILE__ ), 0,-10) . "/202-config/geo/GeoIPISP.dat", GEOIP_MEMORY_CACHE);
        $isp = geoip_org_by_addr($giisp, $ip->address);
        
        if (! $isp) {
            $isp = "Unknown ISP/Carrier";
        }
        
        geoip_close($giisp);
    } else {
        $isp = "Unknown ISP/Carrier";
    }
    
    return $isp;
}

function systemHash()
{
    $hash = hash('ripemd160', $_SERVER['HTTP_HOST'] . $_SERVER['SERVER_ADDR']);
    return $hash;
}

function setPCIdCookie($click_id_public)
{
    if(trackingEnabled()){
          //set the cookie for the PIXEL to fire, expire in 30 days
          $expire = 0;
          $expire_header = 0;
          $path = '/';
          $domain = $_SERVER['HTTP_HOST'];
          $secure = TRUE;
          $httponly = FALSE;
          
          //legacy cookies
         setcookie('tracking202pci-legacy', $click_id_public, $expire, '/', $domain);
 
         //samesite=none secure cookies
         if (PHP_VERSION_ID < 70300) {
             header('Set-Cookie: tracking202pci='.$click_id_public.';max-age='.$expire_header.';Path=/;Domain='.$domain.';SameSite=None; Secure');        
         }
         else {
             setcookie('tracking202pci' , $click_id_public, ['expires' => $expire,'path' => '/','domain' => $domain,'secure' => $secure,'httponly' => $httponly,'samesite' => 'None']);
         }
    }   
}

function setOutboundCookie($outbound_site_url)
{
    if(trackingEnabled()){     
        //set the cookie for the PIXEL to fire, expire in 30 days
           $expire = 0;
           $expire_header = 0;
           $path = '/';
           $domain = $_SERVER['HTTP_HOST'];
           $secure = TRUE;
           $httponly = FALSE;
           
           //legacy cookies
           setcookie('tracking202outbound-legacy', $outbound_site_url, $expire, '/', $domain);
 
          //samesite=none secure cookies
          if (PHP_VERSION_ID < 70300) {
              header('Set-Cookie: tracking202outbound='.$outbound_site_url.';max-age='.$expire_header.';Path=/;Domain='.$domain.';SameSite=None; Secure');        
          }
          else {
              setcookie('tracking202outbound' , $outbound_site_url, ['expires' => $expire,'path' => '/','domain' => $domain,'secure' => $secure,'httponly' => $httponly,'samesite' => 'None']);
          }
    }
}

function getPrePopVars($vars)
{
    $urlvars = '';
    $stoplist = array(
        'subid',
        'c1',
        'c2',
        'c3',
        'c4',
        't202kw',
        't202id',
        't202b',
        't202ref',
        't202pubid',
        'acip',
        '202v',
        '202vars',
        'lpip',
        'pci',
        'gclid',
        'msclkid',
        'fbclid',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content'
    );
    
    foreach ($vars as $key => $value) {
        if (! in_array($key, $stoplist)) {
            $urlvars .= $key . "=" . $value . "&";
        }
    }
    
    return $urlvars;
}

function setPrePopVars($urlvars, $redirect_site_url, $b64 = false)
{
    if (isset($urlvars) && $urlvars != '') {
        
        // remove & at the end of the string
        $urlvars = rtrim($urlvars, '&');
        if ($b64) {
            $urlvars = "202vars=" . base64_encode($urlvars);
        }
        if (! parse_url($redirect_site_url, PHP_URL_QUERY)) {
            
            // if there is no query url the add a ? to thecVars but before doing that remove case where there may be a ? at the end of the url and nothing else
            $redirect_site_url = rtrim($redirect_site_url, '?');
            
            // remove the & from thecVars and put a ? in fron t of it
            
            $redirect_site_url .= "?" . $urlvars;
        } else {
            
            $redirect_site_url .= "&" . $urlvars;
        }
    }
    
    return $redirect_site_url;
}

function record_mysql_error($db, $sql)
{
    global $server_row;
    
    // record the mysql error
    $clean['mysql_error_text'] = mysqli_error($db);
    
    // if on dev server, echo the error
    
    echo $sql . '<br/><br/>' . $clean['mysql_error_text'] . '<br/><br/>';
    
    
    $ip_id = INDEXES::get_ip_id($db,$ip_address);
    $mysql['ip_id'] = $db->real_escape_string($ip_id);
    
    $site_url = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
    $site_id = INDEXES::get_site_url_id($db,$site_url);
    $mysql['site_id'] = $db->real_escape_string($site_id);
    
    $mysql['user_id'] = $db->real_escape_string(strip_tags($_SESSION['user_id']));
    $mysql['mysql_error_text'] = $db->real_escape_string($clean['mysql_error_text']);
    $mysql['mysql_error_sql'] = $db->real_escape_string($sql);
    $mysql['script_url'] = $db->real_escape_string(strip_tags($_SERVER['SCRIPT_URL']));
    $mysql['server_name'] = $db->real_escape_string(strip_tags($_SERVER['SERVER_NAME']));
    $mysql['mysql_error_time'] = time();
    
    $report_sql = "INSERT     INTO  202_mysql_errors
								SET     mysql_error_text='" . $mysql['mysql_error_text'] . "',
										mysql_error_sql='" . $mysql['mysql_error_sql'] . "',
										user_id='" . $mysql['user_id'] . "',
										ip_id='" . $mysql['ip_id'] . "',
										site_id='" . $mysql['site_id'] . "',
										mysql_error_time='" . $mysql['mysql_error_time'] . "'";
    $report_query = _mysqli_query($db, $report_sql);

    // email administration of the error
    $to = $_SERVER['SERVER_ADMIN'];
    $subject = 'mysql error reported - ' . $site_url;
    $message = '<b>A mysql error has been reported</b><br/><br/>
		
					time: ' . date('r', time()) . '<br/>
					server_name: ' . $_SERVER['SERVER_NAME'] . '<br/><br/>
					
					user_id: ' . $_SESSION['user_id'] . '<br/>
					script_url: ' . $site_url . '<br/>
					$_SERVER: ' . serialize($_SERVER) . '<br/><br/>
					
					. . . . . . . . <br/><br/>
												 
					_mysqli_query: ' . $sql . '<br/><br/>
					 
					mysql_error: ' . $clean['mysql_error_text'];
    $from = $_SERVER['SERVER_ADMIN'];
    $type = 3; // type 3 is mysql_error
               
    // send_email($to,$subject,$message,$from,$type);
               
    // report error to user and end page    ?>
<div class="warning" style="margin: 40px auto; width: 450px;">
	<div>
		<h3>A database error has occured, the webmaster has been notified</h3>
		<p>If this error persists, you may email us directly: <?php printf('<a href="mailto:%s">%s</a>',$_SERVER['SERVER_ADMIN'],$_SERVER['SERVER_ADMIN']); ?></p>
	</div>
</div>


<?php    
    die();
}

function getSplitTestValue(array $values)
{
    $sum = 0;
    
    foreach ($values as $key => $value) {
        if ($value['weight'] == 0) {
            unset($values[$key]);
        } else {
            $sum = $sum + $value['weight'];
        }
    }
    
    $rand = @mt_rand(1, (int) $sum);
    
    foreach ($values as $key => $value) {
        $rand -= $value['weight'];
        if ($rand <= 0) {
            return $key;
        }
    }
}

function get_absolute_url() {
	return substr(substr(dirname( __FILE__ ), 0,-10),strlen(realpath($_SERVER['DOCUMENT_ROOT'])));
}

function getTrackingDomain() {
    global $db;
    
    $tracking_domain_sql = "
		SELECT
			`user_tracking_domain`
		FROM
			`202_users_pref`
		WHERE
			`user_id`='1'
	";
    $tracking_domain_result = _mysqli_query($db, $tracking_domain_sql); //($user_sql);
    $tracking_domain_row = $tracking_domain_result->fetch_assoc();
    $tracking_domain = $_SERVER['SERVER_NAME'];
    if(strlen($tracking_domain_row['user_tracking_domain'])>0) {
        $tracking_domain = $tracking_domain_row['user_tracking_domain'];
    }
    return $tracking_domain;
}

function updateLpClickDataForRotator($redirect_id, $click_id, $rotator_id, $rule_id) {
    global $db;
    $click_sql = "
        REPLACE INTO
            202_clicks_rotator
        SET
            click_id='".$click_id."',
            rotator_id='".$rotator_id."',
            rule_id='".$rule_id."',
            rule_redirect_id = '".$redirect_id."'";
    $db->query($click_sql);
}

function parseUaForRotatorCriteria($detect, $parser, $ua) {
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

    return $result;
}

function ip_in_range($ip, $range) {
  if (strpos($range, '/') !== false) {
    list($range, $netmask) = explode('/', $range, 2);
    if (strpos($netmask, '.') !== false) {
      $netmask = str_replace('*', '0', $netmask);
      $netmask_dec = ip2long($netmask);
      return ( (ip2long($ip) & $netmask_dec) == (ip2long($range) & $netmask_dec) );
    } else {
      $x = explode('.', $range);
      while(count($x)<4) $x[] = '0';
      list($a,$b,$c,$d) = $x;
      $range = sprintf("%u.%u.%u.%u", empty($a)?'0':$a, empty($b)?'0':$b,empty($c)?'0':$c,empty($d)?'0':$d);
      $range_dec = ip2long($range);
      $ip_dec = ip2long($ip);

      $wildcard_dec = pow(2, (32-$netmask)) - 1;
      $netmask_dec = ~ $wildcard_dec;

      return (($ip_dec & $netmask_dec) == ($range_dec & $netmask_dec));
    }
  } else {
    if (strpos($range, '*') !==false) { 
      $lower = str_replace('*', '0', $range);
      $upper = str_replace('*', '255', $range);
      $range = "$lower-$upper";
    }

    if (strpos($range, '-')!==false) { // A-B format
      list($lower, $upper) = explode('-', $range, 2);
      $lower_dec = (float)sprintf("%u",ip2long($lower));
      $upper_dec = (float)sprintf("%u",ip2long($upper));
      $ip_dec = (float)sprintf("%u",ip2long($ip));
      return ( ($ip_dec>=$lower_dec) && ($ip_dec<=$upper_dec) );
    }

    return false;
  }
}

function getData($url)
{
if(function_exists('curl_version')){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
else{
    return false;
}
    
}

function getPublisher($pubid)
{
    global $db;
    
    $publisher_id_sql = "
		SELECT
			`user_id`
		FROM
			`202_users`
		WHERE
			`user_public_publisher_id`='".$pubid."'";
    
    $pubid_row = memcache_mysql_fetch_assoc($db, $publisher_id_sql);
    if($pubid_row){
        return $db->real_escape_string($pubid_row['user_id']);
    }
    else{
        return 1;
    }
    
}

function getTokens($mysql){
    $tokens = array(
        "subid" => $mysql['click_id'],
        "t202kw" => $mysql['t202kw'],
        "c1" => $mysql['c1'],
        "c2" => $mysql['c2'],
        "c3" => $mysql['c3'],
        "c4" => $mysql['c4'],
        "gclid" => $mysql['gclid'],
        "msclkid" => $mysql['msclkid'],
        "fbclid" => $mysql['fbclid'],
        "utm_source" => $mysql['utm_source'],
        "utm_medium" => $mysql['utm_medium'],
        "utm_campaign" => $mysql['utm_campaign'],
        "utm_term" => $mysql['utm_term'],
        "utm_content" => $mysql['utm_content'],
        "cpc" => round($mysql['cpc'], 2),
        "cpc2" => $mysql['cpc'],
        "timestamp" => time(),
        "payout" => $mysql['payout'],
        "random" => mt_rand(1000000, 9999999),
        "referer" => $mysql['referer'],
        "sourceid" => $mysql['ppc_account_id'],
        "transactionid" => $mysql['txid']
    );
    
    return $tokens;
}

function ipAddress($ip_address){

    $ip = new stdClass;
    
    if (filter_var($ip_address, FILTER_VALIDATE_IP)) {
        $ip->address=$ip_address;
        if (filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip->type='ipv4';
        } else {
            $ip->type='ipv6';
        }
    } else {
        $ip->type='invalid';
    }
    
    if(!trackingEnabled()){
        $ip=maskIpAddress($ip);
    }    

    $ip->address=@inet_ntop(inet_pton($ip->address)); //format ip addess in standard form
    return $ip;
}

function maskIpAddress($ip){
    
    if ($ip->type=='ipv4') {
        $bits=explode('.',$ip->address);
        $masked=implode(".",array_slice($bits,0, 3)).".0";
    } else if($ip->type=='ipv6'){
         $bits=explode(':',$ip->address);
         $masked=implode(":",array_slice($bits,0, 3)).":0000:0000:0000:0000:0000";   
    }
    if(isset($masked)){
        $ip->address = $masked;
    }
    
    return $ip;
}

function inet6_ntoa($ip){
    return @inet_ntop($ip);
}

function inet6_aton($ip){
    return @inet_pton($ip);
}    

function sanitizeIn($data){
    
}

function getTrackerDetail(&$mysql){
    global $db;
    $tracker_sql = "SELECT 202_trackers.user_id,
						202_trackers.aff_campaign_id,
						text_ad_id,
						ppc_account_id,
						click_cpc,
						click_cpa,
						click_cloaking,
						aff_campaign_rotate,
						aff_campaign_url,
						aff_campaign_url_2,
						aff_campaign_url_3,
						aff_campaign_url_4,
						aff_campaign_url_5,
						aff_campaign_payout,
						aff_campaign_cloaking,
						2cv.ppc_variable_ids,
						2cv.parameters,
                        user_timezone,
		                user_keyword_searched_or_bidded,
                        user_pref_referer_data,
                        user_pref_dynamic_bid,
		                maxmind_isp
                        FROM 202_trackers
                        LEFT JOIN 202_users_pref USING (user_id)
                LEFT JOIN 202_users USING (user_id)
    			LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)
				LEFT JOIN 202_ppc_accounts USING (ppc_account_id)
				LEFT JOIN (SELECT ppc_network_id, GROUP_CONCAT(ppc_variable_id) AS ppc_variable_ids, GROUP_CONCAT(parameter) AS parameters FROM 202_ppc_network_variables GROUP BY ppc_network_id) AS 2cv USING (ppc_network_id)
				WHERE tracker_id_public='".$mysql['tracker_id_public']."'";
    
 
    $tracker_row = memcache_mysql_fetch_assoc($db, $tracker_sql);

    //set all mysql vars
    $mysql['aff_campaign_id'] = $db->real_escape_string($tracker_row['aff_campaign_id']);
$mysql['ppc_account_id'] = $db->real_escape_string($tracker_row['ppc_account_id']);
$mysql['user_pref_dynamic_bid'] = $db->real_escape_string($tracker_row['user_pref_dynamic_bid']);
$mysql['user_pref_referer_data'] = $db->real_escape_string($tracker_row['user_pref_referer_data']);
// set cpc use dynamic variable if set or the default if not
if (isset ( $_GET ['t202b'] ) && $mysql['user_pref_dynamic_bid'] == '1') {
    $_GET ['t202b']=ltrim($_GET ['t202b'],'$');
    if(is_numeric ( $_GET ['t202b'] )){
        $bid = number_format ( $_GET ['t202b'], 5, '.', '' );
        $mysql ['click_cpc'] = $db->real_escape_string ( $bid );
    }
    else{
        $mysql ['click_cpc'] = $db->real_escape_string ( $tracker_row ['click_cpc'] );
    }
} else
    $mysql ['click_cpc'] = $db->real_escape_string ( $tracker_row ['click_cpc'] );
	
$mysql['click_cpa'] = $db->real_escape_string($tracker_row['click_cpa']);
$mysql['click_payout'] = $db->real_escape_string($tracker_row['aff_campaign_payout']);

$mysql['text_ad_id'] = $db->real_escape_string($tracker_row['text_ad_id']);

$mysql['user_keyword_searched_or_bidded'] = $db->real_escape_string($tracker_row['user_keyword_searched_or_bidded']);

$mysql['user_id'] = $db->real_escape_string($tracker_row['user_id']);
$mysql['user_timezone'] = $db->real_escape_string($tracker_row['user_timezone']);

$mysql['aff_campaign_url'] = $db->real_escape_string($tracker_row['aff_campaign_url']);

    return $tracker_row;
}

function getTrackerDetailPT(&$mysql){
    global $db;
    $tracker_sql = "SELECT 202_trackers.user_id,
						202_trackers.aff_campaign_id,
						text_ad_id,
						ppc_account_id,
						click_cpc,
						click_cpa,
						click_cloaking,
						aff_campaign_rotate,
                        landing_page_url,
						aff_campaign_url,
						aff_campaign_url_2,
						aff_campaign_url_3,
						aff_campaign_url_4,
						aff_campaign_url_5,
						aff_campaign_payout,
						aff_campaign_cloaking,
						2cv.ppc_variable_ids,
						2cv.parameters,
                        user_timezone,
		                user_keyword_searched_or_bidded,
                        user_pref_referer_data,
                        user_pref_dynamic_bid,
		                maxmind_isp
                        FROM 202_trackers
                        LEFT JOIN 202_users_pref USING (user_id)
                LEFT JOIN 202_users USING (user_id)
    			LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)
				LEFT JOIN 202_ppc_accounts USING (ppc_account_id)
                LEFT JOIN 202_landing_pages USING (landing_page_id)
				LEFT JOIN (SELECT ppc_network_id, GROUP_CONCAT(ppc_variable_id) AS ppc_variable_ids, GROUP_CONCAT(parameter) AS parameters FROM 202_ppc_network_variables GROUP BY ppc_network_id) AS 2cv USING (ppc_network_id)
				WHERE tracker_id_public='".$mysql['tracker_id_public']."'";
    
 
    $tracker_row = memcache_mysql_fetch_assoc($db, $tracker_sql);

    //set all mysql vars
    $mysql['aff_campaign_id'] = $db->real_escape_string($tracker_row['aff_campaign_id']);
$mysql['ppc_account_id'] = $db->real_escape_string($tracker_row['ppc_account_id']);
$mysql['user_pref_dynamic_bid'] = $db->real_escape_string($tracker_row['user_pref_dynamic_bid']);
$mysql['user_pref_referer_data'] = $db->real_escape_string($tracker_row['user_pref_referer_data']);
// set cpc use dynamic variable if set or the default if not
if (isset ( $_GET ['t202b'] ) && $mysql['user_pref_dynamic_bid'] == '1') {
    $_GET ['t202b']=ltrim($_GET ['t202b'],'$');
    if(is_numeric ( $_GET ['t202b'] )){
        $bid = number_format ( $_GET ['t202b'], 5, '.', '' );
        $mysql ['click_cpc'] = $db->real_escape_string ( $bid );
    }
    else{
        $mysql ['click_cpc'] = $db->real_escape_string ( $tracker_row ['click_cpc'] );
    }
} else
    $mysql ['click_cpc'] = $db->real_escape_string ( $tracker_row ['click_cpc'] );
	
$mysql['click_cpa'] = $db->real_escape_string($tracker_row['click_cpa']);
$mysql['click_payout'] = $db->real_escape_string($tracker_row['aff_campaign_payout']);

$mysql['text_ad_id'] = $db->real_escape_string($tracker_row['text_ad_id']);

$mysql['user_keyword_searched_or_bidded'] = $db->real_escape_string($tracker_row['user_keyword_searched_or_bidded']);

$mysql['user_id'] = $db->real_escape_string($tracker_row['user_id']);
$mysql['user_timezone'] = $db->real_escape_string($tracker_row['user_timezone']);

$mysql['aff_campaign_url'] = $db->real_escape_string($tracker_row['aff_campaign_url']);

    return $tracker_row;
}

function getClickId(){
    global $db;
    
    $click_sql = "INSERT INTO  202_clicks_counter SET click_id=DEFAULT";
    $click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);
    
    //now gather the info for the advance click insert
    $click_id = $db->insert_id;
    return $db->real_escape_string($click_id);
}

function getClickIdPublic($click_id){
    global $db;

    return $db->real_escape_string(rand(1,9) . $click_id . rand(1,9));
}

function insertClicks($mysql){
    global $db;

    if(!$mysql['ppc_account_id']){
        $mysql['ppc_account_id']='0';
    }

    if(!$mysql['click_cpc']){
        $mysql['click_cpc']='0';
    }
    
    switch ($mysql['lp_type']){
        case 'dl': 
        case 'rtr':
            $click_sql = "INSERT IGNORE INTO 202_clicks
			  SET           	click_id='".$mysql['click_id']."',
							user_id = '".$mysql['user_id']."',
							aff_campaign_id = '".$mysql['aff_campaign_id']."',
							ppc_account_id = '".$mysql['ppc_account_id']."',
							click_cpc = '".$mysql['click_cpc']."',
							click_payout = '".$mysql['click_payout']."',
							click_alp = '".$mysql['click_alp']."',
							click_filtered = '".$mysql['click_filtered']."',
							click_bot = '".$mysql['click_bot']."',
							click_time = '".$mysql['click_time']."'";
         break;
         case 'slp':
             $click_sql = "INSERT INTO   202_clicks
			  SET           click_id='" . $mysql['click_id'] . "',
							user_id = '" . $mysql['user_id'] . "',
							aff_campaign_id = '" . $mysql['aff_campaign_id'] . "',
							landing_page_id='" . $mysql['landing_page_id'] . "',
							ppc_account_id = '" . $mysql['ppc_account_id'] . "',
							click_cpc = '" . $mysql['click_cpc'] . "',
							click_payout = '" . $mysql['click_payout'] . "',
							click_filtered = '" . $mysql['click_filtered'] . "',
							click_bot = '" . $mysql['click_bot'] . "',
							click_alp = '" . $mysql['click_alp'] . "',
							click_time = '".$mysql['click_time']."'
							ON DUPLICATE KEY UPDATE
							 click_alp = '".$mysql['click_alp']."'";
             break;
          case 'alp':
              $click_sql = "INSERT INTO   202_clicks
			  SET           	click_id='".$mysql['click_id']."',
							user_id = '".$mysql['user_id']."',
							landing_page_id='".$mysql['landing_page_id']."',
							ppc_account_id = '".$mysql['ppc_account_id']."',
							click_cpc = '".$mysql['click_cpc']."',
							click_filtered = '".$mysql['click_filtered']."',
							click_bot = '".$mysql['click_bot']."',
							click_alp = '".$mysql['click_alp']."',
							click_time = '".$mysql['click_time']."'
							ON DUPLICATE KEY UPDATE
							 click_alp = '".$mysql['click_alp']."'";
            break;
    }
    
    return $click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);
    
}

function insertGclid($mysql){
    global $db;
    // insert gclid and utm vars
    if ($mysql['gclid'] || $mysql['utm_source_id'] || $mysql['utm_medium_id'] || $mysql['utm_campaign_id'] || $mysql['utm_term_id'] || $mysql['utm_content_id']) {
    $click_sql = "REPLACE INTO 202_google
			  SET           click_id='" . $mysql['click_id'] . "',
							gclid = '" . $mysql['gclid'] . "',
                            utm_source_id = '" . $mysql['utm_source_id'] . "',
                            utm_medium_id = '" . $mysql['utm_medium_id'] . "',
                            utm_campaign_id = '" . $mysql['utm_campaign_id'] . "',
                            utm_term_id = '" . $mysql['utm_term_id'] . "',
                            utm_content_id = '" . $mysql['utm_content_id'] . "'";
    return $click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);
    }
}

function getGclid($mysql){
    global $db;
    // get click id
    if (isset($mysql['gclid'])){
        
    $click_sql = "SELECT 202_google.click_id, click_id_public , click_time, c1,c2,c3,c4, keyword AS t202kw, `utm_campaign`, `utm_source`, `utm_source`, `utm_medium`, `utm_term`, `utm_content` FROM 202_google
    LEFT JOIN 202_clicks_advance USING(click_id)
    LEFT JOIN 202_keywords USING(keyword_id)
    LEFT JOIN 202_clicks_tracking USING(click_id)
    LEFT JOIN 202_tracking_c1 USING(c1_id)
    LEFT JOIN 202_tracking_c2 USING(c2_id)
    LEFT JOIN 202_tracking_c3 USING(c3_id)
    LEFT JOIN 202_tracking_c4 USING(c4_id)
    LEFT JOIN 202_utm_campaign USING(utm_campaign_id)
    LEFT JOIN 202_utm_source USING(utm_source_id)
    LEFT JOIN 202_utm_medium USING(utm_medium_id)
    LEFT JOIN 202_utm_term USING(utm_term_id)
    LEFT JOIN 202_utm_content USING(utm_content_id)
    LEFT JOIN 202_clicks_record USING(click_id)
    LEFT JOIN 202_clicks USING(click_id)
    WHERE  gclid =  '" . $mysql['gclid'] . "'";
    // $click_row = memcache_mysql_fetch_assoc($db,$click_sql);    
    $click_result = $db->query($click_sql);
    $click_row = $click_result->fetch_assoc();
    }else{
         $click_row = null;
    }
    return $click_row;
}

function getClickData($mysql){
    global $db;
    // get click id
    if (isset($mysql['click_id'])){
        
    $click_sql = "SELECT click_id,click_id_public, click_time, c1,c2,c3,c4, keyword AS t202kw,`utm_campaign`, `utm_source`, `utm_source`, `utm_medium`, `utm_term`, `utm_content` FROM 202_clicks
	LEFT JOIN 202_clicks_tracking USING(click_id)
	LEFT JOIN 202_clicks_record USING(click_id)
    LEFT JOIN 202_clicks_advance USING(click_id)
    LEFT JOIN 202_google USING(click_id)
    LEFT JOIN 202_keywords USING(keyword_id)
    LEFT JOIN 202_tracking_c1 USING(c1_id)
    LEFT JOIN 202_tracking_c2 USING(c2_id)
    LEFT JOIN 202_tracking_c3 USING(c3_id)
    LEFT JOIN 202_tracking_c4 USING(c4_id)
    LEFT JOIN 202_utm_campaign USING(utm_campaign_id)
    LEFT JOIN 202_utm_source USING(utm_source_id)
    LEFT JOIN 202_utm_medium USING(utm_medium_id)
    LEFT JOIN 202_utm_term USING(utm_term_id)
    LEFT JOIN 202_utm_content USING(utm_content_id)
    WHERE  202_clicks.click_id =  '" . $mysql['click_id'] . "'
    LIMIT 1";

   // echo $click_sql;
    // $click_row = memcache_mysql_fetch_assoc($db,$click_sql);    
    $click_result = $db->query($click_sql);
    $click_row = $click_result->fetch_assoc();
    }else{
         $click_row = null;
    }
    return $click_row;
}

function insertMsclkid($mysql){
    global $db;
    // insert msclkid and utm vars
    if ($mysql['msclkid'] || $mysql['utm_source_id'] || $mysql['utm_medium_id'] || $mysql['utm_campaign_id'] || $mysql['utm_term_id'] || $mysql['utm_content_id']) {
    $click_sql = "REPLACE INTO   202_bing
			  SET           click_id='" . $mysql['click_id'] . "',
							msclkid = '" . $mysql['msclkid'] . "',
                            utm_source_id = '" . $mysql['utm_source_id'] . "',
                            utm_medium_id = '" . $mysql['utm_medium_id'] . "',
                            utm_campaign_id = '" . $mysql['utm_campaign_id'] . "',
                            utm_term_id = '" . $mysql['utm_term_id'] . "',
                            utm_content_id = '" . $mysql['utm_content_id'] . "'";
    return $click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);}
}

function insertFbclid($mysql){
    global $db;
    // insert fbclid and utm vars
    if ($mysql['fbclid'] || $mysql['utm_source_id'] || $mysql['utm_medium_id'] || $mysql['utm_campaign_id'] || $mysql['utm_term_id'] || $mysql['utm_content_id']) {
    $click_sql = "REPLACE INTO 202_facebook
			  SET           click_id='" . $mysql['click_id'] . "',
							fbclid = '" . $mysql['fbclid'] . "',
                            utm_source_id = '" . $mysql['utm_source_id'] . "',
                            utm_medium_id = '" . $mysql['utm_medium_id'] . "',
                            utm_campaign_id = '" . $mysql['utm_campaign_id'] . "',
                            utm_term_id = '" . $mysql['utm_term_id'] . "',
                            utm_content_id = '" . $mysql['utm_content_id'] . "'";
    return $click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);
    }
}

function insertClicksVariable($mysql,$tracker_row){
    global $db;
    $custom_var_ids = array();
    
    $ppc_variable_ids = explode(',', $tracker_row['ppc_variable_ids']);
    $parameters = explode(',', $tracker_row['parameters']);

    foreach ($parameters as $key => $value) {
        if(isset($_GET[$value])){
            $variable = $db->real_escape_string($_GET[$value]);
            if (isset($variable) && $variable != '') {
                $variable = str_replace('%20',' ',$variable);
                $variable_id = INDEXES::get_variable_id($db, $variable, $ppc_variable_ids[$key]);
                $custom_var_ids[] = $variable_id;
            }
        }
        /* this was causing a bug
        else{
            $custom_var_ids[] = '';
        }*/
    }
    
    $total_vars = count($custom_var_ids);
    
    if ($total_vars > 0) {
    
        $variables = implode (",", $custom_var_ids);
        $variable_set_id = INDEXES::get_variable_set_id($db, $variables);
    
        $mysql['variable_set_id'] = $db->real_escape_string($variable_set_id);
    
        $var_sql = "INSERT IGNORE INTO 202_clicks_variable (click_id, variable_set_id) VALUES ('".$mysql['click_id']."', '".$mysql['variable_set_id']."')";
        return $var_result = $db->query($var_sql) or record_mysql_error($db, $var_sql);
    }
    
} 

function insertClicksSite($mysql){
    global $db;
    
    switch ($mysql['lp_type']){
        case 'dl':
        case 'rtr':
            $click_sql = "INSERT IGNORE INTO 202_clicks_site
			  SET           click_id='".$mysql['click_id']."',
							click_referer_site_url_id='".$mysql['click_referer_site_url_id']."',
							click_outbound_site_url_id='".$mysql['click_outbound_site_url_id']."',
							click_redirect_site_url_id='".$mysql['click_redirect_site_url_id']."'";
            break;
        case 'slp':
            $click_sql = "REPLACE INTO  202_clicks_site
			  SET           click_id='" . $mysql['click_id'] . "',
							click_referer_site_url_id='" . $mysql['click_referer_site_url_id'] . "',
							click_landing_site_url_id='" . $mysql['click_landing_site_url_id'] . "',
							click_outbound_site_url_id='" . $mysql['click_outbound_site_url_id'] . "',
							click_cloaking_site_url_id='" . $mysql['click_cloaking_site_url_id'] . "',
							click_redirect_site_url_id='" . $mysql['click_redirect_site_url_id'] . "'";
            break;
        case 'alp':
            $click_sql = "INSERT IGNORE INTO   202_clicks_site
			  SET           	click_id='".$mysql['click_id']."',
							click_referer_site_url_id='".$mysql['click_referer_site_url_id']."',
							click_landing_site_url_id='".$mysql['click_landing_site_url_id']."',
							click_outbound_site_url_id='0',
							click_cloaking_site_url_id='0',
							click_redirect_site_url_id='0'
							ON DUPLICATE KEY UPDATE
							click_referer_site_url_id = '".$mysql['click_referer_site_url_id']."',
							click_landing_site_url_id='".$mysql['click_landing_site_url_id']."'";
            break;
    }
    
    
    return $click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);   
        
}

function insertClicksRecord($mysql){
    global $db;
    $click_sql = "INSERT IGNORE INTO   202_clicks_record
			  SET           click_id='".$mysql['click_id']."',
							click_id_public='".$mysql['click_id_public']."',
							click_cloaking='".$mysql['click_cloaking']."',
							click_in='".$mysql['click_in']."',
							click_out='".$mysql['click_out']."'";
    return $click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);
}

function insertClicksAdvance($mysql){
    global $db;
    $click_sql = "INSERT IGNORE INTO  202_clicks_advance
			  SET           	click_id='".$mysql['click_id']."',
							text_ad_id='".$mysql['text_ad_id']."',
							keyword_id='".$mysql['keyword_id']."',
							ip_id='".$mysql['ip_id']."',
							country_id='".$mysql['country_id']."',
							region_id='".$mysql['region_id']."',
							isp_id='".$mysql['isp_id']."',
							city_id='".$mysql['city_id']."',
							platform_id='".$mysql['platform_id']."',
							browser_id='".$mysql['browser_id']."',
                            device_id='".$mysql['device_id']."'";
    return $click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);
}

function insertClicksTracking($mysql){
    global $db;
    $click_sql = "INSERT IGNORE INTO
		202_clicks_tracking
	SET
		click_id='".$mysql['click_id']."',
		c1_id = '".$mysql['c1_id']."',
		c2_id = '".$mysql['c2_id']."',
		c3_id = '".$mysql['c3_id']."',
		c4_id = '".$mysql['c4_id']."'";
    $click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);
    return $click_result;
}


function processCacheRedirect(){
    global $db;
    $usedCachedRedirect = false;
    if (!$db) $usedCachedRedirect = true;
    
    #the mysql server is down, use the cached redirect
    if ($usedCachedRedirect==true) {
    
        $t202id = $_GET['t202id'];
    
        //if a cached key is found for this t202id, redirect to that url
        if ($memcacheWorking) {
            $getUrl = $memcache->get(md5('url_'.$t202id.systemHash()));
            if ($getUrl) {
    
                $new_url = str_replace("[[subid]]", "p202", $getUrl);
    
                // t202pubid string replace for cached redirect
                if(isset($_GET['t202pubid']) && $_GET['t202pubid'] != ''){
                    $new_url = str_replace("[[t202pubid]]", $_GET['t202pubid'], $new_url);
                }	else {
                    $new_url = str_replace("[[t202pubid]]", "t202pubid", $new_url);
                }
    
                //c1 string replace for cached redirect
                if(isset($_GET['c1']) && $_GET['c1'] != ''){
                    $new_url = str_replace("[[c1]]", $_GET['c1'], $new_url);
                }	else {
                    $new_url = str_replace("[[c1]]", "p202c1", $new_url);
                }
    
                //c2 string replace for cached redirect
                if(isset($_GET['c2']) && $_GET['c2'] != ''){
                    $new_url = str_replace("[[c2]]", $_GET['c2'], $new_url);
                }	else {
                    $new_url = str_replace("[[c2]]", "p202c2", $new_url);
                }
    
                //c3 string replace for cached redirect
                if(isset($_GET['c3']) && $_GET['c3'] != ''){
                    $new_url = str_replace("[[c3]]", $_GET['c3'], $new_url);
                }	else {
                    $new_url = str_replace("[[c3]]", "p202c3", $new_url);
                }
    
                //c4 string replace for cached redirect
                if(isset($_GET['c4']) && $_GET['c4'] != ''){
                    $new_url = str_replace("[[c4]]", $_GET['c4'], $new_url);
                }	else {
                    $new_url = str_replace("[[c4]]", "p202c4", $new_url);
                }
    
                //gclid string replace for cached redirect
                if(isset($_GET['gclid']) && $_GET['gclid'] != ''){
                    $new_url = str_replace("[[gclid]]", $_GET['gclid'], $new_url);
                }	else {
                    $new_url = str_replace("[[gclid]]", "p202gclid", $new_url);
                }
    
                //msclkid string replace for cached redirect
                if(isset($_GET['msclkid']) && $_GET['msclkid'] != ''){
                    $new_url = str_replace("[[msclkid]]", $_GET['msclkid'], $new_url);
                }	else {
                    $new_url = str_replace("[[msclkid]]", "p202msclkid", $new_url);
                }

                //fbclid string replace for cached redirect
                if(isset($_GET['fbclid']) && $_GET['fbclid'] != ''){
                    $new_url = str_replace("[[fbclid]]", $_GET['fbclid'], $new_url);
                }	else {
                    $new_url = str_replace("[[fbclid]]", "p202fbclid", $new_url);
                }
                
                //utm_source string replace for cached redirect
                if(isset($_GET['utm_source']) && $_GET['utm_source'] != ''){
                    $new_url = str_replace("[[utm_source]]", $_GET['utm_source'], $new_url);
                }	else {
                    $new_url = str_replace("[[utm_source]]", "p202utm_source", $new_url);
                }
    
                //utm_medium string replace for cached redirect
                if(isset($_GET['utm_medium']) && $_GET['utm_medium'] != ''){
                    $new_url = str_replace("[[utm_medium]]", $_GET['utm_medium'], $new_url);
                }	else {
                    $new_url = str_replace("[[utm_medium]]", "p202utm_medium", $new_url);
                }
    
                //utm_campaign string replace for cached redirect
                if(isset($_GET['utm_campaign']) && $_GET['utm_campaign'] != ''){
                    $new_url = str_replace("[[utm_campaign]]", $_GET['utm_campaign'], $new_url);
                }	else {
                    $new_url = str_replace("[[utm_campaign]]", "p202utm_campaign", $new_url);
                }
    
                //utm_term string replace for cached redirect
                if(isset($_GET['utm_term']) && $_GET['utm_term'] != ''){
                    $new_url = str_replace("[[utm_term]]", $_GET['utm_term'], $new_url);
                }	else {
                    $new_url = str_replace("[[utm_term]]", "p202utm_term", $new_url);
                }
    
                //utm_content string replace for cached redirect
                if(isset($_GET['utm_content']) && $_GET['utm_content'] != ''){
                    $new_url = str_replace("[[utm_content]]", $_GET['utm_content'], $new_url);
                }	else {
                    $new_url = str_replace("[[utm_content]]", "p202utm_content", $new_url);
                }
    
                //show url or redirect to url
                if(isset($_GET['202rdu']) && $_GET['202rdu'] != ''){
                    echo $new_url;
                }
                else{
                    header('location: '. $new_url);
                }
                	
                die();
    
            }
        }
        die();
    }
}

function setDirtyHour($mysql){   
    $de = new DataEngine();
    $de->setDirtyHour($mysql['click_id']);
    
}

function isSSL() {
     return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || intval($_SERVER['SERVER_PORT']) == intval(getservbyname('https', 'tcp')); 
}

function getScheme(){
    if(isSSL()){
        $scheme = 'https';
    } else{
        $scheme = 'http';
    }

    return $scheme;
}

function is_prefetch(){
	$prefetch = false;
	
	if (isset($_SERVER["HTTP_X_PURPOSE"]) && $_SERVER['HTTP_X_PURPOSE'] == "preview"){
			$prefetch = true;
	}elseif (isset($_SERVER["HTTP_PURPOSE"]) && $_SERVER['HTTP_PURPOSE'] == "prefetch"){
			$prefetch = true;
	}elseif (isset($_SERVER["HTTP_X_MOZ"]) && $_SERVER['HTTP_X_MOZ'] == "prefetch"){
			$prefetch = true;
	}
	
	return $prefetch;
	
    }
    
function getUTMParams(&$mysql){
    global $db;
    //utm_source
	$utm_source = $db->real_escape_string($_GET['utm_source']);
	if(isset($utm_source) && $utm_source != '')
	{
	$utm_source = str_replace('%20',' ',$utm_source);
	$utm_source_id = INDEXES::get_utm_id($db, $utm_source, 'utm_source');
	}
	else{
		$utm_source_id=0;
	}
	$mysql['utm_source_id']=$db->real_escape_string($utm_source_id);
	$mysql['utm_source']=$db->real_escape_string($utm_source);
	
	//utm_medium
	$utm_medium = $db->real_escape_string($_GET['utm_medium']);
	if(isset($utm_medium) && $utm_medium != '')
	{
		$utm_medium = str_replace('%20',' ',$utm_medium);
		$utm_medium_id = INDEXES::get_utm_id($db, $utm_medium, 'utm_medium');
	}
	else{
		$utm_medium_id=0;
	}
	$mysql['utm_medium_id']=$db->real_escape_string($utm_medium_id);
	$mysql['utm_medium']=$db->real_escape_string($utm_medium);
	
	//utm_campaign
	$utm_campaign = $db->real_escape_string($_GET['utm_campaign']);
	if(isset($utm_campaign) && $utm_campaign != '')
	{
		$utm_campaign = str_replace('%20',' ',$utm_campaign);
		$utm_campaign_id = INDEXES::get_utm_id($db, $utm_campaign, 'utm_campaign');
	}
	else{
		$utm_campaign_id=0;
	}
	$mysql['utm_campaign_id']=$db->real_escape_string($utm_campaign_id);
	$mysql['utm_campaign']=$db->real_escape_string($utm_campaign);
	
	//utm_term
	$utm_term = $db->real_escape_string($_GET['utm_term']);
	if(isset($utm_term) && $utm_term != '')
	{
		$utm_term = str_replace('%20',' ',$utm_term);
		$utm_term_id = INDEXES::get_utm_id($db, $utm_term, 'utm_term');
	}
	else{
		$utm_term_id=0;
	}
	$mysql['utm_term_id']=$db->real_escape_string($utm_term_id);
	$mysql['utm_term']=$db->real_escape_string($utm_term);
	
	//utm_content
	$utm_content = $db->real_escape_string($_GET['utm_content']);
	if(isset($utm_content) && $utm_content != '')
	{
		$utm_content = str_replace('%20',' ',$utm_content);
		$utm_content_id = INDEXES::get_utm_id($db, $utm_content, 'utm_content');
	}
	else{
		$utm_content_id=0;
	}
	$mysql['utm_content_id']=$db->real_escape_string($utm_content_id);
	$mysql['utm_content']=$db->real_escape_string($utm_content);
	
}

function updateImpressionPixel(&$mysql){
    global $db;
    if(null !== (getCookie202('p202_ipx'))) {
        $mysql['p202_ipx'] = $db->real_escape_string(getCookie202('p202_ipx'));
        $db->query("UPDATE 202_clicks_impressions SET click_id = '".$mysql['click_id']."' WHERE impression_id = '".$mysql['p202_ipx']."'");
    }    
}

function getCVars(&$mysql){
    
    global $db;
    
    $_lGET = array_change_key_case($_GET, CASE_LOWER); //make lowercase copy of get 

    //Get C1-C4 IDs
    for ($i=1;$i<=4;$i++){
        $custom= "c".$i; //create dynamic variable
        $custom_val=$db->real_escape_string($_lGET[$custom]); // get the value

        if(isset($custom_val) && $custom_val !=''){ //if there's a value get an id
            $custom_val = str_replace(' ','+',$custom_val);
            $custom_id = INDEXES::get_custom_var_id($db, $custom, $custom_val); //get the id
            $mysql[$custom.'_id']=$db->real_escape_string($custom_id); //save it
            $mysql[$custom]=$db->real_escape_string($custom_val); //save it
        }else{
	    	$mysql[$custom.'_id']='0';
	    }
    }
}

function getKeyword(&$mysql){
    global $db;
    /* ok, if $_GET['OVRAW'] that is a yahoo keyword, if on the REFER, there is a $_GET['q], that is a GOOGLE keyword... */
//so this is going to check the REFERER URL, for a ?q=, which is the ACUTAL KEYWORD searched.
$referer_url_parsed = @parse_url($_SERVER['HTTP_REFERER']);
$referer_url_query = $referer_url_parsed['query'];

@parse_str($referer_url_query, $referer_query);

switch ($mysql['user_keyword_searched_or_bidded']) { 

	case "bidded":
	      #try to get the bidded keyword first
		if ($_GET['OVKEY']) { //if this is a Y! keyword
			$keyword = $db->real_escape_string($_GET['OVKEY']);   
		}  elseif ($_GET['t202kw']) { 
			$keyword = $db->real_escape_string($_GET['t202kw']);  
		} elseif ($_GET['target_passthrough']) { //if this is a mediatraffic! keyword
			$keyword = $db->real_escape_string($_GET['target_passthrough']);   
		} else { //if this is a zango, or more keyword
			$keyword = $db->real_escape_string($_GET['keyword']);   
		} 
	break;
	case "searched":
		#try to get the searched keyword
		if ($referer_query['q']) { 
			$keyword = $db->real_escape_string($referer_query['q']);
		} elseif ($_GET['OVRAW']) { //if this is a Y! keyword
			$keyword = $db->real_escape_string($_GET['OVRAW']);   
		} elseif ($_GET['target_passthrough']) { //if this is a mediatraffic! keyword
			$keyword = $db->real_escape_string($_GET['target_passthrough']);   
		} elseif ($_GET['keyword']) { //if this is a zango, or more keyword
			$keyword = $db->real_escape_string($_GET['keyword']);   
		} elseif ($_GET['search_word']) { //if this is a eniro, or more keyword
			$keyword = $db->real_escape_string($_GET['search_word']);   
		} elseif ($_GET['query']) { //if this is a naver, or more keyword
			$keyword = $db->real_escape_string($_GET['query']);   
		} elseif ($_GET['encquery']) { //if this is a aol, or more keyword
			$keyword = $db->real_escape_string($_GET['encquery']);   
		} elseif ($_GET['terms']) { //if this is a about.com, or more keyword
			$keyword = $db->real_escape_string($_GET['terms']);   
		} elseif ($_GET['rdata']) { //if this is a viola, or more keyword
			$keyword = $db->real_escape_string($_GET['rdata']);   
		} elseif ($_GET['qs']) { //if this is a virgilio, or more keyword
			$keyword = $db->real_escape_string($_GET['qs']);   
		} elseif ($_GET['wd']) { //if this is a baidu, or more keyword
			$keyword = $db->real_escape_string($_GET['wd']);   
		} elseif ($_GET['text']) { //if this is a yandex, or more keyword
			$keyword = $db->real_escape_string($_GET['text']);   
		} elseif ($_GET['szukaj']) { //if this is a wp.pl, or more keyword
			$keyword = $db->real_escape_string($_GET['szukaj']);   
		} elseif ($_GET['qt']) { //if this is a O*net, or more keyword
			$keyword = $db->real_escape_string($_GET['qt']);   
		} elseif ($_GET['k']) { //if this is a yam, or more keyword
			$keyword = $db->real_escape_string($_GET['k']);   
		} elseif ($_GET['words']) { //if this is a Rambler, or more keyword
			$keyword = $db->real_escape_string($_GET['words']);   
		} else { 
			$keyword = $db->real_escape_string($_GET['t202kw']);
		}
	break;
}

if (substr($keyword, 0, 8) == 't202var_') {
	$t202var = substr($keyword, strpos($keyword, "_") + 1);

	if (isset($_GET[$t202var])) {
		$keyword = $_GET[$t202var];
	}
}

$keyword = str_replace('%20',' ',$keyword);      
$keyword_id = INDEXES::get_keyword_id($db, $keyword); 
$mysql['keyword_id'] = $db->real_escape_string($keyword_id); 
$mysql['keyword'] = $db->real_escape_string($keyword);	
}

function getReferer(&$mysql){
    global $db;
// if user wants to use t202ref from url variable use that first if it's not set try and get it from the ref url
if ($mysql['user_pref_referer_data'] == 't202ref') {
    if (isset($_GET['t202ref']) && $_GET['t202ref'] != '') { //check for t202ref value
        $mysql['t202ref']= $db->real_escape_string($_GET['t202ref']);
        $click_referer_site_url_id = INDEXES::get_site_url_id($db, $mysql['t202ref']);
    } else { //if not found revert to what we usually do
        if ($referer_query['url']) {
            $click_referer_site_url_id = INDEXES::get_site_url_id($db, $referer_query['url']);
        } else {
            $click_referer_site_url_id = INDEXES::get_site_url_id($db, $_SERVER['HTTP_REFERER']);
        }
    }
} else { //user wants the real referer first

    // now lets get variables for clicks site
    // so this is going to check the REFERER URL, for a ?url=, which is the ACUTAL URL, instead of the google content, pagead2.google....
    if ($referer_query['url']) {
        $click_referer_site_url_id = INDEXES::get_site_url_id($db, $referer_query['url']);
    } else {
        $click_referer_site_url_id = INDEXES::get_site_url_id($db, $_SERVER['HTTP_REFERER']);
    }
}

$mysql['click_referer_site_url_id'] = $db->real_escape_string($click_referer_site_url_id);     
}

function getForeignPayout($currency, $payout_currency, $payout)
{
    $fields = array(
        'currency' => $currency,
        'payout_currency' => $payout_currency,
        'payout' => $payout*10000   //multiply by 10000 to get more accurate exchange rate
    );

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/get-foreign-payout');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,0); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    
    curl_close($ch);
    $result = json_decode($result, true);
    
    $result['exchange_payout'] = $result['exchange_payout'] /10000;
    return $result;
}

function updateForeignPayout(&$mysql){
    global $db;
    // update currency value
    if (isset($_GET['amount']) && is_numeric($_GET['amount'])) {
     $mysql['fpa']  = $_GET['amount'];
    } 
    else{
        $mysql['fpa']  = $_GET['aff_campaign_foreign_payout']  ;
    }       
    if (isset($mysql['aff_campaign_currency']) && isset($mysql['user_account_currency']) && isset($mysql['aff_campaign_id']) && ( $mysql['aff_campaign_currency'] != $mysql['user_account_currency'])){
        
        $exchangePayout =getForeignPayout($mysql['user_account_currency'], $mysql['aff_campaign_currency'], $mysql['fpa']);
        
        $mysql['aff_campaign_payout'] = $db->real_escape_string($exchangePayout['exchange_payout']);  
        
        //if a payout was set in the postback or pixel then use that but don't update the default campaign info
        if (isset($_GET['amount']) && is_numeric($_GET['amount'])) {
          
            $mysql['payout'] = $mysql['click_payout'] * $mysql['aff_campaign_payout'] / $mysql['fpa']; // calculate the value without doing a second api call for exchange rate
            $mysql['aff_campaign_payout'] = $mysql['payout'];
        }else{ //
            $mysql['payout'] = $db->real_escape_string($exchangePayout['exchange_payout']);  

            $aff_campaign_sql = "UPDATE `202_aff_campaigns` SET";        
            $aff_campaign_sql .= " `aff_campaign_payout`='" . $mysql ['aff_campaign_payout'] . "' ";
            $aff_campaign_sql .= "WHERE `aff_campaign_id`='" . $mysql ['aff_campaign_id'] . "'";
            $db->query ( $aff_campaign_sql ) ;
            
    
        }
              
        $click_sql = "UPDATE `202_clicks` SET";        
        $click_sql .= " `click_payout`='" . $mysql ['aff_campaign_payout'] . "' ";
        $click_sql .= "WHERE `click_id`='" . $mysql ['click_id'] . "'";
        $db->query ( $click_sql ) ;
        
    }
}

function getDynamicEPVPixelId(&$mysql){
    global $db;

    if(isset($mysql['ppc_account_id']) && isset($mysql['b202_fbpa_dynamic_epv']) && $mysql['ppc_account_id'] != '' && $mysql['b202_fbpa_dynamic_epv'] != ''){
        $dynamic_epv_sql = "SELECT SUM(`income`)/SUM(`click_out`) as dynamic_epv_value, pixel_code AS pixel_id 
                            FROM `202_dataengine` 
                            LEFT JOIN `202_ppc_account_pixels` USING (`ppc_account_id`)
                            WHERE `ppc_account_id`= ".$mysql['ppc_account_id']." AND `pixel_type_id`= 6 AND `click_time` >= UNIX_TIMESTAMP(TIMESTAMPADD(DAY,-7,NOW()))
        
        ";
        
        $dynamic_epv_result = $db->query($dynamic_epv_sql);
        $dynamic_epv_row=$dynamic_epv_result->fetch_assoc();
        
        if($dynamic_epv_row['dynamic_epv_value']){
            $mysql['dynamic_epv_value']= number_format($dynamic_epv_row['dynamic_epv_value'],2);
        }else{
        $mysql['dynamic_epv_value']= 0;
        }

        if($dynamic_epv_row['pixel_id']){
            $mysql['pixel_id']= $dynamic_epv_row['pixel_id'];
        }else{
        $mysql['pixel_id']= 0;
        }
    }
}

function getPayout(&$mysql){
global $db;
    
    $sql ="
    SELECT 
        2c.click_payout
    FROM `202_clicks` AS 2c    
    WHERE 2c.`click_id` = {$mysql['click_id']}
    LIMIT 1";

    $sql_result = $db->query($sql);    
    $sql_row = $sql_result->fetch_assoc();
            
    $mysql['click_payout'] = $db->real_escape_string($sql_row['click_payout']);   
}

function getUrlVars202(){
    $urlvarslist = [];
    $temp=explode('&',$_SERVER['QUERY_STRING']);
    
    foreach ($temp as $key => $value) {
    	$subkv=explode('=',$value);
	    $key=$subkv[0];
	    $value=$subkv[1];
	    $urlvarslist[$key]=$value;
    }

    $urlvarslist = filter_var_array($urlvarslist, FILTER_SANITIZE_URL);

    return $urlvarslist;
}

function getCookie202($cookieName){
    $cookieValue = null;
    $legacyCookie = $cookieName.'-legacy';
    // check new format
    if(isset($_COOKIE[$cookieName])){
        $cookieValue=$_COOKIE[$cookieName];
    }// if not found check legacy
    else{
        if(isset($_COOKIE[$legacyCookie])){
            $cookieValue=$_COOKIE[$legacyCookie];     
        }   
    }
  return $cookieValue;
}