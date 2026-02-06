<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Core system table definitions.
 */
final class CoreTables
{
    /**
     * Get all core table definitions.
     *
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::version(),
            self::sessions(),
            self::cronjobs(),
            self::cronjobLogs(),
            self::mysqlErrors(),
            self::delayedSqls(),
            self::alerts(),
            self::offers(),
            self::filters(),
            self::userDataFeedback(),
        ];
    }

    public static function version(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::VERSION,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::VERSION . "` (
                `version` varchar(50) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function sessions(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::SESSIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::SESSIONS . "` (
                `session_id` varchar(100) NOT NULL DEFAULT '',
                `session_data` text NOT NULL,
                `expires` int(11) NOT NULL DEFAULT '0',
                PRIMARY KEY (`session_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function cronjobs(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CRONJOBS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CRONJOBS . "` (
                `cronjob_type` char(5) NOT NULL,
                `cronjob_time` int(11) NOT NULL,
                KEY `cronjob_type` (`cronjob_type`,`cronjob_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function cronjobLogs(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CRONJOB_LOGS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CRONJOB_LOGS . "` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `last_execution_time` int(10) unsigned NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function mysqlErrors(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::MYSQL_ERRORS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::MYSQL_ERRORS . "` (
                `mysql_error_id` mediumint(8) unsigned NOT NULL auto_increment,
                `mysql_error_text` text NOT NULL,
                `mysql_error_sql` text NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `ip_id` bigint(20) unsigned NOT NULL,
                `mysql_error_time` int(10) unsigned NOT NULL,
                `site_id` bigint(20) unsigned NOT NULL,
                PRIMARY KEY (`mysql_error_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function delayedSqls(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::DELAYED_SQLS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::DELAYED_SQLS . "` (
                `delayed_sql` text NOT NULL,
                `delayed_time` int(10) unsigned NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function alerts(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ALERTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ALERTS . "` (
                `prosper_alert_id` int(11) NOT NULL,
                `prosper_alert_seen` tinyint(1) NOT NULL,
                UNIQUE KEY `prosper_alert_id` (`prosper_alert_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function offers(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::OFFERS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::OFFERS . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `offer_id` mediumint(10) unsigned NOT NULL,
                `offer_seen` tinyint(1) NOT NULL DEFAULT '1',
                UNIQUE KEY `user_id` (`user_id`,`offer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function filters(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::FILTERS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::FILTERS . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `filter_name` enum('Clicks','Click Throughs','LP CTR','Leads','S/U','Payout','EPC','CPC','eCPA','Income','Cost','Net','ROI') DEFAULT NULL,
                `filter_condition` enum('>','<','=','>=','<=','!=') DEFAULT NULL,
                `filter_value` decimal(20,5) DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function userDataFeedback(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::USER_DATA_FEEDBACK,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::USER_DATA_FEEDBACK . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `install_hash` varchar(255) NOT NULL DEFAULT '',
                `user_email` varchar(100) NOT NULL DEFAULT '',
                `user_hash` varchar(255) NOT NULL DEFAULT '',
                `time_stamp` int(10) unsigned NOT NULL,
                `api_key` varchar(255) DEFAULT NULL,
                `vip_perks_status` tinyint(1) NOT NULL DEFAULT '0',
                `modal_status` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
