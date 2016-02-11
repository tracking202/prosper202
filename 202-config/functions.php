<?php
include_once(dirname( __FILE__ ) . '/functions-upgrade.php');
//our own die, that will display the them around the error message

function get_absolute_url() {
	return substr(substr(dirname( __FILE__ ), 0,-10),strlen($_SERVER['DOCUMENT_ROOT']));
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
	$database = DB::getInstance();
	$db = $database->getConnection();

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
  
<link rel="shortcut icon" href="../202-img/favicon.gif" type="image/ico"/> 
<!-- Loading Bootstrap -->
<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.4/css/bootstrap.min.css" rel="stylesheet"/>
<!-- Loading Flat UI -->
<link href="<?php echo get_absolute_url();?>202-css/css/flat-ui-pro.min.css" rel="stylesheet"/>
<!-- Loading Custom CSS -->
<link href="<?php echo get_absolute_url();?>202-css/custom.min.css" rel="stylesheet"/>
<!--[if lt IE 9]>
      <script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
      <script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
<![endif]-->
<!-- Load JS here -->
    <script src="https://code.jquery.com/jquery-1.11.2.min.js"></script>
    <script type="text/javascript" src="https://code.jquery.com/ui/1.11.2/jquery-ui.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.4/js/bootstrap.min.js"></script>
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

	 $rss = fetch_rss('http://my.tracking202.com/clickserver/currentversion/pro/');
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
						$GetUpdate = @file_get_contents($item['link']);
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