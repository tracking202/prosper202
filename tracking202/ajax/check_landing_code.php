<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');
require_once dirname(__DIR__) . '/setup/_includes/setup_ui.php';

AUTH::require_user();
AUTH::require_permissions('access_to_setup_section');

/*
 * The landing page code check, asked by a page: "Check my page" on Setup ›
 * Get LP Code, and each landing page's pill on Setup › Landing Pages
 * (as=pill). It fetches the landing page's saved URL
 * (Prosper202\Setup\LandingPageCheck), keeps the answer
 * (LandingPageCheckStore) and says whether the page carries its code, as a
 * flash or as the pill. Only the account's own landing pages.
 */
if (!AUTH::check_csrf_token()) {
	http_response_code(403);
	die('Invalid token, please reload the page and try again.');
}

$landingPageId = \Prosper202\Http\PostedId::read($_POST['landing_page_id'] ?? null);
if ($landingPageId === null) {
	die(p202_flash('bad', 'Choose a landing page, get its code, then check the page.'));
}

$asPill = ($_POST['as'] ?? null) === 'pill';
// The fetch can take LandingPageCheck::TIMEOUT seconds; the session is not held for it.
session_write_close();

$store = new \Prosper202\Setup\LandingPageCheckStore(new \Prosper202\Database\Connection($db));
$result = $store->run((int) $_SESSION['user_id'], $landingPageId, getTrackingDomain());
if ($result === null) {
	die($asPill ? p202_setup_lp_check_pill($landingPageId, null, false) : p202_flash('bad', 'That landing page is not yours, or it was removed.'));
}
echo $asPill
	? p202_setup_lp_check_pill($landingPageId, ['status' => $result->status, 'message' => $result->message, 'checked_at' => time()])
	: p202_flash($result->flashKind(), $result->message);
