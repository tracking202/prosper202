<?php 

//if the 202-config.php doesn't exist, we need to build one
if ( !file_exists( $_SERVER['DOCUMENT_ROOT'] . '/202-config.php') ) {
	

	require_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/functions.php');
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