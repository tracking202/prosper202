<?php

declare(strict_types=1);

namespace Prosper202\Database;

/**
 * Bridges the existing DB singleton to the new Connection class.
 *
 * This avoids changing the config/bootstrap layer while providing
 * a typed Connection instance to repositories and services.
 */
final class ConnectionFactory
{
    private static ?Connection $instance = null;

    /**
     * Create (or return cached) Connection from the existing DB singleton.
     *
     * @throws \RuntimeException if no database connection is available
     */
    public static function create(): Connection
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $database = \DB::getInstance();
        if ($database === null) {
            throw new \RuntimeException('Database not initialized. Ensure 202-config.php has been loaded.');
        }

        $write = $database->getConnection();
        if (!$write instanceof \mysqli) {
            throw new \RuntimeException('Unable to obtain write database connection.');
        }

        $read = method_exists($database, 'getConnectionro')
            ? $database->getConnectionro()
            : null;

        if ($read !== null && !$read instanceof \mysqli) {
            $read = null;
        }

        self::$instance = new Connection($write, $read);

        return self::$instance;
    }

    /**
     * Create a Connection from explicit mysqli instances (for testing or custom wiring).
     */
    public static function fromConnections(\mysqli $write, ?\mysqli $read = null): Connection
    {
        return new Connection($write, $read);
    }

    /**
     * Reset the cached instance. Used in tests.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
