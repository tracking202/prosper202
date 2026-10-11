/*
 * Prosper202 chat box, and the Prosper202Messenger analytics command queue.
 *
 * Loaded deferred by the shell on signed-in pages only (p202_shell_assets());
 * template_bottom() writes window.P202_CHAT_CONFIG = {base, token, user} and a
 * queue stub that buffers Prosper202Messenger(...) calls until this runs.
 *
 *   Prosper202Messenger('update', {plan: 'pro'});           // custom attributes
 *   Prosper202Messenger('trackEvent', 'name', {meta: 1});   // behavioural event
 *   Prosper202Messenger('show' | 'hide' | 'toggle');        // the chat box
 *
 * Analytics post to this install (202-account/ajax/messaging/track.php), which
 * records them and delivers them to the portal. Chat does not pass through
 * the install at all: 202-account/ajax/chat-pass.php hands this script a pass,
 * and the box talks to the chat service with it (chat PROTOCOL sections 5.1,
 * 6 and 6.1): GET /v1/customer/state for what is there, a WebSocket for what
 * arrives (subprotocols ["p202chat.v1", pass]), polling when the socket
 * cannot be had. Message text only ever reaches the page through textContent.
 */
(function () {
    'use strict';

    var cfg = window.P202_CHAT_CONFIG;
    if (!cfg || typeof cfg.base !== 'string') {
        return;
    }
    if (!window.fetch || !window.URLSearchParams) {
        return; // the queue stub keeps buffering; nothing here can post
    }
    var BASE = cfg.base;
    var TOKEN = typeof cfg.token === 'string' ? cfg.token : '';
    var USER = String(cfg.user || '');
    var STORE_KEY = 'p202.chat.pass';
    var TYPING_SHOW_MS = 6000;
    var TYPING_SEND_MS = 3000;
    var PING_MS = 30000;
    var POLL_VISIBLE_MS = 5000;
    var POLL_HIDDEN_MS = 60000;
    var SOCKET_RETRY_MS = 300000;
    var MAX_BODY = 10000;
    /* Every modifier spelled out, so tests/Api/V3/ChatBoxClassesAreStyledTest
       can hold each to a rule in 202-css/p202-chat.css (CLAUDE.md #19). */
    var AUTHOR_CLASS = { mine: 'p202-chat__msg--mine', team: 'p202-chat__msg--team', system: 'p202-chat__msg--system' };
    var SEND_CLASS = { sending: 'p202-chat__msg--sending', failed: 'p202-chat__msg--failed' };

    // ------------------------------------------------------------------
    // Requests
    // ------------------------------------------------------------------

    /* A POST to this install, with the session token. Resolves {status, body}
       and never rejects: status 0 is "no answer". */
    function postForm(path, data) {
        var body = new URLSearchParams();
        body.set('token', TOKEN);
        Object.keys(data || {}).forEach(function (key) {
            if (data[key] !== undefined && data[key] !== null) {
                body.set(key, data[key]);
            }
        });
        return window.fetch(BASE + path, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        }).then(readJson, function () {
            return { status: 0, body: null };
        });
    }

    function readJson(response) {
        if (response.status === 204) {
            return { status: 204, body: null };
        }
        return response.json().then(function (json) {
            return { status: response.status, body: json };
        }, function () {
            return { status: response.status, body: null };
        });
    }

    // ------------------------------------------------------------------
    // Analytics command queue
    // ------------------------------------------------------------------

    var flushTimer = null;

    function track(fields) {
        postForm('202-account/ajax/messaging/track.php', fields).then(scheduleFlush);
    }

    /* track.php delivers what it records unless the last delivery was too
       recent; then it says when to ask again, and nothing else would. */
    function scheduleFlush(res) {
        var body = res.body;
        if (flushTimer !== null || !body || body.delivery !== 'throttled') {
            return;
        }
        var wait = typeof body.retry_after_s === 'number' && body.retry_after_s > 0 ? body.retry_after_s : 20;
        flushTimer = window.setTimeout(function () {
            flushTimer = null;
            track({ flush: '1' });
        }, wait * 1000 + 500);
    }

    var visibilityWanted = null;

    function dispatch(command, a, b) {
        switch (command) {
            case 'update':
                if (a && typeof a === 'object') {
                    track({ update: JSON.stringify(a) });
                }
                break;
            case 'trackEvent':
                if (a) {
                    track({ event_name: String(a), metadata: b && typeof b === 'object' ? JSON.stringify(b) : null });
                }
                break;
            case 'show':
            case 'hide':
            case 'toggle':
                if (ui) {
                    setOpen(command === 'toggle' ? !state.open : command === 'show');
                } else {
                    visibilityWanted = command;
                }
                break;
            default:
                break; // unknown commands are ignored
        }
    }

    var queued = window.Prosper202Messenger && window.Prosper202Messenger.q ? window.Prosper202Messenger.q : [];
    window.Prosper202Messenger = function () {
        dispatch.apply(null, arguments);
    };
    Array.prototype.forEach.call(queued, function (call) {
        dispatch.apply(null, call);
    });

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    var state = {
        status: 'loading',   // loading | ready | unavailable
        pass: null,          // {user, pass, chat_url, expires_at_s, name}
        renewed: false,      // a refused pass was replaced once since the last accepted request
        seq: 0,              // highest seq seen
        convs: {},           // id -> CustomerConversation
        messages: {},        // conv id -> [Message] by seq
        pending: [],         // optimistic sends: {cmid, body, conv, state, error}
        open: false,
        view: 'home',        // home | thread
        thread: null,        // conversation id, or 0 for a chat not started yet
        typing: null,        // {conv, name, until}
        readPosted: {}       // conv id -> highest seq a read marker was posted for
    };
    var socket = null;
    var socketFailures = 0;
    var reconnectAttempt = 0;
    var gapFrom = null;
    var timers = {};
    var lastTypingSent = 0;
    var recoverAttempt = 0;
    var ui = null;

    function clearTimer(name) {
        if (timers[name]) {
            window.clearTimeout(timers[name]);
            window.clearInterval(timers[name]);
            timers[name] = null;
        }
    }

    function nowSeconds() {
        return Math.floor(Date.now() / 1000);
    }

    // ------------------------------------------------------------------
    // The pass
    // ------------------------------------------------------------------

    function usable(pass) {
        return pass && pass.user === USER && typeof pass.pass === 'string' && typeof pass.chat_url === 'string'
            && typeof pass.expires_at_s === 'number' && pass.expires_at_s - 3600 > nowSeconds();
    }

    function storedPass() {
        try {
            var pass = JSON.parse(window.sessionStorage.getItem(STORE_KEY) || 'null');
            return usable(pass) ? pass : null;
        } catch (e) {
            return null;
        }
    }

    function dropStoredPass() {
        try {
            window.sessionStorage.removeItem(STORE_KEY);
        } catch (e) { /* storage unavailable: nothing was kept */ }
    }

    /* Rejects with 'hidden' (no API key: the box stays hidden), 'signed_out',
       or 'unavailable'. */
    function fetchPass(refresh) {
        return postForm('202-account/ajax/chat-pass.php', refresh ? { refresh: '1' } : {}).then(function (res) {
            var body = res.body;
            if (res.status === 200 && body && body.ok === true && typeof body.pass === 'string'
                && typeof body.chat_url === 'string' && typeof body.expires_at_s === 'number') {
                var pass = {
                    user: USER,
                    pass: body.pass,
                    chat_url: body.chat_url.replace(/\/+$/, ''),
                    expires_at_s: body.expires_at_s,
                    name: body.user && typeof body.user.name === 'string' ? body.user.name : ''
                };
                try {
                    window.sessionStorage.setItem(STORE_KEY, JSON.stringify(pass));
                } catch (e) { /* kept for this page only */ }
                return pass;
            }
            if (res.status === 409) {
                throw new Error('hidden');
            }
            if (res.status === 401 || res.status === 403) {
                throw new Error('signed_out');
            }
            throw new Error('unavailable');
        });
    }

    function currentPass() {
        if (usable(state.pass)) {
            return Promise.resolve(state.pass);
        }
        var stored = storedPass();
        return stored ? Promise.resolve(stored) : fetchPass(false);
    }

    /* The chat service refused the pass (HTTP 401, close 4001): drop it and
       ask for a new one, once until a request is accepted again. */
    function renewPass() {
        dropStoredPass();
        if (state.renewed) {
            return Promise.resolve(false);
        }
        state.renewed = true;
        return fetchPass(true).then(function (pass) {
            state.pass = pass;
            return true;
        }, function (error) {
            passFailed(error);
            return false;
        });
    }

    function passFailed(error) {
        var reason = error && error.message;
        if (reason === 'hidden' || reason === 'signed_out') {
            shutDown();
        } else {
            setStatus('unavailable');
        }
    }

    // ------------------------------------------------------------------
    // The chat service over HTTP
    // ------------------------------------------------------------------

    function call(method, path, payload) {
        var options = {
            method: method,
            mode: 'cors',
            credentials: 'omit',
            cache: 'no-store',
            headers: { 'Authorization': 'Bearer ' + state.pass.pass }
        };
        if (payload !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(payload);
        }
        return window.fetch(state.pass.chat_url + path, options).then(readJson, function () {
            return { status: 0, body: null };
        });
    }

    function authed(method, path, payload) {
        var ready = state.pass ? Promise.resolve(state.pass) : currentPass().then(function (pass) {
            state.pass = pass;
            return pass;
        });
        return ready.then(function () {
            return call(method, path, payload);
        }, function (error) {
            passFailed(error);
            return { status: 0, body: null };
        }).then(function (res) {
            if (res.status !== 401) {
                if (res.status >= 200 && res.status < 300) {
                    state.renewed = false;
                }
                return res;
            }
            return renewPass().then(function (renewed) {
                return renewed ? call(method, path, payload) : res;
            });
        });
    }

    /* Every conversation, and the messages after `after`, page by page. */
    function loadState(after) {
        return authed('GET', '/v1/customer/state?after=' + after).then(function (res) {
            var body = res.body;
            if (res.status !== 200 || !body || !Array.isArray(body.conversations) || !Array.isArray(body.messages)) {
                throw new Error('unavailable');
            }
            body.conversations.forEach(applyConversation);
            body.messages.forEach(applyMessage);
            var last = after;
            body.messages.forEach(function (m) {
                if (typeof m.seq === 'number' && m.seq > last) {
                    last = m.seq;
                }
            });
            if (typeof body.seq === 'number' && body.seq > state.seq) {
                state.seq = body.seq;
            }
            if (body.more === true && last > after) {
                return loadState(last);
            }
            setStatus('ready');
            render();
            return true;
        });
    }

    // ------------------------------------------------------------------
    // Applying what arrives
    // ------------------------------------------------------------------

    function applyConversation(conv) {
        if (!conv || typeof conv.id !== 'number') {
            return;
        }
        state.convs[conv.id] = conv;
    }

    function applyMessage(m) {
        if (!m || m.kind !== 'message' || typeof m.seq !== 'number' || typeof m.conv !== 'number' || typeof m.body !== 'string') {
            return;
        }
        if (typeof m.name !== 'string') {
            m.name = '';
        }
        if (m.seq > state.seq) {
            state.seq = m.seq;
        }
        if (m.cmid) {
            state.pending = state.pending.filter(function (p) {
                if (p.cmid === m.cmid && p.conv === null && state.thread === 0) {
                    state.thread = m.conv; // the chat this new message started
                }
                return p.cmid !== m.cmid;
            });
        }
        var list = state.messages[m.conv] || (state.messages[m.conv] = []);
        for (var i = 0; i < list.length; i++) {
            if (list[i].seq === m.seq) {
                list[i] = m;
                return;
            }
        }
        list.push(m);
        list.sort(function (x, y) {
            return x.seq - y.seq;
        });
    }

    function handleFrame(frame) {
        if (!frame || typeof frame.t !== 'string') {
            return;
        }
        switch (frame.t) {
            case 'message':
                applyConversation(frame.conversation);
                applyMessage(frame.message);
                if (frame.message && state.typing && state.typing.conv === frame.message.conv && frame.message.author !== 'customer') {
                    state.typing = null;
                }
                render();
                break;
            case 'conversation':
                applyConversation(frame.conversation);
                render();
                break;
            case 'read':
                if (state.convs[frame.conv] && typeof frame.upto === 'number' && frame.upto > state.convs[frame.conv].team_read_upto) {
                    state.convs[frame.conv].team_read_upto = frame.upto;
                    render();
                }
                break;
            case 'typing':
                state.typing = { conv: frame.conv, name: typeof frame.name === 'string' ? frame.name : '', until: Date.now() + TYPING_SHOW_MS };
                clearTimer('typing');
                timers.typing = window.setTimeout(function () {
                    state.typing = null;
                    render();
                }, TYPING_SHOW_MS);
                render();
                break;
            default:
                break; // ready, and anything a later protocol adds
        }
    }

    // ------------------------------------------------------------------
    // The socket, and polling when there is none
    // ------------------------------------------------------------------

    function connect() {
        clearTimer('reconnect');
        if (socket || state.status === 'off') {
            return;
        }
        if (!('WebSocket' in window)) {
            startPolling();
            return;
        }
        currentPass().then(function (pass) {
            state.pass = pass;
            open(pass);
        }, passFailed);
    }

    function open(pass) {
        var opened = false;
        var ws;
        try {
            ws = new window.WebSocket(pass.chat_url.replace(/^http/, 'ws') + '/v1/customer/socket', ['p202chat.v1', pass.pass]);
        } catch (e) {
            socketFailed(false);
            return;
        }
        socket = ws;
        ws.onopen = function () {
            opened = true;
            socketFailures = 0;
            reconnectAttempt = 0;
            state.renewed = false;
            stopPolling();
            clearTimer('ping');
            timers.ping = window.setInterval(function () {
                if (ws.readyState === 1) {
                    ws.send('ping');
                }
            }, PING_MS);
            // What arrived while there was no socket.
            var from = gapFrom === null ? state.seq : gapFrom;
            gapFrom = null;
            loadState(from).catch(function () { /* the socket carries on; the next drop fills again */ });
        };
        ws.onmessage = function (event) {
            if (typeof event.data !== 'string' || event.data === 'pong') {
                return;
            }
            var frame;
            try {
                frame = JSON.parse(event.data);
            } catch (e) {
                return;
            }
            handleFrame(frame);
        };
        ws.onclose = function (event) {
            if (socket === ws) {
                socket = null;
            }
            clearTimer('ping');
            if (gapFrom === null) {
                gapFrom = state.seq;
            }
            if (state.status === 'off') {
                return;
            }
            if (event.code === 4003 || event.code === 4004) {
                // Refused for good: reconnecting cannot help, polling says why.
                socketFailures = 3;
                socketFailed(false);
                return;
            }
            if (event.code === 4001) {
                renewPass().then(function (renewed) {
                    if (renewed) {
                        connect();
                    } else {
                        startPolling();
                    }
                });
                return;
            }
            socketFailed(opened);
        };
    }

    function socketFailed(hadOpened) {
        if (!hadOpened) {
            socketFailures++;
        }
        if (socketFailures >= 3) {
            startPolling();
            clearTimer('socketRetry');
            timers.socketRetry = window.setTimeout(connect, SOCKET_RETRY_MS);
            return;
        }
        scheduleReconnect();
    }

    /* 1 s, 2 s, 4 s ... 30 s, each +/-20 %. */
    function scheduleReconnect() {
        clearTimer('reconnect');
        var delay = Math.min(30000, 1000 * Math.pow(2, reconnectAttempt));
        reconnectAttempt++;
        timers.reconnect = window.setTimeout(connect, delay * (0.8 + Math.random() * 0.4));
    }

    function startPolling() {
        if (timers.poll || state.status === 'off') {
            return;
        }
        schedulePoll(0);
    }

    function stopPolling() {
        clearTimer('poll');
    }

    function schedulePoll(delay) {
        clearTimer('poll');
        timers.poll = window.setTimeout(function () {
            currentPass().then(function (pass) {
                state.pass = pass;
                return loadState(state.seq);
            }).catch(function (error) {
                if (error && (error.message === 'hidden' || error.message === 'signed_out')) {
                    passFailed(error);
                } else {
                    setStatus('unavailable');
                }
            }).then(function () {
                if (timers.poll && !socket && state.status !== 'off') {
                    schedulePoll(document.visibilityState === 'hidden' ? POLL_HIDDEN_MS : POLL_VISIBLE_MS);
                }
            });
        }, delay);
    }

    // ------------------------------------------------------------------
    // Sending, reading, typing
    // ------------------------------------------------------------------

    function newCmid() {
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        var alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        var out = '';
        for (var i = 0; i < bytes.length; i++) {
            out += alphabet.charAt(bytes[i] & 63);
        }
        return out;
    }

    function send(text) {
        var body = text.trim();
        if (body === '') {
            return false;
        }
        if (body.length > MAX_BODY) {
            showComposerError('A message can be at most 10,000 characters.');
            return false;
        }
        var item = { cmid: newCmid(), body: body, conv: state.thread || null, state: 'sending', error: '' };
        state.pending.push(item);
        deliver(item);
        return true;
    }

    function deliver(item) {
        item.state = 'sending';
        item.error = '';
        render(true);
        var payload = { cmid: item.cmid, body: item.body };
        if (item.conv) {
            payload.conv = item.conv;
        }
        authed('POST', '/v1/customer/messages', payload).then(function (res) {
            var body = res.body;
            if ((res.status === 201 || res.status === 200) && body && body.message && body.conversation) {
                applyConversation(body.conversation);
                applyMessage(body.message);
                if (state.thread === 0 && item.conv === null) {
                    state.thread = body.conversation.id;
                }
                render(true);
                if (state.status === 'unavailable') {
                    retryNow(); // answered again: load the rest and reconnect now
                }
                return;
            }
            item.state = 'failed';
            if (res.status === 429) {
                item.error = 'Too many messages. Wait a moment, then retry.';
            } else if (res.status === 400 && body && body.error && typeof body.error.message === 'string') {
                item.error = body.error.message;
            } else {
                item.error = 'Not sent.';
            }
            render();
        });
    }

    function threadVisible() {
        return state.open && state.view === 'thread' && state.thread > 0 && document.visibilityState === 'visible';
    }

    function markRead() {
        if (!threadVisible()) {
            return;
        }
        var conv = state.convs[state.thread];
        var list = state.messages[state.thread] || [];
        var upto = 0;
        list.forEach(function (m) {
            if (m.author !== 'customer' && m.seq > upto) {
                upto = m.seq;
            }
        });
        if (!conv || !(conv.unread > 0) || upto === 0 || (state.readPosted[state.thread] || 0) >= upto) {
            return;
        }
        var id = state.thread;
        state.readPosted[id] = upto;
        authed('POST', '/v1/customer/read', { conv: id, upto: upto }).then(function (res) {
            if (res.status === 204 || res.status === 200) {
                if (state.convs[id]) {
                    state.convs[id].unread = 0;
                }
                renderBadge();
            } else {
                state.readPosted[id] = 0;
            }
        });
    }

    function sendTyping() {
        var now = Date.now();
        if (now - lastTypingSent < TYPING_SEND_MS || !state.pass) {
            return;
        }
        lastTypingSent = now;
        var frame = { t: 'typing' };
        if (state.thread > 0) {
            frame.conv = state.thread;
        }
        if (socket && socket.readyState === 1) {
            socket.send(JSON.stringify(frame));
        } else {
            authed('POST', '/v1/customer/typing', frame.conv ? { conv: frame.conv } : {});
        }
    }

    // ------------------------------------------------------------------
    // The box
    // ------------------------------------------------------------------

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function icon(name) {
        var i = el('i', 'bi ' + name);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    function build() {
        var root = el('div', 'p202-chat');
        var panel = el('section', 'p202-chat__panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Chat with Tracking202 support');
        panel.hidden = true;

        var head = el('header', 'p202-chat__head');
        var back = el('button', 'p202-chat__icon-button p202-chat__back');
        back.type = 'button';
        back.setAttribute('aria-label', 'Back to conversations');
        back.appendChild(icon('bi-chevron-left'));
        back.addEventListener('click', function () {
            state.view = 'home';
            render();
        });
        var heading = el('div', 'p202-chat__heading');
        var title = el('div', 'p202-chat__title');
        var subtitle = el('div', 'p202-chat__subtitle');
        heading.appendChild(title);
        heading.appendChild(subtitle);
        var close = el('button', 'p202-chat__icon-button');
        close.type = 'button';
        close.setAttribute('aria-label', 'Close chat');
        close.appendChild(icon('bi-x-lg'));
        close.addEventListener('click', function () {
            setOpen(false);
        });
        head.appendChild(back);
        head.appendChild(heading);
        head.appendChild(close);

        var notice = el('div', 'p202-chat__notice');
        notice.setAttribute('role', 'status');
        notice.hidden = true;
        var noticeText = el('span', null, 'We can\u2019t connect to chat right now. We\u2019ll keep trying.');
        var retry = el('button', 'p202-chat__link', 'Try again');
        retry.type = 'button';
        retry.addEventListener('click', retryNow);
        notice.appendChild(noticeText);
        notice.appendChild(document.createTextNode(' '));
        notice.appendChild(retry);

        var body = el('div', 'p202-chat__body');
        var typing = el('div', 'p202-chat__typing');
        typing.setAttribute('aria-live', 'polite');

        var composer = el('form', 'p202-chat__composer');
        var input = el('textarea', 'p202-chat__input');
        input.rows = 1;
        input.placeholder = 'Write a message\u2026';
        input.setAttribute('aria-label', 'Message');
        input.maxLength = MAX_BODY;
        var sendButton = el('button', 'p202-chat__send');
        sendButton.type = 'submit';
        sendButton.setAttribute('aria-label', 'Send');
        sendButton.appendChild(icon('bi-send-fill'));
        var composerError = el('div', 'p202-chat__composer-error');
        composerError.hidden = true;
        composer.appendChild(input);
        composer.appendChild(sendButton);
        composer.addEventListener('submit', function (event) {
            event.preventDefault();
            if (send(input.value)) {
                input.value = '';
                fitInput();
            }
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                if (send(input.value)) {
                    input.value = '';
                    fitInput();
                }
            }
        });
        input.addEventListener('input', function () {
            composerError.hidden = true;
            fitInput();
            if (input.value.trim() !== '') {
                sendTyping();
            }
        });

        panel.appendChild(head);
        panel.appendChild(notice);
        panel.appendChild(body);
        panel.appendChild(typing);
        panel.appendChild(composerError);
        panel.appendChild(composer);

        var launcher = el('button', 'p202-chat__launcher');
        launcher.type = 'button';
        launcher.setAttribute('aria-label', 'Open chat with Tracking202 support');
        launcher.setAttribute('aria-expanded', 'false');
        launcher.appendChild(icon('bi-chat-dots-fill'));
        var badge = el('span', 'p202-chat__badge');
        badge.hidden = true;
        launcher.appendChild(badge);
        launcher.addEventListener('click', function () {
            setOpen(!state.open);
        });

        root.appendChild(panel);
        root.appendChild(launcher);
        document.body.appendChild(root);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && state.open) {
                setOpen(false);
            }
        });

        ui = {
            root: root, panel: panel, back: back, title: title, subtitle: subtitle, notice: notice,
            body: body, typing: typing, composer: composer, input: input, composerError: composerError,
            launcher: launcher, badge: badge
        };

        function fitInput() {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 120) + 'px';
        }

        if (visibilityWanted) {
            dispatch(visibilityWanted);
            visibilityWanted = null;
        }
    }

    function showComposerError(text) {
        ui.composerError.textContent = text;
        ui.composerError.hidden = false;
    }

    function setOpen(open) {
        if (!ui) {
            return;
        }
        state.open = open;
        ui.panel.hidden = !open;
        ui.launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
        ui.launcher.setAttribute('aria-label', open ? 'Close chat' : 'Open chat with Tracking202 support');
        render(true);
        if (open && state.view === 'thread') {
            ui.input.focus();
        }
    }

    function setStatus(status) {
        if (state.status === 'off') {
            return;
        }
        state.status = status;
        if (status === 'ready') {
            clearTimer('recover');
            recoverAttempt = 0;
        }
        // While nothing answers, ask again after 30 s, 1 min, 2 min ... 5 min:
        // a pass request reaches the portal whenever none is kept.
        if (status === 'unavailable' && !timers.recover && !socket) {
            var wait = Math.min(300000, 30000 * Math.pow(2, recoverAttempt));
            recoverAttempt++;
            timers.recover = window.setTimeout(function () {
                timers.recover = null;
                retryNow();
            }, wait);
        }
        render();
    }

    function retryNow() {
        clearTimer('recover');
        state.renewed = false;
        currentPass().then(function (pass) {
            state.pass = pass;
            return loadState(state.seq);
        }).then(function () {
            if (!socket) {
                socketFailures = 0;
                reconnectAttempt = 0;
                connect();
            }
        }, function (error) {
            passFailed(error);
        });
    }

    function shutDown() {
        state.status = 'off';
        Object.keys(timers).forEach(clearTimer);
        if (socket) {
            socket.close(1000);
            socket = null;
        }
        if (ui) {
            ui.root.remove();
            ui = null;
        }
    }

    function openThread(id) {
        state.view = 'thread';
        state.thread = id;
        render(true);
        ui.input.focus();
    }

    function chatConversationId() {
        var found = 0;
        Object.keys(state.convs).forEach(function (key) {
            if (state.convs[key].type === 'chat') {
                found = state.convs[key].id;
            }
        });
        return found;
    }

    function conversationTitle(conv) {
        if (conv && conv.type === 'broadcast') {
            return conv.subject || 'Announcement';
        }
        return 'Tracking202 Support';
    }

    function lastMessage(id) {
        var list = state.messages[id] || [];
        return list.length ? list[list.length - 1] : null;
    }

    function relativeTime(ms) {
        if (typeof ms !== 'number' || ms <= 0) {
            return '';
        }
        var seconds = Math.max(0, Math.floor((Date.now() - ms) / 1000));
        if (seconds < 60) {
            return 'now';
        }
        if (seconds < 3600) {
            return Math.floor(seconds / 60) + 'm';
        }
        if (seconds < 86400) {
            return Math.floor(seconds / 3600) + 'h';
        }
        if (seconds < 604800) {
            return Math.floor(seconds / 86400) + 'd';
        }
        return new Date(ms).toLocaleDateString([], { month: 'short', day: 'numeric' });
    }

    function clockTime(ms) {
        if (typeof ms !== 'number' || ms <= 0) {
            return '';
        }
        var date = new Date(ms);
        var today = new Date();
        var time = date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        return date.toDateString() === today.toDateString()
            ? time
            : date.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ', ' + time;
    }

    function unreadTotal() {
        var total = 0;
        Object.keys(state.convs).forEach(function (key) {
            var unread = state.convs[key].unread;
            total += typeof unread === 'number' && unread > 0 ? unread : 0;
        });
        return total;
    }

    function renderBadge() {
        if (!ui) {
            return;
        }
        var total = unreadTotal();
        ui.badge.hidden = total === 0;
        ui.badge.textContent = total > 99 ? '99+' : String(total);
        ui.launcher.classList.toggle('p202-chat__launcher--unread', total > 0);
    }

    function render(stickToBottom) {
        if (!ui) {
            return;
        }
        renderBadge();
        ui.notice.hidden = state.status !== 'unavailable';
        if (!state.open) {
            return;
        }
        if (state.view === 'thread') {
            renderThread(stickToBottom);
        } else {
            renderHome();
        }
        markRead();
    }

    function renderHome() {
        ui.root.classList.remove('p202-chat--thread');
        ui.back.hidden = true;
        ui.composer.hidden = true;
        ui.typing.textContent = '';
        ui.title.textContent = state.pass && state.pass.name ? 'Hi ' + state.pass.name.split(' ')[0] : 'Hi there';
        ui.subtitle.textContent = 'Ask the Tracking202 team anything.';

        var home = el('div', 'p202-chat__home');
        var start = el('button', 'p202-chat__start');
        start.type = 'button';
        start.appendChild(el('span', null, 'Send us a message'));
        start.appendChild(icon('bi-send'));
        start.disabled = state.status === 'loading';
        start.addEventListener('click', function () {
            openThread(chatConversationId());
        });
        home.appendChild(start);

        var ids = Object.keys(state.convs).map(Number).sort(function (a, b) {
            return (state.convs[b].last_message_at || 0) - (state.convs[a].last_message_at || 0) || b - a;
        });
        if (state.status === 'loading' && ids.length === 0) {
            home.appendChild(el('p', 'p202-chat__empty', 'Loading\u2026'));
        } else if (ids.length === 0) {
            if (state.status === 'ready') {
                home.appendChild(el('p', 'p202-chat__empty', 'No messages yet.'));
            }
        } else {
            var list = el('ul', 'p202-chat__list');
            ids.forEach(function (id) {
                var conv = state.convs[id];
                var item = el('li');
                var row = el('button', 'p202-chat__row' + (conv.unread > 0 ? ' p202-chat__row--unread' : ''));
                row.type = 'button';
                var top = el('span', 'p202-chat__row-top');
                top.appendChild(el('span', 'p202-chat__row-title', conversationTitle(conv)));
                top.appendChild(el('span', 'p202-chat__row-time', relativeTime(conv.last_message_at)));
                row.appendChild(top);
                var last = lastMessage(id);
                var preview = last ? (last.author === 'customer' ? 'You: ' : '') + last.body.replace(/\s+/g, ' ') : '';
                row.appendChild(el('span', 'p202-chat__row-preview', preview));
                row.addEventListener('click', function () {
                    openThread(id);
                });
                item.appendChild(row);
                list.appendChild(item);
            });
            home.appendChild(list);
        }
        replaceBody(home);
    }

    function renderThread(stickToBottom) {
        var conv = state.thread > 0 ? state.convs[state.thread] : null;
        ui.root.classList.add('p202-chat--thread');
        ui.back.hidden = false;
        ui.composer.hidden = false;
        ui.title.textContent = conversationTitle(conv);
        ui.subtitle.textContent = conv && conv.type === 'broadcast' ? 'Announcement from Tracking202' : 'We\u2019ll reply here.';

        var nearBottom = ui.body.scrollHeight - ui.body.scrollTop - ui.body.clientHeight < 80;
        var list = el('ol', 'p202-chat__messages');
        var messages = state.thread > 0 ? (state.messages[state.thread] || []) : [];
        var seenAt = -1;
        if (conv) {
            messages.forEach(function (m, i) {
                if (m.author === 'customer' && m.seq <= conv.team_read_upto) {
                    seenAt = i;
                }
            });
            // "Seen" only under the newest message of the customer's.
            for (var j = seenAt + 1; j < messages.length; j++) {
                if (messages[j].author === 'customer') {
                    seenAt = -1;
                    break;
                }
            }
        }
        var previousAuthor = null;
        messages.forEach(function (m, i) {
            var who = m.author === 'customer' ? 'mine' : (m.author === 'system' ? 'system' : 'team');
            var meta = clockTime(m.at);
            if (i === seenAt) {
                meta += ' \u00b7 Seen';
            }
            list.appendChild(messageItem(who, m.author !== 'customer' && previousAuthor !== m.author + m.name ? m.name : '', m.body, meta, null));
            previousAuthor = m.author + m.name;
        });
        state.pending.forEach(function (p) {
            if ((p.conv || 0) !== (state.thread || 0) && !(state.thread > 0 && p.conv === null && conv && conv.type === 'chat')) {
                return;
            }
            list.appendChild(messageItem('mine', '', p.body, p.state === 'failed' ? p.error : 'Sending\u2026', p));
        });
        if (list.children.length === 0) {
            var empty = el('li', 'p202-chat__empty', 'Ask us anything about Prosper202.');
            list.appendChild(empty);
        }
        replaceBody(list);
        var typing = state.typing && state.typing.conv === state.thread && state.typing.until > Date.now() ? state.typing : null;
        ui.typing.textContent = typing ? (typing.name || 'Tracking202') + ' is typing\u2026' : '';
        if (stickToBottom || nearBottom) {
            ui.body.scrollTop = ui.body.scrollHeight;
        }
    }

    function messageItem(who, author, text, meta, pending) {
        var item = el('li', 'p202-chat__msg ' + AUTHOR_CLASS[who] + (pending ? ' ' + SEND_CLASS[pending.state] : ''));
        if (author) {
            item.appendChild(el('div', 'p202-chat__author', author));
        }
        item.appendChild(el('div', 'p202-chat__bubble', text));
        var line = el('div', 'p202-chat__meta', meta);
        if (pending && pending.state === 'failed') {
            var retry = el('button', 'p202-chat__link', 'Retry');
            retry.type = 'button';
            retry.addEventListener('click', function () {
                deliver(pending);
            });
            line.appendChild(document.createTextNode(' '));
            line.appendChild(retry);
        }
        item.appendChild(line);
        return item;
    }

    function replaceBody(node) {
        while (ui.body.firstChild) {
            ui.body.removeChild(ui.body.firstChild);
        }
        ui.body.appendChild(node);
    }

    // ------------------------------------------------------------------
    // Start, once the page is idle
    // ------------------------------------------------------------------

    function start() {
        currentPass().then(function (pass) {
            state.pass = pass;
            build();
            render();
            return loadState(0).then(connect, function () {
                setStatus('unavailable');
                connect();
            });
        }, function (error) {
            if (error && error.message === 'unavailable') {
                // Shown, so the person can see chat is there and why it is not answering.
                build();
                setStatus('unavailable');
            }
            passFailed(error);
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (state.status === 'off' || !ui) {
            return;
        }
        if (timers.poll && document.visibilityState === 'visible') {
            schedulePoll(0);
        }
        markRead();
    });

    function whenIdle(fn) {
        if (window.requestIdleCallback) {
            window.requestIdleCallback(fn, { timeout: 4000 });
        } else {
            window.setTimeout(fn, 1500);
        }
    }

    if (document.readyState === 'complete') {
        whenIdle(start);
    } else {
        window.addEventListener('load', function () {
            whenIdle(start);
        });
    }
})();
