<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/202-config/connect.php';
require_once dirname(__DIR__, 2) . '/202-config/functions-ui-overview.php';

AUTH::require_user();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $request = p202_overview_filter_choices_request($_GET);
    $kind = match ($request['filter']) {
        'region_id' => 'region',
        'isp_id' => 'isp',
        default => throw new InvalidArgumentException('Choose a valid named report filter.'),
    };
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $store = new \Tracking202\Report\ReportPrefsStore(DB::getInstance()->getConnection());
    $choices = $store->suggestions(
        $kind,
        p202_overview_data_user_id(),
        $request['from'],
        $request['to'],
        P202_OVERVIEW_SEEN_LIMIT,
        \Prosper202\Report\FilterChoicesCache::fromGlobals()
    );
    $options = array_map(static fn (string $label): array => ['value' => $label, 'label' => $label], $choices);
    echo json_encode(['options' => $options], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
} catch (RuntimeException | JsonException $error) {
    error_log('Analyze filter choices: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'The suggestions could not be loaded. Try again.'], JSON_THROW_ON_ERROR);
}
