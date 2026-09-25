<?php

declare(strict_types=1);

/**
 * Overview › Account Overview: every campaign and landing page at a glance, with a chart of
 * the figures the user picks.
 *
 * On the v2 shell: the filters are one GET form whose values are applied to
 * the user's report preferences as the page loads, and the report is drawn
 * into the panel from tracking202/ajax/account_overview.php, which reads them.
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
    'id' => 'overview',
    'page_title' => 'Account Overview',
    'title' => 'Account overview',
    'desc' => 'How every campaign and landing page is performing in the window you choose.',
    'icon' => 'bi-speedometer2',
    'action' => $base . 'tracking202/overview/',
    'fragment' => $base . 'tracking202/ajax/account_overview.php',
    'panel' => 'Performance',
    'names' => ['user_pref_show', 'user_cpc_or_cpv'],
    // The customer-lifetime-value strip: drawn only for an account that
    // tracks customers; the fragment answers nothing otherwise.
    'before_panel' => '<div class="mb-3" data-p202-snippet="' . htmlspecialchars($base . 'tracking202/ajax/ltv_snapshot.php', ENT_QUOTES, 'UTF-8') . '"></div>',
]);
