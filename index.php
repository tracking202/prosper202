<?php
declare(strict_types=1);
//if the 202-config.php doesn't exist, we need to build one
if ( !file_exists( __DIR__ . '/202-config.php') ) {
	
	require_once(__DIR__ . '/202-config/version.php');
	require_once(__DIR__ . '/202-config/functions.php');
        //check to make sure this user has the required PHP version
        if (!php_version_supported()) {
                _die("<center><small>Prosper202 requires PHP " . PROSPER202_MIN_PHP_VERSION . " or greater to run. Your server does not meet the <a href='http://prosper.tracking202.com/apps/about/requirements/'>minimum requirements to run Prosper202</a>. Please upgrade PHP or sign up with one of our <a href='http://prosper.tracking202.com/apps/hosting/'>recommended hosting providers</a>.</small></center>");
        }
	
	//no 202-config.php yet — send the user straight into the setup wizard,
	//which collects the database details and can create the database for them.
	header('location: ' . get_absolute_url() . '202-config/setup.php');
	exit;


} else {

	require_once(__DIR__ . '/202-config/connect.php');

	if (  is_installed() == false) {
		
		header('location: '.get_absolute_url().'202-config/setup.php');
	 
	} else {
		
		if ( upgrade_needed() == true) {
			
			header('location: '.get_absolute_url().'202-config/upgrade.php');
			
		} else {
	
			header('location: '.get_absolute_url().'202-login.php');
		
		}
	}
	
}
