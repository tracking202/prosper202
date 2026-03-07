<?php

declare(strict_types=1);

namespace {

if (!defined('DASHBOARD_API_URL')) {
    define('DASHBOARD_API_URL', '');
}
if (!defined('DASHBOARD_CACHE_TTL')) {
    define('DASHBOARD_CACHE_TTL', 300);
}
if (!defined('MYSQL_ASSOC')) {
    define('MYSQL_ASSOC', MYSQLI_ASSOC);
}
if (!defined('MYSQL_HOST')) {
    define('MYSQL_HOST', 'localhost');
}
if (!defined('MYSQL_USER')) {
    define('MYSQL_USER', '');
}
if (!defined('MYSQL_PASS')) {
    define('MYSQL_PASS', '');
}
if (!defined('MYSQL_DATABASE')) {
    define('MYSQL_DATABASE', '');
}
if (!defined('PROSPER202_VERSION')) {
    define('PROSPER202_VERSION', '0.0.0');
}
if (!defined('TRACKING202_ADS_URL')) {
    define('TRACKING202_ADS_URL', '');
}
if (!defined('ABSPATH')) {
    define('ABSPATH', '');
}
if (!defined('LANGDIR')) {
    define('LANGDIR', '');
}
if (!defined('PLUGINDIR')) {
    define('PLUGINDIR', '');
}
if (!function_exists('get_template_directory')) {
    function get_template_directory(): string
    {
        return '';
    }
}

if (!interface_exists('FraudDetectionInterface')) {
    interface FraudDetectionInterface
    {
        public function isFraud($ip);

        public function verifyKey();
    }
}

if (!class_exists('Form')) {
    class Form
    {
    }
}

if (!class_exists('unknown_type')) {
    class unknown_type
    {
    }
}

if (!class_exists('CachedFileReader')) {
    class CachedFileReader
    {
        public function __construct(string $filename)
        {
        }
    }
}

if (!class_exists('gettext_reader')) {
    class gettext_reader
    {
        public function __construct($reader)
        {
        }
    }
}

if (!class_exists('DB')) {
    final class DB
    {
        public static function getInstance(): object
        {
            return new class {
                public function getConnection(): ?\mysqli
                {
                    return null;
                }

                public function getConnectionro(): ?\mysqli
                {
                    return null;
                }
            };
        }
    }
}
}

namespace IPRegistry {
    final class IPRegistry
    {
        /**
         * @return array{country_code: string, region_code: string, city_name: string, zip_code: string, isp_name: string, connection_type_name: string}
         */
        public function getIpInfo(string $ipAddress): array
        {
            return [
                'country_code' => '',
                'region_code' => '',
                'city_name' => '',
                'zip_code' => '',
                'isp_name' => '',
                'connection_type_name' => '',
            ];
        }
    }
}
