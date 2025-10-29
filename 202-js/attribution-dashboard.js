(function (window, document, $, Highcharts) {
    'use strict';

    function formatCurrency(value) {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(value);
    }

    function formatNumber(value) {
        return new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 }).format(value);
    }

    function formatPercent(value) {
        return `${value.toFixed(1)}%`;
    }

    function resolveRoi(value) {
        if (value === null || Number.isNaN(value)) {
            return '–';
        }

        return `${value.toFixed(2)}%`;
    }

    function getUnixHourRange(hours) {
        var end = Math.floor(Date.now() / 1000);
        var start = end - (hours * 3600);
        return { start: start, end: end };
    }

    function toDateTimeLocal(timestamp) {
        var date = new Date(timestamp * 1000);
        var year = date.getUTCFullYear();
        var month = String(date.getUTCMonth() + 1).padStart(2, '0');
        var day = String(date.getUTCDate()).padStart(2, '0');
        var hours = String(date.getUTCHours()).padStart(2, '0');
        var minutes = String(date.getUTCMinutes()).padStart(2, '0');
        return year + '-' + month + '-' + day + 'T' + hours + ':' + minutes;
    }

    function fromDateTimeLocal(value) {
        if (!value) {
            return null;
        }

        var date = new Date(value);
        if (isNaN(date.getTime())) {
            return null;
        }

        return Math.floor(date.getTime() / 1000);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-attribution-dashboard]');
        if (!root) {
            return;
        }

        var apiBase = root.getAttribute('data-api-base');
        if (!apiBase) {
            return;
        }
        var siteBase = apiBase.replace(/\/api\/v2\/attribution$/, '/');

        var modelSelect = root.querySelector('[data-role="model-select"]');
        var modelHelper = root.querySelector('[data-role="model-helper"]');
        var scopeSelect = root.querySelector('[data-role="scope-select"]');
        var scopeInput = root.querySelector('[data-role="scope-id"]');
        var kpiCards = root.querySelectorAll('[data-role="kpi"]');
        var mixList = root.querySelector('[data-role="touchpoint-mix"]');
        var anomalyBanner = root.querySelector('[data-role="anomaly-banner"]');
        var disabledAlert = root.querySelector('[data-role="analytics-disabled"]');
        var emptyState = root.querySelector('[data-role="empty-state"]');
        var lastRefreshed = root.querySelector('[data-role="last-refreshed"]');
        var exportStartInput = root.querySelector('[data-role="export-start"]');
        var exportEndInput = root.querySelector('[data-role="export-end"]');
        var exportButtons = root.querySelectorAll('[data-export-format]');
        var webhookUrlInput = root.querySelector('[data-role="webhook-url"]');
        var webhookSecretInput = root.querySelector('[data-role="webhook-secret"]');
        var webhookHeadersInput = root.querySelector('[data-role="webhook-headers"]');
        var exportFeedback = root.querySelector('[data-role="export-feedback"]');
        var exportTable = root.querySelector('[data-role="export-table"]');
        var exportEmptyRow = root.querySelector('[data-role="export-empty-row"]');

        var state = {
            modelId: null,
            scope: 'global',
            scopeId: null,
            startHour: null,
            endHour: null,
            chart: null,
            exports: [],
        };

        function setLoading(isLoading) {
            if (isLoading) {
                root.classList.add('is-loading');
                if (modelHelper) {
                    modelHelper.textContent = 'Loading…';
                    modelHelper.style.display = 'block';
                }
            } else {
                root.classList.remove('is-loading');
                if (modelHelper) {
                    modelHelper.style.display = 'none';
                }
            }
        }

        function updateScopeState() {
            var scope = scopeSelect.value;
            state.scope = scope;
            if (scope === 'global') {
                scopeInput.setAttribute('disabled', 'disabled');
                scopeInput.value = '';
                state.scopeId = null;
            } else {
                scopeInput.removeAttribute('disabled');
                if (scopeInput.value !== '') {
                    state.scopeId = parseInt(scopeInput.value, 10);
                }
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
                    case 'roi':
                        metric.textContent = resolveRoi(typeof totals.roi === 'number' ? totals.roi : null);
                        break;
                    default:
                        metric.textContent = '–';
                }
            });
        }

        function renderTouchpointMix(mix) {
            while (mixList.firstChild) {
                mixList.removeChild(mixList.firstChild);
            }

            if (!mix || mix.length === 0) {
                var empty = document.createElement('li');
                empty.textContent = 'No touchpoint data available.';
                empty.className = 'text-muted';
                mixList.appendChild(empty);
                return;
            }

            mix.forEach(function (item) {
                var node = document.createElement('li');
                var label = document.createElement('span');
                label.textContent = item.label;
                var value = document.createElement('span');
                value.textContent = formatPercent(item.share) + ' · ' + item.touch_count + ' touches';
                node.appendChild(label);
                node.appendChild(value);
                mixList.appendChild(node);
            });
        }

        function renderAnomalies(anomalies) {
            anomalyBanner.innerHTML = '';
            if (!anomalies || anomalies.length === 0) {
                return;
            }

            anomalies.forEach(function (alert) {
                var div = document.createElement('div');
                div.className = 'alert ' + (alert.severity === 'critical' ? 'alert-danger' : 'alert-warning');
                div.setAttribute('data-role', 'anomaly-alert');
                var direction = alert.direction === 'up' ? '▲' : '▼';
                div.textContent = direction + ' ' + alert.metric + ': ' + alert.message;
                anomalyBanner.appendChild(div);
            });
        }

        function renderChart(snapshots) {
            if (!Highcharts || typeof Highcharts.chart !== 'function') {
                return;
            }

            var seriesRevenue = [];
            var seriesConversions = [];

            (snapshots || []).forEach(function (snapshot) {
                var timestamp = snapshot.date_hour * 1000;
                seriesRevenue.push([timestamp, snapshot.attributed_revenue]);
                seriesConversions.push([timestamp, snapshot.attributed_conversions]);
            });

            if (!state.chart) {
                state.chart = Highcharts.chart('attribution-chart', {
                    title: { text: 'Hourly performance' },
                    xAxis: { type: 'datetime' },
                    yAxis: [{
                        title: { text: 'Revenue' },
                        labels: { formatter: function () { return '$' + this.value; } }
                    }, {
                        title: { text: 'Conversions' },
                        opposite: true
                    }],
                    legend: { enabled: true },
                    series: [{
                        name: 'Revenue',
                        type: 'spline',
                        data: seriesRevenue,
                        tooltip: { valuePrefix: '$' }
                    }, {
                        name: 'Conversions',
                        type: 'spline',
                        yAxis: 1,
                        data: seriesConversions
                    }]
                });
            } else {
                state.chart.series[0].setData(seriesRevenue, false);
                state.chart.series[1].setData(seriesConversions, false);
                state.chart.redraw();
            }
        }

        function updateLastRefreshed() {
            if (lastRefreshed) {
                var now = new Date();
                lastRefreshed.textContent = 'Last refreshed ' + now.toLocaleTimeString();
            }
        }

        function toggleEmptyState(hasData) {
            if (!emptyState) {
                return;
            }

            emptyState.style.display = hasData ? 'none' : 'block';
        }

        function handleError(message) {
            if (disabledAlert) {
                disabledAlert.style.display = 'block';
                disabledAlert.textContent = message;
            }
        }

        function clearError() {
            if (disabledAlert) {
                disabledAlert.style.display = 'none';
            }
        }

        function fetchJson(url) {
            return fetch(url, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            }).then(function (response) {
                if (!response.ok) {
                    var error = new Error('Request failed with status ' + response.status);
                    error.status = response.status;
                    throw error;
                }
                return response.json();
            });
        }

        function postJson(url, payload) {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload || {})
            }).then(function (response) {
                if (!response.ok) {
                    var error = new Error('Request failed with status ' + response.status);
                    error.status = response.status;
                    throw error;
                }

                return response.json();
            });
        }

        function loadMetrics() {
            if (!state.modelId) {
                return;
            }

            setLoading(true);
            clearError();

            var range = getUnixHourRange(24);
            state.startHour = range.start;
            state.endHour = range.end;

            var params = new URLSearchParams({
                model_id: state.modelId,
                scope: state.scope,
                start_hour: state.startHour,
                end_hour: state.endHour
            });

            if (state.scopeId) {
                params.set('scope_id', String(state.scopeId));
            }

            fetchJson(apiBase + '/metrics?' + params.toString())
                .then(function (payload) {
                    if (!payload || payload.error || !payload.data) {
                        handleError('Unable to load analytics for the selected configuration.');
                        toggleEmptyState(true);
                        return;
                    }

                    var data = payload.data;
                    updateKpis(data.totals || {});
                    renderTouchpointMix(data.touchpoint_mix || []);
                    renderAnomalies(data.anomalies || []);
                    renderChart(data.snapshots || []);
                    toggleEmptyState((data.snapshots || []).length > 0);
                    updateLastRefreshed();
                    updateExportWindow(state.startHour, state.endHour);
                })
                .catch(function (error) {
                    var message = 'Attribution analytics could not be retrieved.';
                    if (error && error.status === 403) {
                        message = 'You do not have permission to view analytics for this scope.';
                    }
                    handleError(message);
                    toggleEmptyState(true);
                })
                .finally(function () {
                    setLoading(false);
                });
        }

        function updateExportWindow(startHour, endHour) {
            if (!exportStartInput || !exportEndInput) {
                return;
            }

            if (typeof startHour === 'number' && !Number.isNaN(startHour)) {
                exportStartInput.value = toDateTimeLocal(startHour);
            }

            if (typeof endHour === 'number' && !Number.isNaN(endHour)) {
                exportEndInput.value = toDateTimeLocal(endHour);
            }
        }

        function loadExports() {
            if (!state.modelId) {
                return;
            }

            fetchJson(apiBase + '/models/' + state.modelId + '/exports')
                .then(function (payload) {
                    if (!payload || payload.error) {
                        throw new Error('Unable to load export jobs.');
                    }

                    renderExports(payload.data || []);
                })
                .catch(function (error) {
                    displayExportFeedback(error.message || 'Failed to load export jobs.', true);
                });
        }

        function renderExports(jobs) {
            state.exports = jobs;
            if (exportFeedback) {
                exportFeedback.style.display = 'none';
            }
            if (!exportTable) {
                return;
            }

            var tbody = exportTable.querySelector('tbody');
            if (!tbody) {
                return;
            }

            while (tbody.firstChild) {
                tbody.removeChild(tbody.firstChild);
            }

            if (!jobs || jobs.length === 0) {
                if (exportEmptyRow) {
                    tbody.appendChild(exportEmptyRow.cloneNode(true));
                }
                return;
            }

            jobs.forEach(function (job) {
                var row = document.createElement('tr');

                var idCell = document.createElement('td');
                idCell.textContent = job.export_id || '—';
                row.appendChild(idCell);

                var statusCell = document.createElement('td');
                statusCell.textContent = job.status || 'pending';
                row.appendChild(statusCell);

                var queuedCell = document.createElement('td');
                queuedCell.textContent = job.queued_at ? new Date(job.queued_at * 1000).toLocaleString() : '—';
                row.appendChild(queuedCell);

                var rowsCell = document.createElement('td');
                rowsCell.textContent = typeof job.rows_exported === 'number' ? job.rows_exported : '—';
                row.appendChild(rowsCell);

                var completedCell = document.createElement('td');
                completedCell.textContent = job.completed_at ? new Date(job.completed_at * 1000).toLocaleString() : '—';
                row.appendChild(completedCell);

                var actionCell = document.createElement('td');
                if (job.export_id && job.file_path && job.status === 'completed') {
                    var link = document.createElement('a');
                    link.href = siteBase + '202-account/attribution/download.php?export_id=' + job.export_id;
                    link.textContent = 'Download';
                    link.className = 'btn btn-xs btn-success';
                    actionCell.appendChild(link);
                } else if (job.status === 'failed' && job.last_error) {
                    actionCell.textContent = job.last_error;
                    actionCell.className = 'text-danger';
                }
                row.appendChild(actionCell);

                tbody.appendChild(row);
            });
        }

        function parseWebhookHeaders(raw) {
            var headers = {};
            if (!raw) {
                return headers;
            }

            raw.split(/\r?\n/).forEach(function (line) {
                var trimmed = line.trim();
                if (!trimmed) {
                    return;
                }

                var parts = trimmed.split(':');
                if (parts.length < 2) {
                    return;
                }

                var name = parts.shift().trim();
                var value = parts.join(':').trim();
                if (name) {
                    headers[name] = value;
                }
            });

            return headers;
        }

        function collectWebhookConfig() {
            var url = webhookUrlInput && webhookUrlInput.value ? webhookUrlInput.value.trim() : '';
            var secret = webhookSecretInput && webhookSecretInput.value ? webhookSecretInput.value.trim() : '';
            var headers = webhookHeadersInput ? parseWebhookHeaders(webhookHeadersInput.value) : {};

            if (!url && !secret && Object.keys(headers).length === 0) {
                return null;
            }

            return {
                url: url,
                secret: secret,
                headers: headers,
            };
        }

        function displayExportFeedback(message, isError) {
            if (!exportFeedback) {
                return;
            }

            exportFeedback.textContent = message;
            exportFeedback.style.display = 'block';
            if (isError) {
                exportFeedback.classList.add('text-danger');
                exportFeedback.classList.remove('text-success');
            } else {
                exportFeedback.classList.remove('text-danger');
                exportFeedback.classList.add('text-success');
            }
        }

        function scheduleExport(format) {
            if (!state.modelId) {
                displayExportFeedback('Select an attribution model first.', true);
                return;
            }

            var startHour = exportStartInput ? fromDateTimeLocal(exportStartInput.value) : state.startHour;
            if (startHour === null) {
                startHour = state.startHour;
            }
            var endHour = exportEndInput ? fromDateTimeLocal(exportEndInput.value) : state.endHour;
            if (endHour === null) {
                endHour = state.endHour;
            }

            if (startHour === null || endHour === null) {
                displayExportFeedback('Provide valid start and end timestamps for the export.', true);
                return;
            }

            if (startHour >= endHour) {
                displayExportFeedback('Start time must be before the end time for exports.', true);
                return;
            }

            var payload = {
                format: format,
                scope: state.scope,
                start_hour: startHour,
                end_hour: endHour,
            };

            if (state.scopeId) {
                payload.scope_id = state.scopeId;
            }

            var webhook = collectWebhookConfig();
            if (webhook && webhook.url) {
                payload.webhook = webhook;
            }

            postJson(apiBase + '/models/' + state.modelId + '/exports', payload)
                .then(function (response) {
                    if (!response || response.error) {
                        throw new Error(response && response.message ? response.message : 'Export scheduling failed.');
                    }

                    displayExportFeedback('Export queued successfully. Refresh the jobs list for updates.', false);
                    loadExports();
                })
                .catch(function (error) {
                    displayExportFeedback(error.message || 'Failed to schedule export.', true);
                });
        }

        function loadModels() {
            setLoading(true);
            fetchJson(apiBase + '/models')
                .then(function (payload) {
                    if (!payload || payload.error) {
                        throw new Error('Unable to fetch models.');
                    }

                    var data = payload.data || [];
                    modelSelect.innerHTML = '';

                    if (data.length === 0) {
                        var option = document.createElement('option');
                        option.textContent = 'No models available';
                        option.setAttribute('disabled', 'disabled');
                        modelSelect.appendChild(option);
                        handleError('Create an attribution model to begin analysing journeys.');
                        return;
                    }

                    var defaultModel = data.find(function (item) { return item.is_default; }) || data[0];
                    data.forEach(function (model) {
                        var option = document.createElement('option');
                        option.value = model.model_id;
                        option.textContent = model.name;
                        if (model.model_id === defaultModel.model_id) {
                            option.selected = true;
                        }
                        modelSelect.appendChild(option);
                    });

                    state.modelId = parseInt(defaultModel.model_id, 10);
                    if (modelHelper) {
                        modelHelper.style.display = 'none';
                    }
                    loadMetrics();
                })
                .catch(function (error) {
                    handleError(error.message || 'Failed to load attribution models.');
                })
                .finally(function () {
                    setLoading(false);
                });
        }

        scopeSelect.addEventListener('change', function () {
            updateScopeState();
            loadMetrics();
        });

        scopeInput.addEventListener('input', function () {
            if (scopeInput.value === '') {
                state.scopeId = null;
            } else {
                state.scopeId = parseInt(scopeInput.value, 10);
            }
        });

        scopeInput.addEventListener('change', function () {
            loadMetrics();
        });

        modelSelect.addEventListener('change', function () {
            var value = modelSelect.value;
            state.modelId = value ? parseInt(value, 10) : null;
            loadMetrics();
            loadExports();
        });

        if (exportButtons && exportButtons.length) {
            exportButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var format = button.getAttribute('data-export-format') || 'csv';
                    scheduleExport(format);
                });
            });
        }

        updateScopeState();
        loadModels();
    });
})(window, document, window.jQuery, window.Highcharts);
