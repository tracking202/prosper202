<?php

declare(strict_types=1);

/**
 * Visitors: every click in the window, one row each, with where it came from and went.
 *
 * On the v2 shell: the filters are one GET form whose values are applied to
 * the user's report preferences as the page loads, and the report is drawn
 * into the panel from tracking202/ajax/click_history.php, which reads them.
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
    'id' => 'visitors',
    'page_title' => 'Visitor History',
    'title' => 'Visitor history',
    'desc' => 'Every click in the window you choose, newest first: where it came from, what it saw and whether it converted.',
    'icon' => 'bi-people',
    'action' => $base . 'tracking202/visitors/',
    'fragment' => $base . 'tracking202/ajax/click_history.php',
    'panel' => 'Clicks',
    'names' => [...P202_OVERVIEW_CLICK_FILTERS, 'user_pref_limit'],
    'aside' => '<a class="btn btn-secondary btn-sm" href="' . htmlspecialchars($base . 'tracking202/visitors/download/', ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-file-earmark-spreadsheet"></i> Download to Excel</a>',
]);
