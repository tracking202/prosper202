/*
 * Setup › Campaigns: the Link Assistant. When a campaign URL is a known
 * network's link without [[subid]], it suggests where that network takes a
 * sub id; nothing changes until the user presses the button.
 *
 * suggest() is Prosper202\Setup\LinkAssistant::suggest() line for line, over
 * the table the page prints (LinkAssistant::networks()); LinkAssistantTest
 * runs both on the same links.
 *
 *   [data-p202-link-assistant="#field"]   the suggestion box for that field
 *   script#p202-link-networks              the table, as JSON
 */
(function (root) {
    'use strict';

    var TOKEN = '[[subid]]';

    function split(url) {
        var hash = url.indexOf('#');
        var fragment = hash === -1 ? '' : url.slice(hash);
        var rest = hash === -1 ? url : url.slice(0, hash);
        var mark = rest.indexOf('?');
        return mark === -1 ? [rest, '', fragment] : [rest.slice(0, mark), rest.slice(mark), fragment];
    }

    function placeQuery(url, param) {
        var parts = split(url), path = parts[0], query = parts[1], fragment = parts[2];
        var m = new RegExp('([?&])(' + param + ')=([^&]*)', 'i').exec(query);
        if (m) {
            var value = m[3];
            var start = m.index + m[1].length + m[2].length + 1;
            query = query.slice(0, start) + TOKEN + query.slice(start + value.length);
            return [path + query + fragment, value === '' ? 'fill' : 'replace', value === '' ? null : value];
        }
        var joiner = query === '' ? '?' : (/[?&]$/.test(query) ? '' : '&');
        return [path + query + joiner + param + '=' + TOKEN + fragment, 'add', null];
    }

    function placePair(url, param) {
        var parts = split(url), path = parts[0], query = parts[1], fragment = parts[2];
        var m = new RegExp('/(' + param + '):([^/]*)', 'i').exec(path);
        if (m) {
            var value = m[2];
            var start = m.index + 1 + m[1].length + 1;
            path = path.slice(0, start) + TOKEN + path.slice(start + value.length);
            return [path + query + fragment, value === '' ? 'fill' : 'replace', value === '' ? null : value];
        }
        // A regex, not toLowerCase(): lowercasing can change a string's length.
        var found = /\/destination:/i.exec(path);
        var destination = found ? found.index : -1;
        var head = destination === -1 ? path.replace(/\/+$/, '') : path.slice(0, destination);
        var tail = destination === -1 ? '' : path.slice(destination);
        return [head + '/' + param + ':' + TOKEN + tail + query + fragment, 'add', null];
    }

    function placeSegment(url, match) {
        var parts = split(url), path = parts[0], query = parts[1], fragment = parts[2];
        var m = new RegExp(match, 'i').exec(path);
        if (!m) {
            return null;
        }
        var end = m.index + m[0].length;
        var value = path.slice(end).replace(/^\/+|\/+$/g, '');
        return [path.slice(0, end).replace(/\/+$/, '') + '/' + TOKEN + query + fragment, value === '' ? 'add' : 'replace', value === '' ? null : value];
    }

    function isLinkOf(url, pattern) {
        var parts = split(url);
        var m = new RegExp(pattern, 'i').exec(parts[0] + parts[1]);
        return m !== null && m.index <= parts[0].length;
    }

    function suggest(url, networks) {
        // PHP's trim() set, so both sides read the same link.
        url = String(url).replace(/^[ \n\r\t\v\0]+|[ \n\r\t\v\0]+$/g, '');
        if (url === '' || url.toLowerCase().indexOf(TOKEN) !== -1) {
            return null;
        }
        for (var i = 0; i < networks.length; i++) {
            var network = networks[i];
            if (!isLinkOf(url, network.match)) {
                continue;
            }
            var placed = network.place === 'pair' ? placePair(url, network.param)
                : network.place === 'segment' ? placeSegment(url, network.match)
                : placeQuery(url, network.param);
            if (placed === null) {
                return null;
            }
            return {
                network: network.id,
                name: network.name,
                param: network.param,
                action: placed[1],
                previous: placed[2],
                url: placed[0]
            };
        }
        return null;
    }

    root.P202LinkAssistant = { suggest: suggest };

    if (typeof document === 'undefined') {
        return;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function sentence(s) {
        if (s.action === 'replace') {
            return 'This looks like a ' + s.name + ' link. Its sub id goes in ' + s.param + ', which now holds "' + s.previous
                + '". Replace it with ' + TOKEN + ' so each conversion can be matched to its click?';
        }
        if (s.action === 'fill') {
            return 'This looks like a ' + s.name + ' link, and its ' + s.param + ' is empty. Put ' + TOKEN + ' in it so each conversion can be matched to its click?';
        }
        return 'This looks like a ' + s.name + ' link. ' + s.name + ' takes the sub id in ' + s.param + '. Add ' + TOKEN + ' there so each conversion can be matched to its click?';
    }

    function attach(box, networks) {
        var field = document.querySelector(box.getAttribute('data-p202-link-assistant'));
        if (!field) {
            return;
        }
        var dismissed = null;
        var shownFor = null;
        function render(force) {
            // The blur on pressing a button fires change: rebuilding then
            // would pull the button out from under the click.
            if (force !== true && shownFor === field.value) {
                return;
            }
            shownFor = field.value;
            var s = suggest(field.value, networks);
            box.textContent = '';
            if (!s || dismissed === field.value) {
                box.hidden = true;
                return;
            }
            var alert = el('div', 'alert alert-info p202-flash');
            alert.setAttribute('role', 'status');
            alert.appendChild(el('i', 'bi bi-magic'));
            var body = el('div', 'p202-flash__body');
            body.appendChild(el('div', '', sentence(s)));
            body.appendChild(el('code', 'd-block text-break my-2', s.url));
            var apply = el('button', 'btn btn-primary btn-sm me-2', s.action === 'replace' ? 'Replace' : 'Add ' + TOKEN);
            apply.type = 'button';
            apply.addEventListener('click', function () {
                field.value = s.url;
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.focus();
            });
            var dismiss = el('button', 'btn btn-secondary btn-sm', 'No thanks');
            dismiss.type = 'button';
            dismiss.addEventListener('click', function () {
                dismissed = field.value;
                render(true);
            });
            body.appendChild(apply);
            body.appendChild(dismiss);
            alert.appendChild(body);
            box.appendChild(alert);
            box.hidden = false;
        }
        field.addEventListener('input', render);
        field.addEventListener('change', render);
        render();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var source = document.getElementById('p202-link-networks');
        if (!source) {
            return;
        }
        var networks;
        try {
            networks = JSON.parse(source.textContent);
        } catch (e) {
            return;
        }
        Array.prototype.forEach.call(document.querySelectorAll('[data-p202-link-assistant]'), function (box) {
            attach(box, networks);
        });
    });
})(typeof window !== 'undefined' ? window : globalThis);
