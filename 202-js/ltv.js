/*
 * Analyze › Customer LTV: the section's router, on the v2 shell.
 *
 * Ported from the LTV block of the classic shell's 202-js/custom.php (deleted
 * with that shell in U8). The views are server-rendered partials under tracking202/ajax/
 * swapped into #m-content; this keeps what the classic router did:
 *
 *  - ltvNav(view, params) renders a view AND records it in the address bar as
 *    analyze/ltv.php?view=..., so a view can be bookmarked and Back/Forward
 *    move between views (popstate re-renders);
 *  - each history entry remembers how far down it was scrolled, restored once
 *    the partial has arrived (the browser's own restoration fires too early);
 *  - loadContentPost(url, payload, done) is the one path mutations take
 *    (saves, deletes, merges), and a failed request says so in the panel
 *    instead of leaving it dimmed. Only navigation goes through ltvNav, so a
 *    bookmarked URL can never replay a mutation.
 *
 * What changed in the port: it runs from DOMContentLoaded (v2 page scripts are
 * deferred; UI standard, "the porting rule"), it knows its URLs from
 * #m-content's data attributes rather than PHP-generated JS, the loading
 * state is the component layer's skeleton, a new partial is handed to
 * p202ui.init() so its sortable tables and hints are wired, and an open
 * Bootstrap modal inside the old partial is hidden before the swap so its
 * backdrop cannot outlive it.
 */
(function () {
    'use strict';

    var views = {
        report: 'sort_ltv',
        customer: 'ltv_customer',
        company: 'ltv_company',
        companies: 'ltv_companies',
        products: 'ltv_products',
        subscriptions: 'ltv_subscriptions',
        settings: 'ltv_settings'
    };
    /* Query keys that belong to the page, not to a view: the range filter
       bar submits them, and ltv.php has already applied them. */
    var pageKeys = { view: true, range: true, from: true, to: true };

    var content = null;
    var pageUrl = '';
    var ajaxUrl = '';
    /* The window the page was opened with (range, from, to), carried on every
       view's URL so a link copied from any view still says which window it
       shows. */
    var windowQuery = [];
    /* The view ltv.php drew (ReportView): its window, as a query string.
       Every request this page makes under tracking202/ajax/ carries it, so
       a partial draws the window on screen rather than whatever another
       tab has stored since. */
    var reportView = '';

    function withView(url) {
        if (reportView === '' || typeof url !== 'string' || url.indexOf(ajaxUrl) !== 0) { return url; }
        var hash = url.indexOf('#');
        var tail = hash === -1 ? '' : url.slice(hash);
        var head = hash === -1 ? url : url.slice(0, hash);
        return head + (head.indexOf('?') === -1 ? '?' : '&') + 'view=' + encodeURIComponent(reportView) + tail;
    }

    function onLtvPage() {
        var a = document.createElement('a');
        a.href = pageUrl;
        return content !== null && window.location.pathname === a.pathname;
    }

    function showLoading() {
        content.setAttribute('aria-busy', 'true');
        content.innerHTML = '<div class="p202-skeleton mb-3" style="height: 5.5rem"></div>'
            + '<div class="p202-skeleton" style="height: 18rem"></div>';
    }

    /* Run `then` once no Bootstrap modal inside the current view is open.
       A modal whose element is swapped out while shown would leave its
       backdrop behind over the new view, so it is hidden first and the swap
       waits for it (the merge picker is the one that can be open). */
    function afterModalsClose(then) {
        var open = content.querySelector('.modal.show');
        if (!open || !window.bootstrap || !window.bootstrap.Modal) {
            then();
            return;
        }
        open.addEventListener('hidden.bs.modal', function () { then(); }, { once: true });
        window.bootstrap.Modal.getOrCreateInstance(open).hide();
    }

    function place(html) {
        afterModalsClose(function () {
            // jQuery's html() runs the partial's inline scripts, which define
            // the view's own handlers (ltvLoad, ltvCustomerSave, ...).
            window.jQuery(content).html(html);
            content.removeAttribute('aria-busy');
            if (window.p202ui && typeof window.p202ui.init === 'function') {
                window.p202ui.init(content);
            }
        });
    }

    function failed(xhr) {
        content.removeAttribute('aria-busy');
        var alert = document.createElement('div');
        alert.className = 'alert alert-danger p202-flash';
        alert.setAttribute('role', 'alert');
        alert.innerHTML = '<i class="bi bi-x-circle"></i><div class="p202-flash__body"></div>';
        alert.querySelector('.p202-flash__body').textContent = 'The request failed ('
            + (xhr && xhr.status ? 'HTTP ' + xhr.status : 'network error')
            + '). Your session may have expired — reload the page and try again.';
        content.insertBefore(alert, content.firstChild);
    }

    /* POST a payload and swap the response into #m-content. */
    function loadContentPost(url, payload, done) {
        content.setAttribute('aria-busy', 'true');
        window.jQuery.post(url, payload || {})
            .done(function (data) {
                place(data);
                // After the partial is in the DOM (place() is synchronous
                // unless a modal was open): scroll restoration needs the page
                // to have its real height first.
                if (done) { afterModalsClose(done); }
            })
            .fail(failed);
    }

    function render(view, params, done) {
        afterModalsClose(function () {
            showLoading();
            loadContentPost(ajaxUrl + (views[view] || views.report) + '.php', params || {}, done);
        });
    }

    function stampScroll() {
        if (!(window.history && window.history.replaceState)) { return; }
        var state = window.history.state || {};
        state.ltvScroll = window.pageYOffset || document.documentElement.scrollTop || 0;
        window.history.replaceState(state, '', window.location.href);
    }

    function ltvUrl(view, params) {
        var query = windowQuery.slice();
        if (view !== 'report') {
            query.push('view=' + encodeURIComponent(view));
        }
        Object.keys(params || {}).forEach(function (key) {
            var value = params[key];
            // Zero/empty values are the defaults everywhere in this section
            // (offset 0, no status filter): left out of bookmarks.
            if (value === undefined || value === null || value === '' || value === 0 || value === '0') { return; }
            query.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
        });
        return pageUrl + (query.length ? '?' + query.join('&') : '');
    }

    function ltvNav(view, params, replace) {
        params = params || {};
        if (!views[view]) { view = 'report'; }
        // A partial can only render inside ltv.php's #m-content; anywhere
        // else, follow the deep link as a page load.
        if (!onLtvPage()) {
            window.location.href = ltvUrl(view, params);
            return;
        }
        stampScroll();
        var previous = (window.history && window.history.state && window.history.state.ltvView) || null;
        var toTop = previous !== null && previous !== view;
        render(view, params, toTop ? function () { window.scrollTo(0, 0); } : null);
        if (window.history && window.history.pushState) {
            var url = ltvUrl(view, params);
            var method = (replace || window.location.href === url) ? 'replaceState' : 'pushState';
            window.history[method]({ ltvView: view, ltvParams: params }, '', url);
        }
    }

    function parseQuery() {
        var params = {};
        new URLSearchParams(window.location.search).forEach(function (value, key) {
            if (key !== '') { params[key] = value; }
        });
        return params;
    }

    function viewFromLocation() {
        var params = parseQuery();
        // ?customer_id=N without a view is the legacy deep-link form.
        var view = params.view || (params.customer_id ? 'customer' : 'report');
        Object.keys(pageKeys).forEach(function (key) { delete params[key]; });
        return { view: view, params: params };
    }

    window.loadContentPost = loadContentPost;
    window.ltvNav = ltvNav;
    window.ltvUrl = ltvUrl;
    window.ltvWithView = withView;
    window.ltvViewFromLocation = viewFromLocation;

    function start() {
        content = document.getElementById('m-content');
        if (!content || !content.hasAttribute('data-ltv-page')) {
            content = null;
            return;
        }
        pageUrl = content.getAttribute('data-ltv-page');
        ajaxUrl = content.getAttribute('data-ltv-ajax');
        reportView = content.getAttribute('data-ltv-view') || '';
        // Every request under the partials' directory: the router's own,
        // and the ones a partial's inline script makes ($.post to its own
        // URL, the merge search).
        if (window.jQuery && window.jQuery.ajaxPrefilter) {
            window.jQuery.ajaxPrefilter(function (options) {
                options.url = withView(options.url);
            });
        }
        new URLSearchParams(window.location.search).forEach(function (value, key) {
            if (key !== 'view' && pageKeys[key]) {
                windowQuery.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
            }
        });

        // The page owns its scroll restoration: content arrives after load.
        if (window.history && 'scrollRestoration' in window.history) {
            window.history.scrollRestoration = 'manual';
        }

        window.addEventListener('popstate', function (event) {
            if (!onLtvPage()) { return; }
            var y = (event.state && typeof event.state.ltvScroll === 'number') ? event.state.ltvScroll : 0;
            var restore = function () { window.scrollTo(0, y); };
            if (event.state && event.state.ltvView) {
                render(event.state.ltvView, event.state.ltvParams || {}, restore);
            } else {
                var target = viewFromLocation();
                render(target.view, target.params, restore);
            }
        });

        // Keep each entry's offset current while the reader scrolls
        // (debounced: replaceState is rate-limited in some browsers).
        var timer = null;
        window.addEventListener('scroll', function () {
            if (timer) { window.clearTimeout(timer); }
            timer = window.setTimeout(stampScroll, 150);
        });

        // Whatever view the URL encodes (bookmark, reload, deep link, legacy
        // ?customer_id=N); replace-seed the entry so Back from the first
        // view still returns to the referring page.
        var initial = viewFromLocation();
        ltvNav(initial.view, initial.params, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
