<?php

declare(strict_types=1);

namespace Api\V3;

/**
 * Authentication + authorization — extracted from the old Bootstrap god-class.
 *
 * Authenticates the bearer token, loads the user's roles once, and exposes
 * fine-grained authorization checks that controllers and the router can use.
 */
final readonly class Auth
{
    /** The account the installer creates; its role is Super user. */
    public const SUPER_USER_ID = 1;
    private const ROLE_SUPER_USER = 1;
    private const ROLE_ADMIN = 2;
    /** The legacy permission only the Super user role carries. */
    private const MANAGE_ADMINS = 'add_edit_delete_admin';
    private const PERSONAL_SETTINGS = 'access_to_personal_settings';

    private function __construct(
        private int $userId,
        /** @var string[] lower-cased role names */
        private array $roles,
        /** @var string[] lower-cased api key scopes */
        private array $scopes = ['*'],
        /** The key's ledger reference (SourceRef::apiKey()): a digest, never the key. */
        private string $apiKeyRef = ''
    )
    {
    }

    /**
     * Authenticate the current request from headers.
     * Returns an Auth instance on success, throws on failure.
     */
    public static function fromRequest(array $headers, \mysqli $db): self
    {
        // Header names are case-insensitive per RFC 9110; normalize instead of
        // probing a couple of hardcoded casings.
        $headers = array_change_key_case($headers, CASE_LOWER);
        $authHeader = $headers['authorization'] ?? '';
        if (is_array($authHeader)) {
            $authHeader = $authHeader[0] ?? '';
        }

        $apiKey = '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $apiKey = trim(substr($authHeader, 7));
        }

        if ($apiKey === '') {
            throw new AuthException('API key required. Pass via Authorization: Bearer <key> header.', 401);
        }

        // Join 202_users so keys belonging to soft-deleted users stop
        // authenticating — "deleting" a user must actually revoke access.
        // A deactivated user (Account › Users' Active switch off) is refused
        // too, as the sign-in and the remember-me cookie refuse them
        // (functions-auth.php): this read user_deleted alone, so turning a
        // user off left every key they held working with their role.
        $scopeColumnExists = self::apiKeyScopeColumnExists($db);
        $sql = $scopeColumnExists
            ? 'SELECT k.user_id, k.scope, u.user_active FROM 202_api_keys k INNER JOIN 202_users u ON u.user_id = k.user_id WHERE k.api_key = ? AND u.user_deleted = 0 LIMIT 1'
            : 'SELECT k.user_id, u.user_active FROM 202_api_keys k INNER JOIN 202_users u ON u.user_id = k.user_id WHERE k.api_key = ? AND u.user_deleted = 0 LIMIT 1';
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new AuthException('Authentication unavailable', 500);
        }
        self::bind($stmt, 's', $apiKey);
        if (!self::execute($stmt)) {
            $stmt->close();
            throw new AuthException('Authentication unavailable', 500);
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new AuthException('Authentication unavailable', 500);
        }
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row || !isset($row['user_id'])) {
            throw new AuthException('Invalid API key.', 401);
        }
        // Only 1 is active: the column is int(1) NOT NULL DEFAULT 1, and the
        // sign-in asks for `user_active = 1`, so anything else is refused.
        if ((string) ($row['user_active'] ?? '') !== '1') {
            throw new AuthException('The account this API key belongs to is deactivated; an Admin can turn it back on in Account › Users.', 401);
        }

        $scopes = self::parseScopes((string)($row['scope'] ?? ''));

        return self::loadRoles((int)$row['user_id'], $db, $scopes, \Prosper202\Conversion\Ledger\SourceRef::apiKey($apiKey));
    }

    private static function loadRoles(int $userId, \mysqli $db, array $scopes = ['*'], string $apiKeyRef = ''): self
    {
        $roles = [];
        $stmt = $db->prepare(
            'SELECT r.role_name FROM 202_user_role ur '
            . 'INNER JOIN 202_roles r ON ur.role_id = r.role_id '
            . 'WHERE ur.user_id = ?'
        );
        if (!$stmt) {
            throw new AuthException('Authorization unavailable', 500);
        }

        self::bind($stmt, 'i', $userId);
        if (!self::execute($stmt)) {
            $stmt->close();
            throw new AuthException('Authorization unavailable', 500);
        }

        $roleResult = $stmt->get_result();
        if ($roleResult === false) {
            $stmt->close();
            throw new AuthException('Authorization unavailable', 500);
        }

        while ($r = $roleResult->fetch_assoc()) {
            $roles[] = strtolower($r['role_name']);
        }
        $stmt->close();

        return new self($userId, $roles, $scopes, $apiKeyRef);
    }

    public function userId(): int
    {
        return $this->userId;
    }

    /**
     * What a conversion written with this key records as its source_ref
     * (SourceRef::apiKey()): a truncated digest of the key, so the
     * breakdown can name the key without anyone reading it back.
     */
    public function apiKeyRef(): string
    {
        return $this->apiKeyRef;
    }

    /** @return string[] */
    public function roles(): array
    {
        return $this->roles;
    }

    /** @return string[] */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /**
     * Scope areas the API enforces. One area per top-level route family;
     * `changes` and `audit` routes fall under `sync`.
     */
    public const KNOWN_SCOPE_AREAS = [
        'campaigns',
        'aff-networks',
        'ppc-networks',
        'ppc-accounts',
        'trackers',
        'landing-pages',
        'text-ads',
        'forecast-events',
        'clicks',
        'conversions',
        'reports',
        'ltv',
        'rotators',
        'attribution',
        'apps',
        'goals',
        'events',
        'users',
        'system',
        'sync',
        'staged-changes',
    ];

    /**
     * Map a request path to the scope area that governs it, or null for paths
     * exempt from scope checks (discovery metadata and the pre-auth
     * endpoints). `changes` and `audit` routes fall under `sync`.
     */
    public static function scopeAreaForPath(string $path): ?string
    {
        if (self::isScopeExemptPath($path)) {
            return null;
        }
        $segments = explode('/', ltrim($path, '/'));
        $first = $segments[0] ?? '';
        if ($first === 'changes' || $first === 'audit') {
            return 'sync';
        }
        return in_array($first, self::KNOWN_SCOPE_AREAS, true) ? $first : null;
    }

    /**
     * Whether a path is deliberately outside scope enforcement. Everything
     * else must map to an area: the dispatcher refuses a matched route whose
     * family has no mapping rather than letting it through unchecked, so
     * adding a route family without adding its area fails loudly instead of
     * silently accepting a read-only or propose-only key for writes.
     */
    public static function isScopeExemptPath(string $path): bool
    {
        $segments = explode('/', ltrim($path, '/'));
        $first = $segments[0] ?? '';
        if ($first === '' || $first === 'capabilities' || $first === 'versions') {
            // Clients probe these to discover what they may do.
            return true;
        }
        if ($first === 'system' && ($segments[1] ?? '') === 'health') {
            return true; // answered before authentication
        }
        return false;
    }

    /**
     * Whether a scope check passes for this key.
     *
     * Grammar: `*` (full access), `read` / `write` / `stage` (all areas),
     * and `<area>:read` / `<area>:write` / `<area>:stage`. `write` implies
     * `read` and `stage` at both the global and the area level: a key that
     * may perform a write may also preview and propose it. `stage` implies
     * neither read nor write — a `read,stage` key is the propose-only shape
     * for an agent whose staged writes a person applies. A key's scope
     * attenuates: it limits what the key can do regardless of the user's
     * roles, so an admin holding an explicitly scoped key is bound by that
     * scope. Keys with no stored scope parse to `*` and behave exactly as
     * before scoping existed.
     */
    public function hasScope(string $scope): bool
    {
        $scope = strtolower(trim($scope));
        if ($scope === '') {
            return true;
        }
        if (in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true)) {
            return true;
        }

        $parts = explode(':', $scope);
        if (count($parts) !== 2) {
            return false;
        }
        [$area, $action] = $parts;
        if ($action === 'read') {
            return in_array('read', $this->scopes, true)
                || in_array('write', $this->scopes, true)
                || in_array($area . ':write', $this->scopes, true);
        }
        if ($action === 'write') {
            return in_array('write', $this->scopes, true);
        }
        if ($action === 'stage') {
            return in_array('stage', $this->scopes, true)
                || in_array('write', $this->scopes, true)
                || in_array($area . ':write', $this->scopes, true);
        }
        return false;
    }

    public function requireScope(string $scope): void
    {
        if (!$this->hasScope($scope)) {
            throw new AuthException(
                sprintf(
                    "Insufficient API key scope for this operation: requires '%s' (key has: %s).",
                    strtolower(trim($scope)),
                    implode(',', $this->scopes)
                ),
                403
            );
        }
    }

    /**
     * Whether this key is allowed to mint a key carrying $token.
     * A key can never hand out more access than it holds itself.
     */
    public function coversScopeToken(string $token): bool
    {
        $token = strtolower(trim($token));
        return match ($token) {
            '*' => in_array('*', $this->scopes, true),
            'read' => in_array('*', $this->scopes, true)
                || in_array('read', $this->scopes, true)
                || in_array('write', $this->scopes, true),
            'write' => in_array('*', $this->scopes, true)
                || in_array('write', $this->scopes, true),
            'stage' => in_array('*', $this->scopes, true)
                || in_array('stage', $this->scopes, true)
                || in_array('write', $this->scopes, true),
            default => $this->hasScope($token),
        };
    }

    /** Whether this key carries the full-access scope (`*`). */
    public function hasFullScope(): bool
    {
        return in_array('*', $this->scopes, true);
    }

    /**
     * Whether $token is a scope this API understands: `*`, `read`, `write`,
     * `stage`, or `<area>:read` / `<area>:write` / `<area>:stage` for a
     * known area. Unknown tokens are rejected at key-creation time so a typo
     * cannot mint a key that silently denies everything.
     */
    public static function isValidScopeToken(string $token): bool
    {
        $token = strtolower(trim($token));
        if (in_array($token, ['*', 'read', 'write', 'stage'], true)) {
            return true;
        }
        $parts = explode(':', $token);
        if (count($parts) !== 2) {
            return false;
        }
        [$area, $action] = $parts;
        return in_array($area, self::KNOWN_SCOPE_AREAS, true)
            && in_array($action, ['read', 'write', 'stage'], true);
    }

    public function isAdmin(): bool
    {
        // Role 1, "Super user", is the account the installer creates and
        // outranks Admin in the legacy permission system (see
        // 202-account/user-management.php, which forbids assigning it).
        // Without it here, every fresh install's owner is locked out of the
        // admin-gated v3 endpoints.
        return in_array('admin', $this->roles, true)
            || in_array('administrator', $this->roles, true)
            || in_array('super user', $this->roles, true)
            || in_array('superuser', $this->roles, true);
    }

    public function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            throw new AuthException('Admin access required.', 403);
        }
    }

    /**
     * Require one of the legacy role permissions (202_permissions) — the
     * same check the session pages make through User::hasPermission, so an
     * operation is gated identically on every surface (error pattern #5).
     * The attribution routes use view_attribution_reports for reads and
     * manage_attribution_models for writes.
     *
     * A failed lookup is not "no permission" (error pattern #11): it is a
     * 500, never a 403 that reads as the user's fault, and never a pass.
     */
    public function requirePermission(\mysqli $db, string $permission): void
    {
        if (!$this->hasPermission($db, $permission)) {
            throw new AuthException(
                "This account's role does not have the '" . $permission . "' permission.",
                403
            );
        }
    }

    /**
     * Act on another user's account (profile, password, API keys, signing
     * key, preferences): the rules of 202-account/user-management.php, which
     * the API's plain requireAdmin() did not carry. Admin and Super user
     * both pass requireAdmin(), so an Admin key could make itself Super
     * user, set user 1's password, or mint a full-access key for user 1 —
     * every one of which the page refuses (error pattern #5).
     *
     * - nobody but user 1 acts on user 1;
     * - an account holding the Admin or Super user role is acted on only
     *   with add_edit_delete_admin (the Super user's permission) — an
     *   Admin acting on its own Admin account included, as on the page.
     */
    public function requireMayManageUser(\mysqli $db, int $targetUserId): void
    {
        $this->requireAdmin();
        if ($targetUserId === self::SUPER_USER_ID && $this->userId !== self::SUPER_USER_ID) {
            throw new AuthException('Only the Super user (user 1) can act on the Super user account.', 403);
        }
        if (!$this->hasPermission($db, self::MANAGE_ADMINS)
            && $this->userHoldsRole($db, $targetUserId, [self::ROLE_SUPER_USER, self::ROLE_ADMIN])) {
            throw new AuthException(
                "Changing an Admin or Super user account needs the '" . self::MANAGE_ADMINS . "' permission, which only the Super user role has.",
                403
            );
        }
    }

    /**
     * Personal settings — the preferences, the API keys, the account's time
     * zone: for your own account, the permission Personal Settings asks
     * (account.php shows a user without it only their email and password);
     * for another's, a user requireMayManageUser() lets you act on.
     *
     * Self passed with no permission at all, so any role with a key set
     * every preference the page withholds from it — the privacy setting
     * that governs its visitors, the tracking domain, the currency its
     * payouts are re-priced into — and minted itself more keys.
     */
    public function requirePersonalSettingsOf(\mysqli $db, int $targetUserId): void
    {
        if ($this->userId === $targetUserId) {
            $this->requirePermission($db, self::PERSONAL_SETTINGS);
            return;
        }
        $this->requireMayManageUser($db, $targetUserId);
    }

    /** Your own account, or one requireMayManageUser() lets you act on. */
    public function requireSelfOrMayManageUser(\mysqli $db, int $targetUserId): void
    {
        if ($this->userId === $targetUserId) {
            return;
        }
        $this->requireMayManageUser($db, $targetUserId);
    }

    /**
     * Grant or take away a role. On top of requireMayManageUser(): user 1's
     * role is fixed (the page never edits user 1), Super user is never
     * granted (the page offers roles 2-6 only), and Admin is granted only
     * with add_edit_delete_admin. $roleId is null for a removal, whose
     * target's own roles requireMayManageUser() has already weighed.
     */
    public function requireMayChangeRoles(\mysqli $db, int $targetUserId, ?int $grantRoleId): void
    {
        $this->requireMayManageUser($db, $targetUserId);
        if ($targetUserId === self::SUPER_USER_ID) {
            throw new AuthException("The Super user's role cannot be changed.", 403);
        }
        if ($grantRoleId === self::ROLE_SUPER_USER) {
            throw new AuthException('The Super user role cannot be granted; only the account the installer created holds it.', 403);
        }
        if ($grantRoleId === self::ROLE_ADMIN && !$this->hasPermission($db, self::MANAGE_ADMINS)) {
            throw new AuthException(
                "Granting the Admin role needs the '" . self::MANAGE_ADMINS . "' permission, which only the Super user role has.",
                403
            );
        }
    }

    /**
     * Remove a user: add_edit_delete_admin, and never user 1 or yourself
     * (user-management.php's delete handler).
     */
    public function requireMayDeleteUser(\mysqli $db, int $targetUserId): void
    {
        $this->requireAdmin();
        $this->requirePermission($db, self::MANAGE_ADMINS);
        if ($targetUserId <= self::SUPER_USER_ID) {
            throw new AuthException('The Super user (user 1) cannot be removed.', 403);
        }
        if ($targetUserId === $this->userId) {
            throw new AuthException('You cannot remove your own account.', 403);
        }
    }

    /**
     * Whether this key's user holds a legacy role permission. A failed
     * lookup is a 500, never false (error pattern #11): "could not tell"
     * must not read as either answer.
     */
    public function hasPermission(\mysqli $db, string $permission): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM 202_user_role ur
             JOIN 202_role_permission rp ON rp.role_id = ur.role_id
             JOIN 202_permissions p ON p.permission_id = rp.permission_id
             WHERE ur.user_id = ? AND p.permission_description = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new AuthException('Authorization unavailable', 500);
        }
        self::bind($stmt, 'is', $this->userId, $permission);
        if (!self::execute($stmt)) {
            $stmt->close();
            throw new AuthException('Authorization unavailable', 500);
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new AuthException('Authorization unavailable', 500);
        }
        $granted = $result->fetch_row() !== null;
        $stmt->close();

        return $granted;
    }

    /**
     * Whether $userId holds any of $roleIds. Same failure rule as
     * hasPermission(): a lookup that fails is a 500, not "no".
     *
     * @param list<int> $roleIds
     */
    private function userHoldsRole(\mysqli $db, int $userId, array $roleIds): bool
    {
        $marks = implode(',', array_fill(0, count($roleIds), '?'));
        $stmt = $db->prepare("SELECT 1 FROM 202_user_role WHERE user_id = ? AND role_id IN ($marks) LIMIT 1");
        if (!$stmt) {
            throw new AuthException('Authorization unavailable', 500);
        }
        self::bind($stmt, str_repeat('i', count($roleIds) + 1), $userId, ...$roleIds);
        if (!self::execute($stmt)) {
            $stmt->close();
            throw new AuthException('Authorization unavailable', 500);
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new AuthException('Authorization unavailable', 500);
        }
        $holds = $result->fetch_row() !== null;
        $stmt->close();

        return $holds;
    }

    public function requireSelfOrAdmin(int $targetUserId): void
    {
        if ($this->userId !== $targetUserId && !$this->isAdmin()) {
            throw new AuthException('You can only access your own resources.', 403);
        }
    }

    /**
     * Cached per connection for the life of the process (a function-static;
     * the class is readonly and cannot hold one). The column cannot appear
     * or vanish mid-request, and this sits on the authentication path of
     * every single v3 call — three callers were each paying a round trip for
     * an answer that never changes.
     */
    public static function apiKeyScopeColumnExists(\mysqli $db): bool
    {
        // Keyed by the connection object itself, not spl_object_id(): ids are
        // reused once an object is freed, so a reconnect in a long-lived
        // process could inherit a previous connection's answer. WeakMap
        // entries disappear with the connection.
        static $cache = null;
        $cache ??= new \WeakMap();
        if (!isset($cache[$db])) {
            // Only a successful probe is cached; a failure throws, so an
            // error is never memoized as a lasting answer.
            $cache[$db] = self::probeApiKeyScopeColumn($db);
        }
        return $cache[$db];
    }

    /**
     * Whether 202_api_keys has a scope column — and only that.
     *
     * Every failure here used to return false, which is the same answer as
     * "the column is not there". fromRequest() reads that as an install
     * predating scopes, selects without the column, and parseScopes('')
     * resolves the missing value to ['*'] — so one transient database error
     * silently promoted every scoped key to full access. Error pattern #11:
     * a value that cannot be determined must never resolve to the
     * most-permissive reading. Failing closed here means throwing, the way
     * fromRequest() already treats its own query failures.
     */
    private static function probeApiKeyScopeColumn(\mysqli $db): bool
    {
        // Fail closed: a DB error here must not silently drop the scope column
        // from the auth query (which would grant the key the full '*' scope).
        // Only a successful probe that finds no column may report false —
        // that is the legitimate pre-upgrade schema case.
        $stmt = $db->prepare("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'");
        if (!$stmt) {
            throw new AuthException('Authentication unavailable', 500);
        }
        if (!self::execute($stmt)) {
            $stmt->close();
            throw new AuthException('Authentication unavailable', 500);
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new AuthException('Authentication unavailable', 500);
        }
        $row = $result->fetch_assoc();
        $stmt->close();
        return is_array($row);
    }

    /**
     * Marker for a scope column that exists but cannot be parsed. It matches
     * no route (hasScope() needs `<area>:<action>`, and this has no colon),
     * so such a key authenticates and can then do nothing — and the 403 it
     * gets names this token, so the corrupt row is findable.
     */
    public const MALFORMED_SCOPE = '!unparseable';

    /** @return string[] */
    public static function parseScopes(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['*'];
        }

        $scopes = [];
        if (str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
            // Unreadable JSON leaves $scopes empty and falls through to the
            // MALFORMED_SCOPE branch below. That is deliberately not a throw:
            // a corrupt scope value is a property of one key, so denying that
            // key with a scope nobody matches is both fail-closed and
            // diagnosable, whereas a 500 reports a server fault and names no
            // row.
            if (is_array($decoded)) {
                foreach ($decoded as $scope) {
                    // Skip non-scalars rather than casting: (string) on an array
                    // warns and yields "Array", inventing a scope name that is
                    // not in the column. Contributing nothing lands a list of
                    // them in MALFORMED_SCOPE like any other unreadable value.
                    if (!is_scalar($scope)) {
                        continue;
                    }
                    $value = strtolower(trim((string)$scope));
                    if ($value !== '') {
                        $scopes[] = $value;
                    }
                }
            }
        } else {
            foreach (explode(',', $raw) as $part) {
                $value = strtolower(trim($part));
                if ($value !== '') {
                    $scopes[] = $value;
                }
            }
        }

        if ($scopes === []) {
            // Reached only when $raw was non-empty and produced nothing
            // usable: truncated JSON, `[]`, `[null]`. That is a scope value
            // nobody can read, and reading it as full access is the one
            // interpretation that must never happen -- an unreadable
            // attenuation would silently become no attenuation at all. The
            // genuinely empty case returned ['*'] above, where it means
            // "this key predates scopes".
            return [self::MALFORMED_SCOPE];
        }
        return array_values(array_unique($scopes));
    }

    private static function bind(\mysqli_stmt $stmt, string $types, mixed ...$values): void
    {
        // @phpstan-ignore-next-line Auth is its own checked bind/execute wrapper; no Connection in scope
        if (!$stmt->bind_param($types, ...$values)) {
            throw new AuthException('Authentication unavailable', 500);
        }
    }

    private static function execute(\mysqli_stmt $stmt): bool
    {
        // @phpstan-ignore-next-line Auth is its own checked bind/execute wrapper; no Connection in scope
        return $stmt->execute();
    }
}
