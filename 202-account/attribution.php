<?php

declare(strict_types=1);

include_once __DIR__ . '/../202-config/connect.php';

AUTH::require_user();

global $userObj;

$hasPermission = isset($userObj) && $userObj->hasPermission('view_attribution_reports');

$apiBase = rtrim(get_absolute_url(), '/') . '/api/v2/attribution';
$downloadBase = get_absolute_url() . '202-account/attribution-export.php';

$templateOptions = [
    'body_class' => 'attribution-dashboard',
];

template_top('Attribution Analytics', $templateOptions);
?>
<style>
/* Attribution Page UX Overhaul */
.attribution-page {
    padding-bottom: 40px;
}

/* Header Styling */
.attribution-page .attribution-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e2e8f0;
}
.attribution-page .attribution-header h6 {
    font-size: 26px;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    letter-spacing: -0.5px;
}
.attribution-page .attribution-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}
.attribution-page .attribution-header-actions .text-muted {
    font-size: 13px;
    color: #94a3b8;
}
.attribution-page .attribution-header-actions .btn {
    border-radius: 8px;
    padding: 8px 12px;
    transition: all 0.2s ease;
}
.attribution-page .attribution-header-actions .btn:hover {
    background: #f1f5f9;
    transform: translateY(-1px);
}

/* Intro Banner */
.attribution-page .attribution-intro {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 24px;
    position: relative;
}
.attribution-page .attribution-intro strong {
    font-size: 16px;
    color: #1e40af;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}
.attribution-page .attribution-intro p {
    color: #3b82f6;
    margin: 0;
    font-size: 14px;
    line-height: 1.6;
}
.attribution-page .attribution-intro .close {
    position: absolute;
    top: 16px;
    right: 16px;
    opacity: 0.6;
    font-size: 20px;
}
.attribution-page .attribution-intro .close:hover {
    opacity: 1;
}

/* Panel Styling */
.attribution-page .attribution-panel {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 12px rgba(0,0,0,0.03);
    margin-bottom: 24px;
    overflow: hidden;
    transition: box-shadow 0.2s ease;
}
.attribution-page .attribution-panel:hover {
    box-shadow: 0 4px 6px rgba(0,0,0,0.04), 0 8px 20px rgba(0,0,0,0.06);
}
.attribution-page .attribution-panel .panel-heading {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    border: none;
    padding: 16px 20px;
}
.attribution-page .attribution-panel .panel-heading .panel-title {
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.attribution-page .attribution-panel .panel-heading .panel-title .help-icon {
    color: rgba(255,255,255,0.6);
    font-size: 14px;
}
.attribution-page .attribution-panel .panel-heading .btn {
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.2);
    color: #fff;
    border-radius: 6px;
    font-size: 12px;
    padding: 6px 12px;
}
.attribution-page .attribution-panel .panel-heading .btn:hover {
    background: rgba(255,255,255,0.25);
}
.attribution-page .attribution-panel .panel-body {
    padding: 24px;
    background: #fff;
}
.attribution-page .attribution-panel .panel-footer {
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    padding: 16px 20px;
}

/* Filter Section */
.attribution-page .attribution-filters {
    margin-bottom: 24px;
    padding-bottom: 24px;
    border-bottom: 1px solid #e2e8f0;
}
.attribution-page .attribution-filters .form-group {
    margin-bottom: 0;
}
.attribution-page .attribution-filters .control-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.attribution-page .attribution-filters .help-icon {
    color: #94a3b8;
    font-size: 12px;
    cursor: help;
}
.attribution-page .attribution-filters .form-control {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: #fff;
}
.attribution-page .attribution-filters .form-control:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}
.attribution-page .attribution-filters .help-block {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 6px;
}

/* KPI Cards */
.attribution-page .attribution-kpis {
    margin: 0 -8px;
}
.attribution-page .attribution-kpis > div {
    padding: 0 8px;
    margin-bottom: 16px;
}
.attribution-page .attribution-kpi-card {
    background: linear-gradient(145deg, #f8fafc, #f1f5f9);
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    transition: all 0.2s ease;
    cursor: default;
    height: 100%;
}
.attribution-page .attribution-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border-color: #cbd5e1;
}
.attribution-page .attribution-kpi-card .kpi-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 8px;
}
.attribution-page .attribution-kpi-card .metric {
    display: block;
    font-size: 24px;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.2;
}

/* Chart Area */
.attribution-page .attribution-chart {
    min-height: 320px;
    border-radius: 8px;
    background: #fafafa;
}

/* Touchpoint Mix */
.attribution-page .touchpoint-mix {
    margin: 0;
    padding: 0;
}
.attribution-page .touchpoint-mix li {
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.attribution-page .touchpoint-mix li:last-child {
    border-bottom: none;
}
.attribution-page .touchpoint-empty {
    color: #94a3b8;
    font-size: 14px;
    text-align: center;
    padding: 24px 16px;
}

/* Empty States */
.attribution-page .attribution-empty {
    text-align: center;
    padding: 32px 24px;
    color: #64748b;
    font-size: 14px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px dashed #e2e8f0;
}
.attribution-page .attribution-empty .fui-info-circle {
    margin-right: 8px;
}

/* Loading State */
.attribution-page .attribution-loading {
    text-align: center;
    padding: 24px;
    color: #64748b;
    font-size: 14px;
}
.attribution-page .attribution-loading .fui-time {
    margin-right: 8px;
    animation: pulse 1.5s ease-in-out infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Alerts */
.attribution-page .attribution-alert {
    border-radius: 10px;
    padding: 14px 18px;
    font-size: 14px;
    margin-bottom: 20px;
}

/* Tables */
.attribution-page .sandbox-table {
    margin: 0;
}
.attribution-page .sandbox-table th {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    border-bottom: 2px solid #e2e8f0;
    padding: 12px 16px;
}
.attribution-page .sandbox-table td {
    padding: 14px 16px;
    font-size: 14px;
    vertical-align: middle;
}

/* Buttons */
.attribution-page .btn-primary {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
    border-radius: 8px;
    padding: 12px 24px;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(16,185,129,0.2);
}
.attribution-page .btn-primary:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 4px 12px rgba(16,185,129,0.35);
    transform: translateY(-1px);
}
.attribution-page .btn-primary:disabled {
    background: #94a3b8;
    box-shadow: none;
    transform: none;
}
.attribution-page .btn-success {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-weight: 600;
    font-size: 14px;
    box-shadow: 0 2px 4px rgba(59,130,246,0.2);
}
.attribution-page .btn-success:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    box-shadow: 0 4px 12px rgba(59,130,246,0.35);
}

/* Form Horizontal */
.attribution-page .form-horizontal .control-label {
    font-size: 13px;
    font-weight: 500;
    color: #475569;
    padding-top: 10px;
}
.attribution-page .form-horizontal .form-control {
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    padding: 10px 14px;
}
.attribution-page .form-horizontal .form-control:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}

/* Export Table */
.attribution-page [data-role="export-table"] {
    margin: 0;
    font-size: 13px;
}
.attribution-page [data-role="export-table"] th {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    padding: 10px 12px;
}
.attribution-page [data-role="export-table"] td {
    padding: 10px 12px;
    vertical-align: middle;
}

/* Help Blocks */
.attribution-page .help-block {
    font-size: 13px;
    color: #64748b;
    margin-top: 8px;
    line-height: 1.5;
}
.attribution-page .help-block kbd {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 11px;
    color: #475569;
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .attribution-page .attribution-filters > div {
        margin-bottom: 16px;
    }
    .attribution-page .attribution-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
}
</style>
<div class="attribution-page" data-attribution-page data-api-base="<?php echo htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8'); ?>" data-download-base="<?php echo htmlspecialchars($downloadBase, ENT_QUOTES, 'UTF-8'); ?>" data-has-permission="<?php echo $hasPermission ? '1' : '0'; ?>">
        <!-- Introduction Banner (Dismissible) -->
        <div class="attribution-intro alert alert-info" data-role="intro-banner" style="display:none;">
                <button type="button" class="close" data-role="dismiss-intro" aria-label="Close">&times;</button>
                <strong><span class="fui-info-circle"></span> Welcome to Attribution Analytics</strong>
                <p>Understand how different marketing touchpoints contribute to your conversions.
                   Select an attribution model, choose your scope and timeframe, then explore
                   performance trends and compare models in the sandbox.</p>
        </div>

        <!-- Header Row -->
        <div class="row">
                <div class="col-xs-12">
                        <div class="attribution-header">
                                <h6>Attribution Analytics</h6>
                                <div class="attribution-header-actions">
                                        <span class="text-muted" data-role="last-refreshed"></span>
                                        <button type="button" class="btn btn-default btn-xs" data-role="refresh-analytics">
                                            <span class="fui-refresh"></span>
                                        </button>
                                </div>
                        </div>
                        <div class="alert alert-warning attribution-alert" data-role="permission-alert"<?php echo $hasPermission ? ' style="display:none;"' : ''; ?>>
                                <span class="fui-alert"></span>
                                You do not have permission to view attribution reports. Please contact your administrator to request access.
                        </div>
                        <div class="alert alert-danger attribution-alert" data-role="error-alert" style="display:none;"></div>
                </div>
        </div>

        <!-- Filters Panel -->
        <div class="row">
                <div class="col-xs-12">
                        <div class="panel panel-default attribution-panel">
                                <div class="panel-body">
                                        <div class="row attribution-filters">
                                                <div class="col-md-3 col-sm-6">
                                                        <div class="form-group">
                                                                <label for="attribution-model" class="control-label">Model <i class="fui-question-circle help-icon" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="Attribution models determine how credit for conversions is assigned across touchpoints. Last Touch gives 100% credit to the final interaction."></i></label>
                                                                <select id="attribution-model" class="form-control" data-role="model-select"<?php echo $hasPermission ? '' : ' disabled'; ?>>
                                                                        <option value="">Loading models…</option>
                                                                </select>
                                                                <span class="help-block" data-role="model-helper">Loading models…</span>
                                                        </div>
                                                </div>
                                                <div class="col-md-2 col-sm-6">
                                                        <div class="form-group">
                                                                <label for="attribution-scope" class="control-label">Scope <i class="fui-question-circle help-icon" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="Choose what level to analyze: Global (all data), Campaign, Ad Group, Landing Page, or Traffic Source."></i></label>
                                                                <select id="attribution-scope" class="form-control" data-role="scope-select"<?php echo $hasPermission ? '' : ' disabled'; ?>>
                                                                        <option value="global" data-requires-id="0" selected>Global</option>
                                                                        <option value="campaign" data-requires-id="1">Campaign</option>
                                                                        <option value="adgroup" data-requires-id="1">Ad Group</option>
                                                                        <option value="landing_page" data-requires-id="1">Landing Page</option>
                                                                        <option value="traffic_source" data-requires-id="1">Traffic Source</option>
                                                                </select>
                                                        </div>
                                                </div>
                                                <div class="col-md-2 col-sm-6">
                                                        <div class="form-group">
                                                                <label for="attribution-scope-id" class="control-label">Scope ID <i class="fui-question-circle help-icon" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="Enter the specific ID when filtering by Campaign, Ad Group, etc. Leave empty for Global scope."></i></label>
                                                                <input type="number" id="attribution-scope-id" class="form-control" data-role="scope-id" placeholder="Optional" min="0" disabled>
                                                        </div>
                                                </div>
                                                <div class="col-md-3 col-sm-6">
                                                        <div class="form-group">
                                                                <label for="attribution-timeframe" class="control-label">Timeframe <i class="fui-question-circle help-icon" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="Select the date range for your attribution analysis."></i></label>
                                                                <select id="attribution-timeframe" class="form-control" data-role="timeframe-select"<?php echo $hasPermission ? '' : ' disabled'; ?>>
                                                                        <option value="24">Last 24 hours</option>
                                                                        <option value="72">Last 3 days</option>
                                                                        <option value="168" selected>Last 7 days</option>
                                                                        <option value="720">Last 30 days</option>
                                                                </select>
                                                        </div>
                                                </div>
                                                <div class="col-md-2 col-sm-6">
                                                        <div class="form-group">
                                                                <label for="attribution-interval" class="control-label">Resolution <i class="fui-question-circle help-icon" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="Choose data granularity: Hourly for detailed trends, Daily for overview."></i></label>
                                                                <select id="attribution-interval" class="form-control" data-role="interval-select"<?php echo $hasPermission ? '' : ' disabled'; ?>>
                                                                        <option value="hour">Hourly</option>
                                                                        <option value="day">Daily</option>
                                                                </select>
                                                        </div>
                                                </div>
                                        </div>
                                        <div class="attribution-loading" data-role="loading" style="display:none;">
                                                <span class="fui-time"></span> Loading attribution data…
                                        </div>
                                        <!-- KPI Cards -->
                                        <div class="row attribution-kpis" data-role="kpi-container">
                                                <div class="col-lg-2 col-md-4 col-sm-4 col-xs-6">
                                                        <div class="attribution-kpi-card" data-role="kpi" data-kpi="revenue" data-toggle="tooltip" data-placement="top" title="Total attributed revenue from conversions in the selected timeframe">
                                                                <span class="kpi-label">Revenue</span>
                                                                <span class="metric">$0.00</span>
                                                        </div>
                                                </div>
                                                <div class="col-lg-2 col-md-4 col-sm-4 col-xs-6">
                                                        <div class="attribution-kpi-card" data-role="kpi" data-kpi="conversions" data-toggle="tooltip" data-placement="top" title="Number of conversions attributed to your campaigns">
                                                                <span class="kpi-label">Conversions</span>
                                                                <span class="metric">0</span>
                                                        </div>
                                                </div>
                                                <div class="col-lg-2 col-md-4 col-sm-4 col-xs-6">
                                                        <div class="attribution-kpi-card" data-role="kpi" data-kpi="clicks" data-toggle="tooltip" data-placement="top" title="Total clicks tracked across all touchpoints">
                                                                <span class="kpi-label">Clicks</span>
                                                                <span class="metric">0</span>
                                                        </div>
                                                </div>
                                                <div class="col-lg-2 col-md-4 col-sm-4 col-xs-6">
                                                        <div class="attribution-kpi-card" data-role="kpi" data-kpi="cost" data-toggle="tooltip" data-placement="top" title="Total advertising spend for the selected scope">
                                                                <span class="kpi-label">Cost</span>
                                                                <span class="metric">$0.00</span>
                                                        </div>
                                                </div>
                                                <div class="col-lg-2 col-md-4 col-sm-4 col-xs-6">
                                                        <div class="attribution-kpi-card" data-role="kpi" data-kpi="roi" data-toggle="tooltip" data-placement="top" title="Return on Investment: (Revenue - Cost) / Cost × 100">
                                                                <span class="kpi-label">ROI %</span>
                                                                <span class="metric">–</span>
                                                        </div>
                                                </div>
                                                <div class="col-lg-2 col-md-4 col-sm-4 col-xs-6">
                                                        <div class="attribution-kpi-card" data-role="kpi" data-kpi="profit" data-toggle="tooltip" data-placement="top" title="Net profit: Revenue minus Cost">
                                                                <span class="kpi-label">Profit</span>
                                                                <span class="metric">$0.00</span>
                                                        </div>
                                                </div>
                                        </div>
                                </div>
                        </div>
                </div>
        </div>

        <!-- Chart Row: Performance Trend + Touchpoint/Anomaly -->
        <div class="row">
                <div class="col-md-8">
                        <div class="panel panel-default attribution-panel">
                                <div class="panel-heading">
                                        <h5 class="panel-title">Performance trend <i class="fui-question-circle help-icon" data-toggle="popover" data-trigger="hover" data-placement="right" data-content="Visualize revenue, cost, conversions, and profit over time. Zoom in by clicking and dragging on the chart."></i></h5>
                                </div>
                                <div class="panel-body">
                                        <div id="touch-credit-chart" class="attribution-chart" data-role="trend-chart"></div>
                                        <div class="attribution-empty" data-role="trend-empty" style="display:none;"><span class="fui-info-circle"></span> No data yet for these filters. Try expanding the timeframe or check that tracking is active.</div>
                                </div>
                        </div>
                </div>
                <div class="col-md-4">
                        <div class="panel panel-default attribution-panel">
                                <div class="panel-heading">
                                        <h5 class="panel-title">Touchpoint mix <i class="fui-question-circle help-icon" data-toggle="popover" data-trigger="hover" data-placement="left" data-content="See which touchpoints contribute most to conversions and their relative share."></i></h5>
                                </div>
                                <div class="panel-body">
                                        <ul class="touchpoint-mix list-unstyled" data-role="touchpoint-mix">
                                            <li class="text-muted touchpoint-empty">Touchpoint data appears after conversions are recorded. Make sure your tracking pixels are firing.</li>
                                        </ul>
                                </div>
                        </div>
                        <div class="panel panel-default attribution-panel">
                                <div class="panel-heading">
                                        <h5 class="panel-title">Anomaly alerts <i class="fui-question-circle help-icon" data-toggle="popover" data-trigger="hover" data-placement="left" data-content="Automatic detection of unusual patterns that may need attention."></i></h5>
                                </div>
                                <div class="panel-body" data-role="anomaly-banner">
                                        <p class="text-muted"><span class="fui-checkbox-checked"></span> All metrics are within normal ranges. We'll alert you if unusual patterns emerge.</p>
                                </div>
                        </div>
                </div>
        </div>

        <!-- Bottom Row: Sandbox + Promote/Export -->
        <div class="row">
                <div class="col-md-7">
                        <div class="panel panel-default attribution-panel">
                                <div class="panel-heading clearfix">
                                        <h5 class="panel-title pull-left">Sandbox comparison <i class="fui-question-circle help-icon" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="Test different attribution models side-by-side before making one the default."></i></h5>
                                        <button type="button" class="btn btn-default btn-xs pull-right" data-role="refresh-sandbox">
                                            <span class="fui-refresh"></span> Refresh
                                        </button>
                                </div>
                                <div class="panel-body">
                                        <p class="help-block">
                                            Compare up to three alternative models against the selected scope to preview credit shifts before promoting a default.
                                        </p>
                                        <div class="form-group">
                                                <label class="control-label" for="sandbox-models">Models to compare</label>
                                                <select id="sandbox-models" class="form-control" data-role="sandbox-models" multiple></select>
                                                <span class="help-block">Hold <kbd>Ctrl</kbd> (or <kbd>Cmd</kbd>) to select multiple models.</span>
                                        </div>
                                        <div class="sandbox-summary" data-role="sandbox-summary">
                                            <p class="text-muted">Select models above to run a comparison.</p>
                                        </div>
                                        <div class="table-responsive" style="display:none;" data-role="sandbox-table-wrapper">
                                            <table class="table table-striped sandbox-table">
                                                <thead>
                                                    <tr>
                                                        <th>Model</th>
                                                        <th>Revenue</th>
                                                        <th>Conversions</th>
                                                        <th>Cost</th>
                                                        <th>ROI %</th>
                                                    </tr>
                                                </thead>
                                                <tbody data-role="sandbox-table"></tbody>
                                            </table>
                                        </div>
                                </div>
                        </div>
                </div>
                <div class="col-md-5">
                        <div class="panel panel-default attribution-panel">
                                <div class="panel-heading">
                                        <h5 class="panel-title">Promote a model <i class="fui-question-circle help-icon" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="Set your preferred model as the default for reporting across the platform."></i></h5>
                                </div>
                                <div class="panel-body">
                                        <p class="help-block">Ready to lock in a model? Promote it to become the default for your account or selected scope.</p>
                                        <button type="button" class="btn btn-primary btn-block" data-role="promote-model" disabled>
                                            Promote selected model
                                        </button>
                                        <div class="alert alert-info attribution-alert" data-role="promote-feedback" style="display:none;"></div>
                                </div>
                        </div>
                        <div class="panel panel-default attribution-panel" data-role="export-panel">
                                <div class="panel-heading">
                                        <h5 class="panel-title">Snapshot exports <i class="fui-question-circle help-icon" data-toggle="popover" data-trigger="hover" data-placement="top" data-content="Download attribution data for external analysis or automated reporting via webhook."></i></h5>
                                </div>
                                <div class="panel-body">
                                        <form class="form-horizontal" data-role="export-form">
                                            <div class="form-group">
                                                <label class="col-sm-4 control-label" for="export-format">Format</label>
                                                <div class="col-sm-8">
                                                    <select id="export-format" class="form-control" data-role="export-format">
                                                        <option value="csv">CSV</option>
                                                        <option value="xls">Excel (.xls)</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label class="col-sm-4 control-label" for="export-range">Range</label>
                                                <div class="col-sm-8">
                                                    <select id="export-range" class="form-control" data-role="export-range">
                                                        <option value="24">Last 24 hours</option>
                                                        <option value="168">Last 7 days</option>
                                                        <option value="720">Last 30 days</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label class="col-sm-4 control-label" for="webhook-url">Webhook URL</label>
                                                <div class="col-sm-8">
                                                    <input id="webhook-url" class="form-control" type="url" data-role="webhook-url" placeholder="Optional">
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <div class="col-sm-offset-4 col-sm-8">
                                                    <button type="submit" class="btn btn-success" data-role="submit-export">Schedule export</button>
                                                </div>
                                            </div>
                                            <div class="alert attribution-alert" data-role="export-feedback" style="display:none;"></div>
                                        </form>
                                </div>
                                <div class="panel-footer">
                                        <div class="table-responsive">
                                            <table class="table table-condensed table-striped" data-role="export-table">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Requested</th>
                                                        <th>Status</th>
                                                        <th>Format</th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody data-role="export-rows">
                                                    <tr class="text-muted" data-role="export-empty">
                                                        <td colspan="5"><span class="fui-info-circle"></span> No exports scheduled yet. Use the form above to download attribution data.</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                </div>
                        </div>
                </div>
        </div>
</div>
<?php template_bottom();
