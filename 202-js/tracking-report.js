(function (window, document, $) {
    'use strict';

    var config = window.tracking202ReportCanaryConfig || null;
    if (!config || typeof window.loadContent !== 'function') {
        return;
    }

    var legacyLoadContent = window.loadContent;
    var state = {
        legacyMode: !config.enabled,
        firstLoadPending: true,
    };

    function normalizePath(url) {
        if (!url) {
            return '';
        }

        var anchor = document.createElement('a');
        anchor.href = url;

        return (anchor.pathname || '').replace(/\/+$/, '');
    }

    function matchesLegacyPage(page) {
        var candidate = page || config.legacyPageUrl;

        return normalizePath(candidate) === normalizePath(config.legacyPageUrl);
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function decodeHtmlEntities(value) {
        var textarea = document.createElement('textarea');
        textarea.innerHTML = String(value || '');

        return textarea.value;
    }

    function safeTone(value) {
        switch (value) {
            case 'primary':
            case 'important':
            case 'info':
            case 'default':
                return value;
            default:
                return 'default';
        }
    }

    function normalizeOffset(offset) {
        if (offset === null || offset === undefined || offset === '') {
            return 0;
        }

        var numericOffset = parseInt(offset, 10);
        if (isNaN(numericOffset) || numericOffset < 0) {
            return 0;
        }

        return numericOffset;
    }

    function normalizeOrder(order) {
        if (typeof order !== 'string') {
            return '';
        }

        return order;
    }

    function showLoading() {
        $('#m-content').html(
            '<div class="loading-stats">' +
                '<span class="infotext">Loading stats...</span> ' +
                '<img src="' + escapeHtml(config.loaderUrl) + '">' +
            '</div>'
        );
    }

    function initializeSelect2(selector) {
        if (!$ || !$.fn || typeof $.fn.select2 !== 'function') {
            return;
        }

        var element = $(selector);
        if (element.length === 0) {
            return;
        }

        element.select2();
    }

    function renderFragment(targetId, html) {
        if (!targetId) {
            return;
        }

        $('#' + targetId).html(html || '');
    }

    function normalizedDependentFilterConfig() {
        var dependentFilters = config.dependentFilters || {};
        var methodOfPromotion = dependentFilters.methodOfPromotion || '';

        if (methodOfPromotion === 'landingpage') {
            methodOfPromotion = 'landingpages';
        }

        return {
            jsonBootstrap: !!dependentFilters.jsonBootstrap,
            publisherHidden: !!dependentFilters.publisherHidden,
            affNetworkId: dependentFilters.affNetworkId || '',
            affCampaignId: dependentFilters.affCampaignId || '',
            textAdId: dependentFilters.textAdId || '',
            landingPageId: dependentFilters.landingPageId || '',
            methodOfPromotion: methodOfPromotion
        };
    }

    function bootstrapLegacyDependentFilters() {
        var dependentFilters = normalizedDependentFilterConfig();

        if (!dependentFilters.jsonBootstrap || dependentFilters.publisherHidden) {
            return;
        }

        if (dependentFilters.affCampaignId && typeof window.load_aff_campaign_id === 'function') {
            window.load_aff_campaign_id(dependentFilters.affNetworkId, dependentFilters.affCampaignId);
        }

        if (dependentFilters.textAdId && typeof window.load_text_ad_id === 'function') {
            window.load_text_ad_id(dependentFilters.affCampaignId, dependentFilters.textAdId);
        }

        if (dependentFilters.textAdId && typeof window.load_ad_preview === 'function') {
            window.load_ad_preview(dependentFilters.textAdId);
        }

        if (
            dependentFilters.landingPageId &&
            dependentFilters.methodOfPromotion &&
            typeof window.load_landing_page === 'function'
        ) {
            window.load_landing_page(
                dependentFilters.affCampaignId,
                dependentFilters.landingPageId,
                dependentFilters.methodOfPromotion
            );
        }
    }

    function applyDependentFilters(dependentFilters) {
        if (!dependentFilters || !dependentFilters.requested) {
            return;
        }

        if (!dependentFilters.included) {
            bootstrapLegacyDependentFilters();
            return;
        }

        var fragments = dependentFilters.fragments || {};

        if (fragments.affCampaign) {
            renderFragment(fragments.affCampaign.targetId, fragments.affCampaign.html);
            initializeSelect2('#aff_campaign_id');
        }

        if (fragments.textAd) {
            renderFragment(fragments.textAd.targetId, fragments.textAd.html);
            initializeSelect2('#text_ad_id');
        }

        if (fragments.landingPage) {
            renderFragment(fragments.landingPage.targetId, fragments.landingPage.html);
            initializeSelect2('#landing_page_id');
        }

        if (fragments.adPreview) {
            renderFragment(fragments.adPreview.targetId, fragments.adPreview.html);
        }
    }

    function enablePermanentLegacyMode(reason) {
        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn('Tracking JSON canary falling back to legacy mode:', reason);
        }

        state.legacyMode = true;
    }

    function renderMetricCell(columnId, cell) {
        var display = decodeHtmlEntities(cell && cell.display ? cell.display : '');
        var escapedDisplay = escapeHtml(display);
        var tone = safeTone(cell && cell.tone ? cell.tone : 'default');

        if (columnId === 'income' || columnId === 'cost' || columnId === 'net' || columnId === 'roi') {
            return '<span class="label label-' + tone + '">' + escapedDisplay + '</span>';
        }

        return escapedDisplay;
    }

    function renderFeatureCell(feature) {
        var text = decodeHtmlEntities(feature && feature.text ? feature.text : '');
        var title = decodeHtmlEntities(feature && feature.title ? feature.title : text);
        var variant = feature && feature.variant ? feature.variant : 'plain_text';
        var maxWidthPx = feature && feature.maxWidthPx ? parseInt(feature.maxWidthPx, 10) : 250;
        var flagUrl = feature && feature.flagUrl ? feature.flagUrl : '';

        if (variant === 'truncated_text') {
            return '<div style="text-overflow: ellipsis; overflow: hidden; white-space: nowrap; width: ' +
                maxWidthPx +
                'px;" title="' + escapeHtml(title) + '">' + escapeHtml(text) + '</div>';
        }

        if (variant === 'flagged_location') {
            return '<img src="' + escapeHtml(flagUrl) + '"> ' + escapeHtml(text);
        }

        return escapeHtml(text);
    }

    function renderPagination(report) {
        var pagination = report && report.pagination ? report.pagination : null;
        if (!pagination || !pagination.serverPaginated) {
            return '';
        }
        // Single-page reports need no pager, but keep it when the requested offset is past
        // the end: the table is empty and the "prev" link is the only way back to real data.
        if (pagination.pageCount <= 1 && !pagination.outOfRange) {
            return '';
        }

        var parts = [
            '<div class="row">',
            '<div class="col-xs-12 text-center">',
            '<div class="pagination" id="table-pages">',
            '<ul>'
        ];

        var previousOffset = pagination.hasPreviousPage ? pagination.previousOffset : pagination.currentOffset;
        parts.push(
            '<li class="previous"><a href="#" data-tracking-report-page-link="1" data-offset="' +
                escapeHtml(previousOffset) +
                '" data-order="' +
                escapeHtml(pagination.orderToken || '') +
                '"><span class="fui-arrow-left"></span></a></li>'
        );

        var start = Math.max(pagination.currentOffset - 10, 0);
        var end = Math.min(pagination.currentOffset + 10, pagination.pageCount - 1);
        for (var i = start; i <= end; i++) {
            var activeClass = i === pagination.currentOffset ? ' class="active"' : '';
            parts.push(
                '<li' + activeClass + '><a href="#" data-tracking-report-page-link="1" data-offset="' +
                    escapeHtml(i) +
                    '" data-order="' +
                    escapeHtml(pagination.orderToken || '') +
                    '">' +
                    escapeHtml(i + 1) +
                    '</a></li>'
            );
        }

        var nextOffset = pagination.hasNextPage ? pagination.nextOffset : pagination.currentOffset;
        parts.push(
            '<li class="next"><a href="#" data-tracking-report-page-link="1" data-offset="' +
                escapeHtml(nextOffset) +
                '" data-order="' +
                escapeHtml(pagination.orderToken || '') +
                '"><span class="fui-arrow-right"></span></a></li>'
        );

        parts.push('</ul></div></div></div>');

        return parts.join('');
    }

    function renderReport(report) {
        var parts = [];

        if (report.download && report.download.url) {
            parts.push(
                '<div class="row">' +
                    '<div class="col-xs-12 text-right" style="padding-bottom: 10px;">' +
                        '<img style="margin-bottom:2px;" src="' + escapeHtml(config.excelIconUrl) + '"/>' +
                        '<a style="font-size:12px;" target="_new" href="' + escapeHtml(report.download.url) + '">' +
                            '<strong>' + escapeHtml(report.download.label || 'Download to excel') + '</strong>' +
                        '</a>' +
                    '</div>' +
                '</div>'
            );
        }

        parts.push('<table class="table table-bordered table-hover" id="stats-table">');
        parts.push('<thead><tr style="background-color: #f2fbfa;">');

        for (var i = 0; i < report.columns.length; i++) {
            var column = report.columns[i];
            if (column.id === 'feature') {
                parts.push(
                    '<th colspan="' + escapeHtml(column.colspan || 1) + '" style="text-align:left">' +
                        escapeHtml(column.label) +
                    '</th>'
                );
            } else {
                parts.push('<th>' + escapeHtml(column.label) + '</th>');
            }
        }

        parts.push('</tr></thead><tbody>');

        for (var rowIndex = 0; rowIndex < report.rows.length; rowIndex++) {
            var row = report.rows[rowIndex];
            parts.push('<tr>');
            parts.push('<td colspan="4" style="text-align:left; padding-left:10px">' + renderFeatureCell(row.feature) + '</td>');

            parts.push('<td>' + renderMetricCell('clicks', row.metrics.clicks) + '</td>');
            parts.push('<td>' + renderMetricCell('clickOut', row.metrics.clickOut) + '</td>');
            parts.push('<td>' + renderMetricCell('ctr', row.metrics.ctr) + '</td>');
            parts.push('<td>' + renderMetricCell('leads', row.metrics.leads) + '</td>');
            parts.push('<td>' + renderMetricCell('avgSu', row.metrics.avgSu) + '</td>');
            parts.push('<td>' + renderMetricCell('avgPayout', row.metrics.avgPayout) + '</td>');
            parts.push('<td>' + renderMetricCell('avgEpc', row.metrics.avgEpc) + '</td>');
            parts.push('<td>' + renderMetricCell('avgCpc', row.metrics.avgCpc) + '</td>');
            parts.push('<td>' + renderMetricCell('income', row.metrics.income) + '</td>');
            parts.push('<td>' + renderMetricCell('cost', row.metrics.cost) + '</td>');
            parts.push('<td>' + renderMetricCell('net', row.metrics.net) + '</td>');
            parts.push('<td>' + renderMetricCell('roi', row.metrics.roi) + '</td>');
            parts.push('</tr>');
        }

        var totals = report.totals;
        parts.push('<tr style="background-color: #F8F8F8;" id="totals" class="no-sort">');
        parts.push('<td colspan="4" style="text-align:left; padding-left:10px;"><strong>' + renderFeatureCell(totals.feature) + '</strong></td>');
        parts.push('<td><strong>' + renderMetricCell('clicks', totals.metrics.clicks) + '</strong></td>');
        parts.push('<td><strong>' + renderMetricCell('clickOut', totals.metrics.clickOut) + '</strong></td>');
        parts.push('<td><strong>' + renderMetricCell('ctr', totals.metrics.ctr) + '</strong></td>');
        parts.push('<td><strong>' + renderMetricCell('leads', totals.metrics.leads) + '</strong></td>');
        parts.push('<td><strong>' + renderMetricCell('avgSu', totals.metrics.avgSu) + '</strong></td>');
        parts.push('<td><strong>' + renderMetricCell('avgPayout', totals.metrics.avgPayout) + '</strong></td>');
        parts.push('<td><strong>' + renderMetricCell('avgEpc', totals.metrics.avgEpc) + '</strong></td>');
        parts.push('<td><strong>' + renderMetricCell('avgCpc', totals.metrics.avgCpc) + '</strong></td>');
        parts.push('<td><strong>' + renderMetricCell('income', totals.metrics.income) + '</strong></td>');
        parts.push('<td><strong>' + renderMetricCell('cost', totals.metrics.cost) + '</strong></td>');
        parts.push('<td><strong>' + renderMetricCell('net', totals.metrics.net) + '</strong></td>');
        parts.push('<td><strong>' + renderMetricCell('roi', totals.metrics.roi) + '</strong></td>');
        parts.push('</tr></tbody></table>');

        parts.push(renderPagination(report));

        return parts.join('');
    }

    function initializeTablesort() {
        if (typeof window.Tablesort !== 'function') {
            return;
        }

        var table = document.getElementById('stats-table');
        if (!table) {
            return;
        }

        new window.Tablesort(table, {
            descending: true
        });
    }

    function fetchAndRender(page, offset, order) {
        var requestBody = {
            reportType: config.reportType,
            offset: normalizeOffset(offset),
            order: normalizeOrder(order),
            includeDependentFilters: state.firstLoadPending
        };

        state.firstLoadPending = false;
        showLoading();

        return window.fetch(config.dispatchUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(requestBody)
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    return {
                        ok: response.ok,
                        status: response.status,
                        text: text
                    };
                });
            })
            .then(function (response) {
                var payload;

                try {
                    payload = JSON.parse(response.text);
                } catch (error) {
                    throw new Error('Dispatch response was not valid JSON.');
                }

                if (!response.ok || !payload.ok || !payload.report) {
                    var message = payload && payload.error && payload.error.message
                        ? payload.error.message
                        : 'Dispatch request failed with status ' + response.status + '.';
                    throw new Error(message);
                }

                $('#m-content').html(renderReport(payload.report));
                $('#m-content').css('opacity', '1');
                initializeTablesort();
                applyDependentFilters(payload.report.dependentFilters || null);

                return payload;
            })
            .catch(function (error) {
                enablePermanentLegacyMode(error && error.message ? error.message : 'unknown transport failure');
                bootstrapLegacyDependentFilters();
                return legacyLoadContent(page, offset, order);
            });
    }

    function interceptedLoadContent(page, offset, order) {
        if (state.legacyMode || !config.enabled || !matchesLegacyPage(page) || typeof window.fetch !== 'function') {
            // We're taking the legacy transport. When JSON mode was on at render time,
            // display_calendar() suppressed the legacy aff-campaign/text-ad/landing-page
            // bootstrap on the expectation that the JSON response would supply those
            // fragments — but on this path (most importantly when window.fetch is missing,
            // e.g. an older embedded webview) that response is never fetched, so the saved
            // dependent filters and ad preview would render blank. Hydrate them once here,
            // the same way fetchAndRender()'s failure path does. Gated on firstLoadPending
            // so legacy pagination cannot re-run the cascade, and the helper itself is a
            // no-op unless jsonBootstrap applies, so the flag-off/publisher paths are safe.
            if (state.firstLoadPending) {
                state.firstLoadPending = false;
                bootstrapLegacyDependentFilters();
            }

            return legacyLoadContent(page, offset, order);
        }

        return fetchAndRender(page, offset, order);
    }

    $('#m-content').on('click', '[data-tracking-report-page-link]', function (event) {
        if (state.legacyMode || !config.enabled) {
            return;
        }

        event.preventDefault();

        var offset = $(this).data('offset');
        var order = $(this).data('order') || '';
        fetchAndRender(config.legacyPageUrl, offset, order);
    });

    window.loadContent = interceptedLoadContent;
    window.Tracking202ReportCanary = {
        isEnabled: function () {
            return config.enabled && !state.legacyMode;
        },
        load: function (options) {
            var requestOptions = options || {};

            return fetchAndRender(
                config.legacyPageUrl,
                requestOptions.offset,
                requestOptions.order || ''
            );
        }
    };
}(window, document, window.jQuery));
