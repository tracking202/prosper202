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
 *   table[data-p202-sort]         a sortable table (p202_data_table()), wired
 *                                 to tablesort.js; the header says the order
 *                                 in aria-sort after every sort
 *
 * The v2 shell loads this deferred, after tablesort.js, so it runs once the
 * document is parsed; init() still waits for DOMContentLoaded when it has not
 * fired, so the file is safe to load either way.
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

    /* Sortable tables. tablesort.js marks the header it sorted by with
       `sort-down` or `sort-up`. Both of its comparisons — text and the
       number sort appended to the vendored build — order high to low, and
       `sort-down` reverses that, so `sort-down` is ascending (A to Z, 0 to
       9) and `sort-up` descending, on every column. That reads backwards
       and was first written the other way round for numbers, from a reading
       of the minified comparator; tests/browser/specs/ui-kit-partials
       .spec.js reads the rows after each click and caught it, and holds this
       mapping to the order actually on screen. */
    function sortDirection(th) {
        if (th.classList.contains('sort-down')) {
            return 'ascending';
        }
        return th.classList.contains('sort-up') ? 'descending' : null;
    }

    function initSortable(root) {
        if (!window.Tablesort) {
            return;
        }
        Array.prototype.forEach.call((root || document).querySelectorAll('table[data-p202-sort]'), function (table) {
            if (table.getAttribute('data-p202-sort-ready') === '1' || !table.tHead || !table.tHead.rows.length) {
                return;
            }
            table.setAttribute('data-p202-sort-ready', '1');
            table.addEventListener('afterSort', function () {
                var cells = table.tHead.rows[table.tHead.rows.length - 1].cells;
                Array.prototype.forEach.call(cells, function (th) {
                    var direction = sortDirection(th);
                    if (direction) {
                        th.setAttribute('aria-sort', direction);
                    } else {
                        th.removeAttribute('aria-sort');
                    }
                });
            });
            new window.Tablesort(table);
        });
    }

    /* Prepare a subtree: pages call p202ui.init(root) after inserting markup. */
    function init(root) {
        restoreDisclosures(root);
        initBootstrapHints(root);
        initSortable(root);
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

/* U4: Setup — remote suggestions for a text field, the <datalist> answer to
   the classic typeahead (UI standard, "one replacement per legacy job").

     input[data-p202-suggest-url="…%QUERY…"]

   The URL is same-origin and carries %QUERY; a page may rewrite it while
   the page is open (the redirector rewrites the type in it when a
   criterion's type changes). The field holds a comma-separated list, the
   shape the redirector's rule values have always been stored in, so only the
   part after the last comma is looked up, and each suggestion is offered as
   the whole field with that part replaced: choosing one keeps the values
   already typed. The answer is JSON — a list of strings or of
   {value, label} — and anything else (a network failure, an upstream that
   answered with nothing) offers no suggestions rather than an error: the
   field works without them. A URL still holding an unfilled %…% placeholder
   is not fetched. */
(function () {
    'use strict';

    var timers = typeof WeakMap === 'function' ? new WeakMap() : null;
    var sequence = 0;

    function listFor(input) {
        var id = input.getAttribute('list');
        var list = id ? document.getElementById(id) : null;
        if (list && list.hasAttribute('data-p202-suggest-list')) {
            return list;
        }
        list = document.createElement('datalist');
        list.id = (input.id || 'p202-suggest') + '-list-' + (++sequence);
        list.setAttribute('data-p202-suggest-list', '');
        input.parentNode.insertBefore(list, input.nextSibling);
        input.setAttribute('list', list.id);
        return list;
    }

    function suggest(input) {
        var template = input.getAttribute('data-p202-suggest-url') || '';
        var value = input.value;
        var cut = value.lastIndexOf(',');
        var prefix = cut === -1 ? '' : value.slice(0, cut + 1);
        var term = value.slice(cut + 1).trim();
        if (term.length < 2 || template.indexOf('%QUERY') === -1 || /%(?!QUERY)[A-Z]+%/.test(template)) {
            return;
        }
        var ticket = ++sequence;
        input.setAttribute('data-p202-suggest-ticket', String(ticket));
        fetch(template.replace('%QUERY', encodeURIComponent(term)), { credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.text() : ''; })
            .then(function (text) {
                if (input.getAttribute('data-p202-suggest-ticket') !== String(ticket)) {
                    return;
                }
                var items = [];
                try {
                    items = JSON.parse(text);
                } catch (error) {
                    items = [];
                }
                var list = listFor(input);
                list.innerHTML = '';
                (Array.isArray(items) ? items : []).slice(0, 20).forEach(function (item) {
                    var suggestion = typeof item === 'string' ? item : (item && item.value !== undefined ? String(item.value) : '');
                    if (suggestion === '') {
                        return;
                    }
                    var option = document.createElement('option');
                    option.value = prefix + suggestion;
                    if (item && typeof item === 'object' && item.label) {
                        option.label = String(item.label);
                    }
                    list.appendChild(option);
                });
            })
            .catch(function () {});
    }

    document.addEventListener('input', function (event) {
        var input = event.target;
        if (!input || !input.hasAttribute || !input.hasAttribute('data-p202-suggest-url')) {
            return;
        }
        if (timers) {
            window.clearTimeout(timers.get(input));
            timers.set(input, window.setTimeout(function () { suggest(input); }, 250));
        } else {
            suggest(input);
        }
    });
})();
