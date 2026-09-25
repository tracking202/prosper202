<?php

declare(strict_types=1);

/**
 * The Account pages on the v2 shell: the session token their forms carry, and
 * post-redirect-get with a flash message.
 *
 * Every state-changing form under 202-account/ posts the session token as
 * `token` — the name the pages used before they moved, and the name the
 * classic shell's jQuery prefilter attaches to same-origin AJAX posts — and
 * the handler refuses the request before it writes anything when the token
 * does not match. A refusal is said, in one sentence, rather than swallowed:
 * the old pages answered a bad token with an empty page or a silent redirect,
 * which a person reads as "saved".
 *
 * After a successful write a page redirects to itself (303) with a flash in
 * the session, so a reload does not repeat the write. A submit the server
 * refused is rendered in place instead, with the API's sentence under the
 * field it names and what the person typed kept in the form — never a
 * password.
 */

/** The sentence a refused token gets, on every Account page. */
const P202_ACCOUNT_TOKEN_REFUSED = 'This form expired or did not come from this page, so nothing was saved. Reload the page and try again.';

/**
 * The hidden token field every Account form carries. The handlers check it
 * with AUTH::check_csrf_token(), the app's one implementation (constant-time,
 * and it fails closed when either side is empty).
 */
function p202_account_token_field(): string
{
    return '<input type="hidden" name="token" value="'
        . htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Queue a flash for the next page this session renders.
 *
 * @param 'ok'|'bad'|'warn'|'info' $kind p202_flash()'s vocabulary
 */
function p202_account_flash(string $kind, string $text): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // A flash that cannot be stored would be a write the person is never
        // told about; say so in the log rather than dropping it unseen.
        error_log('p202_account_flash: no active session; flash dropped: ' . $text);
        return;
    }
    $queued = $_SESSION['p202_account_flash'] ?? [];
    if (!is_array($queued)) {
        $queued = [];
    }
    $queued[] = ['kind' => $kind, 'text' => $text];
    $_SESSION['p202_account_flash'] = $queued;
}

/**
 * The queued flashes, removed from the session as they are read.
 *
 * @return list<array{kind: string, text: string}>
 */
function p202_account_take_flashes(): array
{
    $queued = $_SESSION['p202_account_flash'] ?? [];
    unset($_SESSION['p202_account_flash']);
    if (!is_array($queued)) {
        return [];
    }
    $flashes = [];
    foreach ($queued as $flash) {
        if (is_array($flash) && isset($flash['kind'], $flash['text'])) {
            $flashes[] = ['kind' => (string) $flash['kind'], 'text' => (string) $flash['text']];
        }
    }
    return $flashes;
}

/**
 * Redirect to a page of this install after a write (post-redirect-get).
 *
 * $path is relative to the install root, e.g. '202-account/account.php#api-keys'.
 */
function p202_account_redirect(string $path): never
{
    header('Location: ' . get_absolute_url() . ltrim($path, '/'), true, 303);
    exit;
}

/**
 * The flashes a page shows: what the redirect queued, then what this request
 * adds, rendered in the kit's shape (p202_flash()).
 *
 * @param list<array{kind: string, text: string}> $extra
 */
function p202_account_render_flashes(array $extra = []): string
{
    $html = '';
    foreach (array_merge(p202_account_take_flashes(), $extra) as $flash) {
        $html .= p202_flash($flash['kind'], $flash['text']);
    }
    return $html;
}

/**
 * The server's sentence for a field, rendered under it, and the class that
 * marks the field invalid.
 *
 * @param array<string, string> $errors field name => plain-text sentence
 */
function p202_account_field_error(array $errors, string $field): string
{
    if (!isset($errors[$field]) || trim($errors[$field]) === '') {
        return '';
    }
    return '<div class="invalid-feedback d-block">' . htmlspecialchars(trim($errors[$field]), ENT_QUOTES, 'UTF-8') . '</div>';
}

/** @param array<string, string> $errors */
function p202_account_invalid(array $errors, string $field): string
{
    return isset($errors[$field]) && trim($errors[$field]) !== '' ? ' is-invalid' : '';
}
