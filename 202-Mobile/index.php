<?php
declare(strict_types=1);
/*
 * 202-Mobile is retired (U7): every page of the app works at phone width on
 * the v2 shell, so the separate mobile mini-site is gone and its addresses
 * lead to the responsive pages. The root index decides where a visitor
 * belongs (setup, upgrade or sign in), as this page used to.
 */
require_once dirname(__DIR__) . '/202-config/functions.php';

header('Location: ' . get_absolute_url(), true, 302);
exit;
