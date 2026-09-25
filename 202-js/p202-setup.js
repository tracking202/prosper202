/*
 * Behaviour for the Setup family on the v2 shell (U4).
 *
 * Loaded deferred by every Setup page, after p202-ui.js, so it runs once the
 * document is parsed; everything still starts from DOMContentLoaded or a
 * delegated listener, the porting rule for a v2 page script. Markup opts in
 * with data attributes and nothing here is bound by a page's own id except
 * the page sections at the bottom, each of which starts by looking for its
 * page and returns when it is somewhere else.
 *
 *   [data-p202-insert="tok"]            inserts tok at the caret of the field
 *     [data-p202-insert-into="#id"]     named here (the URL placeholders)
 *   [data-p202-filter-list="#list"]     a search box that hides the list's
 *                                       [data-p202-filter-text] items that do
 *                                       not match; a parent stays while any
 *                                       child matches
 *   [data-p202-show-when="name=a,b"]    shown only while the form control
 *                                       `name` reads a or b; with
 *                                       data-p202-disable-hidden its controls
 *                                       are disabled while hidden, so two
 *                                       controls sharing a name never both
 *                                       post
 *   select[data-p202-filter-by="#p"]    keeps only the options whose
 *     [data-p202-filter-key="k"]        data-k equals #p's value (options
 *                                       without data-k always stay)
 *   input[data-p202-sync-from="#sel"]   takes the chosen option's data-<attr>
 *     [data-p202-sync-attr="attr"]      (a hidden aff_network_id following
 *                                       the chosen campaign)
 *   [data-p202-mirror="#field"]         shows the field's text as it is typed,
 *                                       or its own data-p202-mirror-empty
 *   [data-p202-add-row="#template"]     appends the <template>'s content to
 *     [data-p202-add-into="#list"]      the list; [data-p202-remove-row]
 *                                       removes its [data-p202-row]
 *   form[data-p202-ajax-target="#out"]  posts through jQuery (so the session
 *                                       token rides along) and puts the
 *                                       answer in #out
 */
(function () {
    'use strict';

    function $$(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    // ─── Placeholders: insert at the caret ──────────────────────────────
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-p202-insert]') : null;
        if (!button) {
            return;
        }
        event.preventDefault();
        var field = document.querySelector(button.getAttribute('data-p202-insert-into'));
        if (!field) {
            return;
        }
        var text = button.getAttribute('data-p202-insert') || '';
        var start = typeof field.selectionStart === 'number' ? field.selectionStart : field.value.length;
        var end = typeof field.selectionEnd === 'number' ? field.selectionEnd : start;
        field.value = field.value.slice(0, start) + text + field.value.slice(end);
        field.focus();
        field.setSelectionRange(start + text.length, start + text.length);
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });

    // ─── Filter a list in place ─────────────────────────────────────────
    function filterList(input) {
        var list = document.querySelector(input.getAttribute('data-p202-filter-list'));
        if (!list) {
            return;
        }
        var query = input.value.trim().toLowerCase();
        var items = $$('[data-p202-filter-text]', list);
        // Deepest first, so a parent can ask whether any child survived.
        items.reverse().forEach(function (item) {
            var own = (item.getAttribute('data-p202-filter-text') || '').toLowerCase();
            var childShown = $$('[data-p202-filter-text]', item).some(function (child) { return !child.hidden; });
            item.hidden = query !== '' && own.indexOf(query) === -1 && !childShown;
        });
    }

    document.addEventListener('input', function (event) {
        var target = event.target;
        if (target && target.hasAttribute && target.hasAttribute('data-p202-filter-list')) {
            filterList(target);
        }
    });

    // ─── Show a section only for some values of a control ───────────────
    function controlValue(form, name) {
        var scope = form || document;
        var controls = $$('[name="' + name + '"]', scope);
        for (var i = 0; i < controls.length; i++) {
            var control = controls[i];
            if (control.type === 'radio' || control.type === 'checkbox') {
                if (control.checked) {
                    return control.value;
                }
            } else {
                return control.value;
            }
        }
        return '';
    }

    function applyShowWhen(root) {
        $$('[data-p202-show-when]', root).forEach(function (section) {
            var rule = section.getAttribute('data-p202-show-when') || '';
            var at = rule.indexOf('=');
            if (at < 1) {
                return;
            }
            var name = rule.slice(0, at);
            var values = rule.slice(at + 1).split(',');
            var form = section.closest('form');
            // Sections are visited in document order, so an enclosing
            // section has already decided: a section inside a hidden one
            // stays hidden (and disabled) whatever its own control reads,
            // or a nested field would post from a part of the form the
            // user cannot see.
            var enclosing = section.parentElement ? section.parentElement.closest('[data-p202-show-when]') : null;
            var show = values.indexOf(controlValue(form, name)) !== -1 && !(enclosing && enclosing.hidden);
            section.hidden = !show;
            if (section.hasAttribute('data-p202-disable-hidden')) {
                $$('input, select, textarea, button', section).forEach(function (control) {
                    control.disabled = !show;
                });
            }
        });
    }

    // ─── Keep a select's options to those under a parent value ──────────
    function applyFilterBy(select) {
        var parent = document.querySelector(select.getAttribute('data-p202-filter-by'));
        var key = select.getAttribute('data-p202-filter-key');
        if (!parent || !key) {
            return;
        }
        if (!select.p202AllOptions) {
            select.p202AllOptions = Array.prototype.map.call(select.options, function (option) {
                return option.cloneNode(true);
            });
        }
        var current = select.value;
        var wanted = parent.value;
        while (select.options.length) {
            select.remove(0);
        }
        select.p202AllOptions.forEach(function (option) {
            var owner = option.getAttribute('data-' + key);
            if (owner === null || (wanted !== '' && owner === wanted)) {
                select.appendChild(option.cloneNode(true));
            }
        });
        select.value = current;
        if (select.value !== current) {
            select.selectedIndex = 0;
        }
        var empty = select.getAttribute('data-p202-filter-empty');
        var only = select.options.length <= 1;
        if (empty !== null) {
            select.options[0].textContent = only ? empty : (select.getAttribute('data-p202-filter-choose') || select.options[0].textContent);
        }
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function applySync(input) {
        var source = document.querySelector(input.getAttribute('data-p202-sync-from'));
        if (!source) {
            return;
        }
        var option = source.options[source.selectedIndex];
        var value = option ? option.getAttribute('data-' + input.getAttribute('data-p202-sync-attr')) : null;
        input.value = value === null ? '' : value;
    }

    function applyMirror(target) {
        var field = document.querySelector(target.getAttribute('data-p202-mirror'));
        if (!field) {
            return;
        }
        var text = field.value.trim();
        target.textContent = text !== '' ? field.value : (target.getAttribute('data-p202-mirror-empty') || '');
    }

    // The chosen ad's copy, from the option's data attributes, in the preview
    // card the select names; the enabled select wins when two share a card.
    function applyAdPreviews() {
        $$('select[data-p202-ad-preview]').forEach(function (select) {
            if (select.disabled) {
                return;
            }
            var card = document.querySelector(select.getAttribute('data-p202-ad-preview'));
            if (!card) {
                return;
            }
            var option = select.options[select.selectedIndex];
            $$('[data-ad]', card).forEach(function (part) {
                if (!part.hasAttribute('data-ad-default')) {
                    part.setAttribute('data-ad-default', part.textContent);
                }
                var value = option ? option.getAttribute('data-' + part.getAttribute('data-ad')) : null;
                part.textContent = value !== null && value !== '' ? value : part.getAttribute('data-ad-default');
            });
            card.classList.toggle('opacity-50', !option || option.getAttribute('data-headline') === null);
        });
    }

    function refresh(changed) {
        applyShowWhen(document);
        $$('select[data-p202-filter-by]').forEach(function (select) {
            if (!changed || select.getAttribute('data-p202-filter-by') === '#' + changed.id) {
                applyFilterBy(select);
            }
        });
        $$('input[data-p202-sync-from]').forEach(applySync);
        applyAdPreviews();
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || !target.matches) {
            return;
        }
        if (target.matches('input, select, textarea')) {
            refresh(target);
        }
    });

    document.addEventListener('input', function (event) {
        var target = event.target;
        if (!target || !target.id) {
            return;
        }
        $$('[data-p202-mirror="#' + target.id + '"]').forEach(applyMirror);
    });

    // ─── Repeatable rows ─────────────────────────────────────────────────
    var rowCounter = 0;

    function addRow(button) {
        return cloneTemplate(
            document.querySelector(button.getAttribute('data-p202-add-row')),
            document.querySelector(button.getAttribute('data-p202-add-into'))
        );
    }

    function cloneTemplate(template, into) {
        if (!template || !into || !template.content) {
            return null;
        }
        var fragment = template.content.cloneNode(true);
        var row = fragment.firstElementChild;
        // A template's ids repeat with every copy; make each copy's unique
        // and point its labels at the right control.
        rowCounter += 1;
        $$('[id]', fragment).forEach(function (element) {
            var id = element.id;
            element.id = id + '-' + rowCounter;
            $$('label[for="' + id + '"]', fragment).forEach(function (label) {
                label.setAttribute('for', element.id);
            });
        });
        into.appendChild(fragment);
        if (window.p202ui && row) {
            window.p202ui.init(row);
        }
        return row;
    }

    document.addEventListener('click', function (event) {
        var add = event.target.closest ? event.target.closest('[data-p202-add-row]') : null;
        if (add) {
            event.preventDefault();
            var row = addRow(add);
            if (row) {
                row.dispatchEvent(new CustomEvent('p202:row-added', { bubbles: true }));
                var first = row.querySelector('input:not([type="hidden"]), select, textarea');
                if (first) {
                    first.focus();
                }
            }
            return;
        }
        var remove = event.target.closest ? event.target.closest('[data-p202-remove-row]') : null;
        if (remove) {
            event.preventDefault();
            var gone = remove.closest('[data-p202-row]');
            var list = gone ? gone.parentNode : null;
            if (gone) {
                gone.remove();
            }
            if (list) {
                list.dispatchEvent(new CustomEvent('p202:row-removed', { bubbles: true }));
            }
        }
    });

    // ─── Forms answered in place ─────────────────────────────────────────
    function flashHtml(kind, text) {
        var box = document.createElement('div');
        box.className = 'alert ' + (kind === 'bad' ? 'alert-danger' : 'alert-info') + ' p202-flash';
        box.setAttribute('role', 'status');
        var icon = document.createElement('i');
        icon.className = 'bi ' + (kind === 'bad' ? 'bi-x-circle' : 'bi-info-circle');
        var body = document.createElement('div');
        body.className = 'p202-flash__body';
        body.textContent = text;
        box.appendChild(icon);
        box.appendChild(body);
        return box;
    }

    function showAnswer(out, html) {
        out.innerHTML = html;
        out.removeAttribute('aria-busy');
        if (window.p202ui) {
            window.p202ui.init(out);
        }
        out.dispatchEvent(new CustomEvent('p202:answered', { bubbles: true }));
    }

    function postInPlace(form) {
        var out = document.querySelector(form.getAttribute('data-p202-ajax-target'));
        if (!out || !window.jQuery) {
            return;
        }
        form.dispatchEvent(new CustomEvent('p202:before-post', { bubbles: true }));
        out.setAttribute('aria-busy', 'true');
        out.innerHTML = '<div class="p202-skeleton mb-2" style="height: 14px; width: 70%;" aria-hidden="true"></div>'
            + '<div class="p202-skeleton mb-2" style="height: 14px; width: 55%;" aria-hidden="true"></div>'
            + '<div class="p202-skeleton" style="height: 64px;" aria-hidden="true"></div>';
        var button = form.querySelector('[type="submit"]');
        if (button) {
            button.disabled = true;
        }
        window.jQuery.post(form.getAttribute('action'), window.jQuery(form).serialize())
            .done(function (html) {
                showAnswer(out, html);
            })
            .fail(function (xhr) {
                out.removeAttribute('aria-busy');
                out.innerHTML = '';
                var body = xhr && xhr.responseText && xhr.status === 403
                    ? xhr.responseText
                    : 'The request failed (' + (xhr && xhr.status ? 'HTTP ' + xhr.status : 'network error') + '). Your session may have expired; reload the page and try again.';
                out.appendChild(flashHtml('bad', body));
            })
            .always(function () {
                if (button) {
                    button.disabled = false;
                }
            });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.hasAttribute || !form.hasAttribute('data-p202-ajax-target')) {
            return;
        }
        event.preventDefault();
        // Native constraint validation still runs: submit only fires once the
        // browser accepted every required field.
        postInPlace(form);
    });

    // ─── Traffic sources: the custom-variables dialog ───────────────────
    function variablesDialog() {
        var modal = document.getElementById('variables-modal');
        var form = document.getElementById('variables-form');
        var rows = document.getElementById('variable-rows');
        var adder = modal ? modal.querySelector('[data-p202-add-row]') : null;
        if (!modal || !form || !rows || !adder) {
            return;
        }
        var errors = modal.querySelector('[data-variables-error]');
        var networkId = '';

        function say(text) {
            errors.innerHTML = '';
            if (text) {
                errors.appendChild(flashHtml('bad', text));
            }
        }

        modal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) {
                return;
            }
            networkId = button.getAttribute('data-ppc-network-id') || '';
            modal.querySelector('.modal-title').textContent = 'Custom variables · ' + (button.getAttribute('data-ppc-network-name') || '');
            rows.innerHTML = '';
            say('');
            var saved = [];
            try {
                saved = JSON.parse(button.getAttribute('data-variables') || '[]');
            } catch (error) {
                // Never show an unreadable list as "no variables": saving an
                // empty dialog would delete them (error pattern #4).
                say('The saved variables could not be read, so the dialog is not showing them. Reload the page before saving.');
                form.querySelector('[data-variables-save]').disabled = true;
                return;
            }
            form.querySelector('[data-variables-save]').disabled = false;
            saved.forEach(function (variable) {
                var row = addRow(adder);
                row.setAttribute('data-var-id', String(variable.id));
                ['name', 'parameter', 'placeholder'].forEach(function (field) {
                    row.querySelector('[data-var="' + field + '"]').value = variable[field] || '';
                });
            });
            if (saved.length === 0) {
                addRow(adder);
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var vars = $$('[data-var-id]', rows).map(function (row) {
                return {
                    id: row.getAttribute('data-var-id') || 'false',
                    name: row.querySelector('[data-var="name"]').value,
                    parameter: row.querySelector('[data-var="parameter"]').value,
                    placeholder: row.querySelector('[data-var="placeholder"]').value
                };
            });
            var payload = vars.length
                ? { post_vars: 1, ppc_network_id: networkId, vars: vars }
                : { delete_vars: 1, ppc_network_id: networkId };
            var save = form.querySelector('[data-variables-save]');
            save.disabled = true;
            window.jQuery.post(form.getAttribute('data-variables-url'), payload)
                .done(function (answer) {
                    if (String(answer).trim() === 'DONE!') {
                        window.location.href = window.location.pathname + '?variables_saved=1';
                        return;
                    }
                    say('The variables were not saved: ' + String(answer).trim());
                    save.disabled = false;
                })
                .fail(function (xhr) {
                    say('The variables were not saved: ' + ((xhr && xhr.responseText) || 'the request failed') + '.');
                    save.disabled = false;
                });
        });
    }

    document.addEventListener('DOMContentLoaded', variablesDialog);

    // ─── Campaigns: a network integration's offer search ────────────────
    // A port of 202-js/dni.search.offers.tablesorter.php without the
    // tablesorter pager: the rows are the integration server's own markup,
    // as they always were; paging and the name filter are two buttons and a
    // box. Choosing "set up" on an offer fills the campaign form.
    function dniOffers() {
        var modal = document.getElementById('dni-offers-modal');
        if (!modal || !window.jQuery || !window.bootstrap) {
            return;
        }
        var jq = window.jQuery;
        var url = modal.getAttribute('data-offers-url');
        var tbody = modal.querySelector('[data-dni-table] tbody');
        var status = modal.querySelector('[data-dni-status]');
        var count = modal.querySelector('[data-dni-count]');
        var state = { dni: '', page: 0, size: 25, total: 0, name: '', offer: '' };

        function load() {
            status.innerHTML = '<div class="p202-skeleton mb-2" style="height: 14px; width: 40%;" aria-hidden="true"></div>';
            var query = url + '?all_offers&dni=' + encodeURIComponent(state.dni) + '&offset=' + state.page
                + '&limit=' + state.size + '&column';
            if (state.offer !== '') {
                query += '&filter[0]=' + encodeURIComponent(state.offer);
            }
            if (state.name !== '') {
                query += '&filter[1]=' + encodeURIComponent(state.name);
            }
            jq.get(query, null, null, 'html').done(function (html) {
                var box = jq(html).filter('#rowContainer');
                status.innerHTML = '';
                if (!box.length) {
                    status.appendChild(flashHtml('bad', 'The network did not answer with offers. Try again in a minute.'));
                    tbody.innerHTML = '';
                    return;
                }
                state.total = parseInt(box.data('rows'), 10) || 0;
                modal.querySelector('.modal-title').textContent = String(box.data('network') || 'Network offers');
                jq(tbody).html(box.html());
                var first = state.page * state.size + 1;
                var last = Math.min(state.total, (state.page + 1) * state.size);
                count.textContent = state.total === 0 ? 'No offers' : first + ' to ' + last + ' of ' + state.total;
                modal.querySelector('[data-dni-page="-1"]').disabled = state.page === 0;
                modal.querySelector('[data-dni-page="1"]').disabled = last >= state.total;
                if (state.offer !== '') {
                    var toggle = tbody.querySelector('[data-offer-id="' + state.offer + '"]');
                    if (toggle) {
                        toggle.click();
                    }
                    state.offer = '';
                }
            }).fail(function (xhr) {
                status.innerHTML = '';
                status.appendChild(flashHtml('bad', 'The offers could not be loaded (' + (xhr && xhr.status ? 'HTTP ' + xhr.status : 'network error') + ').'));
            });
        }

        modal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (button && button.getAttribute('data-dni-id')) {
                state.dni = button.getAttribute('data-dni-id');
            }
            state.page = 0;
            load();
        });
        modal.addEventListener('hidden.bs.modal', function () {
            tbody.innerHTML = '';
        });
        modal.querySelector('[data-dni-filter]').addEventListener('submit', function (event) {
            event.preventDefault();
            state.name = document.getElementById('dni-filter-name').value.trim();
            state.page = 0;
            load();
        });
        $$('[data-dni-page]', modal).forEach(function (button) {
            button.addEventListener('click', function () {
                state.page = Math.max(0, state.page + parseInt(button.getAttribute('data-dni-page'), 10));
                load();
            });
        });

        function offerRequest(query, done) {
            jq.get(url + '?' + query, null, null, 'html').done(function (answer) {
                // The server answers JSON on its own errors and markup otherwise.
                try {
                    JSON.parse(answer);
                    done(null);
                } catch (error) {
                    done(answer);
                }
            }).fail(function () {
                done(null);
            });
        }

        jq(modal).on('click', '.toggle', function (event) {
            event.preventDefault();
            var link = this;
            var row = link.closest('tr');
            var child = row.nextElementSibling;
            if (child && child.classList.contains('tablesorter-childRow')) {
                child.remove();
                return;
            }
            offerRequest('get_offer&dni=' + encodeURIComponent(state.dni) + '&offer_id=' + encodeURIComponent(link.getAttribute('data-offer-id')), function (html) {
                if (html !== null) {
                    jq(row).after(html);
                }
            });
        });
        jq(modal).on('click', 'button.requestOffer', function (event) {
            event.preventDefault();
            var button = this;
            button.disabled = true;
            offerRequest('request_offer_access&dni=' + encodeURIComponent(state.dni) + '&offer_id=' + encodeURIComponent(button.getAttribute('data-offer-id'))
                + '&type=' + encodeURIComponent(button.getAttribute('data-type')), function (html) {
                if (html === null) {
                    button.disabled = false;
                    return;
                }
                jq(button).parents().eq(1).html(html);
            });
        });
        jq(modal).on('submit', 'form[name="offersQuestionsForm"]', function (event) {
            event.preventDefault();
            var form = this;
            var button = form.querySelector('button');
            if (button) {
                button.disabled = true;
            }
            jq.post(url + '?submit_offer_questions&dni=' + encodeURIComponent(state.dni) + '&offer_id=' + encodeURIComponent(button ? button.getAttribute('data-offer-id') : ''),
                jq(form).serializeArray()).done(function (answer) {
                jq(form).parent().html(answer);
            });
        });
        jq(modal).on('click', 'button.setupOffer', function (event) {
            event.preventDefault();
            var button = this;
            var offer = button.getAttribute('data-offer-id');
            button.disabled = true;
            jq.getJSON(url + '?setup_offer&ddlci=' + encodeURIComponent(modal.getAttribute('data-ddlci') || '') + '&dni=' + encodeURIComponent(state.dni)
                + '&offer_id=' + encodeURIComponent(offer)).done(function (data) {
                var set = function (selector, value) {
                    var field = document.querySelector(selector);
                    if (field && value !== undefined && value !== null) {
                        field.value = value;
                        field.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                };
                set('input[name="dni_id"]', state.dni);
                set('input[name="dni_offer_id"]', offer);
                set('#aff_network_id', data.aff_network_id);
                set('#aff_campaign_name', data.name);
                set('#aff_campaign_url', data.trk_url);
                set('#aff_campaign_payout', data.payout);
                window.bootstrap.Modal.getOrCreateInstance(modal).hide();
                var name = document.getElementById('aff_campaign_name');
                if (name) {
                    name.focus();
                }
            }).fail(function () {
                button.disabled = false;
                status.innerHTML = '';
                status.appendChild(flashHtml('bad', 'The offer could not be set up. Try again, or copy its link by hand.'));
            });
        });

        if (modal.getAttribute('data-open-dni')) {
            state.dni = modal.getAttribute('data-open-dni');
            state.offer = modal.getAttribute('data-open-offer') || '';
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    }

    document.addEventListener('DOMContentLoaded', dniOffers);

    // ─── Redirector: the rules editor ───────────────────────────────────
    // The rules are saved by tracking202/ajax/rotator.php's post_rules
    // handler, unchanged: this builds the same payload the classic editor
    // built (rotator_id, default_type, defaults, and per rule rule_id,
    // rule_name, status, split, redirects[], criteria[]), and posts it
    // through jQuery so the session token rides along. Native validation
    // runs first: every visible field is `required`, and a hidden one is
    // disabled, which exempts it.
    function rulesEditor() {
        var picker = document.querySelector('[data-rotator-picker] select');
        if (picker) {
            picker.addEventListener('change', function () {
                if (picker.value !== '0') {
                    picker.form.submit();
                }
            });
        }
        var form = document.getElementById('rules-form');
        if (!form) {
            return;
        }
        var ruleList = document.getElementById('rule-list');
        var errors = form.querySelector('[data-rules-error]');

        function syncDestination(scope) {
            var type = scope.querySelector('[data-rule-field="redirect_type"], select[name="default_type"]');
            if (!type) {
                return;
            }
            $$('[data-destination]', scope).forEach(function (label) {
                var on = label.getAttribute('data-destination') === type.value;
                label.hidden = !on;
                $$('input, select', label).forEach(function (control) {
                    control.disabled = !on;
                });
            });
        }

        function syncSplit(rule) {
            var split = rule.querySelector('[data-rule-field="split"]').checked;
            var list = rule.querySelector('[data-redirect-list]');
            list.setAttribute('data-split', split ? '1' : '0');
            $$('[data-redirect]', list).forEach(function (row, index) {
                // Without a split test a rule has one destination, the first.
                var used = split || index === 0;
                row.hidden = !used;
                $$('input, select', row).forEach(function (control) {
                    control.disabled = !used;
                });
                if (used) {
                    syncDestination(row);
                }
            });
            $$('[data-split-only]', rule).forEach(function (element) {
                element.hidden = !split;
                $$('input', element).forEach(function (control) {
                    control.disabled = !split;
                });
            });
        }

        function syncCriterion(row) {
            var type = row.querySelector('[data-rule-field="type"]').value;
            var value = row.querySelector('[data-rule-field="value"]');
            if (!value.hasAttribute('data-suggest-template')) {
                value.setAttribute('data-suggest-template', value.getAttribute('data-p202-suggest-url') || '');
            }
            if (type === 'device') {
                value.removeAttribute('data-p202-suggest-url');
                value.setAttribute('list', 'rotator-devices');
            } else if (type === 'ip') {
                value.removeAttribute('data-p202-suggest-url');
                value.removeAttribute('list');
            } else {
                if (value.getAttribute('list') === 'rotator-devices') {
                    value.removeAttribute('list');
                }
                value.setAttribute('data-p202-suggest-url', value.getAttribute('data-suggest-template').replace('%TYPE%', encodeURIComponent(type)));
            }
        }

        function syncRule(rule) {
            $$('[data-criteria]', rule).forEach(syncCriterion);
            syncSplit(rule);
        }

        form.addEventListener('change', function (event) {
            var target = event.target;
            var field = target.getAttribute('data-rule-field');
            if (field === 'redirect_type') {
                syncDestination(target.closest('[data-redirect]'));
            } else if (target.name === 'default_type') {
                syncDestination(form.querySelector('[data-defaults]'));
            } else if (field === 'split') {
                syncSplit(target.closest('[data-rule]'));
            } else if (field === 'type') {
                syncCriterion(target.closest('[data-criteria]'));
            }
        });

        form.addEventListener('click', function (event) {
            var button = event.target.closest('button');
            if (!button) {
                return;
            }
            var rule = button.closest('[data-rule]');
            if (button.hasAttribute('data-add-criterion')) {
                var criterion = cloneTemplate(document.getElementById('criterion-template'), rule.querySelector('[data-criteria-list]'));
                syncCriterion(criterion);
                criterion.querySelector('[data-rule-field="value"]').focus();
            } else if (button.hasAttribute('data-add-redirect')) {
                var redirect = cloneTemplate(document.getElementById('redirect-template'), rule.querySelector('[data-redirect-list]'));
                syncSplit(rule);
                redirect.querySelector('select').focus();
            } else if (button.hasAttribute('data-add-rule')) {
                var added = cloneTemplate(document.getElementById('rule-template'), ruleList);
                syncRule(added);
                added.querySelector('[data-rule-field="rule_name"]').focus();
            }
        });

        function destination(scope, prefix) {
            var type = scope.querySelector(prefix === 'default' ? 'select[name="default_type"]' : '[data-rule-field="redirect_type"]').value;
            var pick = function (suffix) {
                var control = prefix === 'default'
                    ? scope.querySelector('[name="default_' + suffix + '"]')
                    : scope.querySelector('[data-rule-field="redirect_' + suffix + '"]');
                return control ? control.value : '';
            };
            return { type: type, value: type === 'url' ? pick('url') : (type === 'lp' ? pick('lp') : pick('campaign')) };
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            errors.innerHTML = '';
            var defaults = destination(form.querySelector('[data-defaults]'), 'default');
            var rules = $$('[data-rule]', ruleList).map(function (rule) {
                var split = rule.querySelector('[data-rule-field="split"]').checked;
                var redirects = $$('[data-redirect]', rule).filter(function (row, index) {
                    return split || index === 0;
                }).map(function (row) {
                    var target = destination(row, 'redirect');
                    var entry = { id: row.getAttribute('data-redirect-id') || 'none', type: target.type, value: target.value };
                    if (split) {
                        entry.weight = row.querySelector('[data-rule-field="weight"]').value;
                    }
                    return entry;
                });
                var criteria = $$('[data-criteria]', rule).map(function (row) {
                    return {
                        criteria_id: row.getAttribute('data-criteria-id') || 'none',
                        type: row.querySelector('[data-rule-field="type"]').value,
                        statement: row.querySelector('[data-rule-field="statement"]').value,
                        value: row.querySelector('[data-rule-field="value"]').value.split(',').map(function (part) {
                            return part.trim();
                        }).filter(function (part) {
                            return part !== '';
                        }).join(',')
                    };
                });
                return {
                    rule_id: rule.getAttribute('data-rule-id') || 'none',
                    rule_name: rule.querySelector('[data-rule-field="rule_name"]').value,
                    status: rule.querySelector('[data-rule-field="inactive"]').checked ? 'inactive' : 'active',
                    split: split,
                    redirects: redirects,
                    criteria: criteria
                };
            });
            if (rules.length === 0) {
                errors.appendChild(flashHtml('bad', 'Add a rule before saving.'));
                return;
            }
            var save = form.querySelector('[type="submit"]');
            save.disabled = true;
            window.jQuery.post(form.getAttribute('data-rules-url'), {
                post_rules: 1,
                rotator_id: form.getAttribute('data-rotator-id'),
                data: rules,
                default_type: defaults.type,
                defaults: defaults.value,
                token: form.querySelector('input[name="token"]').value
            }).done(function (answer) {
                var result = String(answer).trim();
                if (result === 'DONE') {
                    window.location.href = form.getAttribute('data-done-url');
                    return;
                }
                save.disabled = false;
                errors.appendChild(flashHtml('bad', 'The rules were not saved. Every rule needs a name, a criterion with a value and a destination, and a split test needs a weight for each destination.'));
                errors.scrollIntoView({ block: 'center' });
            }).fail(function (xhr) {
                save.disabled = false;
                errors.appendChild(flashHtml('bad', 'The rules were not saved (' + (xhr && xhr.status ? 'HTTP ' + xhr.status : 'network error') + '). Your session may have expired; reload the page and try again.'));
            });
        });

        syncDestination(form.querySelector('[data-defaults]'));
        $$('[data-rule]', ruleList).forEach(syncRule);
    }

    document.addEventListener('DOMContentLoaded', rulesEditor);

    // ─── Get LP Code › Advanced: the offers on the page ─────────────────
    // get_adv_landing_code.php reads offer_typeN, aff_campaign_id_N and
    // rotator_id_N for N = 1 … counter + 1, so the offers are numbered from
    // 1 without gaps after every add and remove, and counter follows.
    function renumberOffers(form) {
        var offers = $$('[data-lp-offer]', form);
        offers.forEach(function (offer, index) {
            var n = String(index + 1);
            $$('[data-lp-offer-type]', offer).forEach(function (radio, which) {
                radio.name = 'offer_type' + n;
                radio.id = 'offer_type' + n + (which + 1);
                if (radio.nextElementSibling) {
                    radio.nextElementSibling.setAttribute('for', radio.id);
                }
            });
            [['campaign', 'aff_campaign_id_'], ['rotator', 'rotator_id_']].forEach(function (pair) {
                var pick = offer.querySelector('[data-lp-offer-pick="' + pair[0] + '"]');
                var select = pick.querySelector('select');
                select.name = pair[1] + n;
                select.id = pair[1] + n;
                pick.querySelector('label').setAttribute('for', select.id);
            });
        });
        var counter = form.querySelector('[data-lp-counter]');
        if (counter) {
            counter.value = String(Math.max(0, offers.length - 1));
        }
    }

    document.addEventListener('p202:row-added', function (event) {
        var form = event.target.closest ? event.target.closest('[data-lp-offers]') : null;
        if (form) {
            renumberOffers(form);
        }
    });
    document.addEventListener('p202:row-removed', function (event) {
        var form = event.target.closest ? event.target.closest('[data-lp-offers]') : null;
        if (form) {
            renumberOffers(form);
        }
    });
    document.addEventListener('change', function (event) {
        var radio = event.target;
        if (!radio.hasAttribute || !radio.hasAttribute('data-lp-offer-type')) {
            return;
        }
        var offer = radio.closest('[data-lp-offer]');
        $$('[data-lp-offer-pick]', offer).forEach(function (pick) {
            var on = pick.getAttribute('data-lp-offer-pick') === radio.value;
            pick.hidden = !on;
            if (!on) {
                // An offer is one campaign or one redirector: the other
                // choice goes back to none, so it cannot count as chosen.
                pick.querySelector('select').value = '0';
            }
        });
    });

    // ─── Postback / Pixel: the snippets, rebuilt as the choices change ───
    // The same strings the classic page's pixel_data_changed() built, with
    // one repair: its JavaScript pixel's <noscript> fallback lost the quote
    // after `subid=`, so the copied fallback iframe was malformed HTML.
    function postbackBuilder() {
        var root = document.querySelector('[data-postback-builder]');
        if (!root) {
            return;
        }
        var field = function (id) {
            var element = document.getElementById(id);
            return element ? element.value : '';
        };
        function put(id, text) {
            var pre = document.getElementById(id);
            if (!pre) {
                return;
            }
            pre.textContent = text;
            var copy = pre.parentNode.querySelector('[data-p202-copy]');
            if (copy) {
                copy.setAttribute('data-p202-copy', text);
            }
        }
        function build() {
            var secure = document.getElementById('secure_type1').checked;
            var base = (secure ? 'https' : 'http') + '://' + root.getAttribute('data-root-path');
            var amount = field('amount_value');
            var cid = field('aff_campaign_id');
            var subid = field('subid_value');
            put('unsecure_pixel', '<img height="1" width="1" border="0" style="display: none;" src="' + base + 'gpx.php?amount=' + amount + '&subid=' + subid + '" />');
            put('unsecure_postback', base + 'gpb.php?amount=' + amount + '&subid=' + subid);
            put('unsecure_pixel_2', '<img height="1" width="1" border="0" style="display: none;" src="' + base + 'gpx.php?amount=' + amount + '&cid=' + cid + '&subid=' + subid + '" />');
            put('unsecure_postback_2', base + 'gpb.php?amount=' + amount + '&cid=' + cid + '&subid=' + subid);
            put('unsecure_universal_pixel', '<iframe height="1" width="1" border="0" style="display: none;" frameborder="0" scrolling="no" src="' + base + 'upx.php?amount=' + amount + '&subid=' + subid + '" seamless></iframe>');
            put('unsecure_universal_pixel_js', '<script>\n var vars202={amount:"' + amount + '",cid:"",subid:"' + subid + '"};(function(d, s) {\n \tvar js, upxf = d.getElementsByTagName(s)[0], load = function(url, id) {\n \t\tif (d.getElementById(id)) {return;}\n \t\tif202 = d.createElement("iframe");if202.src = url;if202.id = id;if202.height = 1;if202.width = 0;if202.frameBorder = 1;if202.scrolling = "no";if202.noResize = true;\n \t\tupxf.parentNode.insertBefore(if202, upxf);\n \t};\n \tload("' + base + 'upx.php?amount="+vars202[\'amount\']+"&cid="+vars202[\'cid\']+"&subid="+vars202[\'subid\'], "upxif");\n }(document, "script"));</' + 'script>\n<noscript>\n \t<iframe height="1" width="1" border="0" style="display: none;" frameborder="0" scrolling="no" src="' + base + 'upx.php?amount=' + amount + '&cid=&subid=' + subid + '" seamless></iframe>\n</noscript>');
            var decided = document.querySelector('[data-postback-decided]');
            if (decided && decided.getAttribute('data-initial') === null) {
                decided.setAttribute('data-initial', decided.textContent);
                decided.setAttribute('data-initial-secure', secure ? '1' : '0');
            }
            if (decided) {
                decided.textContent = (secure ? '1' : '0') === decided.getAttribute('data-initial-secure')
                    ? decided.getAttribute('data-initial')
                    : 'Links use ' + (secure ? 'https://' : 'http://') + ', as you chose under Advanced.';
            }
        }
        root.addEventListener('input', build);
        root.addEventListener('change', build);
        var change = root.querySelector('[data-postback-change]');
        if (change) {
            change.addEventListener('click', function (event) {
                event.preventDefault();
                var details = document.getElementById('secure-pixels').closest('details');
                if (details) {
                    details.open = true;
                }
                document.querySelector('input[name="secure_type"]:checked').focus();
            });
        }
        build();
    }

    document.addEventListener('DOMContentLoaded', postbackBuilder);

    // ─── Get Links: remove a tracking link ──────────────────────────────
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-delete-tracker]') : null;
        if (!button || !window.jQuery) {
            return;
        }
        event.preventDefault();
        var list = button.closest('[data-delete-url]');
        var item = button.closest('[data-tracker-id]');
        if (!list || !item || !window.confirm('Remove this tracking link? Clicks it already recorded keep their history; new clicks on it are not tracked.')) {
            return;
        }
        button.disabled = true;
        window.jQuery.post(list.getAttribute('data-delete-url'), { tracker_id: button.getAttribute('data-delete-tracker') })
            .done(function () {
                item.remove();
                // Keep the panel's count honest, and say so when none are left.
                var panel = list.closest('.p202-panel');
                var pill = panel ? panel.querySelector('.p202-panel__head .p202-pill') : null;
                var left = list.querySelectorAll('[data-tracker-id]').length;
                if (pill) {
                    pill.textContent = String(left);
                }
                if (left === 0) {
                    var none = document.createElement('p');
                    none.className = 'text-body-secondary mb-0';
                    none.textContent = 'None left. The links you get are kept here, to copy or change later.';
                    list.replaceWith(none);
                }
            })
            .fail(function (xhr) {
                button.disabled = false;
                list.parentNode.insertBefore(flashHtml('bad', 'The link was not removed: ' + ((xhr && xhr.responseText) || 'the request failed') + '.'), list);
            });
    });

    window.p202setup = { flash: flashHtml, refresh: refresh, filterList: filterList };

    document.addEventListener('DOMContentLoaded', function () {
        refresh(null);
        $$('[data-p202-mirror]').forEach(applyMirror);
    });
})();
