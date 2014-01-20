<?php

$version = '1.7.2';

DEFINE('TRACKING202_API_URL', 'http://api.tracking202.com');
DEFINE('TRACKING202_RSS_URL', 'http://rss.tracking202.com');
DEFINE('TRACKING202_ADS_URL', 'http://ads.tracking202.com');

@ini_set('auto_detect_line_endings', TRUE);
@ini_set('register_globals', 0);
@ini_set('display_errors', 'On');
@ini_set('error_reporting', 6135);
@ini_set('safe_mode', 'Off');

//set navigation variable 
$navigation = $_SERVER['REQUEST_URI']; 
$navigation = explode('/', $navigation); 
	
foreach( $navigation as $key => $row ) {    
	$split_chars = preg_split('/\?{1}/',$navigation[$key],-1,PREG_SPLIT_OFFSET_CAPTURE); 
	$navigation[$key] = $split_chars[0][0];
}   

$_SERVER['HTTP_X_FORWARDED_FOR'] = $_SERVER['REMOTE_ADDR'];

//include mysql settings
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/sessions.php'); 
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/template.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions-install.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions-upgrade.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions-auth.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions-export202.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions-tracking202.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions-tracking202api.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions-rss.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/l10n.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/formatting.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/class-curl.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/class-xmltoarray.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-charts/charts.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/ReportSummaryForm.class.php');


//try to connect to memcache server
if ( ini_get('memcache.default_port') ) { 
	
	$memcacheInstalled = true;
	$memcache = new Memcache;
	if ( @$memcache->connect($mchost, 11211) )  	$memcacheWorking = true;
	else 												$memcacheWorking = false;
	
}


//connect to the mysql database, if it couldn't connect error 
$dbconnect = @mysql_connect($dbhost,$dbuser,$dbpass); 
if (!$dbconnect) {  
	_die("<h2>Error establishing a database connection</h2>
			<p>This either means that the username and password information in your <code>202-config.php</code> file is incorrect or we can't contact the database server at <code>$dbhost</code>. This could mean your host's database server is down.</p>
			<ul> 
				<li>Are you sure you have the correct username and password?</li>
				<li>Are you sure that you have typed the correct hostname?</li>
				<li>Are you sure that the database server is running?</li>
			</ul> 
			<p>If you're unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href='http://Prosper202.com/forum/'>Prosper202 Support Forums</a>.</p>
			"); }

//connect to the mysql database, if couldn't connect error
$dbselect = @mysql_select_db($dbname);
if (!$dbselect) { 
		_die("
				<h2>Can&#8217;t select database</h2>
				<p>We were able to connect to the database server (which means your username and password is okay) but not able to select the <code>$dbname</code> database.</p>
				<ul>
				<li>Are you sure it exists?</li>
				<li>Does the user <code>$dbuser</code> have permission to use the <code>$dbname</code> database?</li>
				<li>On some systems the name of your database is prefixed with your username, so it would be like username_Prosper202. Could that be the problem?</li>
				</ul>
				<p>If you don't know how to setup a database you should <strong>contact your host</strong>. If all else fails you may find help at the <a href='http://Prosper202.com/forum/'>Prosper202 Support Forums</a>.</p>
			    "); }


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
	include_once($_SERVER['DOCUMENT_ROOT'] . '/202-cronjobs/index.php'); 
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
			header('location: /202-config/upgrade.php'); die();
		}	
	}
}

//if safe mode is turned on, and the user is trying to use offers202, stats202 or alerts202, show the error page
switch ($navigation[1]) { 
	case "offers202":
	case "alerts202":
	case "stats202":
		if (@ini_get('safe_mode')) { header('location: /202-account/disable-safe-mode.php'); die();  }
		break;
}
