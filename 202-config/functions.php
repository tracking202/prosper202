<?php
use GuzzleHttp\json_decode;
include_once(dirname( __FILE__ ) . '/functions-upgrade.php');
//our own die, that will display the them around the error message

function get_absolute_url(){
    $absolutepath = substr(substr(dirname(__FILE__), 0, - 10), strlen(realpath($_SERVER['DOCUMENT_ROOT'])));
    $absolutepath = str_replace('\\', '/', $absolutepath);
    return $absolutepath;
}


function _die($message) { 

	info_top();
	echo '<div class="main col-xs-7"><center><img src="'.get_absolute_url().'202-img/prosper202.png"></center>';
	echo $message;
	echo '</div>';
	info_bottom();
	die();
}


//our own function for controling mysqls and monitoring then.
function _mysqli_query($sql) {
    global $db;

    $result = @$db->query($sql);
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
		
	$mysql_version = PROSPER202::prosper202_version();
	$php_version = PROSPER202::php_version();
	if ($mysql_version != $php_version) { return true; } else { return false; }
		
}

function info_top() { 
$wp202=getWallpaper();
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>

<title>Prosper202 ClickServer</title>
<meta http-equiv="X-UA-Compatible" content="IE=edge" />
<meta name="description" content="description" />
<meta name="keywords" content="keywords" />
<meta name="copyright" content="202, Inc" />
<meta name="author" content="202, Inc" />
<meta name="MSSmartTagsPreventParsing" content="TRUE" />

<meta http-equiv="Content-Script-Type" content="text/javascript" />
<meta http-equiv="Content-Style-Type" content="text/css" />
<meta http-equiv="imagetoolbar" content="no" />

<link rel="shortcut icon" href="../202-img/favicon.gif" type="image/ico" />
<!-- Loading Bootstrap -->
<link
	href="<?php echo get_absolute_url();?>202-css/css/bootstrap.min.css"
	rel="stylesheet" />
<!-- Loading Flat UI -->
<link
	href="<?php echo get_absolute_url();?>202-css/css/flat-ui-pro.min.css"
	rel="stylesheet" />
<!-- Loading Custom CSS -->
<link href="<?php echo get_absolute_url();?>202-css/custom.min.css"
	rel="stylesheet" />
<!--[if lt IE 9]>
      <script src="https://dp5k1x6z3k332.cloudfront.net/html5shiv.js"></script>
      <script src="https://dp5k1x6z3k332.cloudfront.net/respond.min.js"></script>
<![endif]-->
<!-- Load JS here -->
<script src="https://dp5k1x6z3k332.cloudfront.net/jquery-1.11.2.min.js"></script>
<script type="text/javascript"
	src="https://dp5k1x6z3k332.cloudfront.net/jquery-ui.min.js"></script>
<script src="https://dp5k1x6z3k332.cloudfront.net/bootstrap.min.js"></script>
<script type='text/javascript'>
var googletag=googletag||{};googletag.cmd=googletag.cmd||[];(function(){var e=document.createElement("script");e.async=true;e.type="text/javascript";var t="https:"==document.location.protocol;e.src=(t?"https:":"http:")+"//www.googletagservices.com/tag/js/gpt.js";var n=document.getElementsByTagName("script")[0];n.parentNode.insertBefore(e,n)})()
</script>

<script type='text/javascript'>
googletag.cmd.push(function(){googletag.defineSlot("/1006305/P202_CS_Login_Page_288x200",[288,200],"div-gpt-ad-1398648278789-0").addService(googletag.pubads());googletag.pubads().enableSingleRequest();googletag.enableServices()})
</script>
</head>
<body>
	<a href="<?php echo $wp202['wallpaperUrl'];?>" target="_blank"
		style="background-image: url(<?php echo $wp202['wallpaperImg'];?>); -webkit-background-size: cover; -moz-background-size: cover; -o-background-size: cover; background-size: cover; background-repeat: no-repeat; background-position: center; background-attachment: fixed; position: absolute; display: block; z-index: -1; height: 100%; width: 100%"></a>

	<div class="container">
	<?php } function info_bottom() { ?>
</div>
</body>
</html>

<?php } 

function check_email_address($email)
{
    if (filter_var($email, FILTER_VALIDATE_EMAIL))
        return true;
    else
        return false;
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

function temp_exists() {
	if (is_dir(dirname( __FILE__ ). '/temp/')) {
		return true;
	} else {
		if (@mkdir(dirname( __FILE__ ). '/temp/', 0755)) {
			return true;
		} else {
			return false;
		}
	}
}


function update_needed () { 

	global $version;

	 $rss = getData('https://my.tracking202.com/api/v2/premium-p202/version');
	 if ( isset($rss->items) && 0 != count($rss->items) ) {
			 
		$rss->items = array_slice($rss->items, 0, 1) ;
		foreach ($rss->items as $item ) {
			$latest_version = $item['title'];
			//if current version, is older than the latest version, return true for an update is now needed.
			if (version_compare($version, $latest_version) == '-1') {

				if (!is_writable(dirname( __FILE__ ). '/') || !function_exists('zip_open') || !function_exists('zip_read') || !function_exists('zip_entry_name') || !function_exists('zip_close')) {
					$_SESSION['auto_upgraded_not_possible'] = true;
					return true;
				}

				if ($item['autoupgrade'] == 'true') {
					$decimals = explode('.', $latest_version);
					$versionCount = count($decimals);

					$lastDecimal = substr($latest_version, strrpos($latest_version, '.') + 1);

					if ($versionCount == 2) {
						$calcVersion = ($decimals[0] - 1).'.9.9';

					} else if ($versionCount == 3){
						if ($lastDecimal == '1') {
							if ($decimals[1] == '0') {
								$calcVersion = $decimals[0].'.0';
							} else {
								$calcVersion = $decimals[0].'.'.$decimals[1].'.0';
							}
						} else if ($lastDecimal == '0'){
							$calcVersion = $decimals[0].'.'.($decimals[1] - 1).'.9';
						} else {
							$calcVersion = $decimals[0].'.'.$decimals[1].'.'.($lastDecimal - 1);
						}
					}

					if ($calcVersion == $version) {
						//Auto upgrade without user confirmation
						$GetUpdate = @getData($item['link']);
						if ($GetUpdate) {
						
							if (temp_exists()) {
								$downloadUpdate = @file_put_contents(dirname( __FILE__ ). '/temp/prosper202_'.$latest_version.'.zip', $GetUpdate);
								if ($downloadUpdate) {
									$zip = @zip_open(dirname( __FILE__ ). '/temp/prosper202_'.$latest_version.'.zip');

										if ($zip)
										{	

										    while ($zip_entry = @zip_read($zip))
										    {
										    	$thisFileName = zip_entry_name($zip_entry);

										    	if (substr($thisFileName,-1,1) == '/') {
										    		if (is_dir(substr(dirname( __FILE__ ), 0,-10). '/'.$thisFileName)) {
										    		} else {
											    		if(@mkdir(substr(dirname( __FILE__ ), 0,-10). '/'.$thisFileName, 0755, true)) {
											    		} else {
											    		}
											    	}
										    		
										    	} else {
										    		$contents = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
										    		$file_ext = array_pop(explode(".", $thisFileName));

											    	if($updateThis = @fopen(substr(dirname( __FILE__ ), 0,-10).'/'.$thisFileName, 'wb')) {
											    		fwrite($updateThis, $contents);
						                            	fclose($updateThis);
						                            	unset($contents);	                      
											    	} else {
											    		$log .= "Can't update file:" . $thisFileName . "! Operation aborted";
											    	}
										    		
										    	}

										    	$FilesUpdated = true;
										    }

											zip_close($zip);
										}

								} else {
									$FilesUpdated = false;
								}

							} else {
								$FilesUpdated = false;
							}

						} else {
							$FilesUpdated = false;
						}

						if ($FilesUpdated == true) {
							if (function_exists('apc_clear_cache')) {
								apc_clear_cache('user'); 
							}
							include_once(dirname( __FILE__ ) . '/functions-upgrade.php');

							if (UPGRADE::upgrade_databases(null) == true) {
								$version = $latest_version;
								$upgrade_done = true;	
							} else {
								$upgrade_done = false;	
							}
						}

						if ($upgrade_done) {
							return false;
						} else {
							return true;
						}

					} else {
						return true;
					}

				} else {
					return true;
				}

			} else {
				return false;
			}

		}
	}   
	
}

function check_premium_update() { 
	global $version;
	$json = @getData('https://my.tracking202.com/api/v2/premium-p202/version');
	$array = json_decode($json, true);
	if ((version_compare($version, $array['version']) == '-1')) {
		if (!is_writable(dirname( __FILE__ ). '/') || !function_exists('zip_open') || !function_exists('zip_read') || !function_exists('zip_entry_name') || !function_exists('zip_close')) {
			$_SESSION['auto_upgraded_not_possible'] = true;
		}
		$_SESSION['premium_p202_details'] = $array;
		$_SESSION['premium_update_available'] = 1;
		return 1;
	}
	else{
	    return 0;
	}
}

function iphone() {
	if ($_GET['iphone']) { return true; }
	if(preg_match("/iphone/i",$_SERVER["HTTP_USER_AGENT"])) { return true; } else { return false; }
}

function returnRanges($fromdate, $todate, $type) {
	switch ($type) {
		case 'days':
			$set = 'P1D';
			$add = 'day';
			break;
		
		case 'hours':
			$set = 'PT1H';
			$add = 'hour';
			break;
	}

    return new \DatePeriod(
        $fromdate,
        new \DateInterval($set),
        $todate->modify('+1 '.$add)
    );
}

//function get file extension
function getFileExtension($str) {
    $i = strrpos($str,".");
    if (!$i) { return ""; }

    $l = strlen($str) - $i;
    $ext = substr($str,$i+1,$l);

    return $ext;
}

function getPath($path)
{
    $url = "http".(!empty($_SERVER['HTTPS'])?"s":"").
        "://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
    $dirs = explode('/', trim(preg_replace('/\/+/', '/', $path), '/'));
    foreach ($dirs as $key => $value)
        if (empty($value))  unset($dirs[$key]);
    $parsedUrl = parse_url($url);
    $pathUrl = explode('/', trim($parsedUrl['path'], '/'));
    foreach ($pathUrl as $key => $value)
        if (empty($value))  unset($pathUrl[$key]);
    $count = count($pathUrl);
    foreach ($dirs as $key => $dir)
        if ($dir === '..')
            if ($count > 0)
                array_pop($pathUrl);
            else
                throw new Exception('Wrong Path');
        else if ($dir !== '.')
            if (preg_match('/^(\w|\d|\.| |_|-)+$/', $dir)) {
                $pathUrl[] = $dir;
                ++$count;
            }
            else
                throw new Exception('Not Allowed Char');
    return $parsedUrl['scheme'].'://'.$parsedUrl['host'].'/'.implode('/', $pathUrl);
}

function formatOffset($offset) {
	$hours = $offset / 3600;
	$remainder = $offset % 3600;
	$sign = $hours > 0 ? '+' : '-';
	$hour = (int) abs($hours);
	$minutes = (int) abs($remainder / 60);

	if ($hour == 0 AND $minutes == 0) {
			$sign = ' ';
	}
	return $sign . str_pad($hour, 2, '0', STR_PAD_LEFT) .':'. str_pad($minutes,2, '0');
}

function getCurlValue($filename, $contentType, $postname) {
    // PHP 5.5 introduced a CurlFile object that deprecates the old @filename syntax
    // See: https://wiki.php.net/rfc/curl-file-upload
    if (function_exists('curl_file_create')) {
        return curl_file_create($filename, $contentType, $postname);
    }
 
    // Use the old style if using an older version of PHP
    $value = "@{$this->filename};filename=" . $postname;
    if ($contentType) {
        $value .= ';type=' . $contentType;
    }
 
    return $value;
}

function getAdsFromS3($user, $key) {
	$ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/premium-p202/get-ads/'.$user.'/'.$key);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $results = curl_exec($ch);
    curl_close($ch);
    return json_decode($results, true);
}

function deleteAdFromS3($user, $key, $ad) {
	
	$fields = array(
        'ad' => $ad
    );

	$fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/premium-p202/delete-ad/'.$user.'/'.$key);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $results = curl_exec($ch);
    curl_close($ch);
    echo $results;
}

function getMYSQLVersion($db){
    $mysql_version = mysqli_get_server_info($db);
    $html['mysql_version'] = htmlentities($mysql_version, ENT_QUOTES, 'UTF-8');
    return $html['mysql_version'];
}

function getDynamicContentSegment(){
    print('<br/><strong><small>Dynamic Content Segments</strong></small><br/>
		   <span class="infotext">Dynamic Content Segments can dynamically display the following information on your landing pages:
		   <ul style="font-size: 12px;">
		   	<li>Visitor\'s Country - <strong>t202Country</strong></li>
			<li>Visitor\'s Country Code - <strong>t202CountryCode</strong></li>
			<li>Visitor\'s Region/State - <strong>t202Region</strong></li>
			<li>Visitor\'s City - <strong>t202City</strong></li>
			<li>Visitor\'s Postal/Zip Code - <strong>t202Postal</strong></li>
			<li>Visitor\'s Browser - <strong>t202Browser</strong></li>
			<li>Visitor\'s Operating System - <strong>t202OS</strong></li>
			<li>Visitor\'s Device Type - <strong>t202Device</strong></li>
			<li>Visitor\'s ISP - <strong>t202ISP</strong></li>
	        <li>Visitor\'s IP Address - <strong>t202IP</strong></li>
			<li>Value passed in t202kw - <strong>t202kw</strong></li>
			<li>Value passed in C1-C4 - <strong>t202c1, t202c2, t202c3, t202c4</strong></li>
			<li>Value passed in utm_source - <strong>t202utm_source</strong></li>
			<li>Value passed in utm_medium - <strong>t202utm_medium</strong></li>
			<li>Value passed in utm_term - <strong>t202utm_term</strong></li>
			<li>Value passed in utm_content - <strong>t202utm_content</strong></li>
			<li>Value passed in utm_campaign - <strong>t202utm_campaign</strong></li>
		   </ul>
		   So how easy is it to display the visitor\'s country on your landing page? Here\'s the html for it:<br/>
		   <code>Welcome I see you are reading this from &lt;span name=&quot;t202Country&quot; t202Default=&apos;Your Country&apos;&gt;Your Country&lt;/span&gt;</code></span>');
    
}

function getWallpaper($key='')
{
    $fields = array(
        'key' => $key
    );

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/ads/wallpapers/extern');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    curl_close($ch);

    if(strlen($result)>2000 || !$result){
        $result = array(
            'wallpaperImg' => 'https://tracking202-static.s3.amazonaws.com/wallpaper202.jpg',
            'wallpaperUrl' => 'https://prosper.tracking202.com/apps/?utm_source=p202profb'
        );
        return $result;
    }
    $result = json_decode($result, true);
    return $result;
}