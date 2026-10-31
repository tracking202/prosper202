<?php

declare(strict_types=1);

/**
 * Overview › Breakdown Analysis: the figures per hour, day, month or year.
 *
 * On the v2 shell: the filters are one GET form whose values are applied to
 * the user's report preferences as the page loads, and the report is drawn
 * into the panel from tracking202/ajax/sort_breakdown.php, which reads them.
 * 202-config/functions-ui-overview.php holds the recipe every page in this
 * family follows.
 */

include_once dirname(__DIR__, 2) . '/202-config/connect.php';

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

require_once dirname(__DIR__, 2) . '/202-config/functions-ui-overview.php';

$base = get_absolute_url();

p202_overview_run([
    'id' => 'breakdown',
    'page_title' => 'Breakdown Overview',
    'title' => 'Breakdown analysis',
    'desc' => 'Your figures per hour, day, month or year across the window you choose.',
    'icon' => 'bi-calendar3',
    'action' => $base . 'tracking202/overview/breakdown.php',
    'fragment' => $base . 'tracking202/ajax/sort_breakdown.php',
    'panel' => 'Breakdown',
    'names' => ['user_pref_breakdown', ...P202_OVERVIEW_CLICK_FILTERS, 'user_cpc_or_cpv'],
    'common' => ['user_pref_breakdown'],
]);
