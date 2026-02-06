<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Rotator and redirect rule table definitions.
 */
final class RotatorTables
{
    /**
     * Get all rotator-related table definitions.
     *
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::rotators(),
            self::rotatorRules(),
            self::rotatorRulesCriteria(),
            self::rotatorRulesRedirects(),
            self::rotations(),
        ];
    }

    public static function rotators(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ROTATORS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ROTATORS . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `public_id` int(11) NOT NULL,
                `user_id` int(11) NOT NULL,
                `name` varchar(255) NOT NULL DEFAULT '',
                `default_url` text,
                `default_campaign` int(11) DEFAULT NULL,
                `default_lp` int(11) DEFAULT NULL,
                `auto_monetizer` char(4) DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function rotatorRules(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ROTATOR_RULES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ROTATOR_RULES . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `rotator_id` int(11) NOT NULL,
                `rule_name` varchar(255) NOT NULL DEFAULT '',
                `splittest` tinyint(1) NOT NULL DEFAULT '0',
                `status` int(11) DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function rotatorRulesCriteria(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ROTATOR_RULES_CRITERIA,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ROTATOR_RULES_CRITERIA . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `rotator_id` int(11) NOT NULL,
                `rule_id` int(11) NOT NULL,
                `type` varchar(50) NOT NULL DEFAULT '',
                `statement` varchar(50) NOT NULL DEFAULT '',
                `value` text NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function rotatorRulesRedirects(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ROTATOR_RULES_REDIRECTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ROTATOR_RULES_REDIRECTS . "` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `rule_id` int(11) NOT NULL,
                `redirect_url` text,
                `redirect_campaign` int(11) DEFAULT NULL,
                `redirect_lp` int(11) DEFAULT NULL,
                `auto_monetizer` char(4) DEFAULT NULL,
                `weight` char(3) DEFAULT '0',
                `name` text NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function rotations(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ROTATIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ROTATIONS . "` (
                `aff_campaign_id` mediumint(8) unsigned NOT NULL,
                `rotation_num` tinyint(4) NOT NULL,
                PRIMARY KEY (`aff_campaign_id`)
            ) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'MEMORY'
        );
    }
}
