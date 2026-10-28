<?php
declare(strict_types=1);
include_once(str_repeat("../", 2) . '202-config/connect.php');
require_once dirname(__DIR__, 2) . '/202-config/functions-update-banner.php';

AUTH::require_user();

// The banner under the header (202-config/functions-update-banner.php); the
// chrome script asks check-for-update.php to refresh these flags first.
$hasCustomerKey = false;
if (!empty($_SESSION['premium_update_available']) && empty($_SESSION['update_needed'])) {
	$ownId = (int) ($_SESSION['user_own_id'] ?? 0);
    // A failed read throws (QueryException), and the page's handler records it.
    $conn = new \Prosper202\Database\Connection($db);
    $stmt = $conn->prepareWrite('SELECT p202_customer_api_key FROM 202_users WHERE user_id = ?');
    $conn->bind($stmt, 'i', [$ownId]);
    $row = $conn->fetchOne($stmt);
	$hasCustomerKey = is_array($row) && trim((string) ($row['p202_customer_api_key'] ?? '')) !== '';
}

echo p202_update_banner([
	'show' => !empty($_SESSION['show_update_check']),
	'managed' => auto_upgrade_disabled(),
	'not_possible' => !empty($_SESSION['auto_upgraded_not_possible']),
	'update_needed' => !empty($_SESSION['update_needed']),
	'premium' => !empty($_SESSION['premium_update_available']),
	'premium_details' => is_array($_SESSION['premium_p202_details'] ?? null) ? $_SESSION['premium_p202_details'] : [],
	'has_customer_key' => $hasCustomerKey,
], get_absolute_url());
