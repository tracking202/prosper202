(function (window, document) {
    'use strict';

    function formatCurrency(value) {
        if (typeof value !== 'number') {
            return '—';
        }

        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: 'USD',
            maximumFractionDigits: 2
        }).format(value);
    }

    function formatNumber(value) {
        if (typeof value !== 'number') {
            return '—';
        }

        return new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 }).format(value);
    }

    function formatPercent(value) {
        if (typeof value !== 'number' || Number.isNaN(value)) {
            return '—';
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
})(window, document);
