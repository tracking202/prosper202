<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\HttpException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\StatementHelpers;
use Prosper202\Database\Connection;
use Prosper202\User\CurrencyChange;
use Prosper202\User\ExchangeRates;
use Prosper202\User\PreferenceRules;

class UsersController
{
    use StatementHelpers;

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
        $result = $this->resultOf($stmt, 'List query failed');
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
        $row = $this->resultOf($stmt, 'Query failed')->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('User not found');
        }

        $stmt = $this->prepare('SELECT r.role_id, r.role_name FROM 202_user_role ur INNER JOIN 202_roles r ON ur.role_id = r.role_id WHERE ur.user_id = ?');
        $this->bind($stmt, 'i', $id);
        $this->execute($stmt, 'Roles query failed');
        $roles = [];
        $result = $this->resultOf($stmt, 'Roles query failed');
        while ($r = $result->fetch_assoc()) {
            $roles[] = $r;
        }
        $stmt->close();

        $row['roles'] = $roles;
        return ['data' => $row];
    }

    public function create(array $payload): array
    {
        $password = $payload['user_pass'] ?? '';

        $errors = [];
        foreach (['user_name', 'user_email'] as $required) {
            if (!isset($payload[$required]) || (is_string($payload[$required]) && trim($payload[$required]) === '')) {
                $errors[$required] = 'Required';
            }
        }
        if (!is_string($password) || $password === '') {
            $errors['user_pass'] = 'Required';
        } elseif (strlen($password) < self::PASSWORD_MIN || strlen($password) > self::PASSWORD_MAX) {
            $errors['user_pass'] = 'Must be between ' . self::PASSWORD_MIN . ' and ' . self::PASSWORD_MAX . ' characters';
        }
        if ($errors) {
            throw new ValidationException('Validation failed', $errors);
        }
        // The same rules as an update; the name and email are no account's yet.
        $fields = $this->profileFields(array_intersect_key($payload, array_flip(
            ['user_name', 'user_email', 'user_fname', 'user_lname', 'user_timezone', 'user_active']
        )), null);

        $hashedPass = \hash_user_pass($password);

        $username = (string) $fields['user_name'][1];
        $email = (string) $fields['user_email'][1];
        $fname = (string) ($fields['user_fname'][1] ?? '');
        $lname = (string) ($fields['user_lname'][1] ?? '');
        $tz = (string) ($fields['user_timezone'][1] ?? 'UTC');
        $now = time();
        $active = (int) ($fields['user_active'][1] ?? 1);

        // user_dash_email, install_hash and user_hash are NOT NULL with no default; the
        // V3 connection runs under MySQL strict mode, so they must be supplied explicitly.
        // install_hash is an install-wide value (mirrors the UI, which copies it from user 1).
        $installHash = '';
        $hashStmt = $this->prepare('SELECT install_hash FROM 202_users WHERE user_id = 1 LIMIT 1');
        $this->execute($hashStmt, 'Lookup failed');
        $hashRow = $this->resultOf($hashStmt, 'Lookup failed')->fetch_assoc();
        $hashStmt->close();
        if ($hashRow && isset($hashRow['install_hash'])) {
            $installHash = (string) $hashRow['install_hash'];
        }

        $newId = $this->transaction(function () use ($fname, $lname, $username, $hashedPass, $email, $tz, $now, $active, $installHash): int {
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

            return $newId;
        });

        // Committed: both the user row and its preferences row exist. Only
        // the read-back remains, and its failure is not a failed create.
        try {
            return $this->get($newId);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('user', $e);
        }
    }

    /** bcrypt reads only the first 72 bytes; a longer password would accept any tail. */
    private const PASSWORD_MIN = 8;
    private const PASSWORD_MAX = 72;

    /**
     * $actorUserId is who is asking. Changing your own password needs
     * `current_password`, as Personal settings asks for it
     * (202-account/account.php): an API key, or a session, that is not the
     * password must not be enough to take over the sign-in. Setting another
     * user's password is the admin's reset (user-management.php), which the
     * route has already authorized, and asks for no current password.
     */
    public function update(int $id, array $payload, ?int $actorUserId = null): array
    {
        $this->get($id);

        $sets = [];
        $binds = [];
        $types = '';

        foreach ($this->profileFields($payload, $id) as $f => [$t, $value]) {
            $sets[] = "$f = ?";
            $binds[] = $value;
            $types .= $t;
        }

        if (array_key_exists('user_pass', $payload) && $payload['user_pass'] !== '') {
            $password = $payload['user_pass'];
            if (!is_string($password)) {
                throw new ValidationException('Invalid password', ['user_pass' => 'Must be a string']);
            }
            self::checkPasswordLength($password);
            if ($actorUserId === $id) {
                $this->requireCurrentPassword($id, $payload['current_password'] ?? null);
            }
            $sets[] = 'user_pass = ?';
            $binds[] = \hash_user_pass($password);
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
     * The profile fields a create or an update carries, held to the rules of
     * the pages that write them (user-management.php, account.php), read
     * from the request as sent:
     *
     * - user_name: 1-50 characters, no other account's (the UNIQUE key would
     *   otherwise answer a rename with a database error);
     * - user_email: a valid address of at most 100 characters, no other
     *   account's;
     * - user_fname, user_lname: text of at most 50 characters;
     * - user_timezone: one of DateTimeZone::listIdentifiers(), as Personal
     *   settings offers them (any other string was stored, and every report
     *   then fell back to UTC without a word);
     * - user_active: 0 or 1 ("abc" was bound as an integer, 0, and
     *   deactivated the account).
     *
     * Only the fields present are returned. $selfId is the account being
     * updated, whose own name and email are not "another account's".
     *
     * @param array<string, mixed> $payload
     * @return array<string, array{0: string, 1: string|int}>
     */
    private function profileFields(array $payload, ?int $selfId): array
    {
        $out = [];
        $errors = [];
        $text = static function (string $field, int $max) use ($payload, &$errors): ?string {
            $value = $payload[$field];
            if (!is_string($value)) {
                $errors[$field] = 'Must be text';
                return null;
            }
            $value = trim($value);
            if (mb_strlen($value) > $max) {
                $errors[$field] = "At most $max characters";
                return null;
            }

            return $value;
        };
        foreach (['user_fname' => 50, 'user_lname' => 50] as $field => $max) {
            if (array_key_exists($field, $payload) && ($v = $text($field, $max)) !== null) {
                $out[$field] = ['s', $v];
            }
        }
        if (array_key_exists('user_name', $payload) && ($v = $text('user_name', 50)) !== null) {
            if ($v === '') {
                $errors['user_name'] = 'Required';
            } elseif ($this->takenByAnother('user_name', $v, $selfId)) {
                throw new ConflictException('Username already exists');
            } else {
                $out['user_name'] = ['s', $v];
            }
        }
        if (array_key_exists('user_email', $payload) && ($v = $text('user_email', 100)) !== null) {
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $errors['user_email'] = 'Invalid email format';
            } elseif ($this->takenByAnother('user_email', $v, $selfId)) {
                $errors['user_email'] = 'Another account has this email';
            } else {
                $out['user_email'] = ['s', $v];
            }
        }
        if (array_key_exists('user_timezone', $payload)) {
            $tz = $payload['user_timezone'];
            if (!is_string($tz) || !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $errors['user_timezone'] = 'A time zone such as America/New_York (PHP\'s list, as Personal settings offers it)';
            } else {
                $out['user_timezone'] = ['s', $tz];
            }
        }
        if (array_key_exists('user_active', $payload)) {
            $active = $payload['user_active'];
            if (!in_array($active, [0, 1, '0', '1'], true)) {
                $errors['user_active'] = 'Must be 1 (can sign in) or 0 (cannot)';
            } else {
                $out['user_active'] = ['i', (int) $active];
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Validation failed', $errors);
        }

        return $out;
    }

    /** Whether an account other than $selfId holds $value in $column (user_name or user_email). */
    private function takenByAnother(string $column, string $value, ?int $selfId): bool
    {
        $stmt = $this->prepare("SELECT user_id FROM 202_users WHERE $column = ? AND user_id <> ? LIMIT 1");
        $self = $selfId ?? 0;
        $this->bind($stmt, 'si', $value, $self);
        $this->execute($stmt, 'Lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            // "Free" would let the write reach the UNIQUE key, or a second
            // account take the email.
            $stmt->close();
            throw new DatabaseException('Lookup failed');
        }
        $taken = $result->fetch_row() !== null;
        $stmt->close();

        return $taken;
    }

    private static function checkPasswordLength(string $password): void
    {
        $length = strlen($password);
        if ($length < self::PASSWORD_MIN || $length > self::PASSWORD_MAX) {
            throw new ValidationException(
                'Invalid password',
                ['user_pass' => 'Must be between ' . self::PASSWORD_MIN . ' and ' . self::PASSWORD_MAX . ' characters']
            );
        }
    }

    /**
     * The password check Personal settings makes before a change: a 422
     * naming current_password when it is missing or wrong. A stored hash
     * that cannot be read is a 500, never "wrong password" (error pattern
     * #11).
     */
    private function requireCurrentPassword(int $userId, mixed $current): void
    {
        if (!is_string($current) || $current === '') {
            throw new ValidationException(
                'Changing your own password needs your current password',
                ['current_password' => 'Required when you change your own password']
            );
        }
        $stmt = $this->prepare('SELECT user_pass FROM 202_users WHERE user_id = ? LIMIT 1');
        $this->bind($stmt, 'i', $userId);
        $this->execute($stmt, 'Password check failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Password check failed');
        }
        $row = $result->fetch_assoc();
        $stmt->close();
        if (!$row || !\verify_user_pass($current, (string) ($row['user_pass'] ?? ''))['valid']) {
            throw new ValidationException('Current password is incorrect', ['current_password' => 'Does not match']);
        }
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
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            // InvalidArgumentException: deleteUser()'s own check of the id,
            // which changes nothing either.
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

    /**
     * The role a POST /users/{id}/roles grants, read one way for both the
     * authorization check (Auth::requireMayChangeRoles) and the write. A
     * lenient cast here would let the two disagree: (int) reads "1.0",
     * "1abc" and true all as 1, the Super user role the check refuses.
     */
    public static function roleIdFrom(array $payload): int
    {
        $raw = $payload['role_id'] ?? null;
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^[1-9][0-9]{0,9}$/', $raw) === 1) {
            return (int) $raw;
        }
        throw new ValidationException('role_id is required', ['role_id' => 'Must be a positive whole number']);
    }

    public function assignRole(int $userId, array $payload): array
    {
        $roleId = self::roleIdFrom($payload);

        // Validate BEFORE mutating: 202_user_role has no foreign keys, so an
        // insert for a nonexistent user/role would persist an orphan grant
        // that silently becomes live if that user ID is ever created.
        $this->get($userId);
        $stmt = $this->prepare('SELECT role_id FROM 202_roles WHERE role_id = ? LIMIT 1');
        $this->bind($stmt, 'i', $roleId);
        $this->execute($stmt, 'Role lookup failed');
        $role = $this->resultOf($stmt, 'Role lookup failed')->fetch_assoc();
        $stmt->close();
        if (!$role) {
            throw new ValidationException('Unknown role_id', ['role_id' => 'Role does not exist']);
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
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) {
            // A revocation that matched nothing must not report success.
            throw new NotFoundException('Role assignment not found');
        }
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
        $result = $this->resultOf($stmt, 'Query failed');
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
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) {
            // Callers only ever see masked keys after creation; a mismatched
            // value deleting zero rows must surface as an error — reporting
            // 204 here would tell the caller a live credential was revoked.
            throw new NotFoundException('API key not found');
        }
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
        $row = $this->resultOf($stmt, 'Query failed')->fetch_assoc();
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
        $row = $this->resultOf($stmt, 'Query failed')->fetch_assoc();
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

        // chart_time_range is the Overview chart's, read from 202_charts
        // where the chart reads it; the 202_users_pref column of that name
        // is read by nothing (see updatePreferences()).
        $stmt = $this->prepare('SELECT chart_time_range FROM 202_charts WHERE user_id = ? LIMIT 1');
        $this->bind($stmt, 'i', $userId);
        $this->execute($stmt, 'Query failed');
        $chart = $stmt->get_result();
        if ($chart === false) {
            $stmt->close();
            throw new DatabaseException('Query failed');
        }
        $chartRow = $chart->fetch_assoc();
        $stmt->close();
        if ($chartRow) {
            $row['chart_time_range'] = $chartRow['chart_time_range'];
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

    /**
     * Write preferences, each held to the rule of the page that owns it
     * (Prosper202\User\PreferenceRules). A key that is not a preference this
     * endpoint writes is refused by name rather than dropped.
     *
     * Side effects the pages have, kept here:
     * - `user_account_currency` re-prices the account's campaigns into the
     *   new currency (Personal settings does; the API used to relabel them);
     *   the rates are fetched first, and the currency, the payouts and every
     *   other preference in the request land in one transaction;
     * - changing `cb_key` resets `cb_verified`, as Integrations does;
     * - `chart_time_range` is the Overview chart's resolution, which lives in
     *   202_charts — the column of that name in 202_users_pref is read by
     *   nothing, so writing it changed nothing.
     *
     * Not repeated: account.php also writes several of these to memcache
     * keys under the user id. The only one any request reads back is
     * user_pref_privacy_, and connect2.php reads it under the tracker id,
     * so those keys are never what a redirect sees.
     *
     * @param (callable(string, string, string): mixed)|null $rate
     *   (account currency, campaign currency, payout) => the exchange
     *   service's answer; null asks the live service
     */
    public function updatePreferences(int $userId, array $payload, ?callable $rate = null): array
    {
        $current = $this->getPreferences($userId)['data'];
        if ($payload === []) {
            throw new ValidationException('No valid fields to update');
        }
        [$clean, $errors] = PreferenceRules::validate($payload, self::SUPPORTED_CURRENCIES);
        if ($errors !== []) {
            throw new ValidationException('Validation failed', $errors);
        }

        $conn = new Connection($this->db);
        $chartRange = $clean['chart_time_range'] ?? null;
        unset($clean['chart_time_range']);

        $currencyUpdates = [];
        if (isset($clean['user_account_currency'])) {
            $currency = (string) $clean['user_account_currency'];
            $rate ??= ExchangeRates::foreignPayout(...);
            try {
                $currencyUpdates = CurrencyChange::plan(
                    $conn,
                    $userId,
                    $currency,
                    (string) ($current['user_account_currency'] ?? ''),
                    static fn (string $campaignCurrency, string $payout): mixed => $rate($currency, $campaignCurrency, $payout)
                );
            } catch (\RuntimeException $e) {
                throw new HttpException(
                    'The currency was not changed: re-pricing the campaigns needs the exchange rate service, and ' . $e->getMessage(),
                    502,
                    $e
                );
            }
        }
        if (array_key_exists('cb_key', $clean) && $clean['cb_key'] !== (string) ($current['cb_key'] ?? '')) {
            $clean['cb_verified'] = 0;
        }

        $conn->transaction(function () use ($conn, $userId, $clean, $chartRange, $currencyUpdates): void {
            if ($clean !== []) {
                $sets = [];
                $types = '';
                foreach ($clean as $column => $value) {
                    $sets[] = '`' . $column . '` = ?';
                    $types .= is_int($value) ? 'i' : 's';
                }
                $stmt = $conn->prepareWrite('UPDATE `202_users_pref` SET ' . implode(', ', $sets) . ' WHERE `user_id` = ?');
                $conn->bind($stmt, $types . 'i', [...array_values($clean), $userId]);
                $conn->executeUpdate($stmt);
            }
            CurrencyChange::apply($conn, $currencyUpdates);
            if ($chartRange !== null) {
                $this->saveChartRange($conn, $userId, (string) $chartRange);
            }
        });

        try {
            return $this->getPreferences($userId);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('preferences', $e);
        }
    }

    /**
     * The Overview chart's resolution, where the chart reads it. An account
     * without a chart row (one the API created) gets the installer's
     * default chart with it, so the setting is not a write to nothing.
     */
    private function saveChartRange(Connection $conn, int $userId, string $range): void
    {
        $stmt = $conn->prepareWrite('SELECT 1 FROM `202_charts` WHERE `user_id` = ? LIMIT 1');
        $conn->bind($stmt, 'i', [$userId]);
        if ($conn->fetchOne($stmt) !== null) {
            $stmt = $conn->prepareWrite('UPDATE `202_charts` SET `chart_time_range` = ? WHERE `user_id` = ?');
            $conn->bind($stmt, 'si', [$range, $userId]);
            $conn->executeUpdate($stmt);
            return;
        }
        $stmt = $conn->prepareWrite('INSERT INTO `202_charts` (`user_id`, `data`, `chart_time_range`) VALUES (?, ?, ?)');
        $conn->bind($stmt, 'iss', [$userId, self::DEFAULT_CHART, $range]);
        $conn->executeInsert($stmt);
    }

    /** install.php's default Overview chart: clicks, click-throughs and leads for all campaigns. */
    private const DEFAULT_CHART = 'a:3:{i:0;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:6:"clicks";}i:1;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:9:"click_out";}i:2;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:5:"leads";}}';
}
