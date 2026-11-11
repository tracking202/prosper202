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

/** The sentence a key gets when the licence service could not be asked about it. */
const P202_ACCOUNT_KEY_UNCHECKED = 'The key could not be checked with Tracking202 just now, so nothing was saved. '
    . 'Try again in a minute.';

/**
 * Why a typed key may not be saved, from the licence service's answer for
 * that key (ClickServerKeyValidator::check()), or null when it may. No
 * answer is a refusal here: a key that could not be checked is not saved.
 *
 * @param array{valid: bool, paid: ?bool}|null $licence
 */
function p202_typed_key_error(?array $licence): ?string
{
    if ($licence === null) {
        return P202_ACCOUNT_KEY_UNCHECKED;
    }
    return $licence['valid'] ? null : 'This API Key appears invalid.';
}

/**
 * The account page's Tracking202 key change: why the typed key may not be
 * saved, asking the licence service about that key itself.
 * AUTH::is_valid_api_key() answers from the session once the signed-in key
 * has validated and lets an unanswered check through, which suits a page
 * gate and not a key about to be saved.
 */
function p202_check_typed_key(string $key): ?string
{
    $licence = \Prosper202\License\ClickServerKeyValidator::check(trim($key));
    p202_record_key_answer($key, $licence);
    return p202_typed_key_error($licence);
}

/**
 * Record the licence service's answer for a key a form confirmed, so the
 * grace period knows it (ShellAccessCache::recordLicence()); none records
 * nothing.
 *
 * @param array{valid: bool, paid: ?bool}|null $licence
 */
function p202_record_key_answer(string $key, ?array $licence): void
{
    \Prosper202\License\ShellAccessCache::recordLicence(
        \Prosper202\License\MysqlLicenceAnswerStore::ofThisInstall(),
        trim($key),
        $licence
    );
}

/**
 * Why a customer API key may not be saved, from the licence service's answer
 * for it as ClickServerKeyValidator reads it, or null when it may: no answer
 * (an outage, a gateway's error page) is "could not be checked", not
 * "invalid", and is not saved either.
 *
 * @param array{valid: bool, paid: ?bool}|null $licence
 */
function p202_customer_key_error(?array $licence): ?string
{
    if ($licence === null) {
        return P202_ACCOUNT_KEY_UNCHECKED;
    }
    return $licence['valid'] ? null : 'API key is not valid. Check your key and try again!';
}

/**
 * Why a ClickServer API key may not be saved, from
 * clickserver_api_key_validate()'s answer, or null when it may.
 */
function p202_clickserver_key_error(?bool $valid): ?string
{
    if ($valid === null) {
        return P202_ACCOUNT_KEY_UNCHECKED;
    }
    return $valid ? null : 'This API Key appears invalid.';
}

/**
 * An answer whose failure is in its status as in its body (CLAUDE.md #1):
 * sets $status and returns the JSON the AJAX callers read.
 */
function p202_status_body(int $status, string $message): string
{
    http_response_code($status);
    return (string) json_encode(['code' => $status, 'msg' => $message]);
}

/**
 * The ClickServer key forms: p202_clickserver_key_error() for the key
 * itself. An empty key removes the stored one and is not checked.
 */
function p202_check_clickserver_key(string $key): ?string
{
    return $key === '' ? null : p202_clickserver_key_error(clickserver_api_key_validate($key));
}

/** The account page's customer key form: p202_customer_key_error() for the key itself. */
function p202_check_customer_key(string $key): ?string
{
    $licence = \Prosper202\License\ClickServerKeyValidator::check($key);
    p202_record_key_answer($key, $licence);
    return p202_customer_key_error($licence);
}

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
 * adds, rendered in the kit's shape (p202_flash()). They come in one
 * [data-p202-flashes] block, which p202-ui.js moves into the panel the URL's
 * fragment names: a redirect to "page.php#section" scrolls past the top of
 * the page, where they render.
 *
 * @param list<array{kind: string, text: string}> $extra
 */
function p202_account_render_flashes(array $extra = []): string
{
    $html = '';
    foreach (array_merge(p202_account_take_flashes(), $extra) as $flash) {
        $html .= p202_flash($flash['kind'], $flash['text']);
    }
    return $html === '' ? '' : '<div data-p202-flashes>' . $html . '</div>';
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
 * Account › Settings' pill for a sign-in attempt, from its stored
 * login_success: a right password the sign-in could not complete is neither
 * passed nor failed (the throttle does not count it), and a value no version
 * writes is shown as it is.
 */
function p202_account_sign_in_attempt_pill(mixed $stored): string
{
    $outcome = \Prosper202\User\SignInOutcome::fromStored($stored);
    $shown = is_scalar($stored) ? (string) $stored : get_debug_type($stored);

    return match ($outcome) {
        \Prosper202\User\SignInOutcome::Passed => '<span class="p202-pill p202-pill--good">Passed</span>',
        \Prosper202\User\SignInOutcome::Failed => '<span class="p202-pill p202-pill--bad">Failed</span>',
        \Prosper202\User\SignInOutcome::NotCompleted => '<span class="p202-pill p202-pill--warn"'
            . ' title="The password was right; a database failure stopped the sign-in">Not completed</span>',
        null => '<span class="p202-pill">Unknown (' . htmlspecialchars($shown, ENT_QUOTES, 'UTF-8') . ')</span>',
    };
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
    // Rates first, outside the transaction; then the currency and every
    // re-priced campaign together. PUT /users/{id}/preferences runs the
    // same two steps (Prosper202\User\CurrencyChange).
    $updates = \Prosper202\User\CurrencyChange::plan($conn, $userId, $currency, $storedCurrency, $rate);
    $conn->transaction(static function () use ($conn, $userId, $currency, $updates): void {
        $stmt = $conn->prepareWrite('UPDATE `202_users_pref` SET `user_account_currency` = ? WHERE `user_id` = ?');
        $conn->bind($stmt, 'si', [$currency, $userId]);
        $conn->executeUpdate($stmt);
        \Prosper202\User\CurrencyChange::apply($conn, $updates);
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
 * An edit that switches the user off, or sets their password, deletes their
 * remember-me keys and ends their open sessions in the same transaction
 * (RememberMeKeys, SessionGeneration): a browser kept signed in must not sign
 * back in when they are switched on again, nor a stolen cookie or session
 * outlive the password an admin reset.
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
    $revokes = array_key_exists('user_pass', $userSet) || (array_key_exists('user_active', $userSet)
        && !\Prosper202\User\UserStanding::isActive($userSet['user_active']));

    return $conn->transaction(static function () use ($conn, $editUserId, $assignments, $types, $values, $roleId, $onCreate, $revokes): int {
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
            if ($revokes) {
                \Prosper202\User\RememberMeKeys::revokeAll($conn, $userId);
                \Prosper202\User\SessionGeneration::bump($conn, $userId);
            }
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
 * No key itself is returned: a key is stored hashed (ApiKeyStore) and named
 * by its ref; a key still stored whole is shown as a hashed one would be.
 *
 * @return list<array<string, mixed>>  ref, prefix, created_at, scope (null
 *   when the install has no scope column), label, watcher_token for an AI
 *   connection's key (null otherwise), and the key's last use and ending
 *   (use_recorded false, and each null, before the upgrade adds the columns)
 * @throws Throwable when the keys cannot be read
 */
function p202_account_api_keys(mysqli $db, int $userId): array
{
    $hasScope = \Api\V3\Auth::apiKeyScopeColumnExists($db);
    // label came with 1.9.78; the files can land before its upgrade runs.
    $hasLabel = \Api\V3\Auth::apiKeyLabelColumnExists($db);
    $conn = new \Prosper202\Database\Connection($db);
    $columns = \Prosper202\User\ApiKeyStore::columns($db) . ', created_at'
        . ($hasScope ? ', scope' : '') . ($hasLabel ? ', label' : '')
        . \Prosper202\User\ApiKeyStore::useColumns($db);
    $hasUse = \Api\V3\Auth::apiKeyUseColumnsExist($db);
    $stmt = $conn->prepareRead('SELECT ' . $columns
        . ' FROM 202_api_keys WHERE user_id = ? ORDER BY created_at DESC');
    $conn->bind($stmt, 'i', [$userId]);
    $keys = [];
    foreach ($conn->fetchAll($stmt) as $row) {
        $label = $hasLabel && isset($row['label']) ? (string) $row['label'] : null;
        $keys[] = [
            'ref' => \Prosper202\User\ApiKeyStore::ref($row),
            'prefix' => \Prosper202\User\ApiKeyStore::prefixOf($row),
            'created_at' => $row['created_at'],
            'scope' => $hasScope ? ($row['scope'] ?? null) : null,
            'label' => $label,
            'watcher_token' => \Api\V3\Support\AiConnect::isConnectionLabel($label)
                ? \Prosper202\User\ApiKeyStore::watcherTokenOf($row)
                : null,
            'use_recorded' => $hasUse,
            'last_used_at' => isset($row['last_used_at']) ? (int) $row['last_used_at'] : null,
            'last_used_api' => isset($row['last_used_api']) ? (string) $row['last_used_api'] : null,
            'last_used_client' => isset($row['last_used_client']) ? (string) $row['last_used_client'] : null,
            'ends_at' => isset($row['ends_at']) ? (int) $row['ends_at'] : null,
        ];
    }
    return $keys;
}

/**
 * What a key's row says of its last use and its ending, as text, and whether
 * Replace is offered: not before the upgrade adds the columns, not for a key
 * already replaced, and not for an AI app's key (its app connects again).
 * The client is the request's own User-Agent, so it is shown as its words.
 *
 * @param array<string, mixed> $key one of p202_account_api_keys()
 * @return array{used: string, ending: string, ended: bool, replaceable: bool}
 */
function p202_account_key_use(array $key, int $now): array
{
    if (!($key['use_recorded'] ?? false)) {
        return ['used' => '', 'ending' => '', 'ended' => false, 'replaceable' => false];
    }
    $used = 'No use recorded';
    if ($key['last_used_at'] !== null) {
        $used = 'Last used ' . date('M j, Y H:i', (int) $key['last_used_at']);
        $api = (string) ($key['last_used_api'] ?? '');
        if ($api !== '') {
            $used .= ' on API ' . $api;
        }
        $client = (string) ($key['last_used_client'] ?? '');
        if ($client !== '') {
            $used .= ', by a client calling itself ' . $client;
        }
    }
    $ending = '';
    $ended = false;
    if ($key['ends_at'] !== null) {
        $ended = (int) $key['ends_at'] <= $now;
        $ending = ($ended ? 'Replaced; ended ' : 'Replaced; works until ') . date('M j, Y H:i', (int) $key['ends_at']);
    }
    return [
        'used' => $used,
        'ending' => $ending,
        'ended' => $ended,
        'replaceable' => $key['ends_at'] === null
            && !\Api\V3\Support\AiConnect::isConnectionLabel($key['label'] ?? null),
    ];
}

/** The IPQualityScore card's description on Account › API integrations. */
function p202_ipqs_card_desc(): string
{
    return 'Score your clicks for fraud. A few minutes after a click arrives, IPQualityScore scores its visitor’s'
        . ' IP address, and a click scoring ' . \Prosper202\Fraud\IpqsScoring::FRAUD_THRESHOLD . ' or more (out'
        . ' of 100) is filtered out of your reports: it shows under Filtered clicks, with the reason fraud_score.';
}

/**
 * What the IPQualityScore card says is sent, and, once a key is saved, how
 * the account's scoring went last (Prosper202\Fraud\IpqsScoring::status()),
 * as HTML.
 */
function p202_ipqs_card_note(\mysqli $db, int $userId, bool $keySaved): string
{
    $note = '<p class="text-secondary small">Only while a key is saved, the IP address of each visitor from outside'
        . ' the EU, the EEA, the UK and Switzerland is sent to IPQualityScore (ipqualityscore.com, in the USA) for'
        . ' fraud prevention. Nothing else about the click is sent, an address is looked up at most once a day, and'
        . ' its score is deleted after 24 hours. Visitors from the EU, the EEA, the UK and Switzerland, and visitors'
        . ' whose country is unknown, are never sent and are not scored. That includes the EU\'s outermost regions'
        . ' and Åland, Gibraltar, the Isle of Man, Jersey, Guernsey, and Svalbard and Jan Mayen. A redirector'
        . ' "High fraud score" rule also sends the visitor\'s user agent and language with the address, at'
        . ' redirect time, under the same regional limits. While your privacy setting holds back every visitor'
        . ' (\'all\'), nothing is sent and no click is scored, and a click whose address was stored masked is'
        . ' never scored.</p>';
    if (!$keySaved) {
        return $note;
    }
    try {
        $status = \Prosper202\Fraud\IpqsScoring::status(new \Prosper202\Database\Connection($db), $userId);
    } catch (\Throwable $failed) {
        error_log('api-integrations.php: the IPQualityScore scoring status could not be read: '
            . $failed->getMessage());

        return $note . p202_flash('warn', 'The scoring status could not be read.');
    }
    if ($status !== null && $status['failures'] > 0 && $status['last_error'] !== null) {
        $failedAt = date('Y-m-d H:i', (int) $status['last_error_at']);

        return $note . p202_flash('warn', 'The last lookup failed (' . $failedAt . '): ' . $status['last_error']
            . ' Scoring tries again after ' . date('H:i', $status['retry_at']) . '.');
    }
    if ($status !== null && $status['last_lookup_at'] !== null) {
        return $note . '<p class="text-secondary small">Last lookup: '
            . htmlspecialchars(date('Y-m-d H:i', $status['last_lookup_at']), ENT_QUOTES, 'UTF-8') . '.</p>';
    }

    return $note . '<p class="text-secondary small">No click has been looked up yet: scoring starts with the next'
        . ' clicks.</p>';
}

/**
 * Each AI-connection key's watchers, as Tracking202's MCP server reports
 * them (AiWatchers::list()): the key's ref => the list, or null where it
 * could not be read. Keys of no AI connection get no entry, so an account without one
 * makes no call.
 *
 * @param list<array<string, mixed>> $keys p202_account_api_keys()
 * @return array<string, list<array<string, mixed>>|null>
 */
function p202_account_ai_watchers(array $keys, \Api\V3\Support\AiWatchers $relay): array
{
    $watchers = [];
    foreach ($keys as $key) {
        $token = $key['watcher_token'] ?? null;
        if (is_string($token) && $token !== '') {
            $watchers[(string) $key['ref']] = $relay->list($token);
        }
    }
    return $watchers;
}

/**
 * The account's own campaigns among $ids: id => name, '(deleted)' after a
 * deleted one's. Another account's campaign, or none, is not in the answer.
 *
 * @param list<int> $ids
 * @return array<int, string>
 * @throws Throwable when the campaigns cannot be read
 */
function p202_account_campaign_names(mysqli $db, int $userId, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }
    $conn = new \Prosper202\Database\Connection($db);
    $stmt = $conn->prepareRead('SELECT aff_campaign_id, aff_campaign_name, aff_campaign_deleted FROM 202_aff_campaigns'
        . ' WHERE user_id = ? AND aff_campaign_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')');
    $conn->bind($stmt, 'i' . str_repeat('i', count($ids)), array_merge([$userId], $ids));
    $names = [];
    foreach ($conn->fetchAll($stmt) as $row) {
        $names[(int) $row['aff_campaign_id']] = (string) $row['aff_campaign_name']
            . ((int) ($row['aff_campaign_deleted'] ?? 0) === 1 ? ' (deleted)' : '');
    }
    return $names;
}

/**
 * What a watcher watches, in one sentence, from its rule, breakdown and
 * window: "Each landing page's EPC 30% or more below breakeven, after 500
 * clicks, since the watcher started. Checked every 60 minutes."
 *
 * @param array<string, mixed> $watcher AiWatchers::list()'s entry
 */
function p202_account_watcher_rule(array $watcher): string
{
    $rule = is_array($watcher['rule'] ?? null) ? $watcher['rule'] : [];
    $breakdowns = [
        'campaign' => 'The campaign\'s', 'ppc_account' => 'Each traffic source account\'s',
        'ppc_network' => 'Each traffic source\'s', 'landing_page' => 'Each landing page\'s',
        'keyword' => 'Each keyword\'s', 'country' => 'Each country\'s', 'device' => 'Each device\'s',
        'text_ad' => 'Each text ad\'s', 'rotator' => 'Each rotator\'s',
    ];
    $metrics = ['epc' => 'EPC', 'roi' => 'ROI', 'cvr' => 'conversion rate'];
    $comparators = ['lt' => 'below', 'lte' => 'at or below', 'gt' => 'above', 'gte' => 'at or above'];
    $windows = [
        'since_start' => 'since the watcher started', 'today' => 'today', 'yesterday' => 'yesterday',
        'last7' => 'over the last 7 days', 'last14' => 'over the last 14 days', 'last30' => 'over the last 30 days',
    ];
    $text = static fn (mixed $v): string => is_string($v) ? $v : '';
    $number = static fn (mixed $v): string => is_int($v) || is_float($v)
        ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.')
        : '?';

    $breakdown = $text($watcher['breakdown'] ?? '');
    $subject = $breakdowns[$breakdown]
        ?? ($breakdown === '' ? 'The campaign\'s' : 'Each ' . str_replace('_', ' ', $breakdown) . '\'s');
    $metricKey = $text($rule['metric'] ?? '');
    if ($metricKey === '') {
        return 'What it watches was not given.';
    }
    $metric = $metrics[$metricKey] ?? $metricKey;
    $unit = in_array($metricKey, ['roi', 'cvr'], true) ? '%' : '';
    if ($text($rule['thresholdKind'] ?? '') === 'pctBelowBreakeven') {
        $condition = $number($rule['threshold'] ?? null) . '% or more below breakeven';
    } else {
        $comparator = $text($rule['comparator'] ?? '');
        $condition = ($comparators[$comparator] ?? $comparator) . ' ' . $number($rule['threshold'] ?? null) . $unit;
    }
    $sentence = $subject . ' ' . $metric . ' ' . $condition;
    if (is_int($rule['minClicks'] ?? null) && $rule['minClicks'] > 0) {
        $sentence .= ', after ' . number_format($rule['minClicks']) . ' click' . ($rule['minClicks'] === 1 ? '' : 's');
    }
    $window = $text($watcher['window'] ?? '');
    if ($window !== '') {
        $sentence .= ', ' . ($windows[$window] ?? str_replace('_', ' ', $window));
    }
    $sentence .= '.';
    if (is_int($rule['checkEveryMinutes'] ?? null) && $rule['checkEveryMinutes'] > 0) {
        $every = $rule['checkEveryMinutes'];
        $sentence .= ' Checked every ' . ($every % 60 === 0 && $every >= 60
            ? ($every === 60 ? 'hour' : ($every / 60) . ' hours')
            : $every . ' minute' . ($every === 1 ? '' : 's')) . '.';
    }
    return $sentence;
}

/**
 * Account › API keys' Stop: stops one watcher of one of the signed-in user's
 * AI-connection keys, named by the ref the page printed (ApiKeyStore::ref()).
 * Another user's key, or a key of no AI connection, stops nothing.
 *
 * @return array{kind: 'ok'|'bad'|'warn', text: string} the flash to show
 */
function p202_account_stop_ai_watcher(
    mysqli $db,
    int $userId,
    string $postedRef,
    string $watcherId,
    \Api\V3\Support\AiWatchers $relay
): array {
    if (preg_match(\Api\V3\Support\AiWatchers::ID, $watcherId) !== 1) {
        return ['kind' => 'warn', 'text' => 'That watcher was not recognised; nothing was stopped.'];
    }
    if (!\Prosper202\User\ApiKeyStore::isRef($postedRef)) {
        return ['kind' => 'warn', 'text' => 'That AI connection key was not found on this account; nothing was stopped.'];
    }
    try {
        $tokens = \Api\V3\Support\AiWatchers::connectionTokens($db, $userId, null, $postedRef);
    } catch (Throwable $e) {
        error_log('Account page: the AI connection key could not be read: ' . $e->getMessage());
        return ['kind' => 'bad', 'text' => 'The watcher could not be stopped just now; try again.'];
    }
    if ($tokens === []) {
        $text = 'That AI connection key was not found on this account; nothing was stopped.';
        return ['kind' => 'warn', 'text' => $text];
    }
    return match ($relay->stop($tokens[0], $watcherId)) {
        'stopped' => ['kind' => 'ok', 'text' => 'Watcher stopped. It reads nothing more with this key.'],
        'not_found' => ['kind' => 'warn', 'text' => 'Tracking202\'s MCP server has no such watcher for this key;'
            . ' it may have ended already.'],
        default => ['kind' => 'bad', 'text' => 'Tracking202\'s MCP server could not be reached to stop the watcher;'
            . ' try again, or revoke the key to cut it off.'],
    };
}

/**
 * Account › API keys' list of one AI-connection key's watchers, with a Stop
 * form for each running one; null watchers is a list that could not be read.
 *
 * @param list<array<string, mixed>>|null $watchers AiWatchers::list()
 * @param array<int, string> $campaignNames p202_account_campaign_names()
 * @param string $action where the Stop form posts
 */
function p202_account_render_watchers(string $keyRef, ?array $watchers, array $campaignNames, string $action): string
{
    $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $html = '<div class="mt-2 ms-3" data-ai-watchers><div class="form-text mt-0 mb-1"><strong>Watchers</strong>'
        . ' · campaign checks this AI app keeps running with this key after the chat ends</div>';
    if ($watchers === null) {
        return $html . '<div class="form-text text-warning">Couldn\'t load watchers right now. Reload the page to'
            . ' try again; revoking the key cuts them all off.</div></div>';
    }
    if ($watchers === []) {
        return $html . '<div class="form-text">No watchers.</div></div>';
    }
    $tones = ['running' => ' p202-pill--good', 'cancelled' => ' p202-pill--warn', 'failed' => ' p202-pill--bad'];
    $ends = ['running' => 'ends', 'completed' => 'ended'];
    $html .= '<ul class="list-unstyled mb-0">';
    foreach ($watchers as $watcher) {
        $campaign = (int) $watcher['campaignId'];
        $status = (string) $watcher['status'];
        $reason = (string) ($watcher['statusReason'] ?? '');
        $html .= '<li class="mb-2" data-ai-watcher="' . $e($watcher['id']) . '"><div><strong>'
            . $e($campaignNames[$campaign] ?? 'Campaign #' . $campaign) . '</strong> · <span class="p202-pill'
            . ($tones[$status] ?? '') . '"' . ($reason !== '' ? ' title="' . $e($reason) . '"' : '') . '>'
            . $e($status) . '</span>';
        if (is_int($watcher['endsAt'] ?? null)) {
            $html .= ' · ' . ($ends[$status] ?? 'was to end') . ' '
                . $e(date('M j, Y g:i a', intdiv($watcher['endsAt'], 1000)));
        }
        $alerts = (int) ($watcher['alerts'] ?? 0);
        $latest = $watcher['lastAlert'] ?? null;
        $html .= '</div><div class="form-text mt-0">' . $e(p202_account_watcher_rule($watcher)) . '</div>'
            . '<div class="form-text mt-0">' . ($alerts === 1 ? '1 alert' : $e($alerts . ' alerts'))
            . (is_string($latest) ? ' · latest: ' . $e($latest) : '') . '</div>';
        if ($status === 'running') {
            $html .= '<form method="post" action="' . $e($action)
                . '" class="mt-1"'
                . ' data-p202-confirm="Stop this watcher? It reads nothing more and sends no more alerts.">'
                . '<input type="hidden" name="stop_ai_watcher" value="1">'
                . '<input type="hidden" name="api_key_ref" value="' . $e($keyRef) . '">'
                . '<input type="hidden" name="watcher_id" value="' . $e($watcher['id']) . '">'
                . p202_account_token_field()
                . '<button class="btn btn-outline-danger btn-sm" type="submit">Stop</button></form>';
        }
        $html .= '</li>';
    }
    return $html . '</ul></div>';
}
