<?php

declare(strict_types=1);

/**
 * Overview › Rotator Breakdown: each rotator, its rules and its default, over the window.
 *
 * On the v2 shell: the filters are one GET form whose values are applied to
 * the user's report preferences as the page loads, and the report is drawn
 * into the panel from tracking202/ajax/sort_rotator.php, which reads them.
 * 202-config/functions-ui-overview.php holds the recipe every page in this
 * family follows.
 */

include_once dirname(__DIR__, 2) . '/202-config/connect.php';

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

require_once dirname(__DIR__, 2) . '/202-config/functions-ui-overview.php';

$base = get_absolute_url();

p202_overview_run([
    'shell' => ['ui' => 'v2'],
    'id' => 'rotator-breakdown',
    'page_title' => 'Redirectors Breakdown Overview',
    'title' => 'Rotator breakdown',
    'desc' => 'Each rotator, its rules and its default redirect, over the window you choose.',
    'icon' => 'bi-shuffle',
    'action' => $base . 'tracking202/overview/rotator-breakdown.php',
    'fragment' => $base . 'tracking202/ajax/sort_rotator.php',
    'panel' => 'Rotators',
    'names' => ['user_pref_show', 'user_cpc_or_cpv'],
]);
