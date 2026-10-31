<?php

/** Optional report choices, requested one filter at a time after the page renders. */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/202-config/connect.php';
require_once dirname(__DIR__, 2) . '/202-config/functions-ui-overview.php';

AUTH::require_user();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $request = p202_overview_filter_choices_request($_GET);
    $dataUserId = p202_overview_data_user_id();
    // Keep long optional lookups from holding the signed-in user's session lock.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $conn = new \Prosper202\Database\Connection(DB::getInstance()->getConnection());
    $list = p202_overview_seen_list(
        $conn,
        $request['filter'],
        $dataUserId,
        $request['from'],
        $request['to'],
        $request['current'],
        P202_OVERVIEW_SEEN_LIMIT,
        \Prosper202\Report\FilterChoicesCache::fromGlobals()
    );
    $options = [];
    foreach ($list as $value => $label) {
        $options[] = ['value' => (string) $value, 'label' => $label];
    }
    echo json_encode(['options' => $options], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
} catch (RuntimeException | JsonException $error) {
    error_log('Report filter choices: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'The choices could not be loaded. Try again.'], JSON_THROW_ON_ERROR);
}
