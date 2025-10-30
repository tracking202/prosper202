(function (window, document, Highcharts) {
    'use strict';

    function formatCurrency(value) {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: 'USD',
            maximumFractionDigits: 2,
        }).format(value || 0);
    }

    function formatNumber(value) {
        return new Intl.NumberFormat(undefined, {
            maximumFractionDigits: 0,
        }).format(value || 0);
    }

    function formatDecimal(value, digits) {
        return new Intl.NumberFormat(undefined, {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        }).format(value || 0);
    }

    function formatPercent(value) {
        if (value === null || typeof value === 'undefined' || Number.isNaN(value)) {
            return '–';
        }

        return value.toFixed(1) + '%';
    }

    function nowSeconds() {
        return Math.floor(Date.now() / 1000);
    }

    function hoursAgo(hours) {
        return nowSeconds() - (hours * 3600);
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

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-attribution-app]');
        if (!root) {
            return;
        }

        var apiBase = root.getAttribute('data-api-base');
        var downloadBase = root.getAttribute('data-download-base');
        if (!apiBase) {
            return;
        }

        var modelSelect = root.querySelector('[data-role="model-select"]');
        var modelHelper = root.querySelector('[data-role="model-helper"]');
        var scopeSelect = root.querySelector('[data-role="scope-select"]');
        var scopeInput = root.querySelector('[data-role="scope-id"]');
        var rangeSelect = root.querySelector('[data-role="range-select"]');
        var intervalSelect = root.querySelector('[data-role="interval-select"]');
        var kpiCards = root.querySelectorAll('[data-role="kpi"]');
        var mixList = root.querySelector('[data-role="touchpoint-mix"]');
        var anomalyBanner = root.querySelector('[data-role="anomaly-banner"]');
        var emptyState = root.querySelector('[data-role="empty-state"]');
        var errorBanner = root.querySelector('[data-role="error-banner"]');
        var lastRefreshed = root.querySelector('[data-role="last-refreshed"]');
        var refreshAnalyticsButton = root.querySelector('[data-role="refresh-analytics"]');
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
            models: [],
            scope: 'global',
            scopeId: null,
            rangeHours: 24,
            interval: 'hour',
            isLoading: false,
        };

        function setLoading(flag) {
            state.isLoading = flag;
            if (flag) {
                root.classList.add('is-loading');
            } else {
                root.classList.remove('is-loading');
            }
        }

        function showError(message) {
            if (!errorBanner) {
                return;
            }
            errorBanner.textContent = message;
            errorBanner.style.display = 'block';
        }

        function clearError() {
            if (errorBanner) {
                errorBanner.style.display = 'none';
            }
        }

        function toggleEmptyState(hasData) {
            if (!emptyState) {
                return;
            }

            emptyState.style.display = hasData ? 'none' : 'block';
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

            if (!chart) {
                chart = Highcharts.chart({
                    chart: {
                        renderTo: root.querySelector('[data-role="trend-chart"]'),
                        zoomType: 'x',
                    },
                    title: { text: 'Attribution performance' },
                    xAxis: { type: 'datetime' },
                    yAxis: [{
                        title: { text: 'Revenue' },
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
                    },
                    legend: { enabled: true },
                    series: [{
                        name: 'Revenue',
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
            instance.series[0].setData(grouped.revenue, false);
            instance.series[1].setData(grouped.conversions, false);
            instance.series[2].setData(grouped.profit, false);
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
                model_id: String(state.modelId),
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
            if (!state.modelId) {
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
            sandboxSelect.innerHTML = '';

            models.forEach(function (model) {
                var option = document.createElement('option');
                option.value = model.model_id;
                option.textContent = model.name;
                if (model.model_id === state.modelId) {
                    option.selected = true;
                }
                modelSelect.appendChild(option);

                var sandboxOption = document.createElement('option');
                sandboxOption.value = model.slug;
                sandboxOption.textContent = model.name;
                sandboxSelect.appendChild(sandboxOption);
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

                    var models = safeArray(response.data);
                    if (models.length === 0) {
                        showError('Create an attribution model to begin analysing journeys.');
                        return;
                    }

                    state.models = models;
                    var defaultModel = models.find(function (item) { return item.is_default; }) || models[0];
                    state.modelId = defaultModel.model_id;
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

            var selected = Array.from(sandboxSelect.selectedOptions || []).map(function (option) {
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

            var params = new URLSearchParams({
                model_id: String(state.modelId),
            });

            fetchJson(apiBase + '/exports?' + params.toString())
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
            var webhookUrl = exportForm.querySelector('[data-role="webhook-url"]').value;
            var webhookMethod = exportForm.querySelector('[data-role="webhook-method"]').value || 'POST';
            var headersText = exportForm.querySelector('[data-role="webhook-headers"]').value;
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

        if (intervalSelect) {
            intervalSelect.addEventListener('change', function () {
                state.interval = intervalSelect.value || 'hour';
                loadAnalytics();
            });
        }

        if (modelSelect) {
            modelSelect.addEventListener('change', function () {
                state.modelId = parseInteger(modelSelect.value);
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

        if (refreshAnalyticsButton) {
            refreshAnalyticsButton.addEventListener('click', function () {
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
})(window, document, window.Highcharts);
