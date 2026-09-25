<?php

declare(strict_types=1);

/**
 * The Account pages on the v2 shell: the session token their forms carry, and
 * post-redirect-get with a flash message.
 *
 * Every state-changing form under 202-account/ posts the session token as
 * `token` — the name the pages used before they moved, and the name the
 * shell's jQuery prefilter (template.php) attaches to same-origin AJAX posts — and
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

/**
 * Save the profile form: the account row and the preferences row, in one
 * transaction.
 *
 * They used to be two autocommitted UPDATEs, so a failed second one left the
 * email and timezone changed while the page said nothing had. Both land or
 * neither does: Connection throws on a failed prepare, bind or execute, and
 * transaction() rolls back on any throw, which this rethrows. The caller
 * tells the session only after this returns.
 *
 * @param array<string, string|int> $prefSet  202_users_pref column => value;
 *   the column names are the caller's own literals, never the request's
 * @throws Throwable when nothing was saved
 */
function p202_account_save_profile(\Prosper202\Database\Connection $conn, int $userId, string $email, string $timezone, array $prefSet): void
{
    if ($prefSet === []) {
        throw new InvalidArgumentException('p202_account_save_profile(): no preferences to save');
    }
    $assignments = [];
    $types = '';
    foreach ($prefSet as $column => $value) {
        if (preg_match('/^[a-z_0-9]+$/', (string) $column) !== 1) {
            throw new InvalidArgumentException("p202_account_save_profile(): '$column' is not a column name");
        }
        $assignments[] = '`' . $column . '` = ?';
        $types .= is_int($value) ? 'i' : 's';
    }

    $conn->transaction(static function () use ($conn, $userId, $email, $timezone, $prefSet, $assignments, $types): void {
        $stmt = $conn->prepareWrite('UPDATE `202_users` SET `user_email` = ?, `user_timezone` = ? WHERE `user_id` = ?');
        $conn->bind($stmt, 'ssi', [$email, $timezone, $userId]);
        $conn->executeUpdate($stmt);

        $stmt = $conn->prepareWrite('UPDATE `202_users_pref` SET ' . implode(', ', $assignments) . ' WHERE `user_id` = ?');
        $conn->bind($stmt, $types . 'i', [...array_values($prefSet), $userId]);
        $conn->executeUpdate($stmt);
    });
}

/**
 * The account's API keys, newest first, each with its scope.
 *
 * An install whose 202_api_keys has no scope column (one that predates
 * scopes, or whose 1.9.75 migration failed) selected a column that is not
 * there, so the prepare failed and every key read as "could not be read"
 * while creating one still worked. The column is probed first, with the
 * predicate the API authenticates through (Auth::apiKeyScopeColumnExists()),
 * and a key read without it carries no scope — full access, which is what
 * the API grants such a key and how UsersController::listApiKeys() lists it.
 * A probe that cannot answer throws rather than answering "no column"
 * (CLAUDE.md #11), and so does a failed read: an unreadable list must not
 * render as "no keys yet".
 *
 * @return list<array<string, mixed>>  api_key, created_at, scope (null when
 *   the install has no scope column)
 * @throws Throwable when the keys cannot be read
 */
function p202_account_api_keys(mysqli $db, int $userId): array
{
    $hasScope = \Api\V3\Auth::apiKeyScopeColumnExists($db);
    $conn = new \Prosper202\Database\Connection($db);
    $stmt = $conn->prepareRead('SELECT api_key, created_at' . ($hasScope ? ', scope' : '') . ' FROM 202_api_keys WHERE user_id = ? ORDER BY created_at DESC');
    $conn->bind($stmt, 'i', [$userId]);
    $keys = [];
    foreach ($conn->fetchAll($stmt) as $row) {
        $row['scope'] = $hasScope ? ($row['scope'] ?? null) : null;
        $keys[] = $row;
    }
    return $keys;
}
