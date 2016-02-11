<?php
//include mysql settings
include_once(dirname( __FILE__ ) . '/connect.php');

//check to see if this is already installed, if so dob't do anything
if (is_installed() == true) {
		_die("<h6>Already Installed</h6>
			  <small>You appear to have already installed Prosper202. To reinstall please clear your old database tables first. <a href='/202-login.php'>Login Now</a></small>");
}


	$mysqlversion = $db->server_info;
	$html['mysqlversion'] = htmlentities($mysqlversion, ENT_QUOTES, 'UTF-8');

	$phpversion = phpversion(); 
	if ($phpversion < 5.3) { 
		$version_error['phpversion'] = 'Prosper202 requires PHP 5.3, or newer.';
	}

	$mysqlversion = substr($mysqlversion,0,3);
	if ($mysqlversion < 5) { 
		$version_error['mysqlversion'] = 'Prosper202 requires MySQL 5.0, or newer.';
	}

	if (!function_exists('curl_version')) { 
		$version_error['curl'] = 'Prosper202 requires CURL to be installed.';
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
	<h6>Welcome</h6>
	<small>Welcome to the five minute Prosper202 installation process! You may want to browse the <a href="http://prosper202.com/apps/docs/">ReadMe documentation</a> at your leisure. Otherwise, just fill in the information below and you'll be on your way to using the most powerful internet marketing applications in the world.</small>

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
			<td>PHP >= 5.3</td>
			<td><span class="label label-<?php if ($version_error['phpversion']) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php echo phpversion(); ?></span></td>
		</tr>
		<tr>
			<td>MySQL >= 5.0</td>
			<td><span class="label label-<?php if ($version_error['mysqlversion']) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php echo $html['mysqlversion'] ;?></span></td>
		</tr>
		<tr>
			<td>CURL</td>
			<td><span class="label label-<?php if ($version_error['curl']) {echo "important";} else {echo "primary";}?>" style="font-size: 100%;"><?php if($version_error['curl']) echo $version_error['curl']; else echo "Installed"; ?></span></td>
		</tr>
		<tr>
			<td>MySQL Partitioning</td>
			<td><span class="label label-<?php if ($partition_support==0) {echo "warning";} else {echo "primary";}?>" style="font-size: 100%;"><?php if($partition_support==0) echo "Missing"; else echo "Enabled"; ?></span></td>
		</tr>
		<tr>
			<td>PHP Memcache (recommended)</td>
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
		<tr>
			<td>PHP write permissions <br>(required for 1-Click Upgrade)</td>
			<td><span class="label label-<?php if (!is_writable(substr(dirname( __FILE__ ), 0,-10).'202-config/temp')) {echo "warning";} else {echo "primary";}?>" style="font-size: 100%;"><?php if(!is_writable(substr(dirname( __FILE__ ), 0,-10).'202-config/temp')) echo "Not writable"; else echo "Writable"; ?></span></td>
		</tr>
	</tbody>
	</table>
	
	<?php if($version_error) { ?>
	<strong style="color:#e74c3c">Your hosting does not meet Prosper202 server requirements!</strong>
	<br></br>
	<span class="small">Prosper202 hosting partners:</span>

	<?php 
		$partners = json_decode(file_get_contents('http://my.tracking202.com/api/v2/hostings'), true);

		foreach ($partners as $partner) { ?>
			<div class="media">
			  <div class="media-left">
			    <a href="<?php echo $partner['url'];?>">
			      <img class="media-object" style="width: 64px; height: 64px;" src="<?php echo $partner['thumb'];?>">
			    </a>
			  </div>
			  <div class="media-body">
			    <a href="<?php echo $partner['url'];?>" style="color: #337ab7;"><strong><?php echo $partner['title'];?></strong></a>
			    <a href="<?php echo $partner['url'];?>" style="color: #333;"><p class="infotext"><?php echo $partner['description'];?></p></a>
			  </div>
			</div>
		<?php }
	?>
	
	<?php } else { ?>
	<a href="<?php echo get_absolute_url();?>202-config/install.php" class="btn btn-lg btn-block btn-p202">Install Prosper202 Now <span class="glyphicon glyphicon-chevron-right"></span></a>
	<?php } ?>	

	</div>
<?php info_bottom(); 