<?php

declare(strict_types=1);

/**
 * Overview › Group Overview: the traffic grouped by up to four dimensions.
 *
 * On the v2 shell: the filters are one GET form whose values are applied to
 * the user's report preferences as the page loads, and the report is drawn
 * into the panel from tracking202/ajax/group_overview.php, which reads them.
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
    'id' => 'group-overview',
    'page_title' => 'Group Overview',
    'title' => 'Group overview',
    'desc' => 'All of your traffic grouped by up to four dimensions, one inside the other.',
    'icon' => 'bi-diagram-3',
    'action' => $base . 'tracking202/overview/group-overview.php',
    'fragment' => $base . 'tracking202/ajax/group_overview.php',
    'panel' => 'Grouped report',
    'names' => ['group_1', 'group_2', ...P202_OVERVIEW_CLICK_FILTERS, 'user_cpc_or_cpv', 'group_3', 'group_4'],
    'common' => ['group_1', 'group_2'],
    // With no grouping stored the report is empty, although the classic
    // menu showed "Traffic Source" over it. Store what the menu shows.
    'defaults' => ['group_1' => P202_OVERVIEW_GROUP_TRAFFIC_SOURCE],
    'aside' => '<a class="btn btn-secondary btn-sm" href="' . htmlspecialchars($base . 'tracking202/overview/group_overview_download.php', ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-file-earmark-spreadsheet"></i> Download to Excel</a>',
]);
