<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * The conversion ledger and what hangs off it.
 *
 * 202_conversion_logs is a core table: every conversion path writes it and
 * MTA only reads it. It used to be defined among the attribution tables,
 * which meant a rewrite of MTA's definitions could touch it; it lives here
 * so it cannot.
 *
 * Column order matters to one reader: the upgrade appends the ledger columns
 * to a table that already exists, so they are declared after customer_id in
 * the order _upgrade_conversion_ledger() adds them.
 */
final class ConversionTables
{
    /**
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::conversionLogs(),
            self::attributionPending(),
            self::conversionUploads(),
            self::notificationPending(),
        ];
    }

    public static function conversionLogs(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CONVERSION_LOGS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CONVERSION_LOGS . "` (
                `conv_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `click_id` bigint(20) unsigned NOT NULL,
                `transaction_id` varchar(255) DEFAULT NULL,
                `campaign_id` mediumint(8) unsigned NOT NULL,
                `click_payout` decimal(11,5) NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `click_time` int(10) NOT NULL,
                `conv_time` int(10) NOT NULL,
                `time_difference` text NOT NULL,
                `ip` varchar(45) NOT NULL DEFAULT '',
                `pixel_type` int(11) unsigned NOT NULL,
                `user_agent` text NOT NULL,
                `deleted` tinyint(4) NOT NULL DEFAULT '0',
                `customer_id` bigint(20) unsigned DEFAULT NULL,
                `source` varchar(32) NOT NULL DEFAULT '',
                `source_ref` varchar(255) DEFAULT NULL,
                `event_name` varchar(255) DEFAULT NULL,
                `payable` tinyint(1) NOT NULL DEFAULT '1',
                `reverses_conv_id` int(11) unsigned DEFAULT NULL,
                `superseded_by` int(11) unsigned DEFAULT NULL,
                `superseded_reason` varchar(16) DEFAULT NULL,
                `dedupe_key` varchar(320) NOT NULL,
                PRIMARY KEY (`conv_id`),
                UNIQUE KEY `uniq_click_dedupe` (`click_id`,`dedupe_key`),
                KEY `click_transaction` (`click_id`,`transaction_id`),
                KEY `user_id` (`user_id`),
                KEY `campaign_id` (`campaign_id`),
                KEY `customer_id` (`customer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * The MTA outbox. Written in the same transaction as the conversion (and
     * every later change to what counts), consumed by the attribution worker.
     * It is conversion schema, not MTA schema: a failure to write it fails the
     * conversion like any other schema failure, and nothing about the MTA
     * engine's health can stop it being written.
     *
     * enqueue_seq increments on every re-queue of a pending row, so a worker
     * that processed a row deletes it only if nothing re-queued it meanwhile.
     */
    public static function attributionPending(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_PENDING,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_PENDING . "` (
                `conv_id` int(11) unsigned NOT NULL,
                `enqueued_at` int(10) unsigned NOT NULL,
                `reason` varchar(32) NOT NULL,
                `enqueue_seq` int(10) unsigned NOT NULL DEFAULT '1',
                PRIMARY KEY (`conv_id`),
                KEY `enqueued_at` (`enqueued_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * One row per revenue CSV upload. Each CSV line becomes a ledger row whose
     * source_ref names its batch, and the newest batch for a click replaces
     * the earlier ones (ClickValueCalculator, rule 2).
     */
    public static function conversionUploads(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CONVERSION_UPLOADS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CONVERSION_UPLOADS . "` (
                `batch_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `file_name` varchar(255) NOT NULL,
                `line_count` int(10) unsigned NOT NULL DEFAULT '0',
                `recorded_count` int(10) unsigned NOT NULL DEFAULT '0',
                `skipped_count` int(10) unsigned NOT NULL DEFAULT '0',
                `uploaded_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`batch_id`),
                KEY `user_uploaded` (`user_id`,`uploaded_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * The traffic-source notification outbox (plan §5.2 step 6, §5.5).
     *
     * A row is written in the same transaction as the ledger row it
     * announces, so a process killed between the commit and the send leaves
     * the row for the worker (202-cronjobs/app-installs.php) instead of
     * nothing. UNIQUE (conv_id, pixel_id, kind) makes a retried request
     * unable to queue the same notification twice.
     *
     * - kind: reached (the first row for an outcome), correction (a
     *   replacement whose predecessor was already sent), retraction (an
     *   outcome retired with no replacement after its reached was sent);
     * - status: pending, sent, failed (attempts exhausted), cancelled (a
     *   pending reached whose outcome was replaced before it went out) and
     *   suppressed (a correction or retraction no correction URL can carry;
     *   `last_error` says why);
     * - url is resolved when the row is queued, with the tokens of the row
     *   it announces, so what is sent is what was decided in the
     *   transaction.
     */
    public static function notificationPending(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::NOTIFICATION_PENDING,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::NOTIFICATION_PENDING . "` (
                `notification_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `conv_id` int(11) unsigned NOT NULL,
                `pixel_id` mediumint(8) unsigned NOT NULL,
                `kind` varchar(16) NOT NULL,
                `status` varchar(16) NOT NULL,
                `url` text NOT NULL,
                `attempts` smallint(5) unsigned NOT NULL DEFAULT '0',
                `next_attempt_at` int(10) unsigned NOT NULL,
                `last_error` varchar(255) DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `sent_at` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`notification_id`),
                UNIQUE KEY `conv_pixel_kind` (`conv_id`,`pixel_id`,`kind`),
                KEY `status_next` (`status`,`next_attempt_at`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Traffic-source notifications queued with the conversions they announce'"
        );
    }
}
