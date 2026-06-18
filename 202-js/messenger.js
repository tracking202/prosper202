/*
 * Prosper202 Messenger — floating Intercom-style widget (client side).
 *
 * Reads its configuration from window.P202M_CONFIG (injected by template.php):
 *   { base: '<absolute url>', token: '<csrf token>' }
 *
 * Public JavaScript API (Intercom-style command queue on a single global):
 *   Prosper202Messenger('update', { plan: 'pro', ... });      // custom attributes
 *   Prosper202Messenger('trackEvent', 'name', { ...meta });   // behavioural event
 *   Prosper202Messenger('show' | 'hide' | 'toggle');          // widget control
 *
 * Calls made before the widget finishes loading are buffered and replayed.
 */
(function () {
    'use strict';

    var cfg = window.P202M_CONFIG || {};
    if (!cfg.base) { return; }

    var BASE = cfg.base;
    var TOKEN = cfg.token || '';
    var POLL_MS = 25000;

    // ---- command queue (so early Prosper202Messenger(...) calls aren't lost) ----
    var existing = window.Prosper202Messenger;
    var queued = (existing && existing.q) ? existing.q : [];

    // ---- tiny helpers -------------------------------------------------------
    function el(tag, attrs, html) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === 'class') { node.className = attrs[k]; }
                else { node.setAttribute(k, attrs[k]); }
            });
        }
        if (html != null) { node.innerHTML = html; }
        return node;
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function timeAgo(ts) {
        if (!ts) { return ''; }
        var then = new Date(String(ts).replace(' ', 'T') + 'Z').getTime();
        if (isNaN(then)) { return ''; }
        var s = Math.max(0, Math.floor((Date.now() - then) / 1000));
        if (s < 60) { return 'just now'; }
        var m = Math.floor(s / 60);
        if (m < 60) { return m + 'm'; }
        var h = Math.floor(m / 60);
        if (h < 24) { return h + 'h'; }
        return Math.floor(h / 24) + 'd';
    }

    function getJSON(path) {
        return fetch(BASE + path, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.ok ? r.json() : null; })
          .catch(function () { return null; });
    }

    function postForm(path, data) {
        var body = new URLSearchParams();
        body.set('token', TOKEN);
        Object.keys(data || {}).forEach(function (k) {
            if (data[k] != null) { body.set(k, data[k]); }
        });
        return fetch(BASE + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        }).then(function (r) { return r.ok ? r.json() : null; })
          .catch(function () { return null; });
    }

    // ---- widget state -------------------------------------------------------
    var root, panel, badge, bodyEl, headerTitle, composer, input, sendBtn;
    var view = 'list';            // 'list' | 'thread'
    var currentConv = null;       // external id of open conversation, or null for new
    var pollTimer = null;

    function build() {
        root = el('div', { id: 'p202-messenger' });

        var launcher = el('button', { id: 'p202-messenger-launcher', type: 'button', 'aria-label': 'Messages' },
            '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 5.94 2 10.8c0 2.78 1.46 5.26 3.75 6.88L5 22l4.2-2.1c.9.2 1.84.3 2.8.3 5.52 0 10-3.94 10-8.8S17.52 2 12 2z"/></svg>');
        badge = el('span', { id: 'p202-messenger-badge' });
        launcher.appendChild(badge);
        launcher.addEventListener('click', toggle);

        panel = el('div', { id: 'p202-messenger-panel', role: 'dialog', 'aria-label': 'Messages' });

        var header = el('div', { id: 'p202-messenger-header' });
        var left = el('div', null, '');
        left.style.display = 'flex';
        left.style.alignItems = 'center';
        var back = el('button', { type: 'button', 'class': 'p202m-back', 'aria-label': 'Back' }, '&#8592;');
        back.addEventListener('click', showList);
        headerTitle = el('h3', { 'class': 'p202m-title' }, 'Messages');
        left.appendChild(back);
        left.appendChild(headerTitle);
        var close = el('button', { type: 'button', 'class': 'p202m-close', 'aria-label': 'Close' }, '&times;');
        close.addEventListener('click', hide);
        header.appendChild(left);
        header.appendChild(close);

        bodyEl = el('div', { id: 'p202-messenger-body' });

        composer = el('form', { id: 'p202-messenger-composer' });
        input = el('textarea', { id: 'p202-messenger-input', rows: '1', placeholder: 'Type a message…' });
        sendBtn = el('button', { id: 'p202-messenger-send', type: 'submit' }, 'Send');
        composer.appendChild(input);
        composer.appendChild(sendBtn);
        composer.style.display = 'none';
        composer.addEventListener('submit', onSubmit);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); onSubmit(e); }
        });

        panel.appendChild(header);
        panel.appendChild(bodyEl);
        panel.appendChild(composer);

        root.appendChild(panel);
        root.appendChild(launcher);
        document.body.appendChild(root);
    }

    // ---- views --------------------------------------------------------------
    function setBadge(count) {
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.classList.add('is-visible');
        } else {
            badge.classList.remove('is-visible');
        }
    }

    function renderList(data) {
        view = 'list';
        currentConv = null;
        root.classList.remove('is-thread');
        headerTitle.textContent = 'Messages';
        composer.style.display = 'none';

        bodyEl.innerHTML = '';
        var newBtn = el('button', { id: 'p202-messenger-newbtn', type: 'button' }, 'Send us a message');
        newBtn.addEventListener('click', startNew);
        bodyEl.appendChild(newBtn);

        var convs = (data && data.conversations) || [];
        if (!convs.length) {
            bodyEl.appendChild(el('div', { 'class': 'p202m-empty' },
                'No messages yet. Start a conversation and we’ll get back to you.'));
            return;
        }

        convs.forEach(function (c) {
            var item = el('div', { 'class': 'p202m-conv' + (c.unread > 0 ? ' is-unread' : '') });
            var subject = c.subject || (c.type === 'broadcast' ? 'Announcement' : 'Conversation');
            var dot = c.unread > 0 ? '<span class="p202m-conv-dot"></span>' : '';
            item.innerHTML =
                '<div class="p202m-conv-top">' +
                    '<p class="p202m-conv-subject">' + dot + esc(subject) + '</p>' +
                    '<span class="p202m-conv-time">' + esc(timeAgo(c.last_message_at)) + '</span>' +
                '</div>' +
                '<p class="p202m-conv-preview">' + esc(c.last_message_preview || '') + '</p>';
            item.addEventListener('click', function () { openThread(c.external_id); });
            bodyEl.appendChild(item);
        });
    }

    function renderThread(data) {
        view = 'thread';
        root.classList.add('is-thread');
        composer.style.display = 'flex';

        var conv = data && data.conversation;
        headerTitle.textContent = (conv && conv.subject)
            ? conv.subject
            : (conv && conv.type === 'broadcast' ? 'Announcement' : 'Conversation');

        bodyEl.innerHTML = '';
        var msgs = (data && data.messages) || [];
        if (!msgs.length) {
            bodyEl.appendChild(el('div', { 'class': 'p202m-empty' }, 'Send a message to get started.'));
        }
        msgs.forEach(function (m) { appendMessage(m); });
        scrollBottom();
    }

    function appendMessage(m) {
        var dir = m.direction === 'outbound' ? 'outbound' : 'inbound';
        var statusClass = '';
        if (m.delivery_status === 'pending') { statusClass = ' pending'; }
        else if (m.delivery_status === 'failed') { statusClass = ' failed'; }

        var wrap = el('div', { 'class': 'p202m-msg ' + dir + statusClass });
        wrap.appendChild(el('div', { 'class': 'p202m-msg-bubble' }, esc(m.body)));
        bodyEl.appendChild(wrap);

        var metaText = dir === 'outbound'
            ? (m.delivery_status === 'pending' ? 'Sending…'
                : m.delivery_status === 'failed' ? 'Not delivered' : 'Sent')
            : (m.author === 'system' ? 'System' : 'Prosper202 Team');
        var ago = timeAgo(m.created_at);
        if (ago) { metaText += ' · ' + ago; } // only add the separator when we have a timestamp
        bodyEl.appendChild(el('div', { 'class': 'p202m-msg-meta ' + dir }, esc(metaText)));
    }

    function scrollBottom() { bodyEl.scrollTop = bodyEl.scrollHeight; }

    // ---- actions ------------------------------------------------------------
    function refreshInbox() {
        return getJSON('202-account/ajax/messaging/inbox.php').then(function (data) {
            if (!data || !data.ok) { return; }
            setBadge(data.unread_count || 0);
            if (root.classList.contains('is-open') && view === 'list') {
                renderList(data);
            }
        });
    }

    function openThread(extId) {
        currentConv = extId;
        getJSON('202-account/ajax/messaging/thread.php?conversation=' + encodeURIComponent(extId))
            .then(function (data) {
                if (!data || !data.ok) { return; }
                renderThread(data);
                // Mark read via the CSRF-protected endpoint (thread.php is read-only),
                // then refresh so the unread badge drops.
                postForm('202-account/ajax/messaging/read.php', { conversation: extId })
                    .then(function () { refreshInbox(); });
            });
    }

    function startNew() {
        currentConv = null;
        renderThread({ conversation: null, messages: [] });
        input.focus();
    }

    function showList() {
        getJSON('202-account/ajax/messaging/inbox.php').then(function (data) {
            renderList(data || { conversations: [] });
        });
    }

    function onSubmit(e) {
        if (e) { e.preventDefault(); }
        var text = input.value.trim();
        if (!text) { return; }

        sendBtn.disabled = true;
        var optimistic = { direction: 'outbound', author: 'user', body: text, delivery_status: 'pending', created_at: null };
        if (view !== 'thread') { renderThread({ conversation: null, messages: [] }); }
        appendMessage(optimistic);
        scrollBottom();
        input.value = '';

        postForm('202-account/ajax/messaging/send.php', { conversation: currentConv || '', body: text })
            .then(function (data) {
                sendBtn.disabled = false;
                if (!data || !data.ok) {
                    // reflect failure on the optimistic bubble
                    openThreadOrRefresh();
                    return;
                }
                currentConv = data.conversation_external_id || currentConv;
                if (currentConv) { openThread(currentConv); } else { refreshInbox(); }
            });
    }

    function openThreadOrRefresh() {
        if (currentConv) { openThread(currentConv); } else { refreshInbox(); }
    }

    // ---- open/close ---------------------------------------------------------
    function show() {
        root.classList.add('is-open');
        if (view === 'thread' && currentConv) { openThread(currentConv); }
        else { showList(); }
    }
    function hide() { root.classList.remove('is-open'); }
    function toggle() { root.classList.contains('is-open') ? hide() : show(); }

    // ---- public API (segmentation + control) -------------------------------
    function track(attributes) {
        if (!attributes || typeof attributes !== 'object') { return; }
        postForm('202-account/ajax/messaging/track.php', { update: JSON.stringify(attributes) });
    }
    function trackEvent(name, metadata) {
        if (!name) { return; }
        var payload = { event_name: String(name) };
        if (metadata && typeof metadata === 'object') { payload.metadata = JSON.stringify(metadata); }
        postForm('202-account/ajax/messaging/track.php', payload);
    }

    function dispatch(command) {
        var args = Array.prototype.slice.call(arguments, 1);
        switch (command) {
            case 'update':     track(args[0]); break;
            case 'trackEvent': trackEvent(args[0], args[1]); break;
            case 'show':       show(); break;
            case 'hide':       hide(); break;
            case 'toggle':     toggle(); break;
            default: /* unknown command — ignore, Intercom-style */ break;
        }
    }

    // Replace the queue stub with the real dispatcher and flush buffered calls.
    window.Prosper202Messenger = function () {
        dispatch.apply(null, arguments);
    };

    function init() {
        build();
        queued.forEach(function (call) { dispatch.apply(null, call); });
        refreshInbox();
        pollTimer = window.setInterval(refreshInbox, POLL_MS);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
