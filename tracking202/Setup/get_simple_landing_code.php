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

p202_setup_landing_code_page($db, 'simple', 'Get Landing Page Code', 'Landing Page Code');
