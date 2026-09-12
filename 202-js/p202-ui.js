/*
 * Behaviour for the v2 (Bootstrap 5.3) shell's component layer.
 *
 * Small and declarative: markup opts in with data attributes, nothing is
 * bound by id, and every hook works on content added later (event delegation).
 *
 *   [data-p202-copy="<text>"]     copies the text and says "Copied" for a moment
 *   [data-p202-reveal="#id"]      swaps a masked value for the real one held in
 *                                 the target's data-p202-value attribute
 *   form[data-p202-confirm="…"]   asks before submitting
 *   [data-bs-toggle="tooltip"]    Bootstrap tooltips and popovers, initialised
 *   [data-bs-toggle="popover"]    here so pages never have to
 */
(function () {
    'use strict';

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            var ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (error) {
                ok = false;
            }
            document.body.removeChild(area);
            if (ok) {
                resolve();
            } else {
                reject(new Error('copy failed'));
            }
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-p202-copy]') : null;
        if (!button) {
            return;
        }
        event.preventDefault();
        var text = button.getAttribute('data-p202-copy') || '';
        var label = button.textContent;
        copyText(text).then(function () {
            button.classList.add('is-copied');
            button.textContent = 'Copied';
            window.setTimeout(function () {
                button.classList.remove('is-copied');
                button.textContent = label;
            }, 1600);
        }, function () {
            button.textContent = 'Press Ctrl+C';
            window.setTimeout(function () {
                button.textContent = label;
            }, 1600);
        });
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-p202-reveal]') : null;
        if (!button) {
            return;
        }
        event.preventDefault();
        var target = document.querySelector(button.getAttribute('data-p202-reveal'));
        if (!target) {
            return;
        }
        var revealed = target.getAttribute('data-p202-revealed') === '1';
        if (revealed) {
            target.textContent = target.getAttribute('data-p202-masked') || '';
            target.setAttribute('data-p202-revealed', '0');
            target.classList.add('p202-code__value--masked');
            button.textContent = 'Reveal';
        } else {
            if (!target.getAttribute('data-p202-masked')) {
                target.setAttribute('data-p202-masked', target.textContent);
            }
            target.textContent = target.getAttribute('data-p202-value') || '';
            target.setAttribute('data-p202-revealed', '1');
            target.classList.remove('p202-code__value--masked');
            button.textContent = 'Hide';
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.getAttribute || !form.hasAttribute('data-p202-confirm')) {
            return;
        }
        var message = form.getAttribute('data-p202-confirm') || 'Are you sure?';
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });

    function initBootstrapHints() {
        if (!window.bootstrap) {
            return;
        }
        Array.prototype.forEach.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'), function (element) {
            window.bootstrap.Tooltip.getOrCreateInstance(element);
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-bs-toggle="popover"]'), function (element) {
            window.bootstrap.Popover.getOrCreateInstance(element);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBootstrapHints);
    } else {
        initBootstrapHints();
    }

    window.p202ui = { initHints: initBootstrapHints, copyText: copyText };
})();
