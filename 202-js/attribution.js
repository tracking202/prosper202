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

    function formatRoi(value) {
        if (value === null || value === undefined || Number.isNaN(value)) {
            return '–';
        }

        return value.toFixed(2) + '%';
    }

    function getUnixHourRange(hours) {
        var end = Math.floor(Date.now() / 1000);
        var start = end - (hours * 3600);
        return { start: start, end: end };
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

    function fetchJson(url, options) {
        if (options === void 0) {
            options = {};
        }

        var config = Object.assign({ method: 'GET' }, options);
        config.credentials = 'same-origin';
        config.headers = Object.assign({ 'Accept': 'application/json' }, config.headers || {});

        return fetch(url, config).then(function (response) {
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

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-attribution-page]');
        if (!root) {
            return;
        }

        var apiBase = root.getAttribute('data-api-base');
        if (!apiBase) {
            return;
        }

        var hasPermission = root.getAttribute('data-has-permission') === '1';

        var permissionAlert = root.querySelector('[data-role="permission-alert"]');
        var errorAlert = root.querySelector('[data-role="error-alert"]');
        var modelSelect = root.querySelector('[data-role="model-select"]');
        var modelHelper = root.querySelector('[data-role="model-helper"]');
        var timeframeSelect = root.querySelector('[data-role="timeframe-select"]');
        var startDateInput = root.querySelector('[data-role="start-date"]');
        var endDateInput = root.querySelector('[data-role="end-date"]');
        var scopeSelect = root.querySelector('[data-role="scope-select"]');
        var scopeIdInput = root.querySelector('[data-role="scope-id"]');
        var refreshButton = root.querySelector('[data-role="refresh-button"]');
        var loadingIndicator = root.querySelector('[data-role="loading"]');
        var kpiCards = root.querySelectorAll('[data-role="kpi"]');
        var trendEmpty = root.querySelector('[data-role="trend-empty"]');
        var comparisonEmpty = root.querySelector('[data-role="comparison-empty"]');
        var lastRefreshed = root.querySelector('[data-role="last-refreshed"]');

        var state = {
            models: [],
            selectedModelId: null,
            scope: scopeSelect ? scopeSelect.value : 'global',
            scopeId: null,
            trendChart: null,
            comparisonChart: null,
            snapshotCache: {},
            currentRange: null
        };

        function disableControls() {
            [modelSelect, timeframeSelect, startDateInput, endDateInput, scopeSelect, scopeIdInput, refreshButton].forEach(function (element) {
                if (element) {
                    element.setAttribute('disabled', 'disabled');
                }
            });
        }

        function enableControls() {
            [modelSelect, timeframeSelect, scopeSelect, refreshButton].forEach(function (element) {
                if (element) {
                    element.removeAttribute('disabled');
                }
            });
            updateDateInputs();
            updateScopeFieldState();
        }

        function setLoading(isLoading) {
            if (isLoading) {
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

        function clearError() {
            hideAlert(errorAlert);
        }

        function showError(message) {
            showAlert(errorAlert, message);
        }

        function setPermissionDenied(message) {
            if (permissionAlert) {
                permissionAlert.innerHTML = '';
                var icon = document.createElement('span');
                icon.className = 'fui-alert';
                permissionAlert.appendChild(icon);
                permissionAlert.appendChild(document.createTextNode(' ' + message));
                permissionAlert.style.display = 'block';
            }
            disableControls();
        }

        function updateLastRefreshed() {
            if (!lastRefreshed) {
                return;
            }

            var now = new Date();
            lastRefreshed.textContent = 'Last refreshed ' + now.toLocaleString();
        }

        function updateDateInputs() {
            if (!timeframeSelect || !startDateInput || !endDateInput) {
                return;
            }

            var isCustom = timeframeSelect.value === 'custom';
            if (isCustom) {
                startDateInput.removeAttribute('disabled');
                endDateInput.removeAttribute('disabled');
            } else {
                startDateInput.value = '';
                endDateInput.value = '';
                startDateInput.setAttribute('disabled', 'disabled');
                endDateInput.setAttribute('disabled', 'disabled');
            }
        }

        function updateScopeFieldState() {
            if (!scopeSelect || !scopeIdInput) {
                state.scopeId = null;
                return;
            }

            var option = scopeSelect.options[scopeSelect.selectedIndex];
            var requiresId = option && option.getAttribute('data-requires-id') === '1';
            if (requiresId) {
                scopeIdInput.removeAttribute('disabled');
                if (scopeIdInput.value !== '') {
                    state.scopeId = parseInt(scopeIdInput.value, 10);
                    if (Number.isNaN(state.scopeId)) {
                        state.scopeId = null;
                    }
                } else {
                    state.scopeId = null;
                }
            } else {
                scopeIdInput.value = '';
                scopeIdInput.setAttribute('disabled', 'disabled');
                state.scopeId = null;
            }
        }

        function resolveRange() {
            if (!timeframeSelect) {
                return null;
            }

            var selection = timeframeSelect.value;
            if (selection === 'custom') {
                var startValue = startDateInput ? startDateInput.value : '';
                var endValue = endDateInput ? endDateInput.value : '';
                if (!startValue || !endValue) {
                    showError('Select both start and end dates for the custom range.');
                    return null;
                }

                var startUnix = toUnixTimestamp(startValue, false);
                var endUnix = toUnixTimestamp(endValue, true);
                if (startUnix === null || endUnix === null) {
                    showError('Enter valid dates for the custom range.');
                    return null;
                }

                if (startUnix > endUnix) {
                    showError('The start date must be earlier than the end date.');
                    return null;
                }

                return { start: startUnix, end: endUnix };
            }

            var hours = TIMEFRAME_PRESETS[selection] || 168;
            var end = Math.floor(Date.now() / 1000);
            var start = end - (hours * 3600);
            return { start: start, end: end };
        }

        function fetchJson(url) {
            return window.fetch(url, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            }).then(function (response) {
                var status = response.status;
                return response.text().then(function (text) {
                    var data = {};
                    if (text) {
                        try {
                            data = JSON.parse(text);
                        } catch (error) {
                            data = {};
                        }
                    }

                    if (!response.ok) {
                        var message = data && data.message ? data.message : 'Request failed with status ' + status;
                        var error = new Error(message);
                        error.status = status;
                        throw error;
                    }

                    return data;
                });
            });
        }

        function fetchSnapshots(modelId, range) {
            var params = new window.URLSearchParams({
                start_hour: String(range.start),
                end_hour: String(range.end),
                scope: state.scope,
                limit: '720'
            });

            if (state.scopeId !== null && state.scopeId !== undefined && state.scopeId !== '') {
                params.set('scope_id', String(state.scopeId));
            }

            return fetchJson(apiBase + '/models/' + modelId + '/snapshots?' + params.toString()).then(function (payload) {
                if (!payload) {
                    return [];
                }

                if (payload.error) {
                    throw new Error(payload.message || 'Unable to load attribution snapshots.');
                }

                return payload.data || [];
            });
        }

        function fetchSnapshotsWithCache(modelId, range) {
            var cacheKey = createCacheKey(modelId, range, state.scope, state.scopeId);
            if (state.snapshotCache[cacheKey]) {
                return Promise.resolve(state.snapshotCache[cacheKey]);
            }

            return fetchSnapshots(modelId, range).then(function (snapshots) {
                state.snapshotCache[cacheKey] = snapshots;
                return snapshots;
            });
        }

        function updateKpis(snapshots) {
            var totals = computeTotals(snapshots);
            kpiCards.forEach(function (card) {
                var metric = card.querySelector('.metric');
                var key = card.getAttribute('data-kpi');
                if (!metric) {
                    return;
                }

                switch (key) {
                    case 'clicks':
                        metric.textContent = formatNumber(totals.clicks);
                        break;
                    case 'conversions':
                        metric.textContent = formatNumber(totals.conversions);
                        break;
                    case 'revenue':
                        metric.textContent = formatCurrency(totals.revenue);
                        break;
                    case 'cost':
                        metric.textContent = formatCurrency(totals.cost);
                        break;
                    case 'roi':
                        metric.textContent = formatRoi(totals.roi);
                        break;
                    default:
                        metric.textContent = '–';
                }
            });
        }

        function updateEmptyState(element, hasData) {
            if (!element) {
                return;
            }

            element.style.display = hasData ? 'none' : 'block';
        }

        function renderTrendChart(snapshots) {
            if (!Highcharts || typeof Highcharts.chart !== 'function') {
                return;
            }

            var revenueSeries = [];
            var costSeries = [];
            var conversionSeries = [];

            (snapshots || []).forEach(function (snapshot) {
                var timestamp = Number(snapshot.date_hour) * 1000;
                revenueSeries.push([timestamp, Number(snapshot.attributed_revenue) || 0]);
                costSeries.push([timestamp, Number(snapshot.attributed_cost) || 0]);
                conversionSeries.push([timestamp, Number(snapshot.attributed_conversions) || 0]);
            });

            if (!state.trendChart) {
                state.trendChart = Highcharts.chart('touch-credit-chart', {
                    chart: { zoomType: 'x' },
                    title: { text: null },
                    xAxis: { type: 'datetime' },
                    yAxis: [{
                        title: { text: 'Revenue / Cost' },
                        labels: {
                            formatter: function () {
                                return '$' + this.value;
                            }
                        }
                    }, {
                        title: { text: 'Conversions' },
                        opposite: true
                    }],
                    legend: { enabled: true },
                    tooltip: {
                        shared: true,
                        xDateFormat: '%b %e, %Y %H:%M'
                    },
                    series: [{
                        name: 'Revenue',
                        type: 'spline',
                        data: revenueSeries,
                        tooltip: { valuePrefix: '$' }
                    }, {
                        name: 'Cost',
                        type: 'spline',
                        data: costSeries,
                        tooltip: { valuePrefix: '$' }
                    }, {
                        name: 'Conversions',
                        type: 'spline',
                        yAxis: 1,
                        data: conversionSeries
                    }]
                });
                return;
            }

            state.trendChart.series[0].setData(revenueSeries, false);
            state.trendChart.series[1].setData(costSeries, false);
            state.trendChart.series[2].setData(conversionSeries, false);
            state.trendChart.redraw();
        }

        function renderComparisonChart(entries) {
            if (!Highcharts || typeof Highcharts.chart !== 'function') {
                return;
            }

            if (!entries || entries.length === 0) {
                updateEmptyState(comparisonEmpty, false);
                if (state.comparisonChart) {
                    state.comparisonChart.destroy();
                    state.comparisonChart = null;
                }
                return;
            }

            updateEmptyState(comparisonEmpty, true);

            var categories = entries.map(function (entry) { return entry.name; });
            var revenueData = entries.map(function (entry) {
                var value = Number(entry.totals.revenue) || 0;
                return {
                    y: value,
                    color: entry.modelId === state.selectedModelId ? '#1abc9c' : undefined
                };
            });
            var costData = entries.map(function (entry) {
                var value = Number(entry.totals.cost) || 0;
                return {
                    y: value,
                    color: entry.modelId === state.selectedModelId ? '#16a085' : undefined
                };
            });
            var roiData = entries.map(function (entry) {
                var roi = entry.totals.roi;
                return roi === null || Number.isNaN(roi) ? null : Number(roi);
            });

            if (!state.comparisonChart) {
                state.comparisonChart = Highcharts.chart('model-comparison-chart', {
                    chart: { zoomType: 'xy' },
                    title: { text: null },
                    xAxis: [{ categories: categories, crosshair: true }],
                    yAxis: [{
                        title: { text: 'Revenue / Cost' },
                        labels: {
                            formatter: function () {
                                return '$' + this.value;
                            }
                        }
                    }, {
                        title: { text: 'ROI %' },
                        labels: {
                            format: '{value}%'
                        },
                        opposite: true
                    }],
                    tooltip: { shared: true },
                    legend: { enabled: true },
                    series: [{
                        name: 'Revenue',
                        type: 'column',
                        data: revenueData,
                        tooltip: { valuePrefix: '$' }
                    }, {
                        name: 'Cost',
                        type: 'column',
                        data: costData,
                        tooltip: { valuePrefix: '$' }
                    }, {
                        name: 'ROI',
                        type: 'spline',
                        yAxis: 1,
                        data: roiData,
                        tooltip: { valueSuffix: '%' }
                    }]
                });
                return;
            }

            state.comparisonChart.xAxis[0].setCategories(categories, false);
            state.comparisonChart.series[0].setData(revenueData, false);
            state.comparisonChart.series[1].setData(costData, false);
            state.comparisonChart.series[2].setData(roiData, false);
            state.comparisonChart.redraw();
        }

        function loadModelComparisons(range) {
            if (!state.models || state.models.length === 0) {
                renderComparisonChart([]);
                return Promise.resolve();
            }

            var targets = state.models.slice(0, MAX_COMPARISON_MODELS);
            var promises = targets.map(function (model) {
                return fetchSnapshotsWithCache(model.model_id, range).then(function (snapshots) {
                    return {
                        modelId: model.model_id,
                        name: model.name,
                        totals: computeTotals(snapshots)
                    };
                }).catch(function (error) {
                    if (error && error.status === 403) {
                        setPermissionDenied('You do not have permission to compare attribution models.');
                        throw error;
                    }

                    showError(error.message || 'Failed to load comparison data.');
                    return null;
                });
            });

            return Promise.all(promises).then(function (results) {
                var filtered = results.filter(function (entry) { return entry !== null; });
                renderComparisonChart(filtered);
            });
        }

        function applyFilters() {
            if (!state.selectedModelId) {
                return;
            }

            clearError();
            var range = resolveRange();
            if (!range) {
                return;
            }

            state.currentRange = range;
            setLoading(true);

            fetchSnapshotsWithCache(state.selectedModelId, range).then(function (snapshots) {
                updateKpis(snapshots);
                renderTrendChart(snapshots);
                updateEmptyState(trendEmpty, snapshots && snapshots.length > 0);
                updateLastRefreshed();
                return loadModelComparisons(range);
            }).catch(function (error) {
                if (error && error.status === 403) {
                    setPermissionDenied('You do not have permission to view attribution data for this selection.');
                    return;
                }

                showError(error && error.message ? error.message : 'Attribution data could not be loaded.');
                renderTrendChart([]);
                updateEmptyState(trendEmpty, false);
            }).finally(function () {
                setLoading(false);
            });
        }

        function loadModels() {
            setLoading(true);
            clearError();

            fetchJson(apiBase + '/models').then(function (payload) {
                if (!payload || payload.error) {
                    throw new Error(payload && payload.message ? payload.message : 'Unable to load attribution models.');
                }

                var models = (payload.data || []).filter(function (model) {
                    return model.is_active !== false;
                });

                state.models = models;

                if (!modelSelect) {
                    return;
                }

                modelSelect.innerHTML = '';
                if (models.length === 0) {
                    var placeholder = document.createElement('option');
                    placeholder.textContent = 'No models available';
                    placeholder.setAttribute('disabled', 'disabled');
                    placeholder.setAttribute('selected', 'selected');
                    modelSelect.appendChild(placeholder);
                    disableControls();
                    showError('Create an attribution model to begin analyzing performance.');
                    return;
                }

                var defaultModel = models.find(function (model) { return model.is_default; }) || models[0];
                models.forEach(function (model) {
                    var option = document.createElement('option');
                    option.value = model.model_id;
                    option.textContent = model.name;
                    if (defaultModel && Number(model.model_id) === Number(defaultModel.model_id)) {
                        option.setAttribute('selected', 'selected');
                    }
                    modelSelect.appendChild(option);
                });

                state.selectedModelId = Number(defaultModel.model_id);
                if (modelHelper) {
                    modelHelper.style.display = 'none';
                }

                enableControls();
                applyFilters();
            }).catch(function (error) {
                if (error && error.status === 403) {
                    setPermissionDenied('You do not have permission to access attribution models.');
                    return;
                }

                disableControls();
                showError(error && error.message ? error.message : 'Failed to load attribution models.');
            }).finally(function () {
                setLoading(false);
            });
        }

        if (!hasPermission) {
            disableControls();
            return;
        }

        updateScopeFieldState();
        updateDateInputs();

        if (timeframeSelect) {
            timeframeSelect.addEventListener('change', function () {
                updateDateInputs();
                if (timeframeSelect.value !== 'custom') {
                    applyFilters();
                }
            });
        }

        function maybeApplyCustomRange() {
            if (timeframeSelect && timeframeSelect.value === 'custom' && startDateInput && endDateInput && startDateInput.value && endDateInput.value) {
                applyFilters();
            }
        }

        if (startDateInput) {
            startDateInput.addEventListener('change', maybeApplyCustomRange);
        }

        if (endDateInput) {
            endDateInput.addEventListener('change', maybeApplyCustomRange);
        }

        if (modelSelect) {
            modelSelect.addEventListener('change', function () {
                var value = modelSelect.value;
                state.selectedModelId = value ? Number(value) : null;
                applyFilters();
            });
        }

        if (scopeSelect) {
            scopeSelect.addEventListener('change', function () {
                state.scope = scopeSelect.value;
                updateScopeFieldState();
                applyFilters();
            });
        }

        if (scopeIdInput) {
            scopeIdInput.addEventListener('change', function () {
                var value = scopeIdInput.value;
                if (value === '') {
                    state.scopeId = null;
                } else {
                    var parsed = parseInt(value, 10);
                    state.scopeId = Number.isNaN(parsed) ? null : parsed;
                }
                applyFilters();
            });
        }

        if (refreshButton) {

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
                        // Keep the selected slugs visible even if backend omits the models.
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
                // Focus fallback when models fail to load.
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
