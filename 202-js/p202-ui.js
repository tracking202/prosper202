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
 *   select[data-p202-range="…"]   a report's range picker; the value names the
 *                                 option that means "custom"
 *   [data-p202-range-field="n"]   a date input that range picker governs; the
 *                                 value is the form field name it submits
 *                                 under while the picker reads "custom"
 *   [data-p202-range-hint]        a line shown only when those inputs are
 *                                 disabled, i.e. only without this script
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

    /* A report's range picker and the two date inputs it governs.
       The dates are submitted only while the picker reads its custom value,
       so the server has one unambiguous answer to "which window is this":
       without that, choosing a preset while the inputs still held the old
       window would silently keep the old window. A browser with JavaScript
       off gets the same answer, because the page renders the inputs disabled
       whenever the picker is not on custom.

       With JavaScript the inputs stay EDITABLE and are withheld from the
       request by dropping their `name` instead. A disabled input — and a
       readonly one — cannot be typed in, so "editing a date selects custom"
       could never once have fired: the first version of this shipped that
       branch unreachable, and the browser test that appeared to cover it
       had re-enabled the field itself before dispatching a synthetic event.
       Nothing is submitted either way; the difference is that a person can
       now pick a date without visiting the picker first, which is what the
       hint beside them says when they cannot. */
    function rangeFields(select) {
        var form = select.form || (select.closest ? select.closest('form') : null);
        return form ? form.querySelectorAll('[data-p202-range-field]') : [];
    }

    function syncRange(select) {
        var live = select.value === select.getAttribute('data-p202-range');
        Array.prototype.forEach.call(rangeFields(select), function (field) {
            /* The name is what a form submits, so removing it is what keeps
               a preset's window out of the request. The field itself stays
               editable — that is the whole point. */
            field.disabled = false;
            field.readOnly = false;
            if (live) {
                field.setAttribute('name', field.getAttribute('data-p202-range-field') || field.id);
            } else {
                field.removeAttribute('name');
            }
        });
        /* The server-rendered hint tells a reader with no JavaScript to use
           the picker, because without it the fields really are disabled.
           Here they are not, so the hint would be false. */
        var form = select.form || (select.closest ? select.closest('form') : null);
        var hint = form ? form.querySelector('[data-p202-range-hint]') : null;
        if (hint) {
            hint.hidden = true;
        }
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || !target.hasAttribute) {
            return;
        }
        if (target.hasAttribute('data-p202-range')) {
            syncRange(target);
            return;
        }
        if (!target.hasAttribute('data-p202-range-field')) {
            return;
        }
        var form = target.form || (target.closest ? target.closest('form') : null);
        var select = form ? form.querySelector('[data-p202-range]') : null;
        if (!select) {
            return;
        }
        /* Only if the picker really has that option: assigning a value a
           <select> does not carry blanks it, which would send no range at
           all. */
        var custom = select.getAttribute('data-p202-range');
        var previous = select.value;
        select.value = custom;
        if (select.value !== custom) {
            select.value = previous;
            return;
        }
        syncRange(select);
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
        Array.prototype.forEach.call((root || document).querySelectorAll('[data-p202-range]'), function (select) {
            syncRange(select);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }

    window.p202ui = { init: init, initHints: initBootstrapHints, copyText: copyText };
})();
