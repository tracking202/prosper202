<?php

declare(strict_types=1);

/**
 * Overview › Day Parting: which hours of the day perform, added up over the window.
 *
 * On the v2 shell: the filters are one GET form whose values are applied to
 * the user's report preferences as the page loads, and the report is drawn
 * into the panel from tracking202/ajax/sort_hourly.php, which reads them.
 * 202-config/functions-ui-overview.php holds the recipe every page in this
 * family follows.
 */

include_once dirname(__DIR__, 2) . '/202-config/connect.php';

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

require_once dirname(__DIR__, 2) . '/202-config/functions-ui-overview.php';

$base = get_absolute_url();

p202_overview_run([
    'id' => 'day-parting',
    'page_title' => 'Hourly Overview',
    'title' => 'Day parting',
    'desc' => 'Which hours of the day perform best, added up across the window you choose.',
    'icon' => 'bi-clock',
    'action' => $base . 'tracking202/overview/day-parting.php',
    'fragment' => $base . 'tracking202/ajax/sort_hourly.php',
    'panel' => 'By hour of the day',
    'names' => [...P202_OVERVIEW_CLICK_FILTERS, 'user_cpc_or_cpv'],
]);
