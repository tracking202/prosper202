<?php

declare(strict_types=1);

namespace Api\V3;

/**
 * Application bootstrap — initializes config, provides DB connection.
 *
 * This is now a focused class: it handles initialization and DB access only.
 * Authentication lives in Auth. HTTP responses live in the router entry point.
 */
final class Bootstrap
{
    private static ?\mysqli $db = null;

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

    public static function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = json_encode(['error' => true, 'message' => 'Response encoding failed', 'status' => 500]);
            http_response_code(500);
        }
        echo $json;
    }

    public static function errorResponse(string $message, int $status = 400, array $extra = []): void
    {
        $body = array_merge(['error' => true, 'message' => $message, 'status' => $status], $extra);
        self::jsonResponse($body, $status);
    }

    /** @internal */
    public static function resetDb(): void
    {
        self::$db = null;
    }
}
