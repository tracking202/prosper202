<?php
declare(strict_types=1);
/*
 * 202-Mobile is retired (U7). The mini account overview's totals (clicks,
 * leads, income, cost, net, ROI for the chosen dates) are Campaign
 * Overview's, which works at phone width; it asks for a sign-in itself.
 */
require_once dirname(__DIR__, 2) . '/202-config/functions.php';

header('Location: ' . get_absolute_url() . 'tracking202/overview/', true, 302);
exit;
