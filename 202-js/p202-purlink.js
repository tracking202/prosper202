/* PurLink template rendering. Initialized by the landing-page tracking script. */
(function (window, document) {
    'use strict';

    var options = null;
    var managed = new WeakMap();
    var listening = false;
    var observer = null;
    var inputTemplates = new WeakMap();
    var preparation = Object.create(null);
    var generation = 0;
    var tokenPattern = /(?:\[\[|%5b%5b)([a-z0-9_]+)(?:\]\]|%5d%5d)/gi;
    var legacyNames = {
        t202kw: 't202Kw', t202pubid: 't202Pubid', c1: 't202C1', c2: 't202C2',
        c3: 't202C3', c4: 't202C4', random: 't202Random', referer: 't202Referrer',
        referrer: 't202Referrer', sourceid: 't202SourceId', gclid: 't202GCLID',
        msclkid: 't202MSCLKID', fbclid: 't202FBCLID', utm_source: 't202Utm_source',
        utm_medium: 't202Utm_medium', utm_campaign: 't202Utm_campaign',
        utm_term: 't202Utm_term', utm_content: 't202Utm_content'
    };

    function encode(value) {
        return encodeURIComponent(String(value)).replace(/[!'()*]/g, function (ch) {
            return '%' + ch.charCodeAt(0).toString(16).toUpperCase();
        }).replace(/%40/g, '@');
    }

    function templateOf(element, attribute) {
        var value = element.getAttribute(attribute);
        var state = managed.get(element);
        var saved = element.getAttribute('data-t202rawlink');
        if (state && state.attribute === attribute) {
            if (saved && saved !== state.template) { return saved; }
            return value === state.rendered ? state.template : (value || '');
        }
        return saved || value || '';
    }

    function offerOf(template) {
        try {
            var parsed = new URL(template, document.baseURI);
            var offer = parsed.searchParams.get('acip');
            return offer && /^[1-9][0-9]*$/.test(offer) ? offer : '';
        } catch (e) {
            return '';
        }
    }

    var subidPattern = /(?:\[\[|%5b%5b)subid(?:\]\]|%5d%5d)/i;

    function hasKnownToken(template) {
        var found = false;
        template.replace(tokenPattern, function (match, name) {
            var key = name.toLowerCase();
            if (legacyNames[key] || Object.prototype.hasOwnProperty.call(options.tokens || {}, key)) { found = true; }
            return match;
        });
        return found;
    }

    // As in 1.9.56: a link carrying [[subid]] or any other known token is a
    // PurLink. Links into the tracker itself are left to its own redirects.
    function isManaged(element, template) {
        if (typeof options.trackerBase === 'string' && options.trackerBase !== '' &&
            template.indexOf(options.trackerBase) === 0) { return false; }
        return element.hasAttribute('data-p202-purlink') ||
            (typeof options.simpleTemplate === 'string' && options.simpleTemplate !== '' &&
                template === options.simpleTemplate) ||
            subidPattern.test(template) || hasKnownToken(template);
    }

    function replaceTokens(template, subid, encodeValues) {
        var values = options.tokens || {};
        var legacy = window.dcs && window.dcs.t202DataObj;
        return template.replace(tokenPattern, function (match, name) {
            var key = name.toLowerCase();
            var value;
            if (key === 'subid') { value = subid; }
            else if (legacy && legacyNames[key] && Object.prototype.hasOwnProperty.call(legacy, legacyNames[key])) {
                value = legacy[legacyNames[key]];
            } else if (Object.prototype.hasOwnProperty.call(values, key)) { value = values[key]; }
            if (value == null) { return match; }
            return encodeValues ? encode(value) : String(value);
        });
    }

    function destination(template, subid) {
        var result = replaceTokens(template, subid, true);
        try {
            var url = new URL(result, document.baseURI);
            return url.protocol === 'http:' || url.protocol === 'https:' ? result : null;
        } catch (e) {
            return null;
        }
    }

    function updateFormInputs(form, subid, direct) {
        var inputs = form.querySelectorAll('input[name],select[name],textarea[name],button[name]');
        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            if (typeof input.value !== 'string' || input.hasAttribute('data-p202-purlink-input')) { continue; }
            var previous = inputTemplates.get(input);
            var template = previous && input.value === previous.rendered ? previous.template : input.value;
            if (!direct) {
                // Do not submit a previous pageview's token after returning
                // to the redirect fallback (for example, after revocation).
                if (previous && input.value === previous.rendered) { input.value = previous.template; }
                inputTemplates.delete(input);
                continue;
            }
            tokenPattern.lastIndex = 0;
            if (!tokenPattern.test(template)) { inputTemplates.delete(input); continue; }
            var rendered;
            try { rendered = replaceTokens(template, subid, /^https?:\/\//i.test(template)); }
            catch (e) { continue; }
            input.value = rendered;
            inputTemplates.set(input, {template: template, rendered: rendered});
        }
    }

    function removeGenerated(state) {
        if (!state || !state.generated) { return; }
        state.generated.forEach(function (input) { input.remove(); });
    }

    function formDestination(form, rendered) {
        var generated = [];
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') {
            return {url: rendered, generated: generated};
        }
        var url = new URL(rendered, document.baseURI);
        var existing = Object.create(null);
        var controls = form.querySelectorAll('input[name],select[name],textarea[name],button[name]');
        for (var i = 0; i < controls.length; i++) {
            if (!controls[i].disabled) { existing[controls[i].getAttribute('name')] = true; }
        }
        url.searchParams.forEach(function (value, name) {
            if (existing[name]) { return; }
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            input.setAttribute('data-p202-purlink-input', '');
            form.appendChild(input);
            generated.push(input);
        });
        url.search = '';
        return {url: url.toString(), generated: generated};
    }

    // One preparation per offer per pageview. A failed preparation is
    // retried at most twice, then the ordinary redirect stays usable.
    // A response belonging to a replaced pageview cannot change this one.
    function prepareOffer(offer) {
        if (!options || typeof options.prepareOffer !== 'function') { return; }
        var state = preparation[offer];
        if (state && (state.pending || state.waiting || state.attempts >= 3)) { return; }
        if (!state) { state = preparation[offer] = {pending: false, attempts: 0}; }
        state.pending = true;
        state.attempts++;
        var pageview = generation;
        var config = options;
        var result;
        try { result = config.prepareOffer(offer); }
        catch (error) { result = Promise.reject(error); }
        Promise.resolve(result).then(function (subid) {
            if (generation !== pageview || options !== config) { return; }
            state.pending = false;
            if (typeof subid !== 'string' || !/^[1-9][0-9]{0,18}-[1-9][0-9]{0,18}-[0-9a-f]{12}$/.test(subid)) {
                throw new Error('Invalid PurLink SubID');
            }
            if (offer) {
                if (!config.offerSubids) { config.offerSubids = Object.create(null); }
                config.offerSubids[offer] = subid;
            } else { config.simpleSubid = subid; }
            refresh();
        }).catch(function () {
            if (generation !== pageview || options !== config) { return; }
            state.pending = false;
            if (state.attempts < 3 && typeof window.setTimeout === 'function') {
                state.waiting = true;
                window.setTimeout(function () {
                    if (generation === pageview && options === config) {
                        state.waiting = false;
                        prepareOffer(offer);
                    }
                }, state.attempts * 1000);
            }
        });
    }

    function refreshElement(element, attribute) {
        var template = templateOf(element, attribute);
        if (!isManaged(element, template)) {
            var prior = managed.get(element);
            removeGenerated(prior);
            if (prior && prior.ownsRaw) { element.removeAttribute('data-t202rawlink'); }
            managed.delete(element);
            return;
        }
        try {
            var templateUrl = new URL(template, document.baseURI);
            if (templateUrl.protocol !== 'http:' && templateUrl.protocol !== 'https:') { return; }
        } catch (e) { return; }
        var offer = offerOf(template);
        // Only a [[subid]] needs a prepared SubID; until it is ready the
        // tracker's redirect stands in. Other tokens render at once.
        var needsSubid = subidPattern.test(template) || !hasKnownToken(template) ||
            template === options.simpleTemplate;
        var prepared = needsSubid ? (offer ? (options.offerSubids || {})[offer] : options.simpleSubid) : null;
        if (needsSubid && !prepared) { prepareOffer(offer); }
        var subid = options.directEnabled === true ? prepared : null;
        var direct = options.directEnabled === true && (!needsSubid || !!subid);
        var rendered;
        try {
            rendered = direct ? destination(template, subid) :
                (typeof options.fallback === 'function' ? options.fallback(offer) : null);
        } catch (e) { return; }
        if (typeof rendered !== 'string' || rendered === '') { return; }
        try {
            var parsed = new URL(rendered, document.baseURI);
            if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') { return; }
        } catch (e) { return; }
        var old = managed.get(element);
        var ownsRaw = old ? old.ownsRaw : !element.hasAttribute('data-t202rawlink');
        removeGenerated(old);
        var generated = [];
        if (element.tagName === 'FORM') {
            updateFormInputs(element, subid, direct);
            var formResult = formDestination(element, rendered);
            rendered = formResult.url;
            generated = formResult.generated;
        }
        if (ownsRaw) { element.setAttribute('data-t202rawlink', template); }
        // A deferred pixel needs this page to stay open: as in 1.9.56 the
        // offer opens in a new tab, unless the page chose a target itself.
        if (options.newTab === true && direct && element.tagName === 'A' && !element.hasAttribute('target')) {
            element.setAttribute('target', '_blank');
            element.setAttribute('rel', 'noopener');
        }
        element.setAttribute(attribute, rendered);
        managed.set(element, {
            attribute: attribute, template: template, rendered: rendered,
            offer: offer, direct: direct, ownsRaw: ownsRaw, generated: generated
        });
    }

    function refresh() {
        if (!options) { return; }
        var links = document.querySelectorAll('a[href]');
        var forms = document.querySelectorAll('form[action]');
        for (var i = 0; i < links.length; i++) { refreshElement(links[i], 'href'); }
        for (var j = 0; j < forms.length; j++) { refreshElement(forms[j], 'action'); }
    }

    function report(event) {
        if (!options || typeof options.report !== 'function' || event.defaultPrevented) { return; }
        if (event.type === 'auxclick' && event.button !== 1) { return; }
        var element = event.target && event.target.closest ? event.target.closest('a,form') : null;
        if (!element || (event.type === 'submit' && element.tagName !== 'FORM') ||
            (event.type !== 'submit' && element.tagName !== 'A')) { return; }
        var state = managed.get(element);
        if (state && state.direct) {
            try { options.report(state.offer, event); } catch (e) { /* Never block navigation. */ }
            if (typeof options.afterExit === 'function') {
                try { options.afterExit(state.offer); } catch (e) { /* Never block navigation. */ }
            }
        }
    }

    // The proof stays in the request body, never in a destination or a URL.
    // Redirects are refused so a 307/308 cannot forward it to another host.
    function createPreparer(endpoint, proof) {
        var url = new URL(endpoint, document.baseURI);
        if ((url.protocol !== 'https:' && url.protocol !== 'http:') || typeof proof !== 'string' || !proof) {
            throw new Error('Invalid PurLink preparation configuration');
        }
        return function (offer) {
            if (typeof window.fetch !== 'function') { return Promise.reject(new Error('Fetch unavailable')); }
            var body = 'proof=' + encodeURIComponent(proof) + (offer ? '&campaign=' + encodeURIComponent(offer) : '');
            var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
            var timer = null;
            var timeout = new Promise(function (_, reject) {
                timer = window.setTimeout(function () {
                    if (controller) { controller.abort(); }
                    reject(new Error('PurLink preparation timed out'));
                }, 8000);
            });
            var request;
            try {
                request = window.fetch(url.toString(), {
                    method: 'POST', mode: 'cors', credentials: 'omit', cache: 'no-store', redirect: 'error',
                    referrerPolicy: 'no-referrer', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: body, signal: controller ? controller.signal : undefined
                }).then(function (response) {
                    if (!response.ok) { throw new Error('PurLink preparation refused'); }
                    return response.json();
                }).then(function (payload) { return payload && payload.subid; });
            } catch (error) { request = Promise.reject(error); }
            return Promise.race([request, timeout]).finally(function () { window.clearTimeout(timer); });
        };
    }

    // The click may navigate away immediately. keepalive lets the browser
    // finish the POST; retries reuse the same sequence and never block it.
    // A revoked/replaced pageview cancels retries through reporter.cancel().
    function createReporter(endpoint, proof, allowed) {
        var url = new URL(endpoint, document.baseURI);
        if ((url.protocol !== 'https:' && url.protocol !== 'http:') || typeof proof !== 'string' || !proof || typeof allowed !== 'function') {
            throw new Error('Invalid PurLink reporting configuration');
        }
        var sequence = 0;
        var active = true;
        function permitted() {
            try { return active && allowed() === true; } catch (e) { return false; }
        }
        function send(body, attempt) {
            if (!permitted()) { return; }
            if (typeof window.fetch !== 'function') {
                try {
                    if (window.navigator && typeof window.navigator.sendBeacon === 'function') {
                        window.navigator.sendBeacon(url.toString(), body);
                    }
                } catch (e) { /* Navigation is never held for a report. */ }
                return;
            }
            var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
            var timer;
            var timeout = new Promise(function (_, reject) {
                timer = window.setTimeout(function () {
                    if (controller) { controller.abort(); }
                    reject(new Error('PurLink exit timed out'));
                }, 8000);
            });
            var request;
            try {
                request = window.fetch(url.toString(), {
                    method: 'POST', mode: 'cors', credentials: 'omit', cache: 'no-store', redirect: 'error',
                    referrerPolicy: 'no-referrer', keepalive: true,
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body,
                    signal: controller ? controller.signal : undefined
                });
            } catch (e) { request = Promise.reject(e); }
            Promise.race([request, timeout]).then(function (response) {
                // A permanent refusal or conflict is not repaired by retrying.
                if (!response.ok && (response.status === 429 || response.status >= 500)) {
                    throw new Error('PurLink exit temporarily unavailable');
                }
            }).catch(function () {
                if (attempt < 3 && permitted()) {
                    window.setTimeout(function () { send(body, attempt + 1); }, attempt * 1000);
                }
            }).finally(function () { window.clearTimeout(timer); });
        }
        function reporter(offer) {
            if (!permitted() || sequence >= 2147483647) { return; }
            sequence++;
            var body = 'proof=' + encodeURIComponent(proof) + '&sequence=' + sequence +
                (offer ? '&campaign=' + encodeURIComponent(offer) : '');
            send(body, 1);
        }
        reporter.cancel = function () { active = false; };
        return reporter;
    }

    // The deferred pixel (1.9.56's defpixel): after a PurLink click-out, ask
    // until the click converts, then render the traffic source's browser
    // pixels on this page. The proof stays in the body. A refusal (an expired
    // proof, a page that moved) or withdrawn consent stops it for good.
    function createPixelPoller(endpoint, proof, allowed, render) {
        var url = new URL(endpoint, document.baseURI);
        if ((url.protocol !== 'https:' && url.protocol !== 'http:') || typeof proof !== 'string' || !proof ||
            typeof allowed !== 'function' || typeof render !== 'function') {
            throw new Error('Invalid PurLink pixel configuration');
        }
        var started = 0;
        var timer = null;
        var active = true;
        function permitted() {
            try { return active && allowed() === true; } catch (e) { return false; }
        }
        function schedule() {
            if (!permitted()) { return; }
            var elapsed = Date.now() - started;
            timer = window.setTimeout(poll, elapsed < 1800000 ? 30000 : 60000);
        }
        function poll() {
            timer = null;
            if (!permitted() || typeof window.fetch !== 'function') { return; }
            window.fetch(url.toString(), {
                method: 'POST', mode: 'cors', credentials: 'omit', cache: 'no-store', redirect: 'error',
                referrerPolicy: 'no-referrer', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'proof=' + encodeURIComponent(proof)
            }).then(function (response) {
                if (response.status === 404 || response.status === 422) { active = false; return null; }
                return response.ok ? response.json() : null;
            }).then(function (payload) {
                if (payload && payload.status === 'fired' && typeof payload.markup === 'string' &&
                    payload.markup !== '' && permitted()) {
                    try { render(payload.markup); } catch (e) { /* A broken pixel never stops tracking. */ }
                }
            }).catch(function () { /* Network trouble: try again later. */ }).then(schedule);
        }
        function poller() {
            if (started || !permitted()) { return; }
            started = Date.now();
            timer = window.setTimeout(poll, 10000);
        }
        poller.cancel = function () {
            active = false;
            if (timer !== null) { window.clearTimeout(timer); timer = null; }
        };
        return poller;
    }

    // Pixel code is the account's own: it runs in this page, where a traffic
    // source pixel (for example one that reads its first-party cookie) works.
    function renderPixels(markup) {
        var host = document.body || document.documentElement;
        var range = document.createRange();
        range.selectNode(host);
        host.appendChild(range.createContextualFragment(markup));
    }

    function init(config) {
        var next = config || {};
        if (options !== next) {
            generation++;
            preparation = Object.create(null);
        }
        options = next;
        if (!listening) {
            document.addEventListener('click', report);
            document.addEventListener('auxclick', report);
            document.addEventListener('submit', report);
            if (window.MutationObserver && document.documentElement) {
                observer = new window.MutationObserver(function (mutations) {
                    var changed = mutations.some(function (mutation) {
                        if (mutation.type === 'attributes') {
                            var target = mutation.target;
                            if (target.tagName !== 'A' && target.tagName !== 'FORM') { return false; }
                            if (mutation.attributeName === 'data-p202-purlink') { return true; }
                            var state = managed.get(target);
                            if (!state) { return true; }
                            // Our own writes also generate mutations. Ignore
                            // them once their final rendered state is visible.
                            if (mutation.attributeName === 'data-t202rawlink') {
                                return target.getAttribute('data-t202rawlink') !== state.template;
                            }
                            return mutation.attributeName === state.attribute &&
                                target.getAttribute(state.attribute) !== state.rendered;
                        }
                        return Array.prototype.some.call(mutation.addedNodes || [], function (node) {
                            return node.nodeType === 1 && !node.hasAttribute('data-p202-purlink-input');
                        });
                    });
                    if (changed) { refresh(); }
                });
                observer.observe(document.documentElement, {
                    childList: true, subtree: true, attributes: true,
                    attributeFilter: ['href', 'action', 'data-t202rawlink', 'data-p202-purlink']
                });
            }
            listening = true;
        }
        refresh();
    }

    window.p202PurLink = {init: init, refresh: refresh, createPreparer: createPreparer, createReporter: createReporter,
        createPixelPoller: createPixelPoller, renderPixels: renderPixels};
    window.updatePurLink = refresh;
}(window, document));
