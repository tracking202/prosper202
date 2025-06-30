<?php
declare(strict_types=1);
//include mysql settings
include_once(dirname( __FILE__ ) . '/connect.php');

// Initialize variables
$version_error = array();
$memcacheInstalled = (extension_loaded('memcache') || extension_loaded('memcached'));

//check to see if this is already installed, if so don't do anything
if (is_installed() == true) {
		_die("<h6>Already Installed</h6>
			  <small>You appear to have already installed Prosper202. To reinstall please clear your old database tables first. <a href='/202-login.php'>Login Now</a></small>");
}

    // Get Database version
	$mysqlversion = $db->server_info;
	if (preg_match('/-(10\..+)-MariaDB/i', $mysqlversion, $match)) {
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
	
	$html['mysqlversion'] = htmlentities($mysqlversion, ENT_QUOTES, 'UTF-8');

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
	$result = $db->query($sql);
	
	if ($result && $result->num_rows > 0) {
	    $partition_support = 1;
	}
	else{
	    $partition_support = 0;
	}
 
info_top(); ?>
	<div class="main col-xs-7 install">
	<center><img src="<?php echo get_absolute_url();?>202-img/prosper202.png"></center>
	<h6>Hey There!</h6>
	<small>Before we get started, let's make sure your server is optimized for the ultimate Prosper202 ClickServer Performance.</small>
	<br></br>
	<h6>For Best Performance - Host With The Prosper202 Official Hosting Partners:</h6>
	<?php 
		$partners_data = getData('https://my.tracking202.com/api/v2/hostings');
		$partners = array();
		
		if ($partners_data) {
			$partners = json_decode($partners_data, true);
		}
		
		// Fallback if API is unavailable
		if (!$partners || !is_array($partners)) {
			$partners = array(
				array(
					'title' => 'Visit Official Hosting Partners',
					'description' => 'Get recommended hosting for Prosper202',
					'url' => 'https://my.tracking202.com/hosting',
					'thumb' => '202-img/prosper202.png'
				)
			);
		}

		foreach ($partners as $partner) { ?>
			<div class="media">
			  <div class="media-left">
			    <a href="<?php echo htmlspecialchars($partner['url']);?>">
			      <img class="media-object" style="width: 64px; height: 64px;" src="<?php echo htmlspecialchars($partner['thumb']);?>">
			    </a>
			  </div>
			  <div class="media-body">
			    <a href="<?php echo htmlspecialchars($partner['url']);?>" style="color: #337ab7;"><strong><?php echo htmlspecialchars($partner['title']);?></strong></a>
			    <p class="infotext"><a href="<?php echo htmlspecialchars($partner['url']);?>" style="color: #333;"><?php echo htmlspecialchars($partner['description']);?></a></p>
			  </div>
			</div>
		<?php }
	?>
	<h6>System requirements</h6>
	<table class="table table-bordered">
	<thead>
		<tr class="info">
			<th>Software / Function</th>
			<th>Status</th>
		</tr>
	</thead>
	<tbody>
                <tr>
                        <td>PHP >= 8.1 <strong>(PHP 8.3+ recommended for best performance)</strong></td>
                        <td><span class="label label-<?php if (isset($version_error['phpversion'])) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php echo phpversion(); ?></span></td>
                </tr>
		<tr>
			<td><?php echo $dbwording?></td>
			<td><span class="label label-<?php if (isset($version_error['mysqlversion'])) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php echo $html['mysqlversion'] ;?></span></td>
		</tr>
		<tr>
			<td>CURL</td>
			<td><span class="label label-<?php if (isset($version_error['curl'])) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php if(isset($version_error['curl'])) echo $version_error['curl']; else echo "Installed"; ?></span></td>
		</tr>
		<tr>
			<td>xml_parser_create()</td>
			<td><span class="label label-<?php if (isset($version_error['xml_parser_create'])) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php if(isset($version_error['xml_parser_create'])) echo $version_error['xml_parser_create']; else echo "Installed"; ?></span></td>
		</tr>
		<tr>
			<td>MySQL Partitioning <br><small>(Recommended for better performance with large datasets)</small></td>
			<td><span class="label label-<?php if ($partition_support==0) {echo "info";} else {echo "primary";}?>" style="font-size: 100%;"><?php if($partition_support==0) echo "Disabled"; else echo "Enabled"; ?></span></td>
		</tr>
		<tr>
			<td>PHP Memcache or Memcached (Recommended for BlazerCache&trade;)</td>
			<td><span class="label label-<?php if (!$memcacheInstalled) {echo "warning";} else {echo "primary";}?>" style="font-size: 100%;"><?php if(!$memcacheInstalled) echo "Missing"; else echo "Installed"; ?></span></td>
		</tr>

		<tr>
			<td>PHP ZipArchive <br><small>(Required for 1-Click Upgrade feature)</small></td>
			<td><span class="label label-<?php if (!class_exists('ZipArchive')) {echo "info";} else {echo "primary";}?>" style="font-size: 100%;"><?php if(!class_exists('ZipArchive')) echo "Not Available"; else echo "Available"; ?></span></td>
		</tr>

		<tr>
			<td>PHP OpenSSL <br>(required for Enhanced Account Security and Clickbank Sales Notification Integration)</td>
			<td><span class="label label-<?php if (!extension_loaded('openssl')) {echo "warning";} else {echo "primary";}?>" style="font-size: 100%;"><?php if(!extension_loaded('openssl')) echo "Missing"; else echo "Installed"; ?></span></td>
		</tr>

	</tbody>
	</table>
	
	<?php if($version_error) { ?>
	<h4 style="color:#e74c3c">Your current host does not meet the Prosper202 Server Requirements! <br><br>Please switch to an Official Hosting Partner below to continue without issues:</h4>
	<br></br>
	<h6>Prosper202 Official Hosting Partners:</h6>

	<?php 
		foreach ($partners as $partner) { ?>
			<div class="media">
			  <div class="media-left">
			    <a href="<?php echo htmlspecialchars($partner['url']);?>">
			      <img class="media-object" style="width: 64px; height: 64px;" src="<?php echo htmlspecialchars($partner['thumb']);?>">
			    </a>
			  </div>
			  <div class="media-body">
			    <a href="<?php echo htmlspecialchars($partner['url']);?>" style="color: #337ab7;"><strong><?php echo htmlspecialchars($partner['title']);?></strong></a>
			    <p class="infotext"><a href="<?php echo htmlspecialchars($partner['url']);?>" style="color: #333;"><?php echo htmlspecialchars($partner['description']);?></a></p>
			  </div>
			</div>
		<?php }
	?>
	
	<?php } else { ?>
	<a href="<?php echo get_absolute_url();?>202-config/get_apikey.php" class="btn btn-lg btn-block btn-p202" target="_blank" id="202_lb_install_btn">Install Prosper202 ClickServer Now <span class="glyphicon glyphicon-chevron-right"></span></a>
	<?php } ?>	

	</div>
	<script type="text/javascript">
<!--
var lb_url = "https://202.redirexit.com/tracking202/redirect/dl.php?t202id=72774&t202kw=req-screen-lb";
function leavebehind202() {
	this.target="_blank";
    setTimeout('window.location.href =lb_url', 200);
	return true;
	}

var el = document.getElementById("202_lb_install_btn");
if(el)
    el.addEventListener("click", leavebehind202);
//-->
</script>
<?php info_bottom(); 