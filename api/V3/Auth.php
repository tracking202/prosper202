<?php

declare(strict_types=1);

namespace Api\V3;

/**
 * Authentication + authorization — extracted from the old Bootstrap god-class.
 *
 * Authenticates the bearer token, loads the user's roles once, and exposes
 * fine-grained authorization checks that controllers and the router can use.
 */
final class Auth
{
    private int $userId;
    /** @var string[] lower-cased role names */
    private array $roles;
    /** @var string[] lower-cased api key scopes */
    private array $scopes;

    private function __construct(int $userId, array $roles, array $scopes = ['*'])
    {
        $this->userId = $userId;
        $this->roles = $roles;
        $this->scopes = $scopes;
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
        $stmt->bind_param('s', $apiKey);
        if (!$stmt->execute()) {
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

        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
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

    public function hasScope(string $scope): bool
    {
        $scope = strtolower(trim($scope));
        if ($scope === '') {
            return true;
        }
        if ($this->isAdmin()) {
            return true;
        }
        return in_array('*', $this->scopes, true)
            || in_array($scope, $this->scopes, true);
    }

    public function requireScope(string $scope): void
    {
        if (!$this->hasScope($scope)) {
            throw new AuthException('Insufficient API key scope for this operation.', 403);
        }
    }

    public function isAdmin(): bool
    {
        return in_array('admin', $this->roles, true)
            || in_array('administrator', $this->roles, true);
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

    private static function apiKeyScopeColumnExists(\mysqli $db): bool
    {
        $stmt = $db->prepare("SHOW COLUMNS FROM 202_api_keys LIKE 'scope'");
        if (!$stmt) {
            return false;
        }
        if (!$stmt->execute()) {
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
    private static function parseScopes(string $raw): array
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
}
