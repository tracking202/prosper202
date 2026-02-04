(function (window, document, Highcharts) {
    'use strict';

    var TIMEFRAME_PRESETS = {
        '24': 24,
        '72': 72,
        '168': 168,
        '720': 720
    };
    var MAX_COMPARISON_MODELS = 5;

    function formatCurrency(value) {
        var amount = Number(value) || 0;
        if (window.Intl && typeof window.Intl.NumberFormat === 'function') {
            return new window.Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: 'USD',
                maximumFractionDigits: 2
            }).format(amount);
        }

        return '$' + amount.toFixed(2);
    }

    function formatNumber(value) {
        var number = Number(value) || 0;
        if (window.Intl && typeof window.Intl.NumberFormat === 'function') {
            return new window.Intl.NumberFormat(undefined, {
                maximumFractionDigits: 0
            }).format(number);
        }

        return String(Math.round(number));
    }

    function formatDecimal(value, digits) {
        if (window.Intl && typeof window.Intl.NumberFormat === 'function') {
            return new window.Intl.NumberFormat(undefined, {
                minimumFractionDigits: digits,
                maximumFractionDigits: digits,
            }).format(value || 0);
        }
        return (value || 0).toFixed(digits);
    }

    function formatPercent(value) {
        if (value === null || typeof value === 'undefined' || Number.isNaN(value)) {
            return '–';
        }

        return value.toFixed(1) + '%';
    }

    function formatRoi(value) {
        if (value === null || value === undefined || Number.isNaN(value)) {
            return '–';
        }

        return value.toFixed(2) + '%';
    }

    function nowSeconds() {
        return Math.floor(Date.now() / 1000);
    }

    function hoursAgo(hours) {
        return nowSeconds() - (hours * 3600);
    }

    function getUnixHourRange(hours) {
        var end = Math.floor(Date.now() / 1000);
        var start = end - (hours * 3600);
        return { start: start, end: end };
    }

    function parseInteger(value) {
        var parsed = parseInt(value, 10);
        return Number.isNaN(parsed) ? null : parsed;
    }

    function parseHeaders(text) {
        if (!text) {
            return {};
        }

        var headers = {};
        text.split(/\r?\n/).forEach(function (line) {
            var trimmed = line.trim();
            if (!trimmed) {
                return;
            }

            var idx = trimmed.indexOf(':');
            if (idx === -1) {
                return;
            }

            var key = trimmed.slice(0, idx).trim();
            var value = trimmed.slice(idx + 1).trim();
            if (key) {
                headers[key] = value;
            }
        });

        return headers;
    }

    function getFocusableElements(container) {
        if (!container) {
            return [];
        }

        return Array.prototype.slice.call(
            container.querySelectorAll(
                'a[href], area[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), iframe, object, embed, [tabindex]:not([tabindex="-1"]), [contenteditable="true"]'
            )
        );
    }

    function toUnixTimestamp(dateString, endOfDay) {
        if (!dateString) {
            return null;
        }

        var date = new Date(dateString + 'T00:00:00');
        if (Number.isNaN(date.getTime())) {
            return null;
        }

        if (endOfDay) {
            date.setHours(23, 59, 59, 999);
        }

        return Math.floor(date.getTime() / 1000);
    }

    function computeTotals(snapshots) {
        var totals = {
            clicks: 0,
            conversions: 0,
            revenue: 0,
            cost: 0,
            roi: null
        };

        (snapshots || []).forEach(function (snapshot) {
            totals.clicks += Number(snapshot.attributed_clicks) || 0;
            totals.conversions += Number(snapshot.attributed_conversions) || 0;
            totals.revenue += Number(snapshot.attributed_revenue) || 0;
            totals.cost += Number(snapshot.attributed_cost) || 0;
        });

        if (totals.cost > 0) {
            totals.roi = ((totals.revenue - totals.cost) / totals.cost) * 100;
        }

        return totals;
    }

    function groupSnapshots(snapshots, interval) {
        if (interval === 'hour') {
            return snapshots;
        }

        var grouped = {};
        (snapshots || []).forEach(function (snapshot) {
            var date = new Date(snapshot.date_hour * 1000);
            date.setHours(0, 0, 0, 0);
            var bucket = Math.floor(date.getTime() / 1000);
            if (!grouped[bucket]) {
                grouped[bucket] = {
                    date_hour: bucket,
                    attributed_revenue: 0,
                    attributed_conversions: 0,
                    attributed_clicks: 0,
                    attributed_cost: 0,
                };
            }

            grouped[bucket].attributed_revenue += snapshot.attributed_revenue || 0;
            grouped[bucket].attributed_conversions += snapshot.attributed_conversions || 0;
            grouped[bucket].attributed_clicks += snapshot.attributed_clicks || 0;
            grouped[bucket].attributed_cost += snapshot.attributed_cost || 0;
        });

        return Object.keys(grouped)
            .map(function (key) { return Number(key); })
            .sort(function (a, b) { return a - b; })
            .map(function (key) { return grouped[key]; });
    }

    function buildChartSeries(snapshots, interval) {
        var resolved = groupSnapshots(snapshots, interval);
        var revenue = [];
        var conversions = [];
        var profit = [];

        resolved.forEach(function (snapshot) {
            var timestamp = (snapshot.date_hour || 0) * 1000;

            var revenueValue = snapshot.attributed_revenue || 0;
            var costValue = snapshot.attributed_cost || 0;
            var profitValue = revenueValue - costValue;

            revenue.push([timestamp, revenueValue]);
            conversions.push([timestamp, snapshot.attributed_conversions || 0]);
            profit.push([timestamp, profitValue]);
        });

        return {
            revenue: revenue,
            conversions: conversions,
            profit: profit,
        };
    }

    function createCacheKey(modelId, range, scope, scopeId) {
        return [
            modelId,
            scope,
            scopeId === null || scopeId === undefined || scopeId === '' ? 'global' : scopeId,
            range.start,
            range.end
        ].join(':');
    }

    function showAlert(element, message) {
        if (!element) {
            return;
        }

        element.textContent = message;
        element.style.display = 'block';
    }

    function hideAlert(element) {
        if (!element) {
            return;
        }

        element.style.display = 'none';
    }

    function fetchJson(url, options) {
        var opts = options || {};
        opts.headers = Object.assign({ 'Accept': 'application/json' }, opts.headers || {});
        opts.credentials = 'same-origin';
        return fetch(url, opts).then(function (response) {
            if (!response.ok) {
                var error = new Error('Request failed with status ' + response.status);
                error.status = response.status;
                throw error;
            }

            if (response.status === 204) {
                return {};
            }

            return response.json();
        });
    }

    function sendJson(url, method, payload) {
        return fetchJson(url, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {}),
        });
    }

    function safeArray(value) {
        return Array.isArray(value) ? value : [];
    }

    // Main dashboard initialization
    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-attribution-app]') || document.querySelector('[data-attribution-page]');
        if (!root) {
            return;
        }

        var apiBase = root.getAttribute('data-api-base');
        var downloadBase = root.getAttribute('data-download-base');
        var hasPermission = root.getAttribute('data-has-permission') !== '0';
        if (!apiBase) {
            return;
        }

        var modelSelect = root.querySelector('[data-role="model-select"]');
        var modelHelper = root.querySelector('[data-role="model-helper"]');
        var scopeSelect = root.querySelector('[data-role="scope-select"]');
        var scopeInput = root.querySelector('[data-role="scope-id"]');
        var rangeSelect = root.querySelector('[data-role="range-select"]');
        var timeframeSelect = root.querySelector('[data-role="timeframe-select"]');
        var intervalSelect = root.querySelector('[data-role="interval-select"]');
        var startDateInput = root.querySelector('[data-role="start-date"]');
        var endDateInput = root.querySelector('[data-role="end-date"]');
        var kpiCards = root.querySelectorAll('[data-role="kpi"]');
        var mixList = root.querySelector('[data-role="touchpoint-mix"]');
        var anomalyBanner = root.querySelector('[data-role="anomaly-banner"]');
        var emptyState = root.querySelector('[data-role="empty-state"]');
        var trendEmpty = root.querySelector('[data-role="trend-empty"]');
        var comparisonEmpty = root.querySelector('[data-role="comparison-empty"]');
        var errorBanner = root.querySelector('[data-role="error-banner"]');
        var errorAlert = root.querySelector('[data-role="error-alert"]');
        var permissionAlert = root.querySelector('[data-role="permission-alert"]');
        var lastRefreshed = root.querySelector('[data-role="last-refreshed"]');
        var refreshButton = root.querySelector('[data-role="refresh-button"]') || root.querySelector('[data-role="refresh-analytics"]');
        var loadingIndicator = root.querySelector('[data-role="loading"]');
        var sandboxSelect = document.querySelector('[data-role="sandbox-models"]');
        var sandboxSummary = document.querySelector('[data-role="sandbox-summary"]');
        var sandboxTableWrapper = document.querySelector('[data-role="sandbox-table-wrapper"]');
        var sandboxTable = document.querySelector('[data-role="sandbox-table"]');
        var refreshSandboxButton = document.querySelector('[data-role="refresh-sandbox"]');
        var promoteButton = document.querySelector('[data-role="promote-model"]');
        var promoteFeedback = document.querySelector('[data-role="promote-feedback"]');
        var exportForm = document.querySelector('[data-role="export-form"]');
        var exportFeedback = document.querySelector('[data-role="export-feedback"]');
        var exportRows = document.querySelector('[data-role="export-rows"]');
        var exportEmpty = document.querySelector('[data-role="export-empty"]');
        var exportPanel = document.querySelector('[data-role="export-panel"]');

        var state = {
            modelId: null,
            selectedModelId: null,
            models: [],
            scope: 'global',
            scopeId: null,
            rangeHours: 24,
            interval: 'hour',
            isLoading: false,
            startHour: null,
            endHour: null,
            trendChart: null,
            comparisonChart: null,
            snapshotCache: {},
            currentRange: null
        };

        function setLoading(flag) {
            state.isLoading = flag;
            if (flag) {
                root.classList.add('is-loading');
                if (loadingIndicator) {
                    loadingIndicator.style.display = 'block';
                }
                if (refreshButton) {
                    refreshButton.setAttribute('disabled', 'disabled');
                }
            } else {
                root.classList.remove('is-loading');
                if (loadingIndicator) {
                    loadingIndicator.style.display = 'none';
                }
                if (refreshButton && hasPermission && state.models.length > 0) {
                    refreshButton.removeAttribute('disabled');
                }
            }
        }

        function showError(message) {
            if (errorBanner) {
                errorBanner.textContent = message;
                errorBanner.style.display = 'block';
            }
            if (errorAlert) {
                showAlert(errorAlert, message);
            }
        }

        function clearError() {
            if (errorBanner) {
                errorBanner.style.display = 'none';
            }
            hideAlert(errorAlert);
        }

        function toggleEmptyState(hasData) {
            if (emptyState) {
                emptyState.style.display = hasData ? 'none' : 'block';
            }
            if (trendEmpty) {
                trendEmpty.style.display = hasData ? 'none' : 'block';
            }
        }

        function updateKpis(totals) {
            kpiCards.forEach(function (card) {
                var key = card.getAttribute('data-kpi');
                var metric = card.querySelector('.metric');
                if (!metric) {
                    return;
                }

                switch (key) {
                    case 'revenue':
                        metric.textContent = formatCurrency(totals.revenue || 0);
                        break;
                    case 'conversions':
                        metric.textContent = formatNumber(totals.conversions || 0);
                        break;
                    case 'clicks':
                        metric.textContent = formatNumber(totals.clicks || 0);
                        break;
                    case 'cost':
                        metric.textContent = formatCurrency(totals.cost || 0);
                        break;
                    case 'roi':
                        metric.textContent = formatPercent(typeof totals.roi === 'number' ? totals.roi : null);
                        break;
                    case 'profit':
                        metric.textContent = formatCurrency((totals.revenue || 0) - (totals.cost || 0));
                        break;
                    default:
                        metric.textContent = '–';
                }
            });
        }

        function renderMix(items) {
            if (!mixList) {
                return;
            }

            mixList.innerHTML = '';

            if (!items || items.length === 0) {
                var empty = document.createElement('li');
                empty.className = 'text-muted';
                empty.textContent = 'No touchpoint data available yet.';
                mixList.appendChild(empty);
                return;
            }

            items.forEach(function (item) {
                var row = document.createElement('li');
                var label = document.createElement('span');
                label.textContent = item.label;
                var value = document.createElement('span');
                value.textContent = formatDecimal(item.share * 100, 1) + '% · ' + formatNumber(item.touch_count || 0) + ' touches';
                row.appendChild(label);
                row.appendChild(value);
                mixList.appendChild(row);
            });
        }

        function renderAnomalies(items) {
            if (!anomalyBanner) {
                return;
            }

            anomalyBanner.innerHTML = '';
            if (!items || items.length === 0) {
                var safe = document.createElement('p');
                safe.className = 'text-muted';
                safe.textContent = 'No anomalies detected for the selected range.';
                anomalyBanner.appendChild(safe);
                return;
            }

            items.forEach(function (item) {
                var div = document.createElement('div');
                div.className = 'alert ' + (item.severity === 'critical' ? 'alert-danger' : 'alert-warning');
                div.textContent = (item.direction === 'down' ? '▼ ' : '▲ ') + item.metric + ': ' + item.message;
                anomalyBanner.appendChild(div);
            });
        }

        var chart = null;

        function ensureChart() {
            if (!Highcharts || typeof Highcharts.chart !== 'function') {
                return null;
            }

            var chartContainer = root.querySelector('[data-role="trend-chart"]') || document.getElementById('touch-credit-chart');
            if (!chartContainer) {
                return null;
            }

            if (!chart) {
                chart = Highcharts.chart({
                    chart: {
                        renderTo: chartContainer,
                        zoomType: 'x',
                    },
                    title: { text: null },
                    xAxis: { type: 'datetime' },
                    yAxis: [{
                        title: { text: 'Revenue / Cost' },
                        labels: {
                            formatter: function () {
                                return '$' + formatDecimal(this.value, 0);
                            }
                        }
                    }, {
                        title: { text: 'Conversions' },
                        opposite: true,
                    }],
                    tooltip: {
                        shared: true,
                        xDateFormat: '%b %e, %Y %H:%M'
                    },
                    legend: { enabled: true },
                    series: [{
                        name: 'Revenue',
                        type: 'spline',
                        data: [],
                        tooltip: { valuePrefix: '$' }
                    }, {
                        name: 'Cost',
                        type: 'spline',
                        data: [],
                        tooltip: { valuePrefix: '$' }
                    }, {
                        name: 'Conversions',
                        type: 'spline',
                        yAxis: 1,
                        data: [],
                    }, {
                        name: 'Profit',
                        type: 'spline',
                        data: [],
                        tooltip: { valuePrefix: '$' }
                    }]
                });
            }

            return chart;
        }

        function renderChart(snapshots) {
            var instance = ensureChart();
            if (!instance) {
                return;
            }

            var grouped = buildChartSeries(snapshots, state.interval);
            var costSeries = [];
            (snapshots || []).forEach(function (snapshot) {
                var timestamp = (snapshot.date_hour || 0) * 1000;
                costSeries.push([timestamp, snapshot.attributed_cost || 0]);
            });

            instance.series[0].setData(grouped.revenue, false);
            instance.series[1].setData(costSeries, false);
            instance.series[2].setData(grouped.conversions, false);
            instance.series[3].setData(grouped.profit, false);
            instance.redraw();
        }

        function updateLastRefreshed() {
            if (!lastRefreshed) {
                return;
            }

            var now = new Date();
            lastRefreshed.textContent = 'Last refreshed ' + now.toLocaleString();
        }

        function buildAnalyticsParams() {
            var endHour = nowSeconds();
            var startHour = hoursAgo(state.rangeHours);
            state.startHour = startHour;
            state.endHour = endHour;

            var params = new URLSearchParams({
                model_id: String(state.modelId || state.selectedModelId),
                scope: state.scope,
                start_hour: String(startHour),
                end_hour: String(endHour),
                limit: String(Math.max(state.rangeHours, state.interval === 'day' ? Math.ceil(state.rangeHours / 24) : state.rangeHours)),
            });

            if (state.scopeId !== null) {
                params.set('scope_id', String(state.scopeId));
            }

            return params;
        }

        function loadAnalytics() {
            var modelId = state.modelId || state.selectedModelId;
            if (!modelId) {
                return;
            }

            setLoading(true);
            clearError();

            var params = buildAnalyticsParams();

            fetchJson(apiBase + '/metrics?' + params.toString())
                .then(function (response) {
                    if (!response || response.error || !response.data) {
                        throw new Error('Analytics payload invalid.');
                    }

                    var data = response.data;
                    updateKpis(data.totals || {});
                    renderMix(safeArray(data.touchpoint_mix));
                    renderAnomalies(safeArray(data.anomalies));
                    renderChart(safeArray(data.snapshots));
                    toggleEmptyState(safeArray(data.snapshots).length > 0);
                    updateLastRefreshed();
                })
                .catch(function (error) {
                    var message = 'Attribution analytics could not be loaded.';
                    if (error && error.status === 403) {
                        message = 'You do not have permission to view analytics for this scope.';
                    }
                    showError(message);
                    toggleEmptyState(false);
                })
                .finally(function () {
                    setLoading(false);
                });
        }

        function populateModelSelect(models) {
            if (!modelSelect) {
                return;
            }

            modelSelect.innerHTML = '';
            if (sandboxSelect) {
                sandboxSelect.innerHTML = '';
            }

            models.forEach(function (model) {
                var option = document.createElement('option');
                option.value = model.model_id;
                option.textContent = model.name;
                if (model.model_id === state.modelId) {
                    option.selected = true;
                }
                modelSelect.appendChild(option);

                if (sandboxSelect) {
                    var sandboxOption = document.createElement('option');
                    sandboxOption.value = model.slug;
                    sandboxOption.textContent = model.name;
                    sandboxSelect.appendChild(sandboxOption);
                }
            });
        }

        function loadModels() {
            if (!modelSelect) {
                return;
            }

            setLoading(true);
            fetchJson(apiBase + '/models')
                .then(function (response) {
                    if (!response || response.error) {
                        throw new Error('Unable to load models.');
                    }

                    var models = safeArray(response.data).filter(function (model) {
                        return model.is_active !== false;
                    });

                    if (models.length === 0) {
                        showError('Create an attribution model to begin analysing journeys.');
                        return;
                    }

                    state.models = models;
                    var defaultModel = models.find(function (item) { return item.is_default; }) || models[0];
                    state.modelId = defaultModel.model_id;
                    state.selectedModelId = defaultModel.model_id;
                    populateModelSelect(models);
                    if (modelHelper) {
                        modelHelper.style.display = 'none';
                    }
                    updatePromoteState();
                    loadAnalytics();
                    loadSandbox();
                    loadExports();
                })
                .catch(function (error) {
                    showError(error.message || 'Failed to load attribution models.');
                })
                .finally(function () {
                    setLoading(false);
                });
        }

        function updatePromoteState() {
            if (!promoteButton) {
                return;
            }

            if (!state.modelId) {
                promoteButton.setAttribute('disabled', 'disabled');
                return;
            }

            promoteButton.removeAttribute('disabled');
        }

        function updateScopeState() {
            var selectedScope = scopeSelect ? scopeSelect.value : 'global';
            state.scope = selectedScope || 'global';

            if (state.scope === 'global') {
                state.scopeId = null;
                if (scopeInput) {
                    scopeInput.value = '';
                    scopeInput.setAttribute('disabled', 'disabled');
                }
            } else if (scopeInput) {
                scopeInput.removeAttribute('disabled');
                if (scopeInput.value !== '') {
                    state.scopeId = parseInteger(scopeInput.value);
                }
            }
        }

        function buildSandboxParams() {
            var params = buildAnalyticsParams();
            params.delete('model_id');

            var selected = Array.from(sandboxSelect ? sandboxSelect.selectedOptions || [] : []).map(function (option) {
                return option.value;
            });

            if (selected.length === 0 && state.modelId) {
                var current = state.models.find(function (model) { return model.model_id === state.modelId; });
                if (current) {
                    selected.push(current.slug);
                }
            }

            selected.forEach(function (slug) {
                params.append('models[]', slug);
            });

            return params;
        }

        function renderSandbox(summary, comparisons) {
            if (sandboxSummary) {
                sandboxSummary.innerHTML = '';
            }

            if (sandboxTable) {
                sandboxTable.innerHTML = '';
            }

            if (sandboxTableWrapper) {
                sandboxTableWrapper.style.display = 'none';
            }

            var message = summary && summary.message ? summary.message : 'Sandbox results will appear here once models are compared.';
            var info = document.createElement('p');
            info.className = 'text-muted';
            info.textContent = message;
            if (sandboxSummary) {
                sandboxSummary.appendChild(info);
            }

            if (!comparisons || comparisons.length === 0 || !sandboxTable) {
                return;
            }

            if (sandboxTableWrapper) {
                sandboxTableWrapper.style.display = 'block';
            }

            comparisons.forEach(function (row) {
                var tr = document.createElement('tr');
                var cells = [
                    row.name,
                    formatCurrency(row.revenue || 0),
                    formatNumber(row.conversions || 0),
                    formatCurrency(row.cost || 0),
                    formatPercent(typeof row.roi === 'number' ? row.roi : null),
                ];

                cells.forEach(function (value) {
                    var td = document.createElement('td');
                    td.textContent = value;
                    tr.appendChild(td);
                });

                sandboxTable.appendChild(tr);
            });
        }

        function loadSandbox() {
            if (!sandboxSelect) {
                return;
            }

            var params = buildSandboxParams();
            fetchJson(apiBase + '/sandbox?' + params.toString())
                .then(function (response) {
                    if (!response || response.error) {
                        throw new Error('Sandbox request failed.');
                    }

                    var data = response.data || {};
                    renderSandbox(data.summary, data.comparisons);
                })
                .catch(function (error) {
                    if (sandboxSummary) {
                        sandboxSummary.innerHTML = '';
                        var alert = document.createElement('div');
                        alert.className = 'alert alert-warning';
                        alert.textContent = error.message || 'Unable to load sandbox results.';
                        sandboxSummary.appendChild(alert);
                    }
                });
        }

        function renderExports(jobs) {
            if (!exportRows || !exportEmpty) {
                return;
            }

            exportRows.innerHTML = '';

            if (!jobs || jobs.length === 0) {
                exportRows.appendChild(exportEmpty);
                exportEmpty.style.display = '';
                return;
            }

            exportEmpty.style.display = 'none';

            jobs.forEach(function (job) {
                var tr = document.createElement('tr');
                var requested = job.created_at ? new Date(job.created_at * 1000).toLocaleString() : '—';
                var statusLabel = job.status ? job.status.replace(/_/g, ' ') : 'unknown';

                var cells = [
                    String(job.export_id || ''),
                    requested,
                    statusLabel,
                    (job.format || '').toUpperCase(),
                ];

                cells.forEach(function (value) {
                    var td = document.createElement('td');
                    td.textContent = value;
                    tr.appendChild(td);
                });

                var actionCell = document.createElement('td');
                actionCell.className = 'text-right';
                if (job.status === 'completed' && job.download_url) {
                    var link = document.createElement('a');
                    link.className = 'btn btn-xs btn-primary';
                    link.href = job.download_url;
                    link.textContent = 'Download';
                    link.setAttribute('target', '_blank');
                    actionCell.appendChild(link);
                } else if (job.status === 'processing') {
                    actionCell.innerHTML = '<span class="label label-info">Processing</span>';
                } else {
                    actionCell.innerHTML = '<span class="text-muted">—</span>';
                }

                tr.appendChild(actionCell);

                if (job.error_message) {
                    var errorRow = document.createElement('tr');
                    var errorCell = document.createElement('td');
                    errorCell.colSpan = 5;
                    errorCell.className = 'text-danger';
                    errorCell.textContent = job.error_message;
                    errorRow.appendChild(errorCell);
                    exportRows.appendChild(tr);
                    exportRows.appendChild(errorRow);
                } else {
                    exportRows.appendChild(tr);
                }
            });
        }

        function buildDownloadUrl(job) {
            if (job.download_url) {
                return job.download_url;
            }

            if (!downloadBase || !job.export_id || !job.download_token) {
                return null;
            }

            return downloadBase + '?export_id=' + encodeURIComponent(job.export_id) + '&token=' + encodeURIComponent(job.download_token);
        }

        function loadExports() {
            if (!exportPanel) {
                return;
            }

            if (!state.modelId) {
                renderExports([]);
                return;
            }

            fetchJson(apiBase + '/models/' + state.modelId + '/exports')
                .then(function (response) {
                    if (!response || response.error) {
                        throw new Error(response && response.message ? response.message : 'Unable to load exports.');
                    }

                    var jobs = safeArray(response.data).map(function (job) {
                        job.download_url = job.download_url || buildDownloadUrl(job);
                        return job;
                    });
                    renderExports(jobs);
                })
                .catch(function (error) {
                    renderExports([]);
                    if (exportFeedback) {
                        exportFeedback.className = 'alert alert-warning attribution-alert';
                        exportFeedback.textContent = error.message || 'Failed to load export history.';
                        exportFeedback.style.display = 'block';
                    }
                });
        }

        function handleExportSubmit(event) {
            event.preventDefault();
            if (!state.modelId) {
                return;
            }

            if (exportFeedback) {
                exportFeedback.style.display = 'none';
            }

            var format = exportForm.querySelector('[data-role="export-format"]').value;
            var range = parseInt(exportForm.querySelector('[data-role="export-range"]').value, 10) || 24;
            var webhookUrlEl = exportForm.querySelector('[data-role="webhook-url"]');
            var webhookMethodEl = exportForm.querySelector('[data-role="webhook-method"]');
            var headersEl = exportForm.querySelector('[data-role="webhook-headers"]');

            var webhookUrl = webhookUrlEl ? webhookUrlEl.value : '';
            var webhookMethod = webhookMethodEl ? webhookMethodEl.value : 'POST';
            var headersText = headersEl ? headersEl.value : '';
            var headers = parseHeaders(headersText);

            var payload = {
                scope: state.scope,
                scope_id: state.scopeId,
                start_hour: hoursAgo(range),
                end_hour: nowSeconds(),
                format: format,
                webhook_url: webhookUrl || undefined,
                webhook_method: webhookMethod,
                webhook_headers: headers,
            };

            sendJson(apiBase + '/models/' + state.modelId + '/exports', 'POST', payload)
                .then(function (response) {
                    if (!response || response.error) {
                        throw new Error(response && response.message ? response.message : 'Unable to schedule export.');
                    }

                    if (exportFeedback) {
                        exportFeedback.className = 'alert alert-success attribution-alert';
                        exportFeedback.textContent = 'Export scheduled successfully. It will appear below once processing begins.';
                        exportFeedback.style.display = 'block';
                    }

                    loadExports();
                })
                .catch(function (error) {
                    if (exportFeedback) {
                        exportFeedback.className = 'alert alert-danger attribution-alert';
                        exportFeedback.textContent = error.message || 'Failed to schedule export.';
                        exportFeedback.style.display = 'block';
                    }
                });
        }

        function promoteModel() {
            if (!state.modelId) {
                return;
            }

            if (promoteFeedback) {
                promoteFeedback.style.display = 'none';
            }

            sendJson(apiBase + '/models/' + state.modelId, 'PATCH', { is_default: true })
                .then(function (response) {
                    if (!response || response.error) {
                        throw new Error(response && response.message ? response.message : 'Unable to promote model.');
                    }

                    if (promoteFeedback) {
                        promoteFeedback.className = 'alert alert-success attribution-alert';
                        promoteFeedback.textContent = 'Model promoted to default successfully.';
                        promoteFeedback.style.display = 'block';
                    }

                    state.models.forEach(function (model) {
                        model.is_default = model.model_id === state.modelId;
                    });
                })
                .catch(function (error) {
                    if (promoteFeedback) {
                        promoteFeedback.className = 'alert alert-danger attribution-alert';
                        promoteFeedback.textContent = error.message || 'Unable to promote model. Ensure you have manage permissions.';
                        promoteFeedback.style.display = 'block';
                    }
                });
        }

        // Event listeners
        if (scopeSelect) {
            scopeSelect.addEventListener('change', function () {
                updateScopeState();
                loadAnalytics();
                loadSandbox();
            });
        }

        if (scopeInput) {
            scopeInput.addEventListener('input', function () {
                state.scopeId = scopeInput.value === '' ? null : parseInteger(scopeInput.value);
            });
            scopeInput.addEventListener('change', function () {
                loadAnalytics();
                loadSandbox();
            });
        }

        if (rangeSelect) {
            rangeSelect.addEventListener('change', function () {
                var value = parseInt(rangeSelect.value, 10);
                state.rangeHours = Number.isNaN(value) ? 24 : value;
                loadAnalytics();
                loadSandbox();
            });
        }

        if (timeframeSelect) {
            timeframeSelect.addEventListener('change', function () {
                var value = timeframeSelect.value;
                if (value !== 'custom') {
                    state.rangeHours = TIMEFRAME_PRESETS[value] || 168;
                    loadAnalytics();
                }
            });
        }

        if (intervalSelect) {
            intervalSelect.addEventListener('change', function () {
                state.interval = intervalSelect.value || 'hour';
                loadAnalytics();
            });
        }

        if (modelSelect) {
            modelSelect.addEventListener('change', function () {
                state.modelId = parseInteger(modelSelect.value);
                state.selectedModelId = state.modelId;
                updatePromoteState();
                loadAnalytics();
                loadSandbox();
                loadExports();
            });
        }

        if (sandboxSelect) {
            sandboxSelect.addEventListener('change', function () {
                loadSandbox();
            });
        }

        if (refreshButton) {
            refreshButton.addEventListener('click', function () {
                loadAnalytics();
            });
        }

        if (refreshSandboxButton) {
            refreshSandboxButton.addEventListener('click', function () {
                loadSandbox();
            });
        }

        if (promoteButton) {
            promoteButton.addEventListener('click', promoteModel);
        }

        if (exportForm) {
            exportForm.addEventListener('submit', handleExportSubmit);
        }

        updateScopeState();
        loadModels();
    });

    // Sandbox modal functionality
    document.addEventListener('DOMContentLoaded', function () {
        var dashboard = document.querySelector('[data-attribution-dashboard]');
        if (!dashboard) {
            return;
        }

        var apiBase = dashboard.getAttribute('data-api-base');
        if (!apiBase) {
            return;
        }

        var sandboxRoot = document.querySelector('[data-role="sandbox-modal"]');
        if (!sandboxRoot) {
            return;
        }

        var openButton = dashboard.querySelector('[data-role="open-sandbox"]');
        if (!openButton) {
            return;
        }

        var dialog = sandboxRoot.querySelector('[data-role="sandbox-dialog"]');
        var closeButtons = sandboxRoot.querySelectorAll('[data-role="sandbox-close"]');
        var dismissBackdrop = sandboxRoot.querySelector('[data-role="sandbox-dismiss"]');
        var candidateSelect = sandboxRoot.querySelector('[data-role="sandbox-models"]');
        var helper = sandboxRoot.querySelector('[data-role="sandbox-helper"]');
        var summaryNode = sandboxRoot.querySelector('[data-role="sandbox-summary"]');
        var placeholder = sandboxRoot.querySelector('[data-role="sandbox-placeholder"]');
        var errorNode = sandboxRoot.querySelector('[data-role="sandbox-error"]');
        var resultsTable = sandboxRoot.querySelector('[data-role="sandbox-results"]');
        var resultsBody = sandboxRoot.querySelector('[data-role="sandbox-results-body"]');
        var promoteButton = sandboxRoot.querySelector('[data-role="sandbox-promote"]');
        var promoteStatus = sandboxRoot.querySelector('[data-role="sandbox-promote-status"]');

        var modelSelect = dashboard.querySelector('[data-role="model-select"]');
        var scopeSelect = dashboard.querySelector('[data-role="scope-select"]');
        var scopeInput = dashboard.querySelector('[data-role="scope-id"]');

        var promoteButtonLabel = promoteButton ? promoteButton.textContent.trim() : '';

        var state = {
            isOpen: false,
            isLoading: false,
            models: [],
            selectedSlugs: [],
            comparison: null,
            focusReturn: null,
            promotion: {
                pending: false,
                previousDefaultSlug: null,
                previousDashboardModelId: null
            }
        };

        function setHidden(element, hidden) {
            if (!element) {
                return;
            }

            if (hidden) {
                element.setAttribute('hidden', 'hidden');
                element.setAttribute('aria-hidden', 'true');
            } else {
                element.removeAttribute('hidden');
                element.setAttribute('aria-hidden', 'false');
            }
        }

        function clearError() {
            if (!errorNode) {
                return;
            }

            errorNode.textContent = '';
            errorNode.style.display = 'none';
        }

        function showError(message) {
            if (!errorNode) {
                return;
            }

            errorNode.textContent = message;
            errorNode.style.display = 'block';
        }

        function updateHelperText() {
            if (!helper) {
                return;
            }

            if (state.selectedSlugs.length === 0) {
                helper.textContent = 'Pick at least one model to start the comparison.';
            } else if (state.selectedSlugs.length === 1) {
                helper.textContent = 'Select another model to compare or promote the highlighted model to default.';
            } else {
                helper.textContent = 'Comparing ' + state.selectedSlugs.length + ' models.';
            }
        }

        function renderSummary(summary) {
            if (!summaryNode) {
                return;
            }

            summaryNode.innerHTML = '';
            if (!summary) {
                return;
            }

            var message = document.createElement('p');
            message.className = 'attribution-sandbox__summary-message';
            message.textContent = summary.message || 'Comparison ready.';
            summaryNode.appendChild(message);

            var metaList = document.createElement('dl');
            metaList.className = 'attribution-sandbox__summary-meta';

            var scopeLabel = (summary.scope || 'global').replace(/_/g, ' ');
            var scopeTerm = document.createElement('dt');
            scopeTerm.textContent = 'Scope';
            var scopeValue = document.createElement('dd');
            scopeValue.textContent = scopeLabel.charAt(0).toUpperCase() + scopeLabel.slice(1);

            var scopeIdTerm = document.createElement('dt');
            scopeIdTerm.textContent = 'Scope ID';
            var scopeIdValue = document.createElement('dd');
            scopeIdValue.textContent = summary.scope_id ? String(summary.scope_id) : 'All';

            var rangeTerm = document.createElement('dt');
            rangeTerm.textContent = 'Range';
            var rangeValue = document.createElement('dd');
            var startDate = summary.start_hour ? new Date(summary.start_hour * 1000) : null;
            var endDate = summary.end_hour ? new Date(summary.end_hour * 1000) : null;
            if (startDate && endDate) {
                rangeValue.textContent = startDate.toLocaleString() + ' – ' + endDate.toLocaleString();
            } else {
                rangeValue.textContent = 'Last 24 hours';
            }

            metaList.appendChild(scopeTerm);
            metaList.appendChild(scopeValue);
            metaList.appendChild(scopeIdTerm);
            metaList.appendChild(scopeIdValue);
            metaList.appendChild(rangeTerm);
            metaList.appendChild(rangeValue);

            summaryNode.appendChild(metaList);
        }

        function setPlaceholder(message) {
            if (!placeholder) {
                return;
            }

            placeholder.textContent = message;
            placeholder.style.display = message ? 'block' : 'none';
        }

        function renderComparisons(payload) {
            if (!resultsBody || !resultsTable) {
                return;
            }

            resultsBody.innerHTML = '';

            if (!payload || !payload.models || payload.models.length === 0) {
                resultsTable.style.display = 'none';
                setPlaceholder('Select models to view comparison metrics.');
                return;
            }

            var comparisons = payload.comparisons || [];
            var comparisonMap = {};
            comparisons.forEach(function (item) {
                if (item && item.slug) {
                    comparisonMap[item.slug] = item;
                }
            });

            var hasMetrics = false;
            payload.models.forEach(function (model) {
                if (state.selectedSlugs.indexOf(model.slug) === -1) {
                    return;
                }

                var row = document.createElement('tr');

                var nameCell = document.createElement('th');
                nameCell.scope = 'row';
                var nameText = model.name;
                if (model.is_default) {
                    nameText += ' (default)';
                }
                nameCell.textContent = nameText;
                row.appendChild(nameCell);

                var typeCell = document.createElement('td');
                typeCell.textContent = (model.type || '').replace(/_/g, ' ') || '—';
                row.appendChild(typeCell);

                var metrics = comparisonMap[model.slug];
                var revenueCell = document.createElement('td');
                var conversionsCell = document.createElement('td');
                var roiCell = document.createElement('td');

                if (metrics && metrics.totals) {
                    hasMetrics = true;
                    revenueCell.textContent = formatCurrency(metrics.totals.revenue);
                    conversionsCell.textContent = formatNumber(metrics.totals.conversions);
                    roiCell.textContent = formatPercent(metrics.totals.roi);
                } else {
                    revenueCell.textContent = '—';
                    conversionsCell.textContent = '—';
                    roiCell.textContent = '—';
                }

                row.appendChild(revenueCell);
                row.appendChild(conversionsCell);
                row.appendChild(roiCell);

                resultsBody.appendChild(row);
            });

            if (!resultsBody.hasChildNodes()) {
                resultsTable.style.display = 'none';
                setPlaceholder('Select models to view comparison metrics.');
                return;
            }

            resultsTable.style.display = '';
            if (!hasMetrics && payload.summary && payload.summary.message) {
                setPlaceholder(payload.summary.message);
            } else if (!hasMetrics) {
                setPlaceholder('Metrics are on their way. Check back soon for comparison data.');
            } else {
                setPlaceholder('');
            }
        }

        function renderCandidateOptions() {
            if (!candidateSelect) {
                return;
            }

            var selected = new Set(state.selectedSlugs);
            candidateSelect.innerHTML = '';

            state.models.forEach(function (model) {
                var option = document.createElement('option');
                option.value = model.slug;
                option.textContent = model.name + (model.is_default ? ' (default)' : '');
                option.dataset.modelId = String(model.model_id);
                if (selected.has(model.slug)) {
                    option.selected = true;
                }
                candidateSelect.appendChild(option);
            });
        }

        function ensureModelsLoaded() {
            if (state.models.length > 0) {
                return Promise.resolve();
            }

            return fetchJson(apiBase + '/models').then(function (payload) {
                if (!payload || payload.error) {
                    throw new Error('Unable to load attribution models.');
                }

                state.models = payload.data || [];
                renderCandidateOptions();
            }).catch(function (error) {
                showError(error.message || 'Failed to load models for the sandbox.');
                throw error;
            });
        }

        function updatePromoteButton() {
            if (!promoteButton) {
                return;
            }

            var canPromote = !state.promotion.pending && state.selectedSlugs.length === 1;
            promoteButton.disabled = !canPromote;
        }

        function dispatchChange(element) {
            if (!element) {
                return;
            }

            var event;
            if (typeof Event === 'function') {
                event = new Event('change', { bubbles: true });
            } else {
                event = document.createEvent('HTMLEvents');
                event.initEvent('change', true, false);
            }

            element.dispatchEvent(event);
        }

        function applyOptimisticPromotion(targetModel) {
            var previousDefault = null;
            state.models.forEach(function (model) {
                if (model.is_default) {
                    previousDefault = model;
                }
                model.is_default = model.slug === targetModel.slug;
            });

            state.promotion.previousDefaultSlug = previousDefault ? previousDefault.slug : null;
            state.promotion.previousDashboardModelId = modelSelect ? modelSelect.value : null;

            renderCandidateOptions();
            if (state.comparison) {
                renderComparisons(state.comparison);
            }

            if (modelSelect) {
                modelSelect.value = String(targetModel.model_id);
                dispatchChange(modelSelect);
            }
        }

        function revertOptimisticPromotion() {
            var previousSlug = state.promotion.previousDefaultSlug;
            var previousModelId = state.promotion.previousDashboardModelId;

            state.models.forEach(function (model) {
                model.is_default = model.slug === previousSlug;
            });

            renderCandidateOptions();
            if (state.comparison) {
                renderComparisons(state.comparison);
            }

            if (modelSelect && previousModelId !== null) {
                modelSelect.value = previousModelId;
                dispatchChange(modelSelect);
            }
        }

        function promoteSelectedModel() {
            if (!promoteButton || state.selectedSlugs.length !== 1) {
                return;
            }

            var slug = state.selectedSlugs[0];
            var targetModel = state.models.find(function (model) { return model.slug === slug; });
            if (!targetModel) {
                return;
            }

            clearError();
            state.promotion.pending = true;
            updatePromoteButton();
            if (promoteStatus) {
                promoteStatus.textContent = 'Promoting ' + targetModel.name + '…';
            }
            promoteButton.textContent = 'Promoting…';

            applyOptimisticPromotion(targetModel);

            fetchJson(apiBase + '/models/' + targetModel.model_id, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ is_default: true })
            }).then(function (payload) {
                state.promotion.pending = false;
                promoteButton.textContent = promoteButtonLabel || 'Promote to default';
                if (promoteStatus) {
                    promoteStatus.textContent = targetModel.name + ' is now the default model.';
                }
                if (payload && payload.data) {
                    state.models = state.models.map(function (model) {
                        if (model.model_id === payload.data.model_id) {
                            return Object.assign({}, model, payload.data);
                        }
                        if (payload.data.is_default) {
                            return Object.assign({}, model, { is_default: false });
                        }
                        return model;
                    });
                    renderCandidateOptions();
                    if (state.comparison) {
                        state.comparison.models = state.models;
                        renderComparisons(state.comparison);
                    }
                }
                updatePromoteButton();
            }).catch(function (error) {
                state.promotion.pending = false;
                promoteButton.textContent = promoteButtonLabel || 'Promote to default';
                if (promoteStatus) {
                    promoteStatus.textContent = error.message || 'Unable to promote model.';
                }
                revertOptimisticPromotion();
                updatePromoteButton();
            });
        }

        function getFilters() {
            var scope = scopeSelect ? scopeSelect.value : 'global';
            var scopeId = null;
            if (scopeInput && scopeInput.value !== '') {
                scopeId = parseInt(scopeInput.value, 10);
            }

            return { scope: scope, scopeId: scopeId };
        }

        function runSandboxComparison() {
            if (state.selectedSlugs.length === 0) {
                renderComparisons({ models: [] });
                renderSummary(null);
                return;
            }

            state.isLoading = true;
            clearError();
            setPlaceholder('Loading comparison…');

            var range = getUnixHourRange(24);
            var filters = getFilters();
            var params = new URLSearchParams({
                scope: filters.scope,
                start_hour: String(range.start),
                end_hour: String(range.end)
            });

            if (filters.scopeId !== null && !Number.isNaN(filters.scopeId)) {
                params.set('scope_id', String(filters.scopeId));
            }

            params.set('models', state.selectedSlugs.join(','));

            fetchJson(apiBase + '/sandbox?' + params.toString())
                .then(function (payload) {
                    state.isLoading = false;
                    if (!payload || payload.error) {
                        showError('Unable to load sandbox comparison.');
                        renderComparisons({ models: [] });
                        renderSummary(null);
                        return;
                    }

                    state.comparison = payload.data || { models: [], comparisons: [], summary: null };
                    if (!state.comparison.models || state.comparison.models.length === 0) {
                        state.comparison.models = state.models.filter(function (model) {
                            return state.selectedSlugs.indexOf(model.slug) !== -1;
                        });
                    }
                    renderSummary(state.comparison.summary || null);
                    renderComparisons(state.comparison);
                })
                .catch(function (error) {
                    state.isLoading = false;
                    showError(error.message || 'Unable to run sandbox comparison.');
                    renderComparisons({ models: [] });
                    renderSummary(null);
                });
        }

        function handleSelectionChange() {
            state.selectedSlugs = Array.prototype.slice.call(candidateSelect ? candidateSelect.selectedOptions : [])
                .map(function (option) { return option.value; });
            updateHelperText();
            updatePromoteButton();
            runSandboxComparison();
        }

        function trapFocus(event) {
            if (event.key !== 'Tab') {
                return;
            }

            var focusable = getFocusableElements(dialog);
            if (focusable.length === 0) {
                event.preventDefault();
                return;
            }

            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            var active = document.activeElement;

            if (event.shiftKey) {
                if (active === first) {
                    event.preventDefault();
                    last.focus();
                }
            } else if (active === last) {
                event.preventDefault();
                first.focus();
            }
        }

        function openSandbox() {
            if (state.isOpen) {
                return;
            }

            state.isOpen = true;
            state.focusReturn = document.activeElement;
            setHidden(sandboxRoot, false);
            document.body.classList.add('attribution-sandbox-open');
            clearError();
            if (promoteStatus) {
                promoteStatus.textContent = '';
            }

            ensureModelsLoaded().then(function () {
                renderCandidateOptions();
                if (state.selectedSlugs.length === 0 && modelSelect && modelSelect.value) {
                    var selectedModel = state.models.find(function (model) {
                        return String(model.model_id) === String(modelSelect.value);
                    });
                    if (selectedModel) {
                        state.selectedSlugs = [selectedModel.slug];
                    }
                }
                renderCandidateOptions();
                updateHelperText();
                updatePromoteButton();
                runSandboxComparison();

                var focusable = getFocusableElements(dialog);
                if (focusable.length > 0) {
                    focusable[0].focus();
                } else if (dialog) {
                    dialog.focus();
                }
            }).catch(function () {
                if (dialog) {
                    dialog.focus();
                }
            });
        }

        function closeSandbox() {
            if (!state.isOpen) {
                return;
            }

            state.isOpen = false;
            setHidden(sandboxRoot, true);
            document.body.classList.remove('attribution-sandbox-open');
            if (state.focusReturn && typeof state.focusReturn.focus === 'function') {
                state.focusReturn.focus();
            } else if (openButton) {
                openButton.focus();
            }
        }

        openButton.addEventListener('click', function () {
            openSandbox();
        });

        if (candidateSelect) {
            candidateSelect.addEventListener('change', handleSelectionChange);
        }

        Array.prototype.slice.call(closeButtons).forEach(function (button) {
            button.addEventListener('click', function () {
                closeSandbox();
            });
        });

        if (dismissBackdrop) {
            dismissBackdrop.addEventListener('click', function () {
                closeSandbox();
            });
        }

        sandboxRoot.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeSandbox();
                return;
            }

            trapFocus(event);
        });

        if (promoteButton) {
            promoteButton.addEventListener('click', function () {
                promoteSelectedModel();
            });
        }
    });
})(window, document, window.Highcharts);
