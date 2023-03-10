<?php
$version = '1.9.55';

DEFINE('TRACKING202_RSS_URL', 'http://rss.tracking202.com');
DEFINE('TRACKING202_ADS_URL', 'https://ads.tracking202.com');

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
@ini_set('set_time_limit', 0);

if(!$_SESSION['user_timezone'])
{
   date_default_timezone_set('GMT');
} else {
	date_default_timezone_set($_SESSION['user_timezone']);
}

mysqli_report(MYSQLI_REPORT_STRICT);

$install_path = substr(ROOT_PATH,strlen($_SERVER['DOCUMENT_ROOT']));

$re = '/\b(api|tracking202|202-\w+).*/';
$str = $_SERVER['REQUEST_URI'];
preg_match_all($re, $str, $matches, PREG_SET_ORDER, 0);
$navigation=$matches[0][0];
$navigation = '/'.$navigation;

$navigation = explode('/', $navigation);
foreach($navigation as $key => $row ) {    
	$split_chars = preg_split('/\?{1}/',$navigation[$key],-1,PREG_SPLIT_OFFSET_CAPTURE); 
	$navigation[$key] = $split_chars[0][0];
}  

//get the real ip
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

if(file_exists(ROOT_PATH  . '202-config.php')){
    include_once(ROOT_PATH  . '202-config.php');    
}else{
     header('location: setup-config.php'); die();
}  
include_once(ROOT_PATH  . '202-config.php');
include_once(CONFIG_PATH . '/sessions.php'); 
include_once(CONFIG_PATH . '/functions-tracking202.php');
include_once(CONFIG_PATH . '/functions.php');
include_once(CONFIG_PATH . '/template.php');

include_once(CONFIG_PATH . '/functions-auth.php');
include_once(CONFIG_PATH . '/class-filterengine.php');

include_once(CONFIG_PATH . '/functions-tracking202api.php');
include_once(CONFIG_PATH . '/functions-rss.php');
include_once(CONFIG_PATH . '/l10n.php');
include_once(CONFIG_PATH . '/formatting.php');
include_once(CONFIG_PATH . '/class-curl.php');
include_once(CONFIG_PATH . '/class-xmltoarray.php');
include_once(CONFIG_PATH . '/Role.class.php');
include_once(CONFIG_PATH . '/User.class.php');
include_once(CONFIG_PATH . '/Slack.class.php');

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

    return $ip;
}

function inet6_ntoa($ip){
    return @inet_ntop($ip);
}

function inet6_aton($ip){
    return @inet_pton($ip);
}


try {
	$database = DB::getInstance();
	$db = $database->getConnection(); 
	// Error handling
	} catch (Exception $e) {
		_die("<h6>Error establishing a database connection</h6>
			<p><small>This either means that the username and password information in your <code>202-config.php</code> file is incorrect or we can't contact the database server. This could mean your host's database server is down.</small></p>
			<small>
			<ul> 
				<li>Are you sure you have the correct username and password?</li>
				<li>Are you sure that you have typed the correct hostname?</li>
				<li>Are you sure that you have typed the correct database name?</li>
				<li>Are you sure that the database server is running?</li>
			</ul>
			</small> 
			<p><small>If you're unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href='http://support.tracking202.com'>Prosper202 Support Center</a>.</small></p>
		");
}

//stop the sessions if this is a redirect or a javascript placement, we were recording sessions on every hit when we don't need it on
if ($navigation[1] == 'tracking202') { 
	switch ($navigation[2]) { 
		case "redirect":
		case "static":
			$stopSessions = true;
			break;
	}
} 
		
//if the mysql tables are all installed now
if (($navigation[1]) and ($navigation[1] != '202-config')) {
	
	//we can initalize the session managers 
	if (!$stopSessions) { 
		
		//disable mysql sessions because they are slow
		//$sess = new SessionManager(); 
		session_start();    
	} 
	
	//run the cronjob checker
//	include_once(ROOT_PATH . '/202-cronjobs/index.php'); 
}

//set token to prevent CSRF attacks
if (!isset($_SESSION['token'])) { $_SESSION['token'] = md5(uniqid(rand(), TRUE)); }



//don't run the upgrade, if regular users are being redirected through the self-hosted software
if (($navigation[1] == 'tracking202') and ($navigation[2] == 'static')) { $skip_upgrade = true; }
if (($navigation[1] == 'tracking202') and ($navigation[2] == 'redirect')) { $skip_upgrade = true; }
	
if ($skip_upgrade == false) {
	
	//only check to see if upgraded, if this thing is acutally already installed
	if (  is_installed() == true) {
	
		//if we need upgrade, and its not already on the upgrade screen, redirect to the upgrade screen
		if ((upgrade_needed() == true) and (($navigation[1] != '202-config') and ($navigation[2] != 'upgrade.php'))) {
			header('location: '.get_absolute_url().'202-config/upgrade.php'); die();
		}	
	}
}

//if safe mode is turned on, and the user is trying to use offers202, stats202 or alerts202, show the error page
switch ($navigation[1]) { 
	case "offers202":
	case "alerts202":
	case "stats202":
		if (@ini_get('safe_mode')) { header('location: '.get_absolute_url().'202-account/disable-safe-mode.php'); die();  }
		break;
}

$userObj = new User($_SESSION['user_own_id']);

if(!$_SESSION['ipv6']){
    $sql="select inet6_ntoa(0x00000000000000000000000000000001) as ipv6";
    $result = $db->query($sql);
    
    if($result){
        $_SESSION['ipv6']="ipv6";
    }else{
        $_SESSION['ipv6']='';
    }  
}

if(isset($_SESSION['ipv6']) && $_SESSION['ipv6'] != ''){
    $inet6_ntoa='inet6_ntoa'; //decodes for display etc
    $inet6_aton='inet6_aton'; //encodes for db
}
else{
    $inet6_ntoa='';
    $inet6_aton='';    
}

$user_sql = "SET session sql_mode= ''";
$user_results = $db->query($user_sql);

function updateBlazerCache(&$mysql,$blazerCacheType=''){
	global $db;
	
	switch ($blazerCacheType) {
		case 'ac':
			$bc_results=($db->query("SELECT DISTINCT tracker_id_public from 202_trackers WHERE aff_campaign_id = 1"));
		
		while($bc_row = $bc_results->fetch_assoc()){
			$bc_key="td_".$bc_row['tracker_id_public'];
			setCache($bc_key, $mysql['aff_campaign_url']);
		}
		break;
		
		default:
			# code...
		
		break;
	}
	
}