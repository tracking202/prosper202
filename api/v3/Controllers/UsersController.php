<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Exception\ValidationException;

class UsersController
{
    /**
     * The currency column's own default, and the fallback for anything
     * unusable. See normalizeCurrency() below.
     */
    public const DEFAULT_CURRENCY = 'USD';

    /**
     * Every currency an account may be set to — the list
     * 202-account/account.php offers, which is the only thing that makes a
     * stored value legitimate.
     *
     * Kept apart from CURRENCY_SYMBOLS below, because "is this a currency this
     * install supports" and "do we have a glyph for it" are different
     * questions. Collapsing them into one broke six real currencies: AUD, CAD,
     * HKD, MXN, NZD and SGD are all selectable on the settings page and none
     * has a symbol here, so validating against the symbol table rewrote a
     * legitimate stored preference to USD, tagged conversion-ledger writes
     * USD, and made the v3 preferences endpoint reject a currency its own
     * settings page offers.
     *
     * tests/User/AccountCurrencyTest.php holds this against the options in
     * account.php, so the two cannot drift.
     *
     * @var list<string>
     */
    public const SUPPORTED_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD',
        'HUF', 'ILS', 'INR', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN',
        'RUB', 'SEK', 'SGD', 'THB', 'TRY', 'TWD', 'USD',
    ];

    /**
     * The subset of those that render as a symbol, and which symbol.
     *
     * dollar_format() in 202-config/functions-tracking202.php reads this and
     * falls back to printing the CODE for a currency that is not here —
     * "AUD10.00" rather than a glyph, which is a fine rendering for a real
     * currency and the behaviour those six have always had. What that fallback
     * must never be reached with is a value that is not a currency at all,
     * which is what normalizeCurrency() and the preferences endpoint are for.
     *
     * A leading empty string means the symbol follows the amount instead.
     *
     * @var array<string, array{0: string, 1: string}> code => [before, after]
     */
    public const CURRENCY_SYMBOLS = [
        'USD' => ['$', ''],
        'BRL' => ['R$', ''],
        'CZK' => ['', 'Kč'],
        'DKK' => ['kr.', ''],
        'EUR' => ['€', ''],
        'HUF' => ['', 'Ft'],
        'ILS' => ['₪', ''],
        'JPY' => ['¥', ''],
        'MYR' => ['RM', ''],
        'NOK' => ['kr', ''],
        'PHP' => ['₱', ''],
        'PLN' => ['zł', ''],
        'GBP' => ['£', ''],
        'SEK' => ['kr', ''],
        'CHF' => ['SFr.', ''],
        'TWD' => ['NT$', ''],
        'THB' => ['฿', ''],
        'TRY' => ['', '₺'],
        'CNY' => ['¥', ''],
        'INR' => ['₹', ''],
        'RUB' => ['₽', ''],
    ];

    public function __construct(private readonly \mysqli $db)
    {
    }

    public function list(): array
    {
        $stmt = $this->prepare(
            'SELECT user_id, user_fname, user_lname, user_name, user_email, user_timezone, user_active, user_deleted, user_time_register
            FROM 202_users WHERE user_deleted = 0 ORDER BY user_id ASC'
        );
        $this->execute($stmt, 'List query failed');
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return ['data' => $rows];
    }

    public function get(int $id): array
    {
        $stmt = $this->prepare(
            'SELECT user_id, user_fname, user_lname, user_name, user_email, user_timezone, user_active, user_deleted, user_time_register
            FROM 202_users WHERE user_id = ? AND user_deleted = 0 LIMIT 1'
        );
        $this->bind($stmt, 'i', $id);
        $this->execute($stmt, 'Query failed');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('User not found');
        }

        $stmt = $this->prepare('SELECT r.role_id, r.role_name FROM 202_user_role ur INNER JOIN 202_roles r ON ur.role_id = r.role_id WHERE ur.user_id = ?');
        $this->bind($stmt, 'i', $id);
        $this->execute($stmt, 'Roles query failed');
        $roles = [];
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $roles[] = $r;
        }
        $stmt->close();

        $row['roles'] = $roles;
        return ['data' => $row];
    }

    public function create(array $payload): array
    {
        $username = trim((string)($payload['user_name'] ?? ''));
        $email = trim((string)($payload['user_email'] ?? ''));
        $password = (string)($payload['user_pass'] ?? '');

        $errors = [];
        if ($username === '') { $errors['user_name'] = 'Required'; }
        if ($email === '') { $errors['user_email'] = 'Required'; }
        if ($password === '') { $errors['user_pass'] = 'Required'; }
        if (strlen($password) > 0 && strlen($password) < 8) { $errors['user_pass'] = 'Must be at least 8 characters'; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['user_email'] = 'Invalid email format'; }
        if ($errors) {
            throw new ValidationException('Validation failed', $errors);
        }

        $stmt = $this->prepare('SELECT user_id FROM 202_users WHERE user_name = ? LIMIT 1');
        $this->bind($stmt, 's', $username);
        $this->execute($stmt, 'Query failed');
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            throw new ConflictException('Username already exists');
        }
        $stmt->close();

        $hashedPass = \hash_user_pass($password);

        $fname = trim((string)($payload['user_fname'] ?? ''));
        $lname = trim((string)($payload['user_lname'] ?? ''));
        $tz = trim((string)($payload['user_timezone'] ?? 'UTC'));
        $now = time();
        $active = (int)($payload['user_active'] ?? 1);

        // user_dash_email, install_hash and user_hash are NOT NULL with no default; the
        // V3 connection runs under MySQL strict mode, so they must be supplied explicitly.
        // install_hash is an install-wide value (mirrors the UI, which copies it from user 1).
        $installHash = '';
        $hashStmt = $this->prepare('SELECT install_hash FROM 202_users WHERE user_id = 1 LIMIT 1');
        $this->execute($hashStmt, 'Lookup failed');
        $hashRow = $hashStmt->get_result()->fetch_assoc();
        $hashStmt->close();
        if ($hashRow && isset($hashRow['install_hash'])) {
            $installHash = (string) $hashRow['install_hash'];
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->prepare(
                'INSERT INTO 202_users (user_fname, user_lname, user_name, user_pass, user_email, user_dash_email, user_timezone, user_time_register, user_active, install_hash, user_hash, user_deleted)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
            );
            $this->bind($stmt, 'sssssssiiss', $fname, $lname, $username, $hashedPass, $email, $email, $tz, $now, $active, $installHash, '');
            $this->execute($stmt, 'Create failed');
            $newId = $stmt->insert_id;
            $stmt->close();

            $stmt = $this->prepare('INSERT INTO 202_users_pref (user_id) VALUES (?)');
            $this->bind($stmt, 'i', $newId);
            $this->execute($stmt, 'Failed to create user preferences');
            $stmt->close();

            // Every account starts with its default attribution model, in the
            // same transaction as the account (plan §6.4).
            \Prosper202\Attribution\DefaultModel::ensureFor(new \Prosper202\Database\Connection($this->db), (int) $newId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        // Committed: both the user row and its preferences row exist. Only
        // the read-back remains, and its failure is not a failed create.
        try {
            return $this->get($newId);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('user', $e);
        }
    }

    public function update(int $id, array $payload): array
    {
        $this->get($id);

        $sets = [];
        $binds = [];
        $types = '';

        foreach (['user_fname' => 's', 'user_lname' => 's', 'user_email' => 's', 'user_timezone' => 's', 'user_active' => 'i'] as $f => $t) {
            if (array_key_exists($f, $payload)) {
                $sets[] = "$f = ?";
                $binds[] = $payload[$f];
                $types .= $t;
            }
        }

        if (array_key_exists('user_email', $payload) && !filter_var($payload['user_email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid email', ['user_email' => 'Invalid email format']);
        }

        if (array_key_exists('user_pass', $payload) && $payload['user_pass'] !== '') {
            if (strlen((string) $payload['user_pass']) < 8) {
                throw new ValidationException('Password too short', ['user_pass' => 'Must be at least 8 characters']);
            }
            $sets[] = 'user_pass = ?';
            $binds[] = \hash_user_pass((string) $payload['user_pass']);
            $types .= 's';
        }

        if (empty($sets)) {
            throw new ValidationException('No fields to update');
        }

        $binds[] = $id;
        $types .= 'i';

        $stmt = $this->prepare('UPDATE 202_users SET ' . implode(', ', $sets) . ' WHERE user_id = ?');
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Update failed');
        $stmt->close();

        return $this->get($id);
    }

    /**
     * Read-only preview of delete() for `?dry_run=1`.
     */
    public function deletePreview(int $id): array
    {
        $existing = $this->get($id);
        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'users',
            'mode' => 'soft',
            'record' => $existing['data'],
            'cascade' => \Prosper202\User\UserDataPurge::cascade($id),
        ]];
    }

    public function delete(int $id): void
    {
        $this->get($id);
        // The soft delete and the purge of what must not outlive the user
        // (API keys, app registrations, the identity graph, MTA state) commit
        // together; the account page deletes through the same class.
        try {
            (new \Prosper202\User\UserDataPurge($this->db))->deleteUser($id);
        } catch (\RuntimeException $e) {
            error_log('p202 users: ' . $e->getMessage());
            throw new DatabaseException('Delete failed; the user and their data are unchanged');
        }
    }

    // --- Roles ---

    public function listRoles(): array
    {
        $result = $this->db->query('SELECT role_id, role_name FROM 202_roles ORDER BY role_id');
        if (!$result) {
            throw new DatabaseException('Roles query failed');
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return ['data' => $rows];
    }

    public function assignRole(int $userId, array $payload): array
    {
        $roleId = (int)($payload['role_id'] ?? 0);
        if ($roleId <= 0) {
            throw new ValidationException('role_id is required', ['role_id' => 'Must be a positive integer']);
        }

        $stmt = $this->prepare('INSERT IGNORE INTO 202_user_role (user_id, role_id) VALUES (?, ?)');
        $this->bind($stmt, 'ii', $userId, $roleId);
        $this->execute($stmt, 'Failed to assign role');
        $stmt->close();

        return $this->get($userId);
    }

    public function removeRole(int $userId, int $roleId): void
    {
        $stmt = $this->prepare('DELETE FROM 202_user_role WHERE user_id = ? AND role_id = ?');
        $this->bind($stmt, 'ii', $userId, $roleId);
        $this->execute($stmt, 'Failed to remove role');
        $stmt->close();
    }

    // --- API Keys ---

    public function listApiKeys(int $userId): array
    {
        $hasScopeColumn = \Api\V3\Auth::apiKeyScopeColumnExists($this->db);
        $columns = $hasScopeColumn
            ? 'user_id, api_key, scope, created_at'
            : 'user_id, api_key, created_at';
        $stmt = $this->prepare("SELECT $columns FROM 202_api_keys WHERE user_id = ?");
        $this->bind($stmt, 'i', $userId);
        $this->execute($stmt, 'Query failed');
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            // Mask key: show first 8 chars only
            if (isset($row['api_key']) && strlen($row['api_key']) > 8) {
                $row['api_key'] = substr($row['api_key'], 0, 8) . str_repeat('*', 24);
            }
            // Normalize scope for display: rows without one are full-access.
            $row['scope'] = implode(',', \Api\V3\Auth::parseScopes((string)($row['scope'] ?? '')));
            $rows[] = $row;
        }
        $stmt->close();
        return ['data' => $rows];
    }

    /**
     * The account's customer-id linking key (plan §6.2): what the operator's
     * own server signs customer ids with, so a `cust` on a public pixel links
     * journeys only when it carries `cust_sig`. Minted on first read. It is a
     * signing secret, so it is shown in full only here, to the account itself
     * or an admin, and never in a list.
     */
    public function identityKey(int $userId): array
    {
        $this->requireUser($userId);
        $keys = new \Prosper202\Identity\IdentityKeys(new \Prosper202\Database\Connection($this->db));

        return ['data' => self::identityKeyView($keys->forUser($userId)['link'])];
    }

    /**
     * Replace the linking key. Every signature computed with the old key
     * stops linking at once; ids already linked stay linked.
     */
    public function rotateIdentityKey(int $userId): array
    {
        $this->requireUser($userId);
        $keys = new \Prosper202\Identity\IdentityKeys(new \Prosper202\Database\Connection($this->db));

        return ['data' => self::identityKeyView($keys->rotateLinkKey($userId))];
    }

    /** @return array<string, string> */
    private static function identityKeyView(string $linkKey): array
    {
        return [
            'linking_key' => $linkKey,
            'algorithm' => 'HMAC-SHA256',
            'signs' => '<cust_type>:<cust>, e.g. custom:12345 or email_sha256:<lower-case hex digest>; cust_type defaults to custom',
            'parameter' => 'cust_sig (lower-case hex)',
        ];
    }

    private function requireUser(int $userId): void
    {
        $stmt = $this->prepare('SELECT user_id FROM 202_users WHERE user_id = ? AND user_deleted = 0 LIMIT 1');
        $this->bind($stmt, 'i', $userId);
        $this->execute($stmt, 'Query failed');
        if (!$stmt->store_result()) {
            $stmt->close();
            throw new DatabaseException('Failed to read user ' . $userId);
        }
        $found = $stmt->num_rows > 0;
        $stmt->close();
        if (!$found) {
            throw new NotFoundException('User not found');
        }
    }

    public function createApiKey(int $userId, array $payload = [], ?\Api\V3\Auth $auth = null): array
    {
        $scopeTokens = $this->normalizeRequestedScope($payload['scope'] ?? null);

        foreach ($scopeTokens ?? [] as $token) {
            if (!\Api\V3\Auth::isValidScopeToken($token)) {
                throw new ValidationException('Invalid scope', [
                    'scope' => sprintf(
                        "Unknown scope token '%s'. Valid: *, read, write, stage, or <area>:read / <area>:write / <area>:stage with area one of: %s",
                        $token,
                        implode(', ', \Api\V3\Auth::KNOWN_SCOPE_AREAS)
                    ),
                ]);
            }
        }

        // A key can never mint broader access than it holds itself. A scoped
        // key must say what scope the new key gets — defaulting to full
        // access would be a silent escalation.
        if ($auth !== null && !$auth->hasFullScope()) {
            if ($scopeTokens === null) {
                throw new ValidationException('Invalid scope', [
                    'scope' => 'Your key is scoped (' . implode(',', $auth->scopes())
                        . '), so the new key needs an explicit scope no broader than that.',
                ]);
            }
            foreach ($scopeTokens as $token) {
                if (!$auth->coversScopeToken($token)) {
                    throw new \Api\V3\AuthException(
                        sprintf(
                            "Cannot create a key with scope '%s': your key only has %s.",
                            $token,
                            implode(',', $auth->scopes())
                        ),
                        403
                    );
                }
            }
        }

        $hasScopeColumn = \Api\V3\Auth::apiKeyScopeColumnExists($this->db);
        if ($scopeTokens !== null && !$hasScopeColumn) {
            throw new ConflictException(
                'This install predates API key scopes (no scope column on 202_api_keys). '
                . 'Run the upgrade at /202-config/upgrade.php, or create the key without a scope.'
            );
        }

        $key = bin2hex(random_bytes(32));
        $now = time();
        $storedScope = $scopeTokens === null ? null : implode(',', $scopeTokens);

        if ($hasScopeColumn) {
            $stmt = $this->prepare('INSERT INTO 202_api_keys (user_id, api_key, scope, created_at) VALUES (?, ?, ?, ?)');
            $this->bind($stmt, 'issi', $userId, $key, $storedScope, $now);
        } else {
            $stmt = $this->prepare('INSERT INTO 202_api_keys (user_id, api_key, created_at) VALUES (?, ?, ?)');
            $this->bind($stmt, 'isi', $userId, $key, $now);
        }

        $this->execute($stmt, 'Failed to create API key');
        $stmt->close();

        // Return the full key only on creation — it cannot be retrieved later.
        return ['data' => [
            'user_id' => $userId,
            'api_key' => $key,
            'scope' => $storedScope ?? '*',
            'created_at' => $now,
        ]];
    }

    /**
     * Normalize the requested scope into lower-cased unique tokens, or null
     * when no scope was requested (a full-access key, the pre-scope default).
     * Malformed input is an explicit error, never silently discarded.
     *
     * @return string[]|null
     */
    private function normalizeRequestedScope(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            throw new ValidationException('Invalid scope', [
                'scope' => 'Must be a comma-separated string or an array of scope tokens.',
            ]);
        }
        $tokens = [];
        foreach ($raw as $token) {
            if (!is_string($token)) {
                throw new ValidationException('Invalid scope', [
                    'scope' => 'Scope tokens must be strings.',
                ]);
            }
            $value = strtolower(trim($token));
            if ($value !== '') {
                $tokens[] = $value;
            }
        }
        $tokens = array_values(array_unique($tokens));
        if ($tokens === []) {
            // An explicitly supplied but empty scope is malformed input, not
            // a request for full access: silently minting a `*` key here
            // would hand out more privilege than the caller asked for.
            throw new ValidationException('Invalid scope', [
                'scope' => 'Scope was supplied but contained no tokens. Pass at least one token '
                    . '(for example `read`, `read,stage`, or `campaigns:write`), or omit scope '
                    . 'entirely for a full-access key.',
            ]);
        }
        return $tokens;
    }

    public function deleteApiKey(int $userId, string $apiKey): void
    {
        $stmt = $this->prepare('DELETE FROM 202_api_keys WHERE user_id = ? AND api_key = ?');
        $this->bind($stmt, 'is', $userId, $apiKey);
        $this->execute($stmt, 'Failed to delete API key');
        $stmt->close();
    }

    /**
     * Read-only preview of deleteApiKey() for `?dry_run=1`. The key in the
     * preview is masked like listApiKeys() — the full value never leaves
     * creation.
     */
    public function deleteApiKeyPreview(int $userId, string $apiKey): array
    {
        $stmt = $this->prepare('SELECT user_id, api_key, created_at FROM 202_api_keys WHERE user_id = ? AND api_key = ? LIMIT 1');
        $this->bind($stmt, 'is', $userId, $apiKey);
        $this->execute($stmt, 'Query failed');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('API key not found for user');
        }
        if (isset($row['api_key']) && strlen($row['api_key']) > 8) {
            $row['api_key'] = substr($row['api_key'], 0, 8) . str_repeat('*', 24);
        }

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'api-keys',
            'mode' => 'hard',
            'record' => $row,
            'cascade' => [],
        ]];
    }

    /**
     * Read-only preview of removeRole() for `?dry_run=1`.
     */
    public function removeRolePreview(int $userId, int $roleId): array
    {
        $stmt = $this->prepare(
            'SELECT ur.user_id, ur.role_id, r.role_name FROM 202_user_role ur '
            . 'INNER JOIN 202_roles r ON ur.role_id = r.role_id '
            . 'WHERE ur.user_id = ? AND ur.role_id = ? LIMIT 1'
        );
        $this->bind($stmt, 'ii', $userId, $roleId);
        $this->execute($stmt, 'Query failed');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('Role assignment not found for user');
        }

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'user-roles',
            'mode' => 'hard',
            'record' => $row,
            'cascade' => [],
        ]];
    }

    // --- Preferences ---

    public function getPreferences(int $userId): array
    {
        $stmt = $this->prepare('SELECT * FROM 202_users_pref WHERE user_id = ? LIMIT 1');
        $this->bind($stmt, 'i', $userId);
        $this->execute($stmt, 'Query failed');
        // Checked: get_result() returns false on failure, and false read as
        // "no row" is indistinguishable from a user who has no preferences —
        // the silent shape CLAUDE.md error pattern #1 names get_result() for.
        // Unchecked, the ->fetch_assoc() below raises \Error, which is not an
        // \Exception and escapes every catch on the page path.
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Query failed');
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('User preferences not found');
        }
        return ['data' => $row];
    }

    /**
     * The currency this account's money is denominated in.
     *
     * Every page that prints an amount needs this, and each one reaching for
     * the raw column would be a second place to get the fallback wrong. Lives
     * here because preferences do: 202_users_pref.user_account_currency is one
     * of the twenty-one codes dollar_format() knows, USD when unset.
     *
     * A failed read answers USD rather than propagating: an amount rendered
     * in the wrong symbol is a cosmetic error, and a report that 500s because
     * a preferences row could not be read is not. \Throwable, not
     * HttpException: the promise is "this never takes the page down", and
     * narrowing it to the exception type the happy path throws would have
     * been a promise the code did not keep — a driver-level \Error is exactly
     * the case worth surviving.
     */
    public function accountCurrency(int $userId): string
    {
        try {
            $preferences = $this->getPreferences($userId)['data'];
        } catch (\Throwable) {
            return self::DEFAULT_CURRENCY;
        }

        return self::normalizeCurrency($preferences['user_account_currency'] ?? null);
    }

    /**
     * A stored currency as a code dollar_format() can actually render.
     *
     * Membership of SUPPORTED_CURRENCIES, not a three-letter shape: the shape
     * test let any three letters through to dollar_format(), whose last
     * resort is to print the code as the symbol, so a stored "XYZ" put
     * "XYZ10.00" on every page looking exactly like a real currency. Not
     * membership of CURRENCY_SYMBOLS either — that rejected the six supported
     * currencies which have no glyph and legitimately render as their code.
     */
    public static function normalizeCurrency(mixed $raw): string
    {
        $currency = is_scalar($raw) ? strtoupper(trim((string)$raw)) : '';

        return in_array($currency, self::SUPPORTED_CURRENCIES, true) ? $currency : self::DEFAULT_CURRENCY;
    }

    public function updatePreferences(int $userId, array $payload): array
    {
        $this->getPreferences($userId);

        $allowedFields = [
            'user_pref_limit' => 'i', 'user_pref_time_predefined' => 's',
            'user_tracking_domain' => 's', 'user_cpc_or_cpv' => 's',
            'user_account_currency' => 's', 'user_slack_incoming_webhook' => 's',
            'user_pref_cloak_referer' => 's', 'user_daily_email' => 's',
            'ipqs_api_key' => 's', 'chart_time_range' => 's',
        ];

        // Refused here, not normalised on the way out. The read path resolves
        // an unrenderable code to USD so no page prints "XYZ10.00", but that
        // is a repair for rows already stored — applying it to a write would
        // answer 200 and quietly keep a currency the caller did not choose
        // (error pattern #4). The check belongs at the layer that accepts the
        // value (#12), and it names what it will take.
        if (array_key_exists('user_account_currency', $payload)) {
            $raw = $payload['user_account_currency'];
            $currency = is_scalar($raw) ? strtoupper(trim((string)$raw)) : '';
            if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
                throw new ValidationException('Validation failed', [
                    'user_account_currency' => 'Must be one of: '
                        . implode(', ', self::SUPPORTED_CURRENCIES),
                ]);
            }
            $payload['user_account_currency'] = $currency;
        }

        $sets = [];
        $binds = [];
        $types = '';

        foreach ($allowedFields as $f => $t) {
            if (array_key_exists($f, $payload)) {
                $sets[] = "$f = ?";
                $binds[] = $payload[$f];
                $types .= $t;
            }
        }

        if (empty($sets)) {
            throw new ValidationException('No valid fields to update');
        }

        $binds[] = $userId;
        $types .= 'i';

        $stmt = $this->prepare('UPDATE 202_users_pref SET ' . implode(', ', $sets) . ' WHERE user_id = ?');
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Preferences update failed');
        $stmt->close();

        return $this->getPreferences($userId);
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed');
        }
        return $stmt;
    }

    private function bind(\mysqli_stmt $stmt, string $types, mixed ...$values): void
    {
        // @phpstan-ignore-next-line prosper202.directStmtCall -- local checked bind wrapper
        if (!$stmt->bind_param($types, ...$values)) {
            $stmt->close();
            throw new DatabaseException('Bind failed');
        }
    }

    private function execute(\mysqli_stmt $stmt, string $message): void
    {
        // @phpstan-ignore-next-line prosper202.directStmtCall -- local checked execute wrapper
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException($message);
        }
    }
}
