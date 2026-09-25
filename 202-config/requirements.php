<?php
declare(strict_types=1);
//include mysql settings
include_once(__DIR__ . '/connect.php');

// Initialize variables
$version_error = [];
$memcacheInstalled = (extension_loaded('memcache') || extension_loaded('memcached'));
$db = $db ?? null;

//check to see if this is already installed, if so don't do anything
if (is_installed() == true) {
		_die("<h6>Already Installed</h6>
			  <small>You appear to have already installed Prosper202. To reinstall please clear your old database tables first. <a href='/202-login.php'>Login Now</a></small>");
}

    // Get Database version
		$mysqlversion = $db ? $db->server_info : '';
	if (preg_match('/-(10\..+)-MariaDB/i', (string) $mysqlversion, $match)) {
	    // Support For MariaDB
	    $mysqlversion = $match[1];
	    $dbwording="MariaDB >= 10.6";
	    if ((version_compare($mysqlversion, '10.6') < 0)) {
	        $version_error['mysqlversion'] = 'Prosper202 requires MariaDB 10.6, or newer.';
	    }
	}
	else{
	    $dbwording="MySQL >= 8.0";
	    if ((version_compare($mysqlversion, '8.0') < 0)) {
	        $version_error['mysqlversion'] = 'Prosper202 requires MySQL 8.0, or newer.';
	    }
	     
	}
	
	$html['mysqlversion'] = htmlentities((string) $mysqlversion, ENT_QUOTES, 'UTF-8');

        if (!php_version_supported()) {
                $version_error['phpversion'] = 'Prosper202 requires PHP ' . PROSPER202_MIN_PHP_VERSION . ', or newer.';
        }


	if (!function_exists('curl_version')) { 
		$version_error['curl'] = 'Prosper202 requires CURL to be installed.';
	}  
	
	if (!function_exists('xml_parser_create')) {
		$version_error['xml_parser_create'] = 'Prosper202 requires xml_parser_create() function to be installed.';
	}

	// Check if partitioning is supported by querying INFORMATION_SCHEMA.PARTITIONS
	$sql = "SELECT COUNT(*) as partition_support FROM INFORMATION_SCHEMA.PARTITIONS LIMIT 1";
	$result = $db ? $db->query($sql) : false;
	
	if ($result && $result->num_rows > 0) {
	    $partition_support = 1;
	}
	else{
	    $partition_support = 0;
	}
 
$partners_data = getData('https://my.tracking202.com/api/v2/hostings');
$partners = $partners_data ? json_decode((string) $partners_data, true) : null;
$tone = static fn (bool $ok, string $whenNot = 'bad'): string => $ok ? 'good' : $whenNot;

info_top(['title' => 'Server check - Prosper202 ClickServer', 'wide' => true]);
if ($version_error) {
	echo p202_standalone_card('This server does not meet the requirements', 'Prosper202 cannot be installed here until the items marked below are fixed. An Official Hosting Partner runs it without changes.');
} else {
	echo p202_standalone_card('Your server is ready', 'Step 1 of 3: Prosper202 checked what it needs from this server. Next, your license key.');
}
echo p202_standalone_requirements([
	['PHP >= ' . PROSPER202_MIN_PHP_VERSION, phpversion(), $tone(!isset($version_error['phpversion']))],
	[$dbwording, $mysqlversion, $tone(!isset($version_error['mysqlversion']))],
	['CURL', isset($version_error['curl']) ? $version_error['curl'] : 'Installed', $tone(!isset($version_error['curl']))],
	['xml_parser_create()', isset($version_error['xml_parser_create']) ? $version_error['xml_parser_create'] : 'Installed', $tone(!isset($version_error['xml_parser_create']))],
	['MySQL partitioning', $partition_support == 0 ? 'Disabled' : 'Enabled', $tone($partition_support != 0, 'neutral'), 'Recommended for better performance with large datasets'],
	['PHP Memcache or Memcached', $memcacheInstalled ? 'Installed' : 'Missing', $tone($memcacheInstalled, 'warn'), 'Recommended for BlazerCache'],
	['PHP ZipArchive', class_exists('ZipArchive') ? 'Available' : 'Not available', $tone(class_exists('ZipArchive'), 'neutral'), 'Required for the 1-Click Upgrade feature'],
	['PHP OpenSSL', extension_loaded('openssl') ? 'Installed' : 'Missing', $tone(extension_loaded('openssl'), 'warn'), 'Required for Enhanced Account Security and ClickBank sales notifications'],
]);
if (!$version_error) { ?>
	<a href="<?php echo get_absolute_url(); ?>202-config/get_apikey.php" class="btn btn-primary btn-lg w-100 mt-3" target="_blank" id="202_lb_install_btn">Install Prosper202 ClickServer now</a>
<?php }
echo p202_standalone_card_end();
echo p202_standalone_card('For best performance, host with an Official Hosting Partner', 'Hosting that runs every Prosper202 requirement out of the box.');
echo p202_standalone_partners($partners);
echo p202_standalone_card_end();
?>
	<script>
var lb_url = "https://202.redirexit.com/tracking202/redirect/dl.php?t202id=72774&t202kw=req-screen-lb";
function leavebehind202() {
	this.target="_blank";
    setTimeout('window.location.href =lb_url', 200);
	return true;
	}

var el = document.getElementById("202_lb_install_btn");
if(el)
    el.addEventListener("click", leavebehind202);
</script>
<?php info_bottom();
