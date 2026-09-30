<?php

declare(strict_types=1);

/**
 * Markup helpers for the Setup family on the v2 shell (U4).
 *
 * The Setup pages keep their own request handling — every handler reads the
 * same POST and GET names it always read, and says the same sentences — and
 * render through these. Each helper returns markup copied from the kit
 * (202-account/ui-kit.php), parts included (error pattern #19): a field's
 * sentence is `.invalid-feedback` under the control, a code snippet is the
 * kit's `.p202-code` with a Copy button, a flash is p202_flash().
 *
 * The render helpers are pure functions of their arguments — no session, no
 * database — so tests/Setup/SetupUiHelpersTest.php can call them without an
 * install. The two data helpers at the bottom take the connection and check
 * every query (error pattern #1: a failed query must not read as "you have
 * no campaigns").
 *
 * The AJAX fragments that only these pages load (tracking202/ajax/
 * generate_tracking_link.php, get_landing_code.php, get_adv_landing_code.php)
 * render through the same helpers, so their markup is v2 too.
 */

/** Escape for an HTML text node or a quoted attribute. */
function p202_setup_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * A stored error as the sentence the user reads.
 *
 * The handlers built their errors as `<div class="error">…</div>` strings,
 * sometimes several appended to one key (a URL that is both missing and
 * scheme-less). Those tags were Bootstrap 3 markup the v2 page does not
 * style, so the sentence is recovered from them — each <div> becomes one
 * sentence, joined by a space — rather than printed raw. A plain string
 * passes through unchanged.
 */
function p202_setup_error_text(mixed $stored): string
{
    $text = (string) $stored;
    if ($text === '') {
        return '';
    }
    // Only the wrapper the handlers wrote is removed; anything else between
    // angle brackets is text (strip_tags() would drop it silently).
    $text = preg_replace('~</div>\s*~i', "\n", $text) ?? $text;
    // (the icon the AJAX fragments put before a sentence is an empty span)
    $text = preg_replace('~<div\b[^>]*>|</?small>|<span\b[^>]*></span>~i', '', $text) ?? $text;
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $l): bool => $l !== ''));
    return implode(' ', $lines);
}

/**
 * ' is-invalid' when any of the keys carries an error, for a control's class.
 *
 * @param array<string, mixed> $errors
 */
function p202_setup_invalid(array $errors, string ...$keys): string
{
    foreach ($keys as $key) {
        if (p202_setup_error_text($errors[$key] ?? '') !== '') {
            return ' is-invalid';
        }
    }
    return '';
}

/**
 * The handler's own sentence for a field, rendered under it.
 *
 * `d-block` because Bootstrap shows `.invalid-feedback` only as the next
 * sibling of an `.is-invalid` control, and several of these fields sit in an
 * input group or have a hint between the control and the sentence.
 *
 * @param array<string, mixed> $errors
 */
function p202_setup_feedback(array $errors, string ...$keys): string
{
    $sentences = [];
    foreach ($keys as $key) {
        $text = p202_setup_error_text($errors[$key] ?? '');
        if ($text !== '') {
            $sentences[] = $text;
        }
    }
    if ($sentences === []) {
        return '';
    }
    return '<div class="invalid-feedback d-block">' . p202_setup_e(implode(' ', $sentences)) . '</div>';
}

/**
 * The flashes for a refused submit.
 *
 * Every error whose key is not a field on the form (the token, an ownership
 * refusal) is said in its own flash, because there is no field to put it
 * under. When only field errors exist, one flash says so and the sentences
 * sit under their fields; a refusal must never be silent (error pattern #4),
 * so an error keyed by a field this form does not render is flashed too.
 *
 * @param array<string, mixed> $errors
 * @param list<string> $fieldKeys the keys this form renders under a field
 */
function p202_setup_error_flashes(array $errors, array $fieldKeys): string
{
    $out = '';
    $fieldErrors = 0;
    foreach ($errors as $key => $stored) {
        $text = p202_setup_error_text($stored);
        if ($text === '') {
            continue;
        }
        if (in_array((string) $key, $fieldKeys, true)) {
            $fieldErrors++;
            continue;
        }
        $out .= p202_flash('bad', $text);
    }
    if ($fieldErrors > 0) {
        $out = p202_flash('bad', $fieldErrors === 1
            ? 'Nothing was saved. One field needs attention; its sentence is under it.'
            : 'Nothing was saved. ' . $fieldErrors . ' fields need attention; each sentence is under its field.') . $out;
    }
    return $out;
}

/** The session token as the hidden field every setup form posts. */
function p202_setup_token_field(string $token): string
{
    return '<input type="hidden" name="token" value="' . p202_setup_e($token) . '">';
}

/**
 * A read-only code box with a Copy button: the kit's `.p202-code`.
 *
 * $value is the raw snippet; it is escaped here. `long` caps a many-line
 * snippet's height so a forty-line redirect script does not push the next
 * snippet off the screen; it scrolls inside its own box instead.
 *
 * @param array{label?: string, id?: string, long?: bool} $options
 */
function p202_setup_code_box(string $value, array $options = []): string
{
    $id = isset($options['id']) ? ' id="' . p202_setup_e($options['id']) . '"' : '';
    $class = 'p202-code__value' . (!empty($options['long']) ? ' p202-code__value--long' : '');
    $label = isset($options['label']) && $options['label'] !== ''
        ? '<div class="form-label">' . p202_setup_e($options['label']) . '</div>'
        : '';
    return $label . '<div class="p202-code mb-3">'
        . '<pre class="' . $class . '"' . $id . '>' . p202_setup_e($value) . '</pre>'
        . '<button type="button" class="btn btn-secondary btn-sm p202-copy" data-p202-copy="' . p202_setup_e($value) . '"><i class="bi bi-clipboard"></i> Copy</button>'
        . '</div>';
}

/**
 * The tracking placeholders a campaign or landing-page URL may carry, as
 * buttons that insert the token at the caret of $targetId (p202-setup.js).
 *
 * They sit under a closed disclosure: they are a typing aid, not a field,
 * and seventeen buttons open by default buried the one field that matters.
 */
function p202_setup_placeholders(string $targetId, string $remember): string
{
    $tokens = ['[[subid]]', '[[c1]]', '[[c2]]', '[[c3]]', '[[c4]]', '[[random]]', '[[referer]]', '[[gclid]]',
        '[[utm_source]]', '[[utm_medium]]', '[[utm_campaign]]', '[[utm_term]]', '[[utm_content]]',
        '[[payout]]', '[[cpc]]', '[[cpc2]]', '[[timestamp]]'];
    $buttons = '';
    foreach ($tokens as $token) {
        $buttons .= '<button type="button" class="btn btn-outline-secondary btn-sm font-monospace" data-p202-insert="'
            . p202_setup_e($token) . '" data-p202-insert-into="#' . p202_setup_e($targetId) . '">' . p202_setup_e($token) . '</button>';
    }
    return '<details class="p202-disclosure mt-2" data-p202-remember="' . p202_setup_e($remember) . '">'
        . '<summary>Tracking placeholders <span class="p202-disclosure__hint">click one to insert it where the cursor is</span></summary>'
        . '<div class="p202-disclosure__body"><div class="p202-toolbar">' . $buttons . '</div></div>'
        . '</details>';
}

/**
 * <option>s for a native select, flat or grouped.
 *
 * $options is value => label, or value => ['label' => …, 'data' => [name =>
 * value]] to carry data attributes (the network a campaign belongs to, the
 * campaign a landing page belongs to), which p202-setup.js reads to filter
 * and sync. An <optgroup> is any key => ['label' => group label, 'options' =>
 * [...]]: the group is keyed by something unique (its id), never by its
 * label, because two categories may share a name and a label key would fold
 * them into one group (error pattern #17). Every value is compared as a
 * string, so '12' selects 12.
 *
 * @param array<string|int, mixed> $options
 */
function p202_setup_options(array $options, mixed $selected, ?string $emptyLabel = null, string $emptyValue = ''): string
{
    $selected = $selected === null ? null : (string) $selected;
    $out = $emptyLabel !== null
        ? '<option value="' . p202_setup_e($emptyValue) . '">' . p202_setup_e($emptyLabel) . '</option>'
        : '';
    $one = static function (string|int $value, mixed $entry) use ($selected): string {
        $label = is_array($entry) ? (string) ($entry['label'] ?? '') : (string) $entry;
        $data = '';
        if (is_array($entry)) {
            foreach (($entry['data'] ?? []) as $name => $datum) {
                $data .= ' data-' . p202_setup_e($name) . '="' . p202_setup_e($datum) . '"';
            }
        }
        $isSelected = $selected !== null && (string) $value === $selected ? ' selected' : '';
        return '<option value="' . p202_setup_e($value) . '"' . $data . $isSelected . '>' . p202_setup_e($label) . '</option>';
    };
    foreach ($options as $value => $entry) {
        if (is_array($entry) && isset($entry['options']) && is_array($entry['options'])) {
            $out .= '<optgroup label="' . p202_setup_e($entry['label'] ?? '') . '">';
            foreach ($entry['options'] as $childValue => $child) {
                $out .= $one($childValue, $child);
            }
            $out .= '</optgroup>';
            continue;
        }
        $out .= $one($value, $entry);
    }
    return $out;
}

/**
 * A search box that filters a list in place (p202-setup.js): the kit's
 * panel-aside filter. Rows are the list's `[data-p202-filter-text]` items.
 */
function p202_setup_list_filter(string $listId, string $label): string
{
    return '<input type="search" class="form-control form-control-sm" data-p202-filter-list="#' . p202_setup_e($listId)
        . '" placeholder="' . p202_setup_e($label) . '" aria-label="' . p202_setup_e($label) . '">';
}

/**
 * A remove action that keeps the request the handler has always read — a GET
 * with the id, the name (for the Slack notice) and the session token — but
 * asks first, through the standard's confirm (form[data-p202-confirm]).
 *
 * @param array<string, scalar> $params
 */
function p202_setup_remove_form(string $action, array $params, string $confirm, string $label = 'remove'): string
{
    $inputs = '';
    foreach ($params as $name => $value) {
        $inputs .= '<input type="hidden" name="' . p202_setup_e($name) . '" value="' . p202_setup_e($value) . '">';
    }
    return '<form method="get" action="' . p202_setup_e($action) . '" class="d-inline" data-p202-confirm="' . p202_setup_e($confirm) . '">'
        . $inputs . '<button type="submit" class="p202-list__action p202-list__action--danger">' . p202_setup_e($label) . '</button></form>';
}

/**
 * The flash a page shows after its own post-redirect-get.
 *
 * @param array<string, string> $messages query key => sentence
 * @param array<string, mixed> $query normally $_GET
 */
function p202_setup_query_flashes(array $messages, array $query): string
{
    $out = '';
    foreach ($messages as $key => $sentence) {
        if (isset($query[$key]) && (string) $query[$key] === '1') {
            $out .= p202_flash('ok', $sentence);
        }
    }
    return $out;
}

/** The Setup family's page script, loaded after p202-ui.js. */
function p202_setup_script_tag(string $base): string
{
    return '<script src="' . p202_setup_e(rtrim($base, '/') . '/202-js/p202-setup.js?v=1') . '" defer></script>';
}

/**
 * The dynamic content segments a landing page can print, as v2 markup.
 * The list itself is getDynamicContentSegments(), the one source of truth.
 *
 * @param array<string, string> $segments element name => description
 */
function p202_setup_segment_help(array $segments): string
{
    $items = '';
    foreach ($segments as $name => $description) {
        $items .= '<li><code>' . p202_setup_e($name) . '</code> · ' . p202_setup_e($description) . '</li>';
    }
    return '<details class="p202-disclosure mt-3" data-p202-remember="setup-lp-code-segments">'
        . '<summary>Dynamic content segments <span class="p202-disclosure__hint">show the visitor\'s country, device and more on the page</span></summary>'
        . '<div class="p202-disclosure__body">'
        . '<p class="mb-2">Put a span named after a segment anywhere on the landing page, and the code above fills it in. For the visitor\'s country:</p>'
        . p202_setup_code_box('Welcome, I see you are reading this from <span name="t202Country" t202Default="Your Country">Your Country</span>')
        . '<ul class="small mb-0">' . $items . '</ul>'
        . '</div></details>';
}

/**
 * The one choice a list offers, when it offers exactly one: a form picks it
 * rather than asking (UI standard, rule 3). Grouped lists count across their
 * groups.
 *
 * @param array<string|int, mixed> $options as p202_setup_options() takes them
 */
function p202_setup_only_option(array $options): ?string
{
    $values = [];
    foreach ($options as $value => $entry) {
        if (is_array($entry) && isset($entry['options']) && is_array($entry['options'])) {
            foreach (array_keys($entry['options']) as $child) {
                $values[] = (string) $child;
            }
            continue;
        }
        $values[] = (string) $value;
    }
    return count($values) === 1 ? $values[0] : null;
}

// ─── Data ──────────────────────────────────────────────────────────────

/**
 * Rows of a query, or the page stops with the database error recorded.
 *
 * record_mysql_error() never returns, so a failed query cannot render as an
 * empty list — "you have no campaigns" when the truth is "the database did
 * not answer" (error pattern #1).
 *
 * @return list<array<string, mixed>>
 */
function p202_setup_rows(mysqli $db, string $sql): array
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        record_mysql_error($db, $sql);
    }
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
    return $rows;
}

/**
 * The account's live campaigns grouped by category, for one <optgroup>ed
 * select (the UI standard's replacement for the dependent category →
 * campaign pair). Each option carries its category id as data-network, so a
 * form that still posts aff_network_id fills it from the chosen campaign.
 *
 * @return array<string, array{label: string, options: array<string, array{label: string, data: array<string, string>}>}>
 */
function p202_setup_campaign_options(mysqli $db, int $userId): array
{
    $rows = p202_setup_rows($db, "SELECT ac.aff_campaign_id, ac.aff_campaign_name, an.aff_network_id, an.aff_network_name
        FROM 202_aff_campaigns AS ac
        INNER JOIN 202_aff_networks AS an ON (an.aff_network_id = ac.aff_network_id)
        WHERE ac.user_id = '" . $userId . "' AND ac.aff_campaign_deleted = 0 AND an.aff_network_deleted = 0
        ORDER BY an.aff_network_name ASC, ac.aff_campaign_name ASC");
    $groups = [];
    foreach ($rows as $row) {
        $group = 'n' . $row['aff_network_id'];
        $groups[$group]['label'] = (string) $row['aff_network_name'];
        $groups[$group]['options'][(string) $row['aff_campaign_id']] = [
            'label' => (string) $row['aff_campaign_name'],
            'data' => ['network' => (string) $row['aff_network_id']],
        ];
    }
    return $groups;
}
