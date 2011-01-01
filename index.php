<?php 

//if the 202-config.php doesn't exist, we need to build one
if ( !file_exists( $_SERVER['DOCUMENT_ROOT'] . '/202-config.php') ) {
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions.php');
	
	//check to make sure this user has php 5 or greater
	$php_version = phpversion();
	$php_version = substr($php_version,0,1);
	if ($php_version < 5) {
		_die("Prosper202 requires PHP 5 or greater to run.  Your server does not meet the <a href='http://prosper.tracking202.com/apps/about/requirements/'>minimum requirements to run Prosper202</a>.  Please either have your hosting provider upgrade to PHP 5 or simply sign up with one of our <a href='http://prosper.tracking202.com/apps/hosting/'>recommended hosting providers</a>.");
	}
	
	//require the 202-config.php file
	_die("There doesn't seem to be a <code>202-config.php</code> file. I need this before we can get started. Need more help? <a href=\"http://prosper202.com/apps/about/contact/\">Contact Us</a>. You can <a href='/202-config/setup-config.php'>create a <code>202-config.php</code> file through a web interface</a>, but this doesn't work for all server setups. The safest way is to manually create the file.", "202 &rsaquo; Error");


} else {

	require_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');

	if (  is_installed() == false) {
		
		header('location: /202-config/install.php');
	 
	} else {
		
		if ( upgrade_needed() == true) {
			
			header('location: /202-config/upgrade.php');
			
		} else {
	
			header('location: /202-login.php');
		
		}
	}
	
}