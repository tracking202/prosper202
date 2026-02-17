<?php

use GuzzleHttp\json_decode;

include_once(__DIR__ . '/functions-upgrade.php');

// Add ipAddress function
function ipAddress($ip_address): stdClass
{
    $ip = new stdClass;
    if (filter_var($ip_address, FILTER_VALIDATE_IP)) {
        $ip->address = $ip_address;
        if (filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip->type = 'ipv4';
        } else {
            $ip->type = 'ipv6';
        }
    } else {
        $ip->type = 'invalid';
        $ip->address = '127.0.0.1'; // fallback
    }
    
    // Skip the tracking check for now to avoid dependency issues
    $ip->address = @inet_ntop(inet_pton($ip->address)); //format ip address in standard form
    return $ip;
}

//our own die, that will display the them around the error message

// Add prosper_log function
function prosper_log($category, $message): void {
    // Simple logging function - you can expand this as needed
    error_log("[Prosper202][$category] $message");
}

function get_absolute_url(): string
{
	$absolutepath = substr(substr(__DIR__, 0, -10), strlen(realpath($_SERVER['DOCUMENT_ROOT'])));
	$absolutepath = str_replace('\\', '/', $absolutepath);
	return $absolutepath;
}


function _die($message): never
{

	info_top();
	echo '<div class="main col-xs-7"><center><img src="' . get_absolute_url() . '202-img/prosper202.png"></center>';
	echo $message;
	echo '</div>';
	info_bottom();
	die();
}


//our own function for controling mysqls and monitoring then.
/**
 * Execute a MySQL query.
 *
 * Supports two calling conventions:
 * - _mysqli_query($sql) - uses global $db
 * - _mysqli_query($db, $sql) - uses provided $db
 *
 * @param \mysqli|string $db_or_sql Database connection or SQL query
 * @param string|null $sql SQL query (if first param is db connection)
 * @return \mysqli_result|bool
 *
 * @overload
 * @param string $sql
 * @return \mysqli_result|bool
 *
 * @overload
 * @param \mysqli $db
 * @param string $sql
 * @return \mysqli_result|bool
 */
function _mysqli_query($db_or_sql, $sql = null): \mysqli_result|bool
{
	// Support both calling conventions:
	//   _mysqli_query($sql)         — 1 arg, uses global $db
	//   _mysqli_query($db, $sql)    — 2 args, uses provided $db
	if ($sql === null) {
		$sql = $db_or_sql;
		global $db;
	} else {
		$db = $db_or_sql;
	}

	if ($db instanceof \mysqli) {
		$result = @$db->query($sql);
	} else {
		$connection = $db->getConnection();
		$result = @$connection->query($sql);
	}
	return $result;
}

function salt_user_pass($user_pass): string
{

	$salt = '202';
	$user_pass = md5($salt . md5($user_pass . $salt));
	return $user_pass;
}

if (!function_exists('array_any')) {
	function array_any(array $items, callable $callback): bool
	{
		$parameterCount = null;

		foreach ($items as $key => $value) {
			if ($parameterCount === null) {
				try {
					$reflection = is_array($callback)
						? new \ReflectionMethod($callback[0], $callback[1])
						: new \ReflectionFunction($callback);
					$parameterCount = $reflection->getNumberOfParameters();
				} catch (\ReflectionException) {
					$parameterCount = 1;
				}
			}

			$result = $parameterCount >= 2 ? $callback($value, $key) : $callback($value);
			if ($result) {
				return true;
			}
		}

		return false;
	}
}


function is_installed()
{
	// Skip the check if we're accessing the installer or API key setup
	if (
		str_contains((string) $_SERVER['PHP_SELF'], '202-config/install.php') ||
		str_contains((string) $_SERVER['PHP_SELF'], '202-config/get_apikey.php')
	) {
		return false;
	}

	$database = DB::getInstance();
	$db = $database->getConnection();

	//if a user account already exists, this application is installed 
	try {
		$user_sql = "SELECT COUNT(*) FROM 202_users";
		$user_result = $db->query($user_sql);
		if ($user_result) {
			return true;
		}
	} catch (mysqli_sql_exception) {
		// Table doesn't exist yet, return false
		return false;
	}
	return false;
}

function upgrade_needed()
{

	// Call static methods
	$mysql_version = PROSPER202::prosper202_version();
	$php_version = PROSPER202::php_version();
	if ($mysql_version != $php_version) {
		return true;
	} else {
		return false;
	}
}

function info_top()
{
	$wp202 = getWallpaper();
?>

	<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
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
			href="<?php echo get_absolute_url(); ?>202-css/css/bootstrap.min.css"
			rel="stylesheet" />
		<!-- Loading Flat UI -->
		<link
			href="<?php echo get_absolute_url(); ?>202-css/css/flat-ui-pro.min.css"
			rel="stylesheet" />
		<!-- Loading Custom CSS -->
		<link href="<?php echo get_absolute_url(); ?>202-css/custom.min.css"
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
			var googletag = googletag || {};
			googletag.cmd = googletag.cmd || [];
			(function() {
				var e = document.createElement("script");
				e.async = true;
				e.type = "text/javascript";
				var t = "https:" == document.location.protocol;
				e.src = (t ? "https:" : "http:") + "//www.googletagservices.com/tag/js/gpt.js";
				var n = document.getElementsByTagName("script")[0];
				n.parentNode.insertBefore(e, n)
			})()
		</script>

		<script type='text/javascript'>
			googletag.cmd.push(function() {
				googletag.defineSlot("/1006305/P202_CS_Login_Page_288x200", [288, 200], "div-gpt-ad-1398648278789-0").addService(googletag.pubads());
				googletag.pubads().enableSingleRequest();
				googletag.enableServices()
			})
		</script>
	</head>

	<body>
		<a href="<?php echo $wp202['wallpaperUrl']; ?>" target="_blank"
			style="background-image: url(<?php echo $wp202['wallpaperImg']; ?>); -webkit-background-size: cover; -moz-background-size: cover; -o-background-size: cover; background-size: cover; background-repeat: no-repeat; background-position: center; background-attachment: fixed; position: absolute; display: block; z-index: -1; height: 100%; width: 100%"></a>

		<div class="container">
		<?php }
	function info_bottom()
	{ ?>
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

	function print_r_html($data, $return_data = false)
	{
		$data = print_r($data, true);
		$data = str_replace(" ", "&nbsp;", $data);
		$data = str_replace("\r\n", "<br/>\r\n", $data);
		$data = str_replace("\r", "<br/>\r", $data);
		$data = str_replace("\n", "<br/>\n", $data);

		if (!$return_data)
			echo $data;
		else
			return $data;
	}


	function html2txt($document)
	{
		$search = [
			'@<script[^>]*?>.*?</script>@si',  // Strip out javascript
			'@<style[^>]*?>.*?</style>@siU',    // Strip style tags properly
			'@<[\/\!]*?[^<>]*?>@si',            // Strip out HTML tags
			'@<![\s\S]*?--[ \t\n\r]*>@'        // Strip multi-line comments including CDATA
		];
		$text = preg_replace($search, '', (string) $document);
		return $text;
	}

	function temp_exists()
	{
		if (is_dir(__DIR__ . '/temp/')) {
			return true;
		} else {
			if (@mkdir(__DIR__ . '/temp/', 0755)) {
				return true;
			} else {
				return false;
			}
		}
	}


	function update_needed()
	{
		global $version;
		$log = ''; // Initialize log variable to store update errors

		$rssData = getData('https://my.tracking202.com/api/v2/premium-p202/version');
		$rss = null;
		if ($rssData !== false && !empty($rssData)) {
			$rss = json_decode((string) $rssData);
		}
		if (isset($rss->items) && 0 != count($rss->items)) {

			$rss->items = array_slice($rss->items, 0, 1);
			foreach ($rss->items as $item) {
				// Check if item is valid
				if (!is_object($item) && !is_array($item)) {
					continue; // Skip this item and move to next in loop
				}

				// Get latest version and link
				$latest_version = null;
				$link = null;
				$autoupgrade = false;

				if (is_array($item)) {
					$latest_version = $item['title'] ?? null;
					$link = $item['link'] ?? null;
					$autoupgrade = $item['autoupgrade'] ?? 'false';
				} elseif (is_object($item)) {
					$latest_version = $item->title ?? null;
					$link = $item->link ?? null;
					$autoupgrade = $item->autoupgrade ?? 'false';
				}

				if ($latest_version === null) {
					continue; // Skip if title is missing
				}

				//if current version, is older than the latest version, return true for an update is now needed.
				if (version_compare($version, $latest_version) == '-1') {

					if (!is_writable(__DIR__ . '/') || !class_exists('ZipArchive')) {
						$_SESSION['auto_upgraded_not_possible'] = true;
						return true;
					}

					if ($autoupgrade == 'true' && $link !== null) {
						$decimals = explode('.', $latest_version);
						$versionCount = count($decimals);
						$lastDecimal = substr($latest_version, strrpos($latest_version, '.') + 1);

						// Calculate version
						if ($lastDecimal == '0') {
							$calcVersion = $decimals[0] . '.' . ($decimals[1] - 1) . '.9';
						} else {
							$calcVersion = $decimals[0] . '.' . $decals[1] . '.' . ($lastDecimal - 1);
						}

						if ($calcVersion == $version) {
							//Auto upgrade without user confirmation
							$GetUpdate = @getData($link);
							if ($GetUpdate) {
								$FilesUpdated = false;

								if (temp_exists()) {
									$downloadUpdate = @file_put_contents(__DIR__ . '/temp/prosper202_' . $latest_version . '.zip', $GetUpdate);
									if ($downloadUpdate) {
										$zip = new ZipArchive();
										$zipResult = $zip->open(__DIR__ . '/temp/prosper202_' . $latest_version . '.zip');

										if ($zipResult === TRUE) {

											for ($i = 0; $i < $zip->numFiles; $i++) {
												$thisFileName = $zip->getNameIndex($i);

												if (str_ends_with($thisFileName, '/')) {
													if (is_dir(substr(__DIR__, 0, -10) . '/' . $thisFileName)) {
														// Directory already exists
													} else {
														@mkdir(substr(__DIR__, 0, -10) . '/' . $thisFileName, 0755, true);
													}
												} else {
													$contents = $zip->getFromIndex($i);
													$file_ext = array_pop(explode(".", $thisFileName));

													if ($updateThis = @fopen(substr(__DIR__, 0, -10) . '/' . $thisFileName, 'wb')) {
														fwrite($updateThis, $contents);
														fclose($updateThis);
														unset($contents);
													} else {
														$log .= "Can't update file:" . $thisFileName . "! Operation aborted";
													}
												}
											}

											$zip->close();
										}
									}
								} else {
									$FilesUpdated = false;
									$log .= "Failed to download update file. ";
								}
							} else {
								$FilesUpdated = false;
								$log .= "Temporary directory doesn't exist or isn't writable. ";
							}

							if ($FilesUpdated == true) {
								// Clear all PHP caches
								clear_php_caches();
								include_once(__DIR__ . '/functions-upgrade.php');

								if (UPGRADE::upgrade_databases(null) == true) {
									$version = $latest_version;
									$upgrade_done = true;
								} else {
									$upgrade_done = false;
								}
							}

							// Store any error logs if they exist
							if (!empty($log)) {
								$_SESSION['upgrade_error_log'] = $log;
							}

							if ($upgrade_done) {
								return false;
							} else {
								return true;
							}
						} else {
							return true;
						}
					}

					return true; // Update needed
				}
			}
		}

		// Return false if no update needed or if checks failed
		return false;
	}

	function check_premium_update()
	{
		global $version;
		$json = @getData('https://my.tracking202.com/api/v2/premium-p202/version');
		$array = json_decode((string) $json, true);
		if ((version_compare($version, $array['version']) == '-1')) {
			if (!is_writable(__DIR__ . '/') || !function_exists('zip_open') || !function_exists('zip_read') || !function_exists('zip_entry_name') || !function_exists('zip_close')) {
				$_SESSION['auto_upgraded_not_possible'] = true;
			}
			$_SESSION['premium_p202_details'] = $array;
			$_SESSION['premium_update_available'] = 1;
			return 1;
		} else {
			return 0;
		}
	}

	function iphone()
	{
		if ($_GET['iphone']) {
			return true;
		}
		if (preg_match("/iphone/i", (string) $_SERVER["HTTP_USER_AGENT"])) {
			return true;
		} else {
			return false;
		}
	}

	function returnRanges($fromdate, $todate, $type)
	{
		// Set default values
		$set = 'P1D';
		$add = 'day';
		
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
			$todate->modify('+1 ' . $add)
		);
	}

	//function get file extension
	function getFileExtension($str)
	{
		$i = strrpos((string) $str, ".");
		if (!$i) {
			return "";
		}

		$l = strlen((string) $str) - $i;
		$ext = substr((string) $str, $i + 1, $l);

		return $ext;
	}

	function getPath($path)
	{
		$url = "http" . (!empty($_SERVER['HTTPS']) ? "s" : "") .
			"://" . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		$dirs = explode('/', trim((string) preg_replace('/\/+/', '/', (string) $path), '/'));
		foreach ($dirs as $key => $value)
			if (empty($value))  unset($dirs[$key]);
		$parsedUrl = parse_url($url);
		$pathUrl = explode('/', trim($parsedUrl['path'], '/'));
		foreach ($pathUrl as $key => $value)
			if (empty($value))  unset($pathUrl[$key]);
		$count = count($pathUrl);
		foreach ($dirs as $dir)
			if ($dir === '..')
				if ($count > 0)
					array_pop($pathUrl);
				else
					throw new Exception('Wrong Path');
			else if ($dir !== '.')
				if (preg_match('/^(\w|\d|\.| |_|-)+$/', $dir)) {
					$pathUrl[] = $dir;
					++$count;
				} else
					throw new Exception('Not Allowed Char');
		return $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/' . implode('/', $pathUrl);
	}

	function formatOffset($offset)
	{
		$hours = $offset / 3600;
		$remainder = $offset % 3600;
		$sign = $hours > 0 ? '+' : '-';
		$hour = (int) abs($hours);
		$minutes = (int) abs($remainder / 60);

		if ($hour == 0 and $minutes == 0) {
			$sign = ' ';
		}
		return $sign . str_pad($hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($minutes, 2, '0');
	}

	function getCurlValue($filename, $contentType, $postname)
	{
		// PHP 5.5 introduced a CurlFile object that deprecates the old @filename syntax
		// See: https://wiki.php.net/rfc/curl-file-upload
		if (function_exists('curl_file_create')) {
			return curl_file_create($filename, $contentType, $postname);
		}

		// Use the old style if using an older version of PHP
		$value = "@{$filename};filename=" . $postname;
		if ($contentType) {
			$value .= ';type=' . $contentType;
		}

		return $value;
	}

	function getAdsFromS3($user, $key)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/premium-p202/get-ads/' . $user . '/' . $key);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$results = curl_exec($ch);
		curl_close($ch);
		return json_decode($results, true);
	}

	function deleteAdFromS3($user, $key, $ad)
	{

		$fields = [
			'ad' => $ad
		];

		$fields = http_build_query($fields);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/premium-p202/delete-ad/' . $user . '/' . $key);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
		$results = curl_exec($ch);
		curl_close($ch);
		echo $results;
	}

	function getMYSQLVersion($db)
	{
		$mysql_version = mysqli_get_server_info($db);
		$html['mysql_version'] = htmlentities($mysql_version, ENT_QUOTES, 'UTF-8');
		return $html['mysql_version'];
	}

	function getDynamicContentSegment()
	{
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

	function getWallpaper($key = '')
	{
		$fields = [
			'key' => $key
		];

		$fields = http_build_query($fields);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/ads/wallpapers/extern');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		$result = curl_exec($ch);
		curl_close($ch);

		if (strlen($result) > 2000 || !$result) {
			$result = [
				'wallpaperImg' => 'https://tracking202-static.s3.amazonaws.com/wallpaper202.jpg',
				'wallpaperUrl' => 'https://prosper.tracking202.com/apps/?utm_source=p202profb'
			];
			return $result;
		}
		$result = json_decode($result, true);
		return $result;
	}

	/**
	 * Clear all available PHP caches (APC, OPcache, etc)
	 * 
	 * This function attempts to clear caches using various mechanisms
	 * without causing errors if the cache systems are not available.
	 */
	function clear_php_caches()
	{
		// Try APC cache
		if (function_exists('apc_clear_cache')) {
			@apc_clear_cache();
			@apc_clear_cache('user');
			@apc_clear_cache('opcode');
		}

		// Try OPcache
		if (function_exists('opcache_reset')) {
			@opcache_reset();
		}

		// Try APCu (APC User Cache)
		if (function_exists('apcu_clear_cache')) {
			@apcu_clear_cache();
		}

		// Could add more cache clearing methods here as needed
	}
