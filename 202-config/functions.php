<?php

//our own die, that will display the them around the error message
function _die($message) { 

	info_top();
	echo '<div class="main col-xs-7"><center><img src="/202-img/prosper202.png"></center>';
	echo $message;
	echo '</div>';
	info_bottom();
	die();
}


//our own function for controling mysqls and monitoring then.
function _mysqli_query($sql) {
	$database = DB::getInstance();
	$db = $database->getConnection();

	$result = $db->query($sql) or die($db->error . '<br/><br/>' . $sql);
	return $result;
	
}


function salt_user_pass($user_pass) { 

	$salt = '202';
	$user_pass = md5($salt . md5($user_pass . $salt));
	return $user_pass;
}


function is_installed() {
	$database = DB::getInstance();
	$db = $database->getConnection();
	
	//if a user account already exists, this application is installed
	$user_sql = "SELECT COUNT(*) FROM 202_users";
	$user_result = $db->query($user_sql);
	
	if ($user_result) {
		return true;
	} else {
		return false;
	}
}

function upgrade_needed() { 
		
	$mysql_version = PROSPER202::mysql_version();
	$php_version = PROSPER202::php_version();
	if ($mysql_version != $php_version) { return true; } else { return false; }
		
}

	
function info_top() { ?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en"> 
<head>

<title>Prosper202 ClickServer</title>
<meta http-equiv="X-UA-Compatible" content="IE=edge"/>
<meta name="description" content="description" />
<meta name="keywords" content="keywords"/>
<meta name="copyright" content="202, Inc" />
<meta name="author" content="202, Inc" />
<meta name="MSSmartTagsPreventParsing" content="TRUE"/>

<meta http-equiv="Content-Script-Type" content="text/javascript" />
<meta http-equiv="Content-Style-Type" content="text/css" />
<meta http-equiv="imagetoolbar" content="no"/>
  
<link rel="shortcut icon" href="/202-img/favicon.gif" type="image/ico"/> 
<!-- Loading Bootstrap -->
<link href="/202-css/css/bootstrap.min.css" rel="stylesheet"/>
<!-- Loading Flat UI -->
<link href="/202-css/css/flat-ui.css" rel="stylesheet"/>
<!-- Loading Custom CSS -->
<link href="/202-css/custom.css" rel="stylesheet"/>
<!--[if lt IE 9]>
      <script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
      <script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
<![endif]-->
<!-- Load JS here -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.0.3/jquery.min.js"></script>
    <script src="/202-js/bootstrap.min.js"></script>
<script type='text/javascript'>
var googletag=googletag||{};googletag.cmd=googletag.cmd||[];(function(){var e=document.createElement("script");e.async=true;e.type="text/javascript";var t="https:"==document.location.protocol;e.src=(t?"https:":"http:")+"//www.googletagservices.com/tag/js/gpt.js";var n=document.getElementsByTagName("script")[0];n.parentNode.insertBefore(e,n)})()
</script>

<script type='text/javascript'>
googletag.cmd.push(function(){googletag.defineSlot("/1006305/P202_CS_Login_Page_288x200",[288,200],"div-gpt-ad-1398648278789-0").addService(googletag.pubads());googletag.pubads().enableSingleRequest();googletag.enableServices()})
</script>
</head>
<body>

<div class="container">
	<?php } function info_bottom() { ?>
</div>
</body>
</html> 

<?php } 

function check_email_address($email) {
// First, we check that there's one @ symbol, and that the lengths are right
 if (!ereg("^[^@]{1,64}@[^@]{1,255}$", $email)) {
 // Email invalid because wrong number of characters in one section, or wrong number of @ symbols.
 return false;
 }
 // Split it into sections to make life easier
 $email_array = explode("@", $email);
 $local_array = explode(".", $email_array[0]);
 for ($i = 0; $i < sizeof($local_array); $i++) {
 if (!ereg("^(([A-Za-z0-9!#$%&'*+/=?^_`{|}~-][A-Za-z0-9!#$%&'*+/=?^_`{|}~\.-]{0,63})|(\"[^(\\|\")]{0,62}\"))$", $local_array[$i])) {
 return false;
 }
 }
 if (!ereg("^\[?[0-9\.]+\]?$", $email_array[1])) { // Check if domain is IP. If not, it should be valid domain name
 $domain_array = explode(".", $email_array[1]);
 if (sizeof($domain_array) < 2) {
 return false; // Not enough parts to domain
 }
 for ($i = 0; $i < sizeof($domain_array); $i++) {
 if (!ereg("^(([A-Za-z0-9][A-Za-z0-9-]{0,61}[A-Za-z0-9])|([A-Za-z0-9]+))$", $domain_array[$i])) {
 return false;
 }
 }
 }
 return true;
}

function print_r_html($data,$return_data=false)
{
	$data = print_r($data,true);
	$data = str_replace( " ","&nbsp;", $data);
	$data = str_replace( "\r\n","<br/>\r\n", $data);
	$data = str_replace( "\r","<br/>\r", $data);
	$data = str_replace( "\n","<br/>\n", $data);

	if (!$return_data)
		echo $data;   
	else
		return $data;
}


function html2txt($document){
$search = array('@<script[^>]*?>.*?</script>@si',  // Strip out javascript
               '@<style[^>]*?>.*?</style>@siU',    // Strip style tags properly
               '@<[\/\!]*?[^<>]*?>@si',            // Strip out HTML tags
               '@<![\s\S]*?--[ \t\n\r]*>@'        // Strip multi-line comments including CDATA
);
$text = preg_replace($search, '', $document);
return $text;
}


function update_needed () { 
	
	global $version;

	 $rss = fetch_rss('http://my.tracking202.com/clickserver/currentversion/');
	 if ( isset($rss->items) && 0 != count($rss->items) ) {
			 
		$rss->items = array_slice($rss->items, 0, 1) ;
		foreach ($rss->items as $item ) {
			$latest_version = $item['title'];
			//if current version, is older than the latest version, return true for an update is now needed.
			if (version_compare($version, $latest_version) == '-1') {
				return true;
			} else {
				return false;
			}

		}
	}   
	
}

function iphone() {
	if ($_GET['iphone']) { return true; }
	if(preg_match("/iphone/i",$_SERVER["HTTP_USER_AGENT"])) { return true; } else { return false; }
}