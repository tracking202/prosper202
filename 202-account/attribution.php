<?php

declare(strict_types=1);

include_once str_repeat('../', 1) . '202-config/connect.php';

AUTH::require_user();

global $userObj;

$hasPermission = isset($userObj) && $userObj->hasPermission('view_attribution_reports');

$apiBase = rtrim(get_absolute_url(), '/') . '/api/v2/attribution';

template_top('Attribution');
?>
<div class="row attribution-page" data-attribution-page data-api-base="<?php echo htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8'); ?>" data-has-permission="<?php echo $hasPermission ? '1' : '0'; ?>">
        <div class="col-xs-12">
                <div class="attribution-header clearfix">
                        <h6>Attribution</h6>
                        <span class="text-muted" data-role="last-refreshed"></span>
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
                                        <div class="col-sm-4">
                                                <label for="attribution-model" class="control-label">Model</label>
                                                <select id="attribution-model" class="form-control" data-role="model-select"<?php echo $hasPermission ? '' : ' disabled'; ?>>
                                                        <option value="">Loading models…</option>
                                                </select>
                                                <span class="help-block" data-role="model-helper">Loading models…</span>
                                        </div>
                                        <div class="col-sm-4">
                                                <label for="attribution-timeframe" class="control-label">Timeframe</label>
                                                <select id="attribution-timeframe" class="form-control" data-role="timeframe-select"<?php echo $hasPermission ? '' : ' disabled'; ?>>
                                                        <option value="24">Last 24 hours</option>
                                                        <option value="72">Last 3 days</option>
                                                        <option value="168" selected>Last 7 days</option>
                                                        <option value="720">Last 30 days</option>
                                                        <option value="custom">Custom range</option>
                                                </select>
                                        </div>
                                        <div class="col-sm-4">
                                                <label class="control-label">Custom range</label>
                                                <div class="attribution-date-range">
                                                        <input type="date" class="form-control" data-role="start-date" disabled>
                                                        <span class="attribution-date-separator">to</span>
                                                        <input type="date" class="form-control" data-role="end-date" disabled>
                                                </div>
                                                <span class="help-block">Select “Custom range” to enable manual dates.</span>
                                        </div>
                                </div>
                                <div class="row attribution-filters">
                                        <div class="col-sm-4">
                                                <label for="attribution-scope" class="control-label">Scope</label>
                                                <select id="attribution-scope" class="form-control" data-role="scope-select"<?php echo $hasPermission ? '' : ' disabled'; ?>>
                                                        <option value="global" data-requires-id="0" selected>Global</option>
                                                        <option value="campaign" data-requires-id="1">Campaign</option>
                                                        <option value="adgroup" data-requires-id="1">Ad Group</option>
                                                        <option value="landing_page" data-requires-id="1">Landing Page</option>
                                                        <option value="traffic_source" data-requires-id="1">Traffic Source</option>
                                                </select>
                                        </div>
                                        <div class="col-sm-4">
                                                <label for="attribution-scope-id" class="control-label">Scope identifier</label>
                                                <input type="number" id="attribution-scope-id" class="form-control" data-role="scope-id" placeholder="Optional" min="0" disabled>
                                                <span class="help-block">Required for non-global scopes.</span>
                                        </div>
                                        <div class="col-sm-4">
                                                <label class="control-label">&nbsp;</label>
                                                <button type="button" class="btn btn-primary btn-block" data-role="refresh-button"<?php echo $hasPermission ? '' : ' disabled'; ?>>Apply filters</button>
                                        </div>
                                </div>
                                <div class="attribution-loading" data-role="loading" style="display:none;">
                                        <span class="fui-time"></span> Loading attribution data…
                                </div>
                                <div class="row attribution-kpis" data-role="kpi-container">
                                        <div class="col-sm-6 col-md-4 col-lg-2">
                                                <div class="attribution-kpi-card" data-role="kpi" data-kpi="clicks">
                                                        <span class="label">Clicks</span>
                                                        <span class="metric">0</span>
                                                </div>
                                        </div>
                                        <div class="col-sm-6 col-md-4 col-lg-2">
                                                <div class="attribution-kpi-card" data-role="kpi" data-kpi="conversions">
                                                        <span class="label">Conversions</span>
                                                        <span class="metric">0</span>
                                                </div>
                                        </div>
                                        <div class="col-sm-6 col-md-4 col-lg-2">
                                                <div class="attribution-kpi-card" data-role="kpi" data-kpi="revenue">
                                                        <span class="label">Revenue</span>
                                                        <span class="metric">$0.00</span>
                                                </div>
                                        </div>
                                        <div class="col-sm-6 col-md-4 col-lg-2">
                                                <div class="attribution-kpi-card" data-role="kpi" data-kpi="cost">
                                                        <span class="label">Cost</span>
                                                        <span class="metric">$0.00</span>
                                                </div>
                                        </div>
                                        <div class="col-sm-6 col-md-4 col-lg-4">
                                                <div class="attribution-kpi-card" data-role="kpi" data-kpi="roi">
                                                        <span class="label">ROI</span>
                                                        <span class="metric">–</span>
                                                </div>
                                        </div>
                                </div>
                        </div>
                </div>
        </div>
        <div class="col-md-8">
                <div class="panel panel-default attribution-panel">
                        <div class="panel-heading">
                                <h5 class="panel-title">Touch-credit trends</h5>
                        </div>
                        <div class="panel-body">
                                <div id="touch-credit-chart" class="attribution-chart"></div>
                                <div class="attribution-empty" data-role="trend-empty" style="display:none;">No snapshots are available for the selected filters.</div>
                        </div>
                </div>
        </div>
        <div class="col-md-4">
                <div class="panel panel-default attribution-panel">
                        <div class="panel-heading">
                                <h5 class="panel-title">Model comparison</h5>
                        </div>
                        <div class="panel-body">
                                <div id="model-comparison-chart" class="attribution-chart"></div>
                                <div class="attribution-empty" data-role="comparison-empty" style="display:none;">Add active attribution models to compare performance.</div>
                        </div>
                </div>
        </div>
</div>
<?php template_bottom();
