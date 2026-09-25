/*
 * Prosper202 chrome behaviour.
 *
 * Plain JavaScript, so the chrome depends on no library a page may or may not
 * use. Everything here is progressive — the account menu is a <details>
 * element that works with no script at all; this only closes it when the
 * user clicks elsewhere or presses Escape, wires the theme switch, and draws
 * the update banner under the header when there is one.
 *
 * It also keeps the current section tab and sub-menu item in view whenever
 * those lists are too long to fit, which is a question about the list rather
 * than about the window — the Analyze strip overflows a 1280px desktop.
 *
 * The script is emitted in <head>, so it binds on DOMContentLoaded; binding at
 * parse time found no header and silently did nothing (caught in review).
 */
(function () {
    'use strict';

    /* The shell loads this script in <head>, before the header exists, so
       everything that touches the DOM waits for it to be parsed. */
    function init() {

    var menus = document.querySelectorAll('details.p202c-menu');

    function closeAll(except) {
        Array.prototype.forEach.call(menus, function (menu) {
            if (menu !== except && menu.open) {
                menu.removeAttribute('open');
            }
        });
    }

    if (menus.length) {
        document.addEventListener('click', function (event) {
            var inside = event.target.closest ? event.target.closest('details.p202c-menu') : null;
            closeAll(inside);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAll(null);
            }
        });
    }

    /* The section tabs and the sub-menu strip scroll sideways when they do not
       fit; scroll the current item to the middle so it is never hidden off the
       edge. Whether they fit is a question about the list, not about the
       window: the Analyze strip overflows a 1280px desktop, and a width
       threshold here left its last entry — the current one — clipped. */
    function centreCurrent(list) {
        var current = list.querySelector('li.active, .is-active');
        if (!current || list.scrollWidth <= list.clientWidth) {
            return;
        }
        var left = current.getBoundingClientRect().left - list.getBoundingClientRect().left + list.scrollLeft;
        list.scrollLeft = left - (list.clientWidth - current.offsetWidth) / 2;
    }

    function centreCurrentEverywhere() {
        Array.prototype.forEach.call(document.querySelectorAll('.p202c-tabs__list, .p202c-strip__list'), centreCurrent);
    }

    centreCurrentEverywhere();

    /* Once per frame, and only for a resize that changed the width. Reading
       scrollWidth and two bounding rects per list then writing scrollLeft is
       a forced layout, and this used to return after one innerWidth read on
       desktop; without the width guard a window drag would run it at the
       event rate. Skipping equal widths also stops a soft-keyboard's height
       change from throwing away a scroll position the reader set by hand. */
    var lastWidth = window.innerWidth;
    var pending = 0;
    window.addEventListener('resize', function () {
        if (window.innerWidth === lastWidth) {
            return;
        }
        /* Recorded before the pending check, not after it: a drag out to one
           width and back while a frame was still queued would otherwise leave
           lastWidth naming a width the window no longer has, and the return
           trip — the one that needs re-centring — would compare equal and be
           dropped. */
        lastWidth = window.innerWidth;
        if (pending) {
            return;
        }
        pending = window.requestAnimationFrame
            ? window.requestAnimationFrame(function () { pending = 0; centreCurrentEverywhere(); })
            : window.setTimeout(function () { pending = 0; centreCurrentEverywhere(); }, 16);
    });

    /* Theme switch: three states — follow the system, light, dark. The choice
       is a per-browser convenience, so localStorage is the right home; the
       shell applies it before first paint from the same key. */
    var STORAGE_KEY = 'p202-theme';
    var switchRoot = document.querySelector('.p202c-theme');

    function readChoice() {
        try {
            var value = localStorage.getItem(STORAGE_KEY);
            return value === 'light' || value === 'dark' ? value : 'system';
        } catch (error) {
            return 'system';
        }
    }

    function apply(choice) {
        var theme = choice;
        if (choice === 'system') {
            theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-bs-theme', theme);
        if (switchRoot) {
            Array.prototype.forEach.call(switchRoot.querySelectorAll('button[data-theme-choice]'), function (button) {
                button.setAttribute('aria-pressed', button.getAttribute('data-theme-choice') === choice ? 'true' : 'false');
            });
        }
    }

    if (switchRoot) {
        switchRoot.addEventListener('click', function (event) {
            var button = event.target.closest ? event.target.closest('button[data-theme-choice]') : null;
            if (!button) {
                return;
            }
            event.preventDefault();
            var choice = button.getAttribute('data-theme-choice');
            try {
                if (choice === 'system') {
                    localStorage.removeItem(STORAGE_KEY);
                } else {
                    localStorage.setItem(STORAGE_KEY, choice);
                }
            } catch (error) {
                /* Storage unavailable: the choice still applies to this page. */
            }
            apply(choice);
        });
        apply(readChoice());

        if (window.matchMedia) {
            var media = window.matchMedia('(prefers-color-scheme: dark)');
            var onChange = function () {
                if (readChoice() === 'system') {
                    apply('system');
                }
            };
            if (media.addEventListener) {
                media.addEventListener('change', onChange);
            } else if (media.addListener) {
                media.addListener(onChange);
            }
        }
    }

    /* The update banner (202-config/functions-update-banner.php). The check
       asks the release feed, so it waits until the page is idle rather than
       competing with the page's own requests. It is a notice, not the page:
       a failed request leaves the slot empty and says nothing. Hiding it
       snoozes it for an hour in this session. */
    var slot = document.getElementById('update_needed');
    if (slot && slot.getAttribute('data-p202-banner')) {
        var fetchText = function (url, options) {
            return window.fetch(url, options || { credentials: 'same-origin' }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text();
            });
        };
        var loadBanner = function () {
            fetchText(slot.getAttribute('data-p202-check'))
                .then(function () { return fetchText(slot.getAttribute('data-p202-banner')); })
                .then(function (html) { slot.innerHTML = html; })
                .catch(function () { /* a notice: leave the slot empty */ });
        };
        slot.addEventListener('click', function (event) {
            var close = event.target.closest ? event.target.closest('[data-p202-update-banner] .btn-close') : null;
            if (!close) {
                return;
            }
            fetchText(slot.getAttribute('data-p202-snooze'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'delay=1'
            }).catch(function () { /* the banner is gone for this page either way */ });
        });
        if (window.fetch) {
            if ('requestIdleCallback' in window) {
                window.requestIdleCallback(loadBanner, { timeout: 2000 });
            } else {
                window.setTimeout(loadBanner, 1500);
            }
        }
    }

    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
