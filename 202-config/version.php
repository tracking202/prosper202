<?php
/**
 * Prosper202 Version Configuration
 * Single source of truth for version management
 */
declare(strict_types=1);

// Validate version format before defining
$version_string = '1.9.78';
if (!preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9]+)?$/', $version_string)) {
    throw new Exception('Invalid version format: ' . $version_string);
}

// Primary version constant
if (!defined('PROSPER202_VERSION')) {
    define('PROSPER202_VERSION', $version_string);
}

// For backward compatibility - make global
global $version;
$version = PROSPER202_VERSION;

/**
 * Helper function for version comparisons
 * @param string $operator Comparison operator (<, >, <=, >=, ==, !=)
 * @param string|null $compare_version Version to compare against (defaults to current version)
 * @return bool Result of version comparison
 */
if (!function_exists('prosper202_version_compare')) {
    function prosper202_version_compare(string $operator, ?string $compare_version = null): bool {
        $compare_against = $compare_version ?? PROSPER202_VERSION;
        return version_compare(PROSPER202_VERSION, $compare_against, $operator);
    }
}

if (!defined('PROSPER202_MIN_PHP_VERSION')) {
    // Must match composer.json's "php" requirement; the codebase uses PHP 8.3
    // syntax (typed class constants), so older runtimes fail to parse it.
    define('PROSPER202_MIN_PHP_VERSION', '8.3.0');
}

if (!function_exists('php_version_supported')) {
    function php_version_supported(): bool
    {
        return version_compare(PHP_VERSION, PROSPER202_MIN_PHP_VERSION, '>=');
    }
}

if (!defined('PROSPER202_MIN_MARIADB_VERSION')) {
    // The schema has JSON columns, which older servers reject partway through an install or upgrade.
    define('PROSPER202_MIN_MARIADB_VERSION', '10.6');
}

if (!defined('PROSPER202_MIN_MYSQL_VERSION')) {
    define('PROSPER202_MIN_MYSQL_VERSION', '8.0');
}

if (!function_exists('database_version_check')) {
    /**
     * Reads a mysqli server_info string against the database floors. MariaDB is
     * found by name: mysqlnd drops the "5.5.5-" prefix, so 10.1 reports
     * "10.1.48-MariaDB-1~bionic", which a prefix match once passed as MySQL 8.
     * A version that can't be read is refused.
     *
     * @return array{product: string, version: string, floor: string, error: ?string}
     */
    function database_version_check(string $serverInfo): array
    {
        $isMariaDb = stripos($serverInfo, 'mariadb') !== false;
        $product = $isMariaDb ? 'MariaDB' : 'MySQL';
        $floor = $isMariaDb ? PROSPER202_MIN_MARIADB_VERSION : PROSPER202_MIN_MYSQL_VERSION;
        $unprefixed = (string) preg_replace('/^5\.5\.5-/', '', trim($serverInfo));
        $version = preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $unprefixed, $m) ? $m[1] : '';
        $supported = $version !== '' && version_compare($version, $floor, '>=');

        return [
            'product' => $product,
            'version' => $version,
            'floor' => $floor,
            'error' => $supported ? null : "Prosper202 requires $product $floor, or newer.",
        ];
    }
}

