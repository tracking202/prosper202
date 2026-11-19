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

        $write = $database->getConnection();
        if (!$write instanceof \mysqli) {
            throw new \RuntimeException('Unable to obtain write database connection.');
        }

        $read = self::readConnectionOf($database);
        if (!$read instanceof \mysqli) {
            $read = null;
        }

        self::$instance = new Connection($write, $read);

        return self::$instance;
    }

    /**
     * The connection a 202-config.php DB object reads from: its
     * getConnectionro(), or its primary connection where the config defines
     * none. An installer-written config is kept across upgrades, so one
     * written before the replica method existed has getConnection() alone;
     * every read of the replica asks here rather than calling the method.
     */
    public static function readConnectionOf(object $database): mixed
    {
        return method_exists($database, 'getConnectionro')
            ? $database->getConnectionro()
            : $database->getConnection();
    }

    /**
     * For a caller that never reads through the read connection (the click
     * path): closes the one a retained 202-config.php opened in its
     * constructor beside the primary, and leaves the primary in its place, so
     * the request holds one connection against MySQL's max_connections rather
     * than two. The samples of 1.9.55 to 1.9.79 open both when built and keep
     * the second in `_connectionro`; a config that opens it on first use holds
     * none yet, and nothing is opened here. Answers whether one was closed.
     */
    public static function releaseReadConnection(object $database, \mysqli $primary): bool
    {
        if (!property_exists($database, '_connectionro')) {
            return false;
        }
        $property = new \ReflectionProperty($database, '_connectionro');
        if ($property->isStatic()) {
            return false;
        }
        $held = $property->getValue($database);
        if (!$held instanceof \mysqli || $held === $primary) {
            return false;
        }
        $property->setValue($database, $primary);
        try {
            $held->close();
        } catch (\Throwable) {
            // A handle that cannot be closed is one the server has already
            // dropped; either way this request no longer holds it.
        }

        return true;
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
