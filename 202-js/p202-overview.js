/*
 * The Overview, Visitors and Spy pages on the v2 shell.
 *
 * Each page renders its filters and an empty report panel; this draws the
 * report into it from the page's AJAX fragment, and wires what the fragment
 * brings with it. Markup opts in with data attributes, and every handler is
 * delegated, so a fragment swapped in later is covered without re-binding:
 *
 *   [data-p202-report="<url>"]   a report panel: POSTs to the fragment with the
 *                                page offset and draws the answer in place
 *   [data-p202-offset="n"]       on a page link: reload the report at page n,
 *                                and put it in the address bar
 *   [data-p202-spy]              on a report panel: the live view. The first
 *                                load draws the table; every five seconds
 *                                after, only clicks newer than the newest one
 *                                shown are fetched and put on top
 *   [data-p202-snippet="<url>"]  an optional panel (the LTV strip): loaded
 *                                once, removed when the fragment has nothing
 *   [data-p202-chart]            a Highcharts config as JSON, drawn in place
 *   [data-p202-chart-range]      radios choosing hours or days for that chart
 *   #p202-build-chart            the chart builder's form, in its modal
 *
 * jQuery is used for the requests, as the classic reports did: the v2 shell
 * attaches the session token to every same-origin jQuery POST (template.php),
 * and jQuery runs the inline scripts of a fragment it inserts. Everything
 * runs from DOMContentLoaded, after the deferred libraries (Highcharts,
 * Tablesort, p202-ui.js) have loaded — the porting rule in ui-standard.md.
 */
(function () {
    'use strict';

    var SPY_INTERVAL = 5000;
    var SPY_MAX_ROWS = 200;

    function escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* A failed request says what failed in place of the report, in the kit's
       flash, rather than leaving a skeleton that never resolves. */
    function failure(panel, xhr) {
        var status = xhr && xhr.status ? 'HTTP ' + xhr.status : 'no answer from the server';
        panel.setAttribute('aria-busy', 'false');
        panel.innerHTML = '<div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-x-circle"></i>'
            + '<div class="p202-flash__body">The report could not be loaded (' + escapeHtml(status) + '). '
            + 'Your session may have expired; reload the page and try again.</div></div>';
    }

    /* Everything a freshly drawn fragment needs: tooltips, sortable tables,
       remembered disclosures (p202-ui.js), and any chart it carries. */
    function prepare(root) {
        if (window.p202ui) {
            window.p202ui.init(root);
        }
        Array.prototype.forEach.call(root.querySelectorAll('[data-p202-chart]'), drawChart);
    }

    function load(panel, offset) {
        var url = panel.getAttribute('data-p202-report');
        panel.setAttribute('aria-busy', 'true');
        panel.style.opacity = '0.6';
        return window.jQuery.post(url, { offset: offset || 0 })
            .done(function (html) {
                window.jQuery(panel).html(html);
                panel.style.opacity = '';
                panel.setAttribute('aria-busy', 'false');
                panel.setAttribute('data-p202-offset', String(offset || 0));
                prepare(panel);
            })
            .fail(function (xhr) {
                panel.style.opacity = '';
                failure(panel, xhr);
            });
    }

    /* ── Charts ─────────────────────────────────────────────────────── */

    function drawChart(element) {
        var config;
        try {
            config = JSON.parse(element.getAttribute('data-p202-chart') || '{}');
        } catch (error) {
            element.textContent = 'The chart could not be read.';
            return;
        }
        window.Highcharts.chart(element, config);
    }

    document.addEventListener('change', function (event) {
        var radio = event.target;
        if (!radio || !radio.matches || !radio.matches('[data-p202-chart-range]')) {
            return;
        }
        var chart = document.getElementById(radio.getAttribute('data-p202-chart-range'));
        if (!chart) {
            return;
        }
        chart.style.opacity = '0.5';
        window.jQuery.post(radio.getAttribute('data-p202-chart-url'), { chart_time_range: radio.value }, null, 'json')
            .done(function (data) {
                var config = JSON.parse(chart.getAttribute('data-p202-chart') || '{}');
                config.title = { text: data.title };
                config.xAxis = { categories: data.categories };
                config.series = data.json.series;
                chart.setAttribute('data-p202-chart', JSON.stringify(config));
                drawChart(chart);
                chart.style.opacity = '';
            })
            .fail(function (xhr) {
                chart.style.opacity = '';
                chart.innerHTML = '<div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-x-circle"></i>'
                    + '<div class="p202-flash__body">The chart could not be redrawn (' + escapeHtml(xhr && xhr.status ? 'HTTP ' + xhr.status : 'no answer') + ').</div></div>';
            });
    });

    /* The chart builder: one row per line on the chart, a campaign and a
       figure each. "Add a line" copies the first row; a row's remove button
       takes it out, but the last row stays. */
    document.addEventListener('click', function (event) {
        var target = event.target.closest ? event.target.closest('[data-p202-chart-add], [data-p202-chart-remove]') : null;
        if (!target) {
            return;
        }
        event.preventDefault();
        var form = document.getElementById('p202-build-chart');
        if (!form) {
            return;
        }
        var rows = form.querySelectorAll('[data-p202-chart-line]');
        if (target.hasAttribute('data-p202-chart-add')) {
            var copy = rows[0].cloneNode(true);
            Array.prototype.forEach.call(copy.querySelectorAll('select'), function (select) {
                select.selectedIndex = 0;
            });
            rows[rows.length - 1].after(copy);
        } else if (rows.length > 1) {
            target.closest('[data-p202-chart-line]').remove();
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.id !== 'p202-build-chart') {
            return;
        }
        event.preventDefault();
        var levels = [];
        var types = [];
        Array.prototype.forEach.call(form.querySelectorAll('[data-p202-chart-line]'), function (row) {
            levels.push({ id: row.querySelector('select[name="data_level[]"]').value });
            types.push({ type: row.querySelector('select[name="data_type[]"]').value });
        });
        var submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        window.jQuery.post(form.getAttribute('action'), { levels: levels, types: types })
            .done(function () {
                var modal = form.closest('.modal');
                if (modal && window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).hide();
                }
                var panel = document.querySelector('[data-p202-report]');
                if (panel) {
                    /* The modal lives inside the report; redraw once it has
                       finished closing so Bootstrap is not left holding a
                       backdrop for an element that is gone. */
                    var redraw = function () { load(panel, 0); };
                    if (modal) {
                        modal.addEventListener('hidden.bs.modal', redraw, { once: true });
                    } else {
                        redraw();
                    }
                }
            })
            .fail(function (xhr) {
                submit.disabled = false;
                var body = form.querySelector('[data-p202-chart-error]');
                if (body) {
                    body.hidden = false;
                    body.textContent = 'The chart was not saved (' + (xhr && xhr.status ? 'HTTP ' + xhr.status : 'no answer from the server') + '). Try again.';
                }
            });
    });

    /* ── Pages of a report ──────────────────────────────────────────── */

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('[data-p202-offset]') : null;
        if (!link || link.hasAttribute('data-p202-report')) {
            return;
        }
        var panel = link.closest('[data-p202-report]');
        if (!panel) {
            return;
        }
        event.preventDefault();
        var offset = parseInt(link.getAttribute('data-p202-offset'), 10) || 0;
        load(panel, offset).done(function () {
            if (window.history && window.history.replaceState && window.URL) {
                var url = new URL(window.location.href);
                if (offset > 0) {
                    url.searchParams.set('offset', String(offset));
                } else {
                    url.searchParams.delete('offset');
                }
                window.history.replaceState(window.history.state, '', url.toString());
            }
            panel.closest('.p202-panel').scrollIntoView({ block: 'start' });
        });
    });

    /* ── Spy ────────────────────────────────────────────────────────── */

    function spy(panel) {
        var url = panel.getAttribute('data-p202-report');
        var latestTime = 0;
        var latestId = 0;
        var inFlight = null;
        var status = document.querySelector('[data-p202-spy-status]');

        function cursor(root) {
            var marker = root.querySelector('[data-p202-spy-latest]');
            if (marker) {
                var time = parseInt(marker.getAttribute('data-time'), 10);
                var id = parseInt(marker.getAttribute('data-id'), 10);
                if (time > latestTime || (time === latestTime && id > latestId)) {
                    latestTime = time;
                    latestId = id;
                }
            }
        }

        function setStatus(ok) {
            if (!status) {
                return;
            }
            status.className = 'p202-pill ' + (ok ? 'p202-pill--good' : 'p202-pill--warn');
            status.textContent = ok ? 'live · last 24 hours' : 'reconnecting…';
        }

        function poll() {
            /* The first, full load is never abandoned; a newer poll only
               replaces an older incremental one still waiting. */
            if (inFlight && inFlight.readyState !== 4) {
                if (latestTime === 0) {
                    return;
                }
                inFlight.abort();
            }
            var params = {};
            if (latestTime > 0) {
                params.since = latestTime;
                params.since_id = latestId;
            }
            inFlight = window.jQuery.get(url, params)
                .done(function (html) {
                    setStatus(true);
                    var body = panel.querySelector('tbody');
                    if (latestTime === 0 || !body) {
                        /* The first load, or an empty state still waiting
                           for its first click: the whole table, as the
                           fragment draws it. */
                        window.jQuery(panel).html(html);
                        panel.setAttribute('aria-busy', 'false');
                        prepare(panel);
                        cursor(panel);
                        return;
                    }
                    /* New rows arrive as bare <tr>s, and the newest-click
                       marker as a hidden row, so a table body parses them. */
                    var holder = document.createElement('tbody');
                    holder.innerHTML = html;
                    cursor(holder);
                    var rows = Array.prototype.filter.call(holder.querySelectorAll('tr[data-click-id]'), function (row) {
                        return !body.querySelector('tr[data-click-id="' + row.getAttribute('data-click-id') + '"]');
                    });
                    rows.reverse().forEach(function (row) {
                        row.classList.add('table-active');
                        body.insertBefore(row, body.firstChild);
                        window.setTimeout(function () { row.classList.remove('table-active'); }, 4000);
                        prepare(row);
                    });
                    var all = body.querySelectorAll('tr[data-click-id]');
                    for (var i = SPY_MAX_ROWS; i < all.length; i++) {
                        all[i].remove();
                    }
                })
                .fail(function (xhr, textStatus) {
                    if (textStatus !== 'abort') {
                        setStatus(false);
                        if (latestTime === 0 && !panel.querySelector('tbody')) {
                            failure(panel, xhr);
                        }
                    }
                });
        }

        poll();
        window.setInterval(poll, SPY_INTERVAL);
    }

    /* ── Start ──────────────────────────────────────────────────────── */

    function start() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-p202-report]'), function (panel) {
            if (panel.hasAttribute('data-p202-spy')) {
                spy(panel);
            } else {
                load(panel, parseInt(panel.getAttribute('data-p202-offset'), 10) || 0);
            }
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-p202-snippet]'), function (holder) {
            /* An (empty) object, not nothing: the shell's token prefilter
               runs after jQuery has serialised the data, and a request with
               no data at all gets an object where jQuery expects a string. */
            window.jQuery.post(holder.getAttribute('data-p202-snippet'), {})
                .done(function (html) {
                    if (String(html).trim() === '') {
                        holder.remove();
                        return;
                    }
                    window.jQuery(holder).html(html);
                    prepare(holder);
                })
                .fail(function () {
                    holder.remove();
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
