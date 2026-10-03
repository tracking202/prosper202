<?php

declare(strict_types=1);

/**
 * Overview › Week Parting: which days of the week perform.
 *
 * On the v2 shell: the filters are one GET form whose values are applied to
 * the user's report preferences as the page loads, and the report is drawn
 * into the panel from tracking202/ajax/sort_weekly.php, which reads them.
 * 202-config/functions-ui-overview.php holds the recipe every page in this
 * family follows.
 */

include_once dirname(__DIR__, 2) . '/202-config/connect.php';

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

require_once dirname(__DIR__, 2) . '/202-config/functions-ui-overview.php';

$base = get_absolute_url();

p202_overview_run([
    'id' => 'week-parting',
    'page_title' => 'Week Parting',
    'title' => 'Week parting',
    'desc' => 'Which days of the week perform best, added up across the window you choose.',
    'icon' => 'bi-calendar-week',
    'action' => $base . 'tracking202/overview/week-parting.php',
    'fragment' => $base . 'tracking202/ajax/sort_weekly.php',
    'panel' => 'By day of the week',
    'names' => [...P202_OVERVIEW_CLICK_FILTERS, 'user_cpc_or_cpv'],
]);
