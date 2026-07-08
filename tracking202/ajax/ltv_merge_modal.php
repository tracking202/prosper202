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
<div id="ltv-merge-overlay" style="display: none; position: fixed; inset: 0; background: rgba(15, 20, 25, 0.45); z-index: 10000;" onclick="if (event.target === this) { ltvMergeClose(); }">
    <div style="max-width: 520px; margin: 9vh auto 0; background: #fff; border-radius: 10px; box-shadow: 0 18px 60px rgba(0,0,0,0.28); overflow: hidden; font-size: 14px;">
        <!-- Step 1: search -->
        <div id="ltv-merge-search-step">
            <div style="padding: 14px 16px 10px;">
                <div id="ltv-merge-title" style="font-weight: 600; margin-bottom: 2px;"></div>
                <div id="ltv-merge-subtitle" class="text-muted" style="font-size: 12px;"></div>
            </div>
            <div style="padding: 0 16px 6px;">
                <input type="text" id="ltv-merge-input" class="form-control" autocomplete="off" spellcheck="false"
                       style="border-radius: 6px; box-shadow: none;"
                       oninput="ltvMergeQueue();" onkeydown="ltvMergeKeys(event);">
            </div>
            <div id="ltv-merge-results" style="max-height: 300px; overflow-y: auto; padding: 4px 8px 8px;"></div>
            <div style="padding: 8px 16px; border-top: 1px solid #eee; color: #999; font-size: 11px;">
                <kbd>&uarr;</kbd> <kbd>&darr;</kbd> to navigate &nbsp; <kbd>&crarr;</kbd> to select &nbsp; <kbd>esc</kbd> to close
            </div>
        </div>
        <!-- Step 2: confirm -->
        <div id="ltv-merge-confirm-step" style="display: none;">
            <div style="padding: 14px 16px 6px;">
                <div style="font-weight: 600;">Confirm merge</div>
            </div>
            <div style="padding: 4px 16px 0;">
                <div id="ltv-merge-card-gone" style="border: 1px solid #e5e5e5; border-radius: 8px; padding: 10px 12px; opacity: 0.75;"></div>
                <div style="text-align: center; color: #888; font-size: 12px; padding: 6px 0;">
                    everything moves down into &nbsp;&darr;&nbsp;
                    <a href="#" onclick="ltvMergeSwap(); return false;" title="Keep the other record instead">swap direction</a>
                </div>
                <div id="ltv-merge-card-kept" style="border: 1px solid #4a90d9; border-radius: 8px; padding: 10px 12px; background: #f4f9ff;"></div>
                <p id="ltv-merge-moves" class="text-muted" style="font-size: 12px; margin: 10px 2px 0;"></p>
                <p class="text-muted" style="font-size: 12px; margin: 4px 2px 0;"><strong>This cannot be undone.</strong></p>
            </div>
            <div style="padding: 12px 16px; text-align: right;">
                <a href="#" onclick="ltvMergeBack(); return false;" style="margin-right: 14px;">Back</a>
                <button type="button" class="btn btn-primary" id="ltv-merge-go" onclick="ltvMergeConfirm();">Merge records</button>
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
    document.getElementById('ltv-merge-overlay').style.display = 'block';
    setTimeout(function() { input.focus(); }, 0);
}

function ltvMergeClose() {
    document.getElementById('ltv-merge-overlay').style.display = 'none';
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
        m.style.cssText = 'padding: 14px 10px; color: #999;';
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
    var row = document.createElement('div');
    row.className = 'ltv-merge-row';
    row.style.cssText = 'display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; cursor: pointer;';
    row.onmouseenter = function() { ltvMergeState.active = index; ltvMergeHighlight(); };
    row.onclick = function() { ltvMergeSelect(index); };

    var left = document.createElement('div');
    left.style.cssText = 'min-width: 0;';
    var label = document.createElement('div');
    label.style.cssText = 'font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;';
    label.textContent = r.label;
    var sub = document.createElement('div');
    sub.style.cssText = 'color: #999; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;';
    sub.textContent = r.sub || '';
    left.appendChild(label);
    left.appendChild(sub);

    var meta = document.createElement('div');
    meta.style.cssText = 'color: #666; font-size: 12px; white-space: nowrap;';
    meta.textContent = r.meta || '';

    row.appendChild(left);
    row.appendChild(meta);
    return row;
}

function ltvMergeHighlight() {
    var rows = document.getElementById('ltv-merge-results').getElementsByClassName('ltv-merge-row');
    for (var i = 0; i < rows.length; i++) {
        rows[i].style.background = (i === ltvMergeState.active) ? '#eef4fb' : '';
    }
}

function ltvMergeKeys(event) {
    if (event.key === 'Escape') { ltvMergeClose(); return; }
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
    badge.style.cssText = 'font-size: 10px; letter-spacing: 0.06em; text-transform: uppercase; color: ' + (kept ? '#3a7bd5' : '#999') + '; margin-bottom: 2px;';
    badge.textContent = kept ? 'Kept — everything ends up here' : 'Merged away — excluded from reports';
    var label = document.createElement('div');
    label.style.cssText = 'font-weight: 600;';
    label.textContent = r.label;
    var sub = document.createElement('div');
    sub.style.cssText = 'color: #999; font-size: 12px;';
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
