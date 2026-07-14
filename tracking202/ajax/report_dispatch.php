<?php

declare(strict_types=1);

use Tracking202\Report\Json\FlatReportPayloadBuilder;
use Tracking202\Report\Json\ReportDispatchRequest;

include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -17) . '/202-config/class-dataengine.php');

if (!function_exists('tracking202ReportDispatchRespondJson')) {
    /**
     * @param array<string, mixed> $payload
     */
    function tracking202ReportDispatchRespondJson(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        try {
            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            http_response_code(500);
            echo '{"ok":false,"error":{"message":"Response encoding failed.","status":500}}';
        }

        exit;
    }
}

if (!tracking202JsonArchitectureEnabled()) {
    tracking202ReportDispatchRespondJson(404, [
        'ok' => false,
        'error' => [
            'message' => 'Tracking JSON architecture mode is disabled.',
            'status' => 404,
        ],
    ]);
}

if (!AUTH::logged_in()) {
    tracking202ReportDispatchRespondJson(401, [
        'ok' => false,
        'error' => [
            'message' => 'Authentication is required.',
            'status' => 401,
        ],
    ]);
}

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

try {
    $request = ReportDispatchRequest::fromGlobals();
    $request->applyLegacyGlobals();

    $time = grab_timeframe();
    $from = $db->real_escape_string((string) $time['from']);
    $to = $db->real_escape_string((string) $time['to']);

    $userId = $db->real_escape_string((string) $_SESSION['user_id']);
    $userSql = "SELECT
                    user_cpc_or_cpv,
                    user_pref_aff_network_id,
                    user_pref_aff_campaign_id,
                    user_pref_text_ad_id,
                    user_pref_method_of_promotion,
                    user_pref_landing_page_id
                FROM 202_users_pref
                WHERE user_id=" . $userId;
    $userResult = _mysqli_query($userSql);
    if (!$userResult instanceof mysqli_result) {
        // No silent fallback: swallowing this would default $cpv to false and cost the
        // entire report as CPC for a CPV user — wrong numbers rather than an error.
        // The Throwable handler below turns this into a clean JSON 500.
        throw new RuntimeException('Failed to read user pricing preferences.');
    }

    $userRow = $userResult->fetch_assoc() ?? [];
    $cpv = (($userRow['user_cpc_or_cpv'] ?? '') === 'cpv');

    $dataEngine = new DataEngine();
    $reportData = $dataEngine->getReportData($request->reportType, $from, $to, $cpv);

    if (!is_array($reportData)) {
        tracking202ReportDispatchRespondJson(500, [
            'ok' => false,
            'error' => [
                'message' => 'Report data could not be generated.',
                'status' => 500,
            ],
        ]);
    }

    tracking202ReportDispatchRespondJson(200, [
        'ok' => true,
        'transportVersion' => 1,
        'generatedAt' => gmdate(DATE_ATOM),
        'request' => [
            'reportType' => $request->reportType,
            'offset' => $request->offset,
            'order' => $request->order,
            'includeDependentFilters' => $request->includeDependentFilters,
        ],
        'report' => FlatReportPayloadBuilder::build(
            $request->reportType,
            $reportData,
            (int) $dataEngine->foundRows(),
            $request,
            $userRow
        ),
        'supportedOrderTokens' => ReportDispatchRequest::supportedOrderTokens(),
    ]);
} catch (InvalidArgumentException $e) {
    $status = (int) $e->getCode();
    if ($status < 400 || $status > 499) {
        $status = 400;
    }

    tracking202ReportDispatchRespondJson($status, [
        'ok' => false,
        'error' => [
            'message' => $e->getMessage(),
            'status' => $status,
        ],
    ]);
} catch (Throwable $e) {
    error_log('tracking202 report dispatch failed: ' . $e->getMessage());

    tracking202ReportDispatchRespondJson(500, [
        'ok' => false,
        'error' => [
            'message' => 'Unexpected server error while generating report data.',
            'status' => 500,
        ],
    ]);
}
