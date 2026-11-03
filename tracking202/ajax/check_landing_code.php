<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');
require_once dirname(__DIR__) . '/setup/_includes/setup_ui.php';

AUTH::require_user();
AUTH::require_permissions('access_to_setup_section');

/*
 * "Check my page" on Setup › Get LP Code: fetches the landing page's saved
 * URL (Prosper202\Setup\LandingPageCheck) and answers whether the page
 * carries its code, as a flash. Only the account's own landing pages.
 */
if (!AUTH::check_csrf_token()) {
	http_response_code(403);
	die('Invalid token, please reload the page and try again.');
}

$landingPageId = \Prosper202\Http\PostedId::read($_POST['landing_page_id'] ?? null);
if ($landingPageId === null) {
	die(p202_flash('bad', 'Choose a landing page, get its code, then check the page.'));
}

$conn = new \Prosper202\Database\Connection($db);
$stmt = $conn->prepareRead('SELECT landing_page_url, landing_page_id_public FROM 202_landing_pages WHERE landing_page_id = ? AND user_id = ? AND landing_page_deleted = 0');
$conn->bind($stmt, 'ii', [$landingPageId, (int) $_SESSION['user_id']]);
$page = $conn->fetchOne($stmt);
if ($page === null) {
	die(p202_flash('bad', 'That landing page is not yours, or it was removed.'));
}

$result = (new \Prosper202\Setup\LandingPageCheck())->check((string) $page['landing_page_url'], (string) ($page['landing_page_id_public'] ?? ''));
echo p202_flash($result->flashKind(), $result->message);
