<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * The identity graph (plan §6.2): which clicks belong to one person.
 * Written by Prosper202\Identity\IdentityGraph; read by the attribution
 * engine's journeys.
 */
final class IdentityTables
{
    /**
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::identityKeys(),
            self::identityVisitors(),
            self::identitySignals(),
            self::identityObservations(),
            self::identityMerges(),
            self::clicksVisitor(),
        ];
    }

    /** Per-account secrets: the signal hashing key and the customer-id linking key. */
    public static function identityKeys(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::IDENTITY_KEYS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::IDENTITY_KEYS . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `hash_key` char(64) NOT NULL,
                `link_key` char(64) NOT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `rotated_at` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /** One row per visitor key; alias_of names the canonical key a merged key now belongs to. */
    public static function identityVisitors(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::IDENTITY_VISITORS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::IDENTITY_VISITORS . "` (
                `visitor_key` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `alias_of` bigint(20) unsigned DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`visitor_key`),
                KEY `user_alias` (`user_id`,`alias_of`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /** Each distinct signal an account has seen, as a keyed hash, and the visitor key it names. */
    public static function identitySignals(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::IDENTITY_SIGNALS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::IDENTITY_SIGNALS . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `signal_type` varchar(8) NOT NULL,
                `signal_hash` char(64) NOT NULL,
                `visitor_key` bigint(20) unsigned NOT NULL,
                `merges` int(10) unsigned NOT NULL DEFAULT '0',
                `quarantined_at` int(10) unsigned DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`user_id`,`signal_type`,`signal_hash`),
                KEY `visitor_key` (`visitor_key`),
                KEY `quarantined` (`user_id`,`quarantined_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /** Which click carried which signal: the evidence a journey view explains a link with. */
    public static function identityObservations(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::IDENTITY_OBSERVATIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::IDENTITY_OBSERVATIONS . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `signal_type` varchar(8) NOT NULL,
                `signal_hash` char(64) NOT NULL,
                `observed_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`click_id`,`signal_type`,`signal_hash`),
                KEY `signal` (`signal_type`,`signal_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /** Append-only record of every merge; requeued_at is set once MTA re-queued what it changed. */
    public static function identityMerges(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::IDENTITY_MERGES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::IDENTITY_MERGES . "` (
                `merge_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `from_key` bigint(20) unsigned NOT NULL,
                `into_key` bigint(20) unsigned NOT NULL,
                `click_id` bigint(20) unsigned NOT NULL,
                `signal_type` varchar(8) NOT NULL,
                `signal_hash` char(64) NOT NULL,
                `merged_at` int(10) unsigned NOT NULL,
                `requeued_at` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`merge_id`),
                KEY `pending` (`requeued_at`),
                KEY `user_into` (`user_id`,`into_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * A click's visitor key, written in the click's own transaction. A click
     * attribute kept off the hot 202_clicks row; journeys read it by
     * (user_id, visitor_key, click_time).
     */
    public static function clicksVisitor(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CLICKS_VISITOR,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CLICKS_VISITOR . "` (
                `click_id` bigint(20) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `visitor_key` bigint(20) unsigned NOT NULL,
                `click_time` int(10) unsigned NOT NULL,
                PRIMARY KEY (`click_id`),
                KEY `user_visitor_time` (`user_id`,`visitor_key`,`click_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
