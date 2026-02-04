<?php

declare(strict_types=1);

include_once __DIR__ . '/../202-config/connect.php';

AUTH::require_user();

global $userObj;

if (!isset($userObj) || !$userObj->hasPermission('view_attribution_reports')) {
    template_top('Attribution Analytics');
    ?>
    <div class="row">
        <div class="col-xs-12">
            <div class="alert alert-warning attribution-alert">
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

$apiBase = rtrim(get_absolute_url(), '/') . '/api/v2/attribution';
$downloadBase = get_absolute_url() . '202-account/attribution-export.php';

$templateOptions = [
    'body_class' => 'attribution-dashboard',
];

template_top('Attribution Analytics', $templateOptions);
?>

<div class="row" data-attribution-app data-api-base="<?php echo htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8'); ?>" data-download-base="<?php echo htmlspecialchars($downloadBase, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="col-xs-12">
        <div class="panel panel-default attribution-panel">
            <div class="panel-heading clearfix">
                <h3 class="panel-title pull-left">Multi-touch Attribution Analytics</h3>
                <div class="pull-right">
                    <span class="text-muted" data-role="last-refreshed"></span>
                    <button type="button" class="btn btn-default btn-xs" data-role="refresh-analytics">
                        <span class="fui-refresh"></span>
                    </button>
                </div>
            </div>
            <div class="panel-body">
                <div class="alert alert-danger attribution-alert" data-role="error-banner" style="display:none;"></div>
                <div class="row attribution-controls">
                    <div class="col-sm-3">
                        <label class="control-label" for="attribution-model">Model</label>
                        <select id="attribution-model" class="form-control" data-role="model-select"></select>
                        <span class="help-block" data-role="model-helper">Loading models…</span>
                    </div>
                    <div class="col-sm-2">
                        <label class="control-label" for="attribution-scope">Scope</label>
                        <select id="attribution-scope" class="form-control" data-role="scope-select">
                            <option value="global">Global</option>
                            <option value="campaign">Campaign</option>
                            <option value="landing_page">Landing Page</option>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <label class="control-label" for="scope-id">Scope ID</label>
                        <input id="scope-id" class="form-control" type="number" data-role="scope-id" placeholder="All" disabled>
                    </div>
                    <div class="col-sm-3">
                        <label class="control-label" for="range-select">Date range</label>
                        <select id="range-select" class="form-control" data-role="range-select">
                            <option value="24">Last 24 hours</option>
                            <option value="168">Last 7 days</option>
                            <option value="720">Last 30 days</option>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <label class="control-label" for="attribution-interval">Resolution</label>
                        <select id="attribution-interval" class="form-control" data-role="interval-select">
                            <option value="hour">Hourly</option>
                            <option value="day">Daily</option>
                        </select>
                    </div>
                </div>

                <div class="row attribution-kpis" data-role="kpi-container">
                    <div class="col-sm-2 col-xs-6" data-role="kpi" data-kpi="revenue">
                        <div class="kpi-card">
                            <span class="label">Revenue</span>
                            <span class="metric">$0.00</span>
                        </div>
                    </div>
                    <div class="col-sm-2 col-xs-6" data-role="kpi" data-kpi="conversions">
                        <div class="kpi-card">
                            <span class="label">Conversions</span>
                            <span class="metric">0</span>
                        </div>
                    </div>
                    <div class="col-sm-2 col-xs-6" data-role="kpi" data-kpi="clicks">
                        <div class="kpi-card">
                            <span class="label">Clicks</span>
                            <span class="metric">0</span>
                        </div>
                    </div>
                    <div class="col-sm-2 col-xs-6" data-role="kpi" data-kpi="cost">
                        <div class="kpi-card">
                            <span class="label">Cost</span>
                            <span class="metric">$0.00</span>
                        </div>
                    </div>
                    <div class="col-sm-2 col-xs-6" data-role="kpi" data-kpi="roi">
                        <div class="kpi-card">
                            <span class="label">ROI %</span>
                            <span class="metric">–</span>
                        </div>
                    </div>
                    <div class="col-sm-2 col-xs-6" data-role="kpi" data-kpi="profit">
                        <div class="kpi-card">
                            <span class="label">Profit</span>
                            <span class="metric">$0.00</span>
                        </div>
                    </div>
                </div>

                <div class="row attribution-data">
                    <div class="col-md-8">
                        <div class="panel panel-default attribution-chart-panel">
                            <div class="panel-heading">
                                <h4 class="panel-title">Performance trend</h4>
                            </div>
                            <div class="panel-body">
                                <div data-role="trend-chart" class="attribution-chart"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="panel panel-default attribution-mix-panel">
                            <div class="panel-heading">
                                <h4 class="panel-title">Touchpoint mix</h4>
                            </div>
                            <div class="panel-body">
                                <ul class="touchpoint-mix" data-role="touchpoint-mix">
                                    <li class="text-muted">Waiting for analytics…</li>
                                </ul>
                            </div>
                        </div>
                        <div class="panel panel-default attribution-anomalies">
                            <div class="panel-heading">
                                <h4 class="panel-title">Anomaly alerts</h4>
                            </div>
                            <div class="panel-body" data-role="anomaly-banner"></div>
                        </div>
                    </div>
                </div>

                <div class="attribution-empty" data-role="empty-state" style="display:none;">
                    <span>No analytics returned for the selected filters. Adjust the scope or timeframe and try again.</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row attribution-sandbox-row" data-role="sandbox-section">
    <div class="col-md-7">
        <div class="panel panel-default attribution-sandbox">
            <div class="panel-heading clearfix">
                <h3 class="panel-title pull-left">Sandbox comparison</h3>
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
        <div class="panel panel-default attribution-default-model">
            <div class="panel-heading">
                <h3 class="panel-title">Promote a model</h3>
            </div>
            <div class="panel-body">
                <p class="help-block">Ready to lock in a model? Promote it to become the default for your account or selected scope.</p>
                <button type="button" class="btn btn-primary btn-block" data-role="promote-model" disabled>
                    Promote selected model
                </button>
                <div class="alert alert-info attribution-alert" data-role="promote-feedback" style="display:none;"></div>
            </div>
        </div>
        <div class="panel panel-default attribution-export" data-role="export-panel">
            <div class="panel-heading">
                <h3 class="panel-title">Snapshot exports</h3>
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
                        <label class="col-sm-4 control-label" for="webhook-method">Webhook method</label>
                        <div class="col-sm-8">
                            <select id="webhook-method" class="form-control" data-role="webhook-method">
                                <option value="POST">POST</option>
                                <option value="PUT">PUT</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-4 control-label" for="webhook-headers">Headers</label>
                        <div class="col-sm-8">
                            <textarea id="webhook-headers" class="form-control" rows="3" data-role="webhook-headers" placeholder="Header: Value"></textarea>
                            <span class="help-block">One header per line (e.g. <code>Authorization: Bearer token</code>).</span>
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
