<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');
require_once __DIR__ . '/../../202-config/functions-account-ui.php';

// Every other endpoint in 202-account/ajax/ authenticates. Without this,
// api_key_validate() makes a server-side cURL POST of attacker-supplied
// input to the vendor API — an unauthenticated outbound-request oracle.
AUTH::require_user();

if (isset($_POST['apikey'])) {
	echo api_key_validate(trim((string) $_POST['apikey'])) ?: p202_status_body(503, 'The key could not be checked.');
}

