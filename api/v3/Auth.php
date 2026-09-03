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
    private function __construct(
        private int $userId,
        /** @var string[] lower-cased role names */
        private array $roles,
        /** @var string[] lower-cased api key scopes */
        private array $scopes = ['*']
    )
    {
    }

    /**
     * Authenticate the current request from headers.
     * Returns an Auth instance on success, throws on failure.
     */
    public static function fromRequest(array $headers, \mysqli $db): self
    {
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
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

        $scopeColumnExists = self::apiKeyScopeColumnExists($db);
        $sql = $scopeColumnExists
            ? 'SELECT user_id, scope FROM 202_api_keys WHERE api_key = ? LIMIT 1'
            : 'SELECT user_id FROM 202_api_keys WHERE api_key = ? LIMIT 1';
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

        $scopes = self::parseScopes((string)($row['scope'] ?? ''));

        return self::loadRoles((int)$row['user_id'], $db, $scopes);
    }

    private static function loadRoles(int $userId, \mysqli $db, array $scopes = ['*']): self
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

        return new self($userId, $roles, $scopes);
    }

    public function userId(): int
    {
        return $this->userId;
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

    public function requireSelfOrAdmin(int $targetUserId): void
    {
        if ($this->userId !== $targetUserId && !$this->isAdmin()) {
            throw new AuthException('You can only access your own resources.', 403);
        }
    }

    public static function apiKeyScopeColumnExists(\mysqli $db): bool
    {
        $stmt = $db->prepare("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'");
        if (!$stmt) {
            return false;
        }
        if (!self::execute($stmt)) {
            $stmt->close();
            return false;
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            return false;
        }
        $row = $result->fetch_assoc();
        $stmt->close();
        return is_array($row);
    }

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
            if (is_array($decoded)) {
                foreach ($decoded as $scope) {
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
            $scopes[] = '*';
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
