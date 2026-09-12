/*
 * Behaviour for the v2 (Bootstrap 5.3) shell's component layer.
 *
 * Small and declarative: markup opts in with data attributes, nothing is
 * bound by id. The click, submit and toggle hooks are delegated, so they work
 * on content added later; tooltips, popovers and the restore of remembered
 * disclosures run at load, and again for any subtree a page passes to
 * window.p202ui.init(root) after inserting markup.
 *
 *   [data-p202-copy="<text>"]     copies the text and says "Copied" for a moment
 *   [data-p202-reveal="#id"]      swaps a masked value for the real one held in
 *                                 the target's data-p202-value attribute
 *   form[data-p202-confirm="…"]   asks before submitting
 *   [data-bs-toggle="tooltip"]    Bootstrap tooltips and popovers, initialised
 *   [data-bs-toggle="popover"]    here so pages never have to
 *   details[data-p202-remember]   keeps a disclosure's open state per browser
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

    /* Disclosures that opt in with data-p202-remember keep their open state
       per browser, so an advanced user who opened "Advanced" once finds it open
       next time and everyone else never sees it. Saving is delegated: toggle
       does not bubble, so the listener sits on the document in the capture
       phase, which reaches a <details> inserted at any time. Restoring runs at
       load and for any subtree passed to p202ui.init(root). */
    function rememberKey(element) {
        var key = element.getAttribute('data-p202-remember');
        return key ? 'p202-disclosure:' + key : null;
    }

    function restoreDisclosures(root) {
        Array.prototype.forEach.call((root || document).querySelectorAll('details[data-p202-remember]'), function (element) {
            var key = rememberKey(element);
            if (!key) {
                return;
            }
            try {
                var saved = localStorage.getItem(key);
                if (saved === 'open') {
                    element.open = true;
                } else if (saved === 'closed') {
                    element.open = false;
                }
            } catch (error) {}
        });
    }

    document.addEventListener('toggle', function (event) {
        var element = event.target;
        if (!element || !element.getAttribute || !element.hasAttribute('data-p202-remember')) {
            return;
        }
        var key = rememberKey(element);
        try {
            if (key) {
                localStorage.setItem(key, element.open ? 'open' : 'closed');
            }
        } catch (error) {}
    }, true);

    function initBootstrapHints(root) {
        if (!window.bootstrap) {
            return;
        }
        var scope = root || document;
        Array.prototype.forEach.call(scope.querySelectorAll('[data-bs-toggle="tooltip"]'), function (element) {
            window.bootstrap.Tooltip.getOrCreateInstance(element);
        });
        Array.prototype.forEach.call(scope.querySelectorAll('[data-bs-toggle="popover"]'), function (element) {
            window.bootstrap.Popover.getOrCreateInstance(element);
        });
    }

    /* Prepare a subtree: pages call p202ui.init(root) after inserting markup. */
    function init(root) {
        restoreDisclosures(root);
        initBootstrapHints(root);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }

    window.p202ui = { init: init, initHints: initBootstrapHints, copyText: copyText };
})();
