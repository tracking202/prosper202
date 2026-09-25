<?php
declare(strict_types=1);
/*
 * 202-Mobile is retired (U7). Its sign-in led to the mini account overview;
 * the sign-in page now leads to the responsive Campaign Overview, which
 * shows the same totals at phone width.
 */
require_once dirname(__DIR__) . '/202-config/functions.php';

$overview = get_absolute_url() . 'tracking202/overview/';
header('Location: ' . get_absolute_url() . '202-login.php?redirect=' . rawurlencode($overview), true, 302);
exit;
