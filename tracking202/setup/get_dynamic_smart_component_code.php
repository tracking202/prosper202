<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: '.get_absolute_url().'tracking202/');
	die();
}

require_once __DIR__ . '/_includes/setup_ui.php';
require_once __DIR__ . '/_includes/landing_code_page.php';

// A Dynamic Smart Component is set up with a simple landing page's code (the
// classic page posted the same form to the same endpoint); the dynamic
// content segments are listed under the code it generates.
p202_setup_landing_code_page($db, 'simple', 'Get Simple Landing Page Code', 'Dynamic Smart Component');
