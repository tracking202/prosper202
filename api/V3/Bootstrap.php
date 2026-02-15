<?php

declare(strict_types=1);

namespace Api\V3;

class Bootstrap
{
    private static ?\mysqli $db = null;
    private static ?int $authenticatedUserId = null;
    private static ?array $authenticatedUserRoles = null;

    public static function init(): void
    {
        $root = dirname(__DIR__, 2);

        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', $root . '/');
        }
        if (!defined('CONFIG_PATH')) {
            define('CONFIG_PATH', $root . '/202-config');
        }

        $configFile = $root . '/202-config.php';
        if (!file_exists($configFile)) {
            throw new \RuntimeException('Configuration not found');
        }

        require_once $configFile;

        $autoload = $root . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    public static function db(): \mysqli
    {
        if (self::$db !== null) {
            return self::$db;
        }

        try {
            $database = \DB::getInstance();
            $conn = $database->getConnection();
            if ($conn === null || $conn->connect_error) {
                throw new \RuntimeException('Database connection failed');
            }
            self::$db = $conn;
            return self::$db;
        } catch (\Exception $e) {
            throw new \RuntimeException('Database connection failed');
        }
    }

    public static function authenticate(array $params, array $headers): int
    {
        $apiKey = '';

        // Bearer token auth only — query param removed to prevent credential leakage in logs/referers
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (is_array($authHeader)) {
            $authHeader = $authHeader[0] ?? '';
        }
        if (str_starts_with($authHeader, 'Bearer ')) {
            $apiKey = trim(substr($authHeader, 7));
        }

        if ($apiKey === '') {
            throw new AuthException('API key required. Pass via Authorization: Bearer <key> header.', 401);
        }

        $db = self::db();
        $stmt = $db->prepare('SELECT user_id FROM 202_api_keys WHERE api_key = ? LIMIT 1');
        if (!$stmt) {
            throw new \RuntimeException('Authentication unavailable');
        }
        $stmt->bind_param('s', $apiKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row || !isset($row['user_id'])) {
            throw new AuthException('Invalid API key.', 401);
        }

        self::$authenticatedUserId = (int)$row['user_id'];

        // Load user roles for authorization checks
        self::$authenticatedUserRoles = [];
        $stmt = $db->prepare('SELECT r.role_name FROM 202_user_role ur INNER JOIN 202_roles r ON ur.role_id = r.role_id WHERE ur.user_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', self::$authenticatedUserId);
            $stmt->execute();
            $roleResult = $stmt->get_result();
            while ($r = $roleResult->fetch_assoc()) {
                self::$authenticatedUserRoles[] = strtolower($r['role_name']);
            }
            $stmt->close();
        }

        return self::$authenticatedUserId;
    }

    public static function userId(): int
    {
        if (self::$authenticatedUserId === null) {
            throw new AuthException('Not authenticated.', 401);
        }
        return self::$authenticatedUserId;
    }

    public static function isAdmin(): bool
    {
        return self::$authenticatedUserRoles !== null
            && (in_array('admin', self::$authenticatedUserRoles, true)
                || in_array('administrator', self::$authenticatedUserRoles, true));
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            throw new AuthException('Admin access required.', 403);
        }
    }

    public static function requireSelfOrAdmin(int $targetUserId): void
    {
        if (self::userId() !== $targetUserId && !self::isAdmin()) {
            throw new AuthException('You can only access your own resources.', 403);
        }
    }

    public static function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function errorResponse(string $message, int $status = 400): void
    {
        self::jsonResponse(['error' => true, 'message' => $message, 'status' => $status], $status);
    }
}
