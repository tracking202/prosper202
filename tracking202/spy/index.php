<?php

declare(strict_types=1);

/**
 * Spy: the clicks of the last 24 hours, as they arrive.
 *
 * On the v2 shell: the filters are one GET form whose values are applied to
 * the user's report preferences as the page loads, and the report is drawn
 * into the panel from tracking202/ajax/click_history.php?spy=1, which reads them.
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
    'id' => 'spy',
    'page_title' => 'Spy View',
    'title' => 'Spy',
    'desc' => 'Clicks from the last 24 hours as they arrive; new ones appear at the top every few seconds.',
    'icon' => 'bi-broadcast',
    'action' => $base . 'tracking202/spy/',
    'fragment' => $base . 'tracking202/ajax/click_history.php?spy=1',
    'panel' => 'Live clicks',
    // The live view has its own window, the last 24 hours, whatever the
    // reports' range says; the classic page offered no range either.
    'range' => false,
    'spy' => true,
    'names' => P202_OVERVIEW_CLICK_FILTERS,
    'note' => 'The last 24 hours, whatever range the reports use. The filters you apply here also open by default on the reports.',
]);
