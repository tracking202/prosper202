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
	$stmt = $db->prepare('SELECT p202_customer_api_key FROM 202_users WHERE user_id = ?');
	if ($stmt === false) {
		throw new RuntimeException('update-needed: could not prepare the customer key lookup: ' . $db->error);
	}
	$stmt->bind_param('i', $ownId);
	if (!$stmt->execute()) {
		$stmt->close();
		throw new RuntimeException('update-needed: could not read the customer key: ' . $db->error);
	}
	$result = $stmt->get_result();
	if ($result === false) {
		$stmt->close();
		throw new RuntimeException('update-needed: could not read the customer key: ' . $db->error);
	}
	$row = $result->fetch_assoc();
	$stmt->close();
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
