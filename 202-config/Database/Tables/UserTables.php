<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * User and role table definitions.
 */
final class UserTables
{
    /**
     * Get all user-related table definitions.
     *
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::users(),
            self::usersPref(),
            self::usersLog(),
            self::roles(),
            self::permissions(),
            self::rolePermission(),
            self::userRole(),
            self::apiKeys(),
            self::authKeys(),
        ];
    }

    public static function users(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::USERS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::USERS . "` (
                `user_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `user_fname` varchar(50) DEFAULT NULL,
                `user_lname` varchar(50) DEFAULT NULL,
                `user_name` varchar(50) NOT NULL,
                `user_pass` varchar(255) NOT NULL,
                `user_email` varchar(100) NOT NULL,
                `user_dash_email` varchar(100) NOT NULL,
                `user_public_publisher_id` varchar(10) DEFAULT NULL,
                `user_timezone` varchar(50) NOT NULL DEFAULT 'America/Los_Angeles',
                `user_time_register` int(10) unsigned NOT NULL,
                `user_pass_key` varchar(255) DEFAULT NULL,
                `user_pass_time` int(10) unsigned DEFAULT NULL,
                `user_api_key` varchar(255) DEFAULT NULL,
                `user_stats202_app_key` varchar(255) DEFAULT NULL,
                `user_last_login_ip_id` bigint(20) unsigned DEFAULT NULL,
                `clickserver_api_key` varchar(255) DEFAULT NULL,
                `install_hash` varchar(255) NOT NULL,
                `user_hash` varchar(255) NOT NULL,
                `modal_status` int(1) DEFAULT NULL,
                `vip_perks_status` int(1) DEFAULT NULL,
                `user_active` int(1) NOT NULL DEFAULT '1',
                `user_deleted` int(1) NOT NULL DEFAULT '0',
                `secret_key` char(48) DEFAULT NULL,
                `user_mods_lb` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `p202_customer_api_key` char(60) DEFAULT NULL,
                PRIMARY KEY (`user_id`),
                UNIQUE KEY `user_name_2` (`user_name`),
                KEY `user_name` (`user_name`,`user_pass`),
                KEY `user_pass_key` (`user_pass_key`(5)),
                KEY `user_last_login_ip_id` (`user_last_login_ip_id`),
                KEY `user_public_publisher_id` (`user_public_publisher_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function usersPref(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::USERS_PREF,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::USERS_PREF . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `user_pref_limit` tinyint(3) unsigned NOT NULL DEFAULT '50',
                `user_pref_show` varchar(25) DEFAULT NULL,
                `user_pref_time_from` int(10) unsigned DEFAULT NULL,
                `user_pref_time_to` int(10) unsigned DEFAULT NULL,
                `user_pref_time_predefined` varchar(25) NOT NULL DEFAULT 'today',
                `user_pref_adv` tinyint(1) DEFAULT NULL,
                `user_pref_ppc_network_id` mediumint(8) unsigned DEFAULT NULL,
                `user_pref_ppc_account_id` mediumint(8) unsigned DEFAULT NULL,
                `user_pref_aff_network_id` mediumint(8) unsigned DEFAULT NULL,
                `user_pref_aff_campaign_id` mediumint(8) unsigned DEFAULT NULL,
                `user_pref_text_ad_id` mediumint(8) unsigned DEFAULT NULL,
                `user_pref_method_of_promotion` varchar(25) DEFAULT NULL,
                `user_pref_landing_page_id` mediumint(8) unsigned DEFAULT NULL,
                `user_pref_country_id` tinyint(3) unsigned DEFAULT NULL,
                `user_pref_region_id` tinyint(3) unsigned DEFAULT NULL,
                `user_pref_device_id` tinyint(3) unsigned DEFAULT NULL,
                `user_pref_browser_id` tinyint(3) unsigned DEFAULT NULL,
                `user_pref_platform_id` tinyint(3) unsigned DEFAULT NULL,
                `user_pref_isp_id` tinyint(3) unsigned DEFAULT NULL,
                `user_pref_subid` bigint(20) unsigned DEFAULT NULL,
                `user_pref_ip` varchar(100) DEFAULT NULL,
                `user_pref_dynamic_bid` tinyint(1) NOT NULL DEFAULT '0',
                `user_pref_referer` varchar(100) DEFAULT NULL,
                `user_pref_keyword` varchar(100) DEFAULT NULL,
                `user_pref_breakdown` varchar(100) NOT NULL DEFAULT 'day',
                `user_pref_chart` varchar(255) NOT NULL DEFAULT 'net',
                `user_cpc_or_cpv` char(3) NOT NULL DEFAULT 'cpc',
                `user_keyword_searched_or_bidded` varchar(255) NOT NULL DEFAULT 'searched',
                `user_pref_privacy` varchar(100) NOT NULL DEFAULT 'disabled',
                `user_pref_referer_data` varchar(10) NOT NULL DEFAULT 'browser',
                `user_tracking_domain` varchar(255) NOT NULL DEFAULT '',
                `user_pref_group_2` tinyint(3) DEFAULT NULL,
                `user_pref_group_3` tinyint(3) DEFAULT NULL,
                `user_pref_group_4` tinyint(3) DEFAULT NULL,
                `user_pref_group_1` tinyint(3) DEFAULT NULL,
                `cache_time` varchar(4) NOT NULL DEFAULT '0',
                `cb_key` varchar(250) DEFAULT NULL,
                `cb_verified` tinyint(1) NOT NULL DEFAULT '0',
                `maxmind_isp` tinyint(1) NOT NULL DEFAULT '0',
                `chart_time_range` char(10) DEFAULT 'days',
                `user_slack_incoming_webhook` text DEFAULT NULL,
                `user_pref_cloak_referer` varchar(11) DEFAULT 'origin',
                `auto_cron` tinyint(1) NOT NULL DEFAULT '0',
                `user_daily_email` char(2) NOT NULL DEFAULT '07',
                `user_auto_database_optimization_days` int(11) unsigned DEFAULT '0',
                `user_delete_data_clickid` int(10) unsigned DEFAULT NULL,
                `zaxaa_api_signature` varchar(250) DEFAULT NULL,
                `jvzoo_ipn_secret_key` varchar(250) DEFAULT NULL,
                `user_account_currency` char(3) NOT NULL DEFAULT 'USD',
                `revcontent_user_id` varchar(250) DEFAULT NULL,
                `revcontent_user_secret` varchar(250) DEFAULT NULL,
                `facebook_ads_linked` int(1) NOT NULL DEFAULT '0',
                `user_pref_ad_settings` varchar(11) NOT NULL DEFAULT 'show_all',
                `ipqs_api_key` varchar(250) DEFAULT NULL,
                PRIMARY KEY (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function usersLog(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::USERS_LOG,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::USERS_LOG . "` (
                `login_id` mediumint(9) NOT NULL auto_increment,
                `user_name` varchar(255) NOT NULL,
                `user_pass` varchar(255) NOT NULL,
                `ip_address` varchar(255) NOT NULL,
                `login_time` int(10) unsigned NOT NULL,
                `login_success` tinyint(1) NOT NULL,
                `login_error` text NOT NULL,
                `login_server` text NOT NULL,
                `login_session` text NOT NULL,
                PRIMARY KEY (`login_id`),
                KEY `login_pass` (`login_success`),
                KEY `ip_address` (`ip_address`(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function roles(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ROLES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ROLES . "` (
                `role_id` int(11) NOT NULL AUTO_INCREMENT,
                `role_name` varchar(50) NOT NULL,
                PRIMARY KEY (`role_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function permissions(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::PERMISSIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::PERMISSIONS . "` (
                `permission_id` int(11) NOT NULL AUTO_INCREMENT,
                `permission_description` varchar(50) NOT NULL,
                PRIMARY KEY (`permission_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function rolePermission(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ROLE_PERMISSION,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ROLE_PERMISSION . "` (
                `role_id` int(11) NOT NULL,
                `permission_id` int(11) NOT NULL,
                KEY `role_id` (`role_id`),
                KEY `permission_id` (`permission_id`),
                CONSTRAINT `202_role_permission_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `202_roles` (`role_id`),
                CONSTRAINT `202_role_permission_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `202_permissions` (`permission_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function userRole(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::USER_ROLE,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::USER_ROLE . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `role_id` int(11) NOT NULL,
                KEY `user_id` (`user_id`),
                KEY `role_id` (`role_id`),
                CONSTRAINT `202_user_role_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `202_users` (`user_id`),
                CONSTRAINT `202_user_role_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `202_roles` (`role_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function apiKeys(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::API_KEYS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::API_KEYS . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `api_key` varchar(250) NOT NULL DEFAULT '',
                `created_at` int(10) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function authKeys(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::AUTH_KEYS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::AUTH_KEYS . "` (
                `user_id` mediumint(8) NOT NULL,
                `auth_key` varchar(64) NOT NULL,
                `expires` int(11) NOT NULL,
                KEY `202_auth_keys_user_id_auth_key` (`user_id`,`auth_key`),
                KEY `202_auth_keys_expires` (`expires`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
