<?php

declare(strict_types=1);

include_once str_repeat('../', 1) . '202-config/connect.php';

AUTH::require_user();

global $userObj;

if (!isset($userObj) || !$userObj->hasPermission('view_attribution_reports')) {
    template_top('Attribution Analytics');
    ?>
    <div class="row">
        <div class="col-xs-12">
            <div class="alert alert-warning" style="margin-top: 20px;">
                <span class="fui-alert"></span>
                You do not have access to attribution analytics. Please contact an administrator to request the
                <strong>view_attribution_reports</strong> permission.
            </div>
        </div>
    </div>
    <?php
    template_bottom();
    return;
}

$assetBase = get_absolute_url();
$extraHead = <<<HTML
    <script src="https://code.highcharts.com/highcharts.js"></script>
    <script src="{$assetBase}202-js/chart.theme.js"></script>
    <style>
        .attribution-dashboard .dashboard-panel { margin-top: 20px; }
        .attribution-dashboard .kpi-card { text-align: center; margin-bottom: 20px; }
        .attribution-dashboard .kpi-card .metric { font-size: 2rem; font-weight: 600; display: block; }
        .attribution-dashboard .kpi-card .label { text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.05em; }
        .attribution-dashboard .touchpoint-mix-list { list-style: none; padding-left: 0; margin-bottom: 0; }
        .attribution-dashboard .touchpoint-mix-list li { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f1f1; }
        .attribution-dashboard .touchpoint-mix-list li:last-child { border-bottom: none; }
        .attribution-dashboard .control-row { margin-bottom: 15px; }
        .attribution-dashboard .anomaly-banner .alert { margin-bottom: 10px; }
        .attribution-dashboard .empty-state { padding: 30px; text-align: center; color: #7f8c8d; }
    </style>
    <script src="{$assetBase}202-js/attribution-dashboard.js"></script>
HTML;

template_top('Attribution Analytics', [
    'body_class' => 'attribution-dashboard',
    'extra_head' => $extraHead,
]);

$apiBase = rtrim(get_absolute_url(), '/') . '/api/v2/attribution';
?>

<div class="row attribution-dashboard" data-attribution-dashboard data-api-base="<?php echo htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="col-xs-12">
        <div class="panel panel-default dashboard-panel">
            <div class="panel-heading clearfix">
                <h3 class="panel-title pull-left">Multi-touch Attribution Analytics</h3>
                <span class="pull-right text-muted" data-role="last-refreshed">&nbsp;</span>
            </div>
            <div class="panel-body">
                <div class="row control-row">
                    <div class="col-sm-4">
                        <label for="attribution-model" class="control-label">Attribution model</label>
                        <select id="attribution-model" class="form-control" data-role="model-select"></select>
                        <span class="help-block" data-role="model-helper">Loading models…</span>
                    </div>
                    <div class="col-sm-4">
                        <label for="attribution-scope" class="control-label">Scope</label>
                        <select id="attribution-scope" class="form-control" data-role="scope-select">
                            <option value="global">Global</option>
                            <option value="campaign">Campaign</option>
                            <option value="landing_page">Landing Page</option>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label for="scope-id" class="control-label">Scope identifier</label>
                        <input type="number" id="scope-id" class="form-control" data-role="scope-id" placeholder="Optional" disabled>
                        <span class="help-block">Leave blank for account-wide performance.</span>
                    </div>
                </div>

                <div class="row" data-role="kpi-container">
                    <div class="col-sm-3 col-xs-6 kpi-card" data-role="kpi" data-kpi="revenue">
                        <span class="label">Revenue</span>
                        <span class="metric">$0.00</span>
                    </div>
                    <div class="col-sm-3 col-xs-6 kpi-card" data-role="kpi" data-kpi="conversions">
                        <span class="label">Conversions</span>
                        <span class="metric">0</span>
                    </div>
                    <div class="col-sm-3 col-xs-6 kpi-card" data-role="kpi" data-kpi="clicks">
                        <span class="label">Clicks</span>
                        <span class="metric">0</span>
                    </div>
                    <div class="col-sm-3 col-xs-6 kpi-card" data-role="kpi" data-kpi="roi">
                        <span class="label">ROI %</span>
                        <span class="metric">–</span>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-8">
                        <div id="attribution-chart" style="height: 320px; margin-top: 10px;"></div>
                    </div>
                    <div class="col-md-4">
                        <h5>Touchpoint mix</h5>
                        <ul class="touchpoint-mix-list" data-role="touchpoint-mix">
                            <li class="text-muted">Waiting for analytics…</li>
                        </ul>
                    </div>
                </div>

                <div class="row" style="margin-top: 20px;">
                    <div class="col-xs-12">
                        <div class="anomaly-banner" data-role="anomaly-banner"></div>
                        <div class="alert alert-info" data-role="analytics-disabled" style="display: none;">
                            Attribution analytics are currently unavailable. Verify that multi-touch processing is enabled and
                            snapshots are being generated for the selected model.
                        </div>
                    </div>
                </div>

                <div class="row" style="margin-top: 10px;">
                    <div class="col-xs-12">
                        <div class="empty-state" data-role="empty-state" style="display: none;">
                            No analytics were returned for the selected filters. Try broadening your scope or picking a different
                            attribution model.
                        </div>
                    </div>
                </div>

                <div class="row" style="margin-top: 30px;">
                    <div class="col-md-6">
                        <h4>Schedule snapshot export</h4>
                        <p class="text-muted">Exports are processed asynchronously. You'll receive a downloadable file once the job completes.</p>
                        <form data-role="export-form">
                            <div class="form-group">
                                <label for="export-start" class="control-label">Start (UTC)</label>
                                <input type="datetime-local" id="export-start" class="form-control" data-role="export-start">
                                <span class="help-block">Defaults to 24 hours before the selected analytics window.</span>
                            </div>
                            <div class="form-group">
                                <label for="export-end" class="control-label">End (UTC)</label>
                                <input type="datetime-local" id="export-end" class="form-control" data-role="export-end">
                            </div>
                            <div class="form-group">
                                <label class="control-label">Webhook URL (optional)</label>
                                <input type="url" class="form-control" placeholder="https://example.com/callback" data-role="webhook-url">
                                <span class="help-block">Provide a webhook endpoint to receive the export payload as JSON.</span>
                            </div>
                            <div class="form-group">
                                <label class="control-label">Webhook secret</label>
                                <input type="text" class="form-control" placeholder="Optional signing secret" data-role="webhook-secret">
                            </div>
                            <div class="form-group">
                                <label class="control-label">Webhook headers</label>
                                <textarea class="form-control" rows="3" placeholder="X-Custom: Value" data-role="webhook-headers"></textarea>
                                <span class="help-block">One header per line in <code>Name: Value</code> format.</span>
                            </div>
                            <div class="form-group">
                                <button type="button" class="btn btn-primary" data-export-format="csv">Request CSV Export</button>
                                <button type="button" class="btn btn-default" data-export-format="xls">Request XLS Export</button>
                                <span class="help-block" data-role="export-feedback" style="display: none;"></span>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <h4>Recent export jobs</h4>
                        <table class="table table-striped table-condensed" data-role="export-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Status</th>
                                    <th>Queued</th>
                                    <th>Rows</th>
                                    <th>Completed</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="text-muted" data-role="export-empty-row">
                                    <td colspan="6">No exports have been scheduled yet.</td>
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
