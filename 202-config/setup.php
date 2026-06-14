<?php
declare(strict_types=1);
/**
 * Unified setup entry point.
 *
 * A fresh install (Docker, shared-hosting upload, or local) lands here and is
 * forwarded to whichever step still needs doing, so the user follows one linear
 * path instead of hunting for the right page:
 *
 *   1. Database      -> 202-config/setup-config.php   (no 202-config.php yet)
 *   2. Requirements  -> 202-config/requirements.php   (server checks)
 *   3. API key       -> 202-config/get_apikey.php     (linked from requirements)
 *   4. Admin account -> 202-config/install.php        (creates the account)
 *
 * Steps 2->3->4 are already chained by the buttons on those pages; this router
 * just picks the correct starting point and skips steps already completed.
 */
require_once(__DIR__ . '/functions.php');

// Step 1: without 202-config.php we can't reach the database yet — collect the
// database credentials (and auto-create the database) in the config wizard.
if (!file_exists(__DIR__ . '/../202-config.php')) {
    header('Location: ' . get_absolute_url() . '202-config/setup-config.php');
    exit;
}

// Config exists, so pull in the full bootstrap (this also enforces the
// vendor/ dependency preflight in connect.php).
require_once(__DIR__ . '/connect.php');

// Already finished? Go to login.
if (is_installed() === true) {
    header('Location: ' . get_absolute_url() . '202-login.php');
    exit;
}

// Always start at the requirements screen so the server checks (PHP version and
// extensions, MySQL version, curl) run before account creation — install.php
// performs none of those. A stale user_api cookie must not let the user skip the
// gate, so we no longer short-circuit to install.php. requirements.php ->
// get_apikey.php -> install.php are chained by the buttons on each page.
header('Location: ' . get_absolute_url() . '202-config/requirements.php');
exit;
