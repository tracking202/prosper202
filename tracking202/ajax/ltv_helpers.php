<?php

declare(strict_types=1);

/**
 * Shared formatting helpers for the LTV AJAX partials — one definition of
 * money/escaping/date rendering instead of a per-file closure trio that
 * drifts (the customer view's timestamped date format already had) — and
 * the readers of what the partials are posted.
 */

function p202_ltv_money(mixed $v): string
{
    return number_format((float) $v, 2);
}

function p202_ltv_esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function p202_ltv_when(mixed $ts, bool $withTime = false): string
{
    return ((int) $ts) > 0 ? date($withTime ? 'M j, Y g:ia' : 'M j, Y', (int) $ts) : '—';
}

/*
 * What the partials' forms and buttons post, read exactly. Each is handed
 * the posted value (the partial reads $_POST itself) and throws a
 * RuntimeException carrying the partial's sentence, which it shows: `(int)`
 * made `12abc` company 12 in a merge, one of the account's own and not the
 * one named, and `(string)` read a list (`name[]=…`) as "Array" (CLAUDE.md #4).
 */

/** The id a row's button or the merge dialog posts (PostedId), or $refusal. */
function p202_ltv_posted_id(mixed $posted, string $refusal): int
{
    return \Prosper202\Http\PostedId::read($posted) ?? throw new \RuntimeException($refusal);
}

/** As p202_ltv_posted_id(), where none ('' or absent) is 0: no row asked for. */
function p202_ltv_posted_id_or_none(mixed $posted, string $refusal): int
{
    return $posted === null || $posted === '' ? 0 : p202_ltv_posted_id($posted, $refusal);
}

/** The pager's offset: none, '' or 0 is the first page. */
function p202_ltv_posted_offset(mixed $posted): int
{
    return $posted === '0' ? 0 : p202_ltv_posted_id_or_none($posted, 'Pick a page with Previous or Next.');
}

/** One text field: '' when absent; a list is refused. */
function p202_ltv_posted_text(mixed $posted, string $field): string
{
    if ($posted !== null && !is_string($posted)) {
        throw new \RuntimeException('The form sent "' . $field . '" as a list; it takes one value.');
    }

    return $posted ?? '';
}

/**
 * A group of fields (`crm[city]=…`), each one text: none is []. One value in
 * the group's place is refused, where it was read as no fields at all, and so
 * is a list in a field's.
 *
 * @return array<array-key, string>
 */
function p202_ltv_posted_group(mixed $posted, string $field): array
{
    if ($posted === null) {
        return [];
    }
    if (!is_array($posted)) {
        throw new \RuntimeException('The form sent "' . $field . '" as one value; it takes a group of fields.');
    }
    foreach ($posted as $key => $value) {
        if (!is_string($value)) {
            throw new \RuntimeException('The form sent "' . $field . '[' . $key . ']" as a list; it takes one value.');
        }
    }

    return $posted;
}

/**
 * What $read() reads, or $default with its refusal kept in $refusal (the
 * first one): for what a list shows, where one value refused leaves the
 * others as they were sent, and the partial shows the sentence.
 *
 * @template T
 * @param callable(): T $read
 * @param T $default
 * @return T
 */
function p202_ltv_posted_or(callable $read, mixed $default, ?string &$refusal): mixed
{
    try {
        return $read();
    } catch (\RuntimeException $refused) {
        $refusal ??= $refused->getMessage();

        return $default;
    }
}

/**
 * One of $choices' keys, $default when none ('' or absent) is sent; anything
 * else is $refusal, where it was read as the default without a word.
 *
 * @param array<string, string> $choices
 */
function p202_ltv_posted_choice(mixed $posted, array $choices, string $default, string $refusal): string
{
    if ($posted === null || $posted === '') {
        return $default;
    }
    if (!is_string($posted) || !array_key_exists($posted, $choices)) {
        throw new \RuntimeException($refusal);
    }

    return $posted;
}

/**
 * Neutralize spreadsheet formula injection for exported cells: Excel treats
 * cells starting with =, +, -, or @ (or tab/CR) as formulas even inside an
 * HTML .xls table, and refs/names/emails/companies are customer-controlled
 * (pixels, API pushes). A leading apostrophe forces Excel to render the cell
 * as text without altering the visible value. HTML-escape separately.
 */
function p202_ltv_xls_cell(mixed $v): string
{
    $s = (string) $v;
    if ($s !== '' && str_contains("=+-@\t\r", $s[0])) {
        $s = "'" . $s;
    }

    return $s;
}
