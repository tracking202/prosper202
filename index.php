<?php
declare(strict_types=1);
//if the 202-config.php doesn't exist, we need to build one
if ( !file_exists( dirname( __FILE__ ) . '/202-config.php') ) {
	
	require_once(dirname( __FILE__ ) . '/202-config/functions.php');
        //check to make sure this user has the required PHP version
        if (!php_version_supported()) {
                _die("<center><small>Prosper202 requires PHP " . PROSPER202_MIN_PHP_VERSION . " or greater to run. Your server does not meet the <a href='http://prosper.tracking202.com/apps/about/requirements/'>minimum requirements to run Prosper202</a>. Please upgrade PHP or sign up with one of our <a href='http://prosper.tracking202.com/apps/hosting/'>recommended hosting providers</a>.</small></center>");
        }
	
	//require the 202-config.php file
	_die("<center><small>There doesn't seem to be a <code>202-config.php</code> file. I need this before we can get started. <br/>Need more help? <a href=\"http://prosper202.com/apps/about/contact/\">Contact Us</a>. You can <a href='".get_absolute_url()."202-config/setup-config.php'>create a <code>202-config.php</code> file through a web interface</a>, but this doesn't work for all server setups. The safest way is to manually create the file.</small></center>");


} else {

	require_once(dirname( __FILE__ ) . '/202-config/connect.php');

	if (  is_installed() == false) {
		
		header('location: '.get_absolute_url().'202-config/requirements.php');
	 
	} else {
		
		if ( upgrade_needed() == true) {
			
			header('location: '.get_absolute_url().'202-config/upgrade.php');
			
		} else {
	
			header('location: '.get_absolute_url().'202-login.php');
		
		}
	}
	
}
