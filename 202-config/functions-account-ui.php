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
 * Save the account currency and re-price this account's campaigns into it:
 * all of it or none.
 *
 * account.php wrote the currency, then each campaign's payout, each an
 * autocommitted UPDATE and none of the campaign ones checked: a failure
 * part-way left some campaigns re-priced while the page said "saved" (#165).
 * And the exchange-rate helper answers an unreachable service with a payout
 * of 0 (it divides a missing value), so every campaign was re-priced to
 * nothing and that was stored. Now the rates are asked for first, outside
 * the transaction (no lock is held across a network call), a rate the
 * service did not give stops the whole change, and every write runs in one
 * transaction. A payout of 0 is 0 in any currency and is not asked for.
 *
 * @param callable(string, string): mixed $rate  (campaign currency, payout)
 *   => the service's answer, ['exchange_payout' => number]
 * @throws Throwable when nothing was saved
 */
function p202_account_save_currency(\Prosper202\Database\Connection $conn, int $userId, string $currency, string $storedCurrency, callable $rate): void
{
    $updates = [];
    if ($storedCurrency !== $currency) {
        $stmt = $conn->prepareWrite('SELECT `aff_campaign_id`, `aff_campaign_payout`, `aff_campaign_currency`, `aff_campaign_foreign_payout` FROM `202_aff_campaigns` WHERE `aff_campaign_deleted` = 0 AND `user_id` = ?');
        $conn->bind($stmt, 'i', [$userId]);
        $converted = static function (string $campaignCurrency, string $payout) use ($rate): string {
            if ((float) $payout == 0.0) {
                return '0';
            }
            $answer = $rate($campaignCurrency, $payout);
            $value = is_array($answer) ? ($answer['exchange_payout'] ?? null) : null;
            if (!is_numeric($value) || (float) $value <= 0) {
                throw new RuntimeException('The exchange rate service did not answer with a payout for ' . $campaignCurrency . ' ' . $payout . '.');
            }
            return (string) $value;
        };
        foreach ($conn->fetchAll($stmt) as $row) {
            $id = (int) $row['aff_campaign_id'];
            $payout = (string) $row['aff_campaign_payout'];
            $foreign = (string) $row['aff_campaign_foreign_payout'];
            $campaignCurrency = (string) $row['aff_campaign_currency'];
            if ((float) $foreign == 0.0) {
                // Still in its own currency: keep the original, show the converted.
                $updates[] = ['UPDATE `202_aff_campaigns` SET `aff_campaign_foreign_payout` = ?, `aff_campaign_payout` = ? WHERE `aff_campaign_id` = ? AND `user_id` = ?', 'ssii', [$payout, $converted($campaignCurrency, $payout), $id, $userId]];
            } elseif ($currency === $campaignCurrency) {
                // Back to its own currency: the original returns.
                $updates[] = ['UPDATE `202_aff_campaigns` SET `aff_campaign_payout` = ?, `aff_campaign_foreign_payout` = \'0.00\' WHERE `aff_campaign_id` = ? AND `user_id` = ?', 'sii', [$foreign, $id, $userId]];
            } else {
                $updates[] = ['UPDATE `202_aff_campaigns` SET `aff_campaign_payout` = ? WHERE `aff_campaign_id` = ? AND `user_id` = ?', 'sii', [$converted($campaignCurrency, $foreign), $id, $userId]];
            }
        }
    }

    $conn->transaction(static function () use ($conn, $userId, $currency, $updates): void {
        $stmt = $conn->prepareWrite('UPDATE `202_users_pref` SET `user_account_currency` = ? WHERE `user_id` = ?');
        $conn->bind($stmt, 'si', [$currency, $userId]);
        $conn->executeUpdate($stmt);
        foreach ($updates as [$sql, $types, $values]) {
            $stmt = $conn->prepareWrite($sql);
            $conn->bind($stmt, $types, $values);
            $conn->executeUpdate($stmt);
        }
    });
}

/**
 * Create a user, or save an edit to one, with its role: all of it or none.
 *
 * Settings › Users wrote the user row, then the role, then (for a new user)
 * the preferences row, each autocommitted. A failure after the first left a
 * live account with a password and no role, which a retry then collided with
 * on its username (#165, #173). One transaction now holds every write, and
 * Connection throws on a failed prepare, bind or execute, so transaction()
 * rolls all of it back. The caller must not be inside a transaction already:
 * mysqli's begin_transaction() commits an open one (CLAUDE.md #13), and
 * nothing on the page that calls this opens one.
 *
 * An edit is also held to a user who is still there: the row is locked and
 * must not be soft-deleted, and the role is replaced rather than updated in
 * place, so a user who somehow has no role row gets one.
 *
 * A new user's other first rows (its default attribution model) are written
 * by $onCreate inside the same transaction, so an account never commits
 * without them; it must not open a transaction of its own.
 *
 * @param array<string, string|int|null> $userSet  202_users column => value; the
 *   column names are the caller's own literals, never the request's
 * @param (callable(int): mixed)|null $onCreate  run with the new user's id, for a create only
 * @return int the user's id
 * @throws DomainException when the edited user is not there any more
 * @throws Throwable when nothing was saved
 */
function p202_account_save_user(\Prosper202\Database\Connection $conn, ?int $editUserId, array $userSet, int $roleId, ?callable $onCreate = null): int
{
    if ($userSet === []) {
        throw new InvalidArgumentException('p202_account_save_user(): nothing to save');
    }
    $assignments = [];
    $types = '';
    foreach ($userSet as $column => $value) {
        if (preg_match('/^[a-z_0-9]+$/', (string) $column) !== 1) {
            throw new InvalidArgumentException("p202_account_save_user(): '$column' is not a column name");
        }
        $assignments[] = '`' . $column . '` = ?';
        $types .= is_int($value) || $value === null ? 'i' : 's';
    }
    $values = array_values($userSet);

    return $conn->transaction(static function () use ($conn, $editUserId, $assignments, $types, $values, $roleId, $onCreate): int {
        if ($editUserId !== null) {
            $stmt = $conn->prepareWrite('SELECT `user_id` FROM `202_users` WHERE `user_id` = ? AND `user_deleted` != 1 FOR UPDATE');
            $conn->bind($stmt, 'i', [$editUserId]);
            if ($conn->fetchOne($stmt) === null) {
                throw new DomainException('That user is not there any more.');
            }
            $stmt = $conn->prepareWrite('UPDATE `202_users` SET ' . implode(', ', $assignments) . ' WHERE `user_id` = ? AND `user_deleted` != 1');
            $conn->bind($stmt, $types . 'i', [...$values, $editUserId]);
            $conn->executeUpdate($stmt);
            $userId = $editUserId;
            $stmt = $conn->prepareWrite('DELETE FROM `202_user_role` WHERE `user_id` = ?');
            $conn->bind($stmt, 'i', [$userId]);
            $conn->executeUpdate($stmt);
        } else {
            $stmt = $conn->prepareWrite('INSERT INTO `202_users` SET ' . implode(', ', $assignments));
            $conn->bind($stmt, $types, $values);
            $userId = $conn->executeInsert($stmt);
            if ($userId <= 0) {
                // The same value, asked of the server on this connection:
                // the role and preferences rows must name the new user, and
                // an id of 0 would attach them to nobody.
                $stmt = $conn->prepareWrite('SELECT LAST_INSERT_ID() AS `id`');
                $userId = (int) (($conn->fetchOne($stmt) ?? [])['id'] ?? 0);
            }
            if ($userId <= 0) {
                throw new RuntimeException('The new user was not given an id.');
            }
            $stmt = $conn->prepareWrite('INSERT INTO `202_users_pref` (`user_id`) VALUES (?)');
            $conn->bind($stmt, 'i', [$userId]);
            $conn->executeInsert($stmt);
        }
        $stmt = $conn->prepareWrite('INSERT INTO `202_user_role` (`user_id`, `role_id`) VALUES (?, ?)');
        $conn->bind($stmt, 'ii', [$userId, $roleId]);
        $conn->executeInsert($stmt);
        if ($editUserId === null && $onCreate !== null) {
            $onCreate($userId);
        }
        return $userId;
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
