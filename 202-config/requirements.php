<?php
//include mysql settings
include_once(dirname( __FILE__ ) . '/connect.php');

//check to see if this is already installed, if so dob't do anything
if (is_installed() == true) {
		_die("<h6>Already Installed</h6>
			  <small>You appear to have already installed Prosper202. To reinstall please clear your old database tables first. <a href='/202-login.php'>Login Now</a></small>");
}

    // Get Database version
	$mysqlversion = $db->server_info;
	if (preg_match('/-(10\..+)-MariaDB/i', $mysqlversion, $match)) {
	    // Support For MariaDB
	    $mysqlversion = $match[1];
	    $dbwording="MariaDB >= 10.0.12";
	    if ((version_compare($mysqlversion, '10.0.12') < 0)) {
	        $version_error['mysqlversion'] = 'Prosper202 requires MariaDB 10.0.12, or newer.';
	    }
	}
	else{
	    $dbwording="MySQL >= 5.6";
	    if ((version_compare($mysqlversion, '5.6') < 0)) {
	        $version_error['mysqlversion'] = 'Prosper202 requires MySQL 5.6, or newer.';
	    }
	     
	}
	
	$html['mysqlversion'] = htmlentities($mysqlversion, ENT_QUOTES, 'UTF-8');

	//$phpversion = phpversion(); 
	if ((version_compare(PHP_VERSION, '5.4') < 0)) { 
		$version_error['phpversion'] = 'Prosper202 requires PHP 5.4, or newer.';
	}


	if (!function_exists('curl_version')) { 
		$version_error['curl'] = 'Prosper202 requires CURL to be installed.';
	}  
	
	if (!function_exists('xml_parser_create')) {
		$version_error['xml_parser_create'] = 'Prosper202 requires xml_parser_create() function to be installed.';
	}

	$sql = "SELECT PLUGIN_NAME as Name, PLUGIN_STATUS as Status FROM INFORMATION_SCHEMA.PLUGINS WHERE PLUGIN_TYPE='STORAGE ENGINE' AND PLUGIN_NAME='partition' AND PLUGIN_STATUS='ACTIVE'";
	$result = $db->query($sql);
	
	if ($result->num_rows != 1) {
	    $partition_support = 0;
	}
	else{
	    $partition_support = 1;
	}
 
info_top(); ?>
	<div class="main col-xs-7 install">
	<center><img src="<?php echo get_absolute_url();?>202-img/prosper202.png"></center>
	<h6>Hey There!</h6>
	<small>Before we get started, let's make sure your server is optimized for the ultimate Prosper202 ClickServer Performance.</small>
	<br></br>
	<h6>For Best Performance - Host With The Prosper202 Official Hosting Partners:</h6>
	<?php 
		$partners = json_decode(getData('https://my.tracking202.com/api/v2/hostings'), true);

		foreach ($partners as $partner) { ?>
			<div class="media">
			  <div class="media-left">
			    <a href="<?php echo $partner['url'];?>">
			      <img class="media-object" style="width: 64px; height: 64px;" src="<?php echo $partner['thumb'];?>">
			    </a>
			  </div>
			  <div class="media-body">
			    <a href="<?php echo $partner['url'];?>" style="color: #337ab7;"><strong><?php echo $partner['title'];?></strong></a>
			    <p class="infotext"><a href="<?php echo $partner['url'];?>" style="color: #333;"><?php echo $partner['description'];?></a></p>
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
			<td>PHP >= 5.6 <strong> (Use PHP 7+ For Up to 400x Faster Redirects)</strong></td>
			<td><span class="label label-<?php if ($version_error['phpversion']) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php echo phpversion(); ?></span></td>
		</tr>
		<tr>
			<td><?php echo $dbwording?></td>
			<td><span class="label label-<?php if ($version_error['mysqlversion']) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php echo $html['mysqlversion'] ;?></span></td>
		</tr>
		<tr>
			<td>CURL</td>
			<td><span class="label label-<?php if ($version_error['curl']) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php if($version_error['curl']) echo $version_error['curl']; else echo "Installed"; ?></span></td>
		</tr>
		<tr>
			<td>xml_parser_create()</td>
			<td><span class="label label-<?php if ($version_error['xml_parser_create']) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php if($version_error['xml_parser_create']) echo $version_error['xml_parser_create']; else echo "Installed"; ?></span></td>
		</tr>
		<tr>
			<td>MySQL Partitioning</td>
			<td><span class="label label-<?php if ($partition_support==0) {echo "warning";} else {echo "primary";}?>" style="font-size: 100%;"><?php if($partition_support==0) echo "Missing"; else echo "Enabled"; ?></span></td>
		</tr>
		<tr>
			<td>PHP Memcache or Memcached (Recommended for BlazerCache&trade;)</td>
			<td><span class="label label-<?php if (!$memcacheInstalled) {echo "warning";} else {echo "primary";}?>" style="font-size: 100%;"><?php if(!$memcacheInstalled) echo "Missing"; else echo "Installed"; ?></span></td>
		</tr>

		<tr>
			<td>PHP zip_open() <br>(required for 1-Click Upgrade)</td>
			<td><span class="label label-<?php if (!function_exists('zip_open')) {echo "warning";} else {echo "primary";}?>" style="font-size: 100%;"><?php if(!function_exists('zip_open')) echo "Missing"; else echo "Installed"; ?></span></td>
		</tr>

		<tr>
			<td>PHP Mycrypt <br>(required for Enhanced Account Security and Clickbank Sales Notification Integration)</td>
			<td><span class="label label-<?php if (!function_exists('mcrypt_encrypt')) {echo "warning";} else {echo "primary";}?>" style="font-size: 100%;"><?php if(!function_exists('mcrypt_encrypt')) echo "Missing"; else echo "Installed"; ?></span></td>
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
			    <a href="<?php echo $partner['url'];?>">
			      <img class="media-object" style="width: 64px; height: 64px;" src="<?php echo $partner['thumb'];?>">
			    </a>
			  </div>
			  <div class="media-body">
			    <a href="<?php echo $partner['url'];?>" style="color: #337ab7;"><strong><?php echo $partner['title'];?></strong></a>
			    <p class="infotext"><a href="<?php echo $partner['url'];?>" style="color: #333;"><?php echo $partner['description'];?></a></p>
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