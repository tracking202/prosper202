<?php

declare(strict_types=1);

namespace Api\V3;

class Bootstrap
{
    private static ?\mysqli $db = null;
    private static ?int $authenticatedUserId = null;

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
            throw new \RuntimeException('202-config.php not found');
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
            self::$db->query("SET session sql_mode=''");
            return self::$db;
        } catch (\Exception $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    public static function authenticate(array $params, array $headers): int
    {
        $apiKey = '';

        // Check Authorization: Bearer header first
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (is_array($authHeader)) {
            $authHeader = $authHeader[0] ?? '';
        }
        if (str_starts_with($authHeader, 'Bearer ')) {
            $apiKey = trim(substr($authHeader, 7));
        }

        // Fall back to query parameter
        if ($apiKey === '') {
            $apiKey = trim((string)($params['apikey'] ?? ''));
        }

        if ($apiKey === '') {
            throw new AuthException('API key required. Pass via Authorization: Bearer <key> header or ?apikey=<key> parameter.', 401);
        }

        $db = self::db();
        $stmt = $db->prepare('SELECT user_id FROM 202_api_keys WHERE api_key = ? LIMIT 1');
        if (!$stmt) {
            throw new \RuntimeException('Database error');
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
        return self::$authenticatedUserId;
    }

    public static function userId(): int
    {
        if (self::$authenticatedUserId === null) {
            throw new AuthException('Not authenticated.', 401);
        }
        return self::$authenticatedUserId;
    }

    public static function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function errorResponse(string $message, int $status = 400): void
    {
        self::jsonResponse(['error' => true, 'message' => $message], $status);
    }
}

class AuthException extends \RuntimeException
{
}
