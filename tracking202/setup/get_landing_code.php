<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: '.get_absolute_url().'tracking202/');
	die();
}

// Redirect to Simple Landing Page by default
// Users can switch to Advanced using the tabs on that page
header('location: '.get_absolute_url().'tracking202/setup/get_simple_landing_code.php');
die();
