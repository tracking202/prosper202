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
<div class="row attribution-page" data-attribution-page data-api-base="<?php echo htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8'); ?>" data-download-base="<?php echo htmlspecialchars($downloadBase, ENT_QUOTES, 'UTF-8'); ?>" data-has-permission="<?php echo $hasPermission ? '1' : '0'; ?>">
        <div class="col-xs-12">
                <div class="attribution-header clearfix">
                        <h6>Attribution Analytics</h6>
                        <span class="text-muted" data-role="last-refreshed"></span>
                        <button type="button" class="btn btn-default btn-xs" data-role="refresh-analytics">
                            <span class="fui-refresh"></span>
                        </button>
                </div>
                <div class="alert alert-warning attribution-alert" data-role="permission-alert"<?php echo $hasPermission ? ' style="display:none;"' : ''; ?>>
                        <span class="fui-alert"></span>
                        You do not have permission to view attribution reports. Please contact your administrator to request access.
                </div>
                <div class="alert alert-danger attribution-alert" data-role="error-alert" style="display:none;"></div>
        </div>
        <div class="col-xs-12">
                <div class="panel panel-default attribution-panel">
                        <div class="panel-body">
                                <div class="row attribution-filters">
                                        <div class="col-sm-3">
                                                <label for="attribution-model" class="control-label">Model</label>
                                                <select id="attribution-model" class="form-control" data-role="model-select"<?php echo $hasPermission ? '' : ' disabled'; ?>>
                                                        <option value="">Loading models…</option>
                                                </select>
                                                <span class="help-block" data-role="model-helper">Loading models…</span>
                                        </div>
                                        <div class="col-sm-2">
                                                <label for="attribution-scope" class="control-label">Scope</label>
                                                <select id="attribution-scope" class="form-control" data-role="scope-select"<?php echo $hasPermission ? '' : ' disabled'; ?>>
                                                        <option value="global" data-requires-id="0" selected>Global</option>
                                                        <option value="campaign" data-requires-id="1">Campaign</option>
                                                        <option value="adgroup" data-requires-id="1">Ad Group</option>
                                                        <option value="landing_page" data-requires-id="1">Landing Page</option>
                                                        <option value="traffic_source" data-requires-id="1">Traffic Source</option>
                                                </select>
                                        </div>
                                        <div class="col-sm-2">
                                                <label for="attribution-scope-id" class="control-label">Scope ID</label>
                                                <input type="number" id="attribution-scope-id" class="form-control" data-role="scope-id" placeholder="Optional" min="0" disabled>
                                        </div>
                                        <div class="col-sm-3">
                                                <label for="attribution-timeframe" class="control-label">Timeframe</label>
                                                <select id="attribution-timeframe" class="form-control" data-role="timeframe-select"<?php echo $hasPermission ? '' : ' disabled'; ?>>
                                                        <option value="24">Last 24 hours</option>
                                                        <option value="72">Last 3 days</option>
                                                        <option value="168" selected>Last 7 days</option>
                                                        <option value="720">Last 30 days</option>
                                                </select>
                                        </div>
                                        <div class="col-sm-2">
                                                <label for="attribution-interval" class="control-label">Resolution</label>
                                                <select id="attribution-interval" class="form-control" data-role="interval-select"<?php echo $hasPermission ? '' : ' disabled'; ?>>
                                                        <option value="hour">Hourly</option>
                                                        <option value="day">Daily</option>
                                                </select>
                                        </div>
                                </div>
                                <div class="attribution-loading" data-role="loading" style="display:none;">
                                        <span class="fui-time"></span> Loading attribution data…
                                </div>
                                <div class="row attribution-kpis" data-role="kpi-container">
                                        <div class="col-sm-2 col-xs-6">
                                                <div class="attribution-kpi-card" data-role="kpi" data-kpi="revenue">
                                                        <span class="label">Revenue</span>
                                                        <span class="metric">$0.00</span>
                                                </div>
                                        </div>
                                        <div class="col-sm-2 col-xs-6">
                                                <div class="attribution-kpi-card" data-role="kpi" data-kpi="conversions">
                                                        <span class="label">Conversions</span>
                                                        <span class="metric">0</span>
                                                </div>
                                        </div>
                                        <div class="col-sm-2 col-xs-6">
                                                <div class="attribution-kpi-card" data-role="kpi" data-kpi="clicks">
                                                        <span class="label">Clicks</span>
                                                        <span class="metric">0</span>
                                                </div>
                                        </div>
                                        <div class="col-sm-2 col-xs-6">
                                                <div class="attribution-kpi-card" data-role="kpi" data-kpi="cost">
                                                        <span class="label">Cost</span>
                                                        <span class="metric">$0.00</span>
                                                </div>
                                        </div>
                                        <div class="col-sm-2 col-xs-6">
                                                <div class="attribution-kpi-card" data-role="kpi" data-kpi="roi">
                                                        <span class="label">ROI %</span>
                                                        <span class="metric">–</span>
                                                </div>
                                        </div>
                                        <div class="col-sm-2 col-xs-6">
                                                <div class="attribution-kpi-card" data-role="kpi" data-kpi="profit">
                                                        <span class="label">Profit</span>
                                                        <span class="metric">$0.00</span>
                                                </div>
                                        </div>
                                </div>
                        </div>
                </div>
        </div>
        <div class="col-md-8">
                <div class="panel panel-default attribution-panel">
                        <div class="panel-heading">
                                <h5 class="panel-title">Performance trend</h5>
                        </div>
                        <div class="panel-body">
                                <div id="touch-credit-chart" class="attribution-chart" data-role="trend-chart"></div>
                                <div class="attribution-empty" data-role="trend-empty" style="display:none;">No snapshots are available for the selected filters.</div>
                        </div>
                </div>
        </div>
        <div class="col-md-4">
                <div class="panel panel-default attribution-panel">
                        <div class="panel-heading">
                                <h5 class="panel-title">Touchpoint mix</h5>
                        </div>
                        <div class="panel-body">
                                <ul class="touchpoint-mix" data-role="touchpoint-mix">
                                    <li class="text-muted">Waiting for analytics…</li>
                                </ul>
                        </div>
                </div>
                <div class="panel panel-default attribution-panel">
                        <div class="panel-heading">
                                <h5 class="panel-title">Anomaly alerts</h5>
                        </div>
                        <div class="panel-body" data-role="anomaly-banner">
                                <p class="text-muted">No anomalies detected.</p>
                        </div>
                </div>
        </div>
        <div class="col-md-7">
                <div class="panel panel-default attribution-panel">
                        <div class="panel-heading clearfix">
                                <h5 class="panel-title pull-left">Sandbox comparison</h5>
                                <button type="button" class="btn btn-default btn-xs pull-right" data-role="refresh-sandbox">
                                    <span class="fui-refresh"></span> Refresh
                                </button>
                        </div>
                        <div class="panel-body">
                                <p class="help-block">
                                    Compare up to three alternative models against the selected scope to preview credit shifts before promoting a default.
                                </p>
                                <div class="row">
                                    <div class="col-sm-12">
                                        <label class="control-label" for="sandbox-models">Models to compare</label>
                                        <select id="sandbox-models" class="form-control" data-role="sandbox-models" multiple></select>
                                        <span class="help-block">Hold <kbd>Ctrl</kbd> (or <kbd>Cmd</kbd>) to select multiple models.</span>
                                    </div>
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
                                <h5 class="panel-title">Promote a model</h5>
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
                                <h5 class="panel-title">Snapshot exports</h5>
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
                                                <td colspan="5">No exports scheduled yet.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                        </div>
                </div>
        </div>
</div>
<?php template_bottom();
