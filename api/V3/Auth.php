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

    private function __construct(int $userId, array $roles)
    {
        $this->userId = $userId;
        $this->roles = $roles;
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

        $stmt = $db->prepare('SELECT user_id FROM 202_api_keys WHERE api_key = ? LIMIT 1');
        if (!$stmt) {
            throw new AuthException('Authentication unavailable', 500);
        }
        $stmt->bind_param('s', $apiKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row || !isset($row['user_id'])) {
            throw new AuthException('Invalid API key.', 401);
        }

        return self::loadRoles((int)$row['user_id'], $db);
    }

    private static function loadRoles(int $userId, \mysqli $db): self
    {
        $roles = [];
        $stmt = $db->prepare(
            'SELECT r.role_name FROM 202_user_role ur '
            . 'INNER JOIN 202_roles r ON ur.role_id = r.role_id '
            . 'WHERE ur.user_id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $roleResult = $stmt->get_result();
            while ($r = $roleResult->fetch_assoc()) {
                $roles[] = strtolower($r['role_name']);
            }
            $stmt->close();
        }

        return new self($userId, $roles);
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
}
