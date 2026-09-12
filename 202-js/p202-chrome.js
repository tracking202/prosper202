/*
 * Prosper202 chrome behaviour, shared by both page shells.
 *
 * Plain JavaScript on purpose: the classic shell runs jQuery 1.11 and the v2
 * shell jQuery 3.7, and the chrome must not care which. Everything here is
 * progressive — the account menu is a <details> element that works with no
 * script at all; this only closes it when the user clicks elsewhere or presses
 * Escape, and wires the theme switch the v2 shell renders.
 */
(function () {
    'use strict';

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

    /* Theme switch: three states — follow the system, light, dark. The choice
       is a per-browser convenience, so localStorage is the right home; the v2
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
})();
