<?php

declare(strict_types=1);

/**
 * Shared merge-picker modal for the LTV partials (customers, companies).
 * Include once per partial render, then open with:
 *
 *   ltvMergeOpen({
 *     entity: 'customer' | 'company',
 *     noun: 'customer',
 *     placeholder: 'Search by name, email…',
 *     moves: 'aliases, revenue, …',        // what transfers, plain text
 *     target: {id, label, sub, meta},      // the record the button was on
 *     confirm: function(keptId, goneId) {} // performs the CSRF-gated merge
 *   });
 *
 * Type-ahead search (debounced, via ltv_merge_search.php), arrow-key +
 * enter/escape keyboard support, and a two-card confirm step with a
 * direction swap. Everything user-sourced renders through textContent —
 * never innerHTML — because names/emails/refs originate from pixels and
 * API pushes.
 */
?>
<?php /* A Bootstrap 5 modal (the v2 shell loads its script): focus is held
         inside it while it is open and returned when it closes, Escape and a
         click outside close it, and it follows the theme. */ ?>
<div class="modal fade" id="ltv-merge-overlay" tabindex="-1" aria-labelledby="ltv-merge-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <!-- Step 1: search -->
            <div id="ltv-merge-search-step">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-6" id="ltv-merge-title"></h2>
                        <div id="ltv-merge-subtitle" class="text-secondary small"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pb-2">
                    <input type="text" id="ltv-merge-input" class="form-control" autocomplete="off" spellcheck="false"
                           aria-label="Search" oninput="ltvMergeQueue();" onkeydown="ltvMergeKeys(event);">
                    <div id="ltv-merge-results" class="list-group list-group-flush mt-2" style="max-height: 300px; overflow-y: auto;"></div>
                </div>
                <div class="modal-footer justify-content-start text-secondary small">
                    <span><kbd>&uarr;</kbd> <kbd>&darr;</kbd> to navigate</span>
                    <span><kbd>&crarr;</kbd> to select</span>
                    <span><kbd>esc</kbd> to close</span>
                </div>
            </div>
            <!-- Step 2: confirm -->
            <div id="ltv-merge-confirm-step" style="display: none;">
                <div class="modal-header">
                    <h2 class="modal-title fs-6">Confirm merge</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="ltv-merge-card-gone" class="border rounded-3 p-2 px-3 opacity-75"></div>
                    <div class="text-center text-secondary small py-2">
                        everything moves down into &nbsp;&darr;&nbsp;
                        <a href="#" onclick="ltvMergeSwap(); return false;" title="Keep the other record instead">swap direction</a>
                    </div>
                    <div id="ltv-merge-card-kept" class="border border-primary rounded-3 p-2 px-3 bg-primary-subtle"></div>
                    <p id="ltv-merge-moves" class="text-secondary small mt-3 mb-0"></p>
                    <p class="text-secondary small mt-1 mb-0"><strong>This cannot be undone.</strong></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link" onclick="ltvMergeBack();">Back</button>
                    <button type="button" class="btn btn-primary" id="ltv-merge-go" onclick="ltvMergeConfirm();">Merge records</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
var ltvMergeCfg = null;
var ltvMergeState = { results: [], active: -1, selected: null, swapped: false, timer: null, seq: 0 };
var ltvMergeSearchUrl = '<?php echo get_absolute_url(); ?>tracking202/ajax/ltv_merge_search.php';

function ltvMergeOpen(cfg) {
    ltvMergeCfg = cfg;
    ltvMergeState = { results: [], active: -1, selected: null, swapped: false, timer: null, seq: 0 };
    document.getElementById('ltv-merge-title').textContent = 'Merge another ' + cfg.noun + ' into "' + cfg.target.label + '"';
    document.getElementById('ltv-merge-subtitle').textContent = 'Find the duplicate ' + cfg.noun + ' — you can swap which record survives before confirming.';
    var input = document.getElementById('ltv-merge-input');
    input.value = '';
    input.placeholder = cfg.placeholder || 'Type to search…';
    ltvMergeRenderResults([], 'Start typing to search.');
    document.getElementById('ltv-merge-search-step').style.display = '';
    document.getElementById('ltv-merge-confirm-step').style.display = 'none';
    var overlay = document.getElementById('ltv-merge-overlay');
    overlay.addEventListener('shown.bs.modal', function () { input.focus(); }, { once: true });
    overlay.addEventListener('hidden.bs.modal', function () { ltvMergeCfg = null; }, { once: true });
    bootstrap.Modal.getOrCreateInstance(overlay).show();
}

function ltvMergeClose() {
    var overlay = document.getElementById('ltv-merge-overlay');
    var modal = overlay ? bootstrap.Modal.getInstance(overlay) : null;
    if (modal) { modal.hide(); }
    ltvMergeCfg = null;
}

function ltvMergeQueue() {
    if (ltvMergeState.timer) { clearTimeout(ltvMergeState.timer); }
    ltvMergeState.timer = setTimeout(ltvMergeSearch, 250);
}

function ltvMergeSearch() {
    if (!ltvMergeCfg) { return; }
    var q = document.getElementById('ltv-merge-input').value.replace(/^\s+|\s+$/g, '');
    if (q === '') { ltvMergeRenderResults([], 'Start typing to search.'); return; }
    var seq = ++ltvMergeState.seq;
    ltvMergeRenderResults([], 'Searching…');
    $.post(ltvMergeSearchUrl, { entity: ltvMergeCfg.entity, q: q, exclude: ltvMergeCfg.target.id })
        .done(function(data) {
            if (seq !== ltvMergeState.seq || !ltvMergeCfg) { return; } // stale response
            var results = (data && data.results) || [];
            ltvMergeRenderResults(results, results.length ? null : 'No matching ' + ltvMergeCfg.noun + 's.');
        })
        .fail(function() {
            if (seq !== ltvMergeState.seq) { return; }
            ltvMergeRenderResults([], 'Search failed — try again.');
        });
}

// DOM built node-by-node with textContent: names/emails/refs are
// customer-supplied (pixels, API pushes) and must never hit innerHTML.
function ltvMergeRenderResults(results, message) {
    ltvMergeState.results = results;
    ltvMergeState.active = results.length ? 0 : -1;
    var box = document.getElementById('ltv-merge-results');
    while (box.firstChild) { box.removeChild(box.firstChild); }
    if (message) {
        var m = document.createElement('div');
        m.className = 'text-secondary small px-2 py-3';
        m.textContent = message;
        box.appendChild(m);
        return;
    }
    for (var i = 0; i < results.length; i++) {
        box.appendChild(ltvMergeRow(results[i], i));
    }
    ltvMergeHighlight();
}

function ltvMergeRow(r, index) {
    var row = document.createElement('button');
    row.type = 'button';
    row.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2 ltv-merge-row';
    row.onmouseenter = function() { ltvMergeState.active = index; ltvMergeHighlight(); };
    row.onclick = function() { ltvMergeSelect(index); };

    var left = document.createElement('div');
    left.style.cssText = 'min-width: 0;';
    var label = document.createElement('div');
    label.className = 'fw-bold text-truncate';
    label.textContent = r.label;
    var sub = document.createElement('div');
    sub.className = 'text-secondary small text-truncate';
    sub.textContent = r.sub || '';
    left.appendChild(label);
    left.appendChild(sub);

    var meta = document.createElement('div');
    meta.className = 'text-secondary small text-nowrap';
    meta.textContent = r.meta || '';

    row.appendChild(left);
    row.appendChild(meta);
    return row;
}

function ltvMergeHighlight() {
    var rows = document.getElementById('ltv-merge-results').getElementsByClassName('ltv-merge-row');
    for (var i = 0; i < rows.length; i++) {
        rows[i].classList.toggle('active', i === ltvMergeState.active);
    }
}

function ltvMergeKeys(event) {
    if (event.key === 'Escape') { return; } // the modal closes itself
    if (!ltvMergeState.results.length) { return; }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        var delta = event.key === 'ArrowDown' ? 1 : -1;
        var n = ltvMergeState.results.length;
        ltvMergeState.active = (ltvMergeState.active + delta + n) % n;
        ltvMergeHighlight();
    } else if (event.key === 'Enter') {
        event.preventDefault();
        if (ltvMergeState.active >= 0) { ltvMergeSelect(ltvMergeState.active); }
    }
}

function ltvMergeSelect(index) {
    ltvMergeState.selected = ltvMergeState.results[index];
    ltvMergeState.swapped = false;
    ltvMergeRenderConfirm();
    document.getElementById('ltv-merge-search-step').style.display = 'none';
    document.getElementById('ltv-merge-confirm-step').style.display = '';
}

function ltvMergeCard(el, r, kept) {
    while (el.firstChild) { el.removeChild(el.firstChild); }
    var badge = document.createElement('div');
    badge.className = 'small text-uppercase fw-bold ' + (kept ? 'text-primary-emphasis' : 'text-secondary');
    badge.textContent = kept ? 'Kept — everything ends up here' : 'Merged away — excluded from reports';
    var label = document.createElement('div');
    label.className = 'fw-bold';
    label.textContent = r.label;
    var sub = document.createElement('div');
    sub.className = 'text-secondary small';
    sub.textContent = [r.sub, r.meta].filter(Boolean).join(' — ');
    el.appendChild(badge);
    el.appendChild(label);
    el.appendChild(sub);
}

function ltvMergeRenderConfirm() {
    var kept = ltvMergeState.swapped ? ltvMergeState.selected : ltvMergeCfg.target;
    var gone = ltvMergeState.swapped ? ltvMergeCfg.target : ltvMergeState.selected;
    ltvMergeCard(document.getElementById('ltv-merge-card-gone'), gone, false);
    ltvMergeCard(document.getElementById('ltv-merge-card-kept'), kept, true);
    document.getElementById('ltv-merge-moves').textContent = 'Moves to the kept record: ' + ltvMergeCfg.moves + '.';
}

function ltvMergeSwap() {
    ltvMergeState.swapped = !ltvMergeState.swapped;
    ltvMergeRenderConfirm();
}

function ltvMergeBack() {
    document.getElementById('ltv-merge-confirm-step').style.display = 'none';
    document.getElementById('ltv-merge-search-step').style.display = '';
    setTimeout(function() { document.getElementById('ltv-merge-input').focus(); }, 0);
}

function ltvMergeConfirm() {
    if (!ltvMergeCfg || !ltvMergeState.selected) { return; }
    var kept = ltvMergeState.swapped ? ltvMergeState.selected : ltvMergeCfg.target;
    var gone = ltvMergeState.swapped ? ltvMergeCfg.target : ltvMergeState.selected;
    var confirmFn = ltvMergeCfg.confirm;
    ltvMergeClose();
    confirmFn(kept.id, gone.id);
}
</script>
