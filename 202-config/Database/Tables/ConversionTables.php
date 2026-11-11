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
 *
 * transaction_id and dedupe_key are utf8mb4_bin. They are identities a
 * network or a source chose, and UNIQUE (click_id, dedupe_key) is what makes
 * a retry a duplicate: under the table's case-insensitive collation `tx:A-1`
 * and `tx:a-1` on one click were one key, so a second, different sale was
 * answered as a replay of the first (CLAUDE.md #17: the key must be
 * injective), and a reversal naming one transaction id could net the other.
 * The upgrade converges an existing table to the same collations.
 *
 * `match_method` is how the row's click was found (Ledger\MatchMethod). It
 * is last, after the ledger columns, because 1.9.79 added it to tables that
 * already had them (_upgrade_conversion_ledger_columns()); its default,
 * 'unknown', is what every row from before then holds.
 *
 * `currency` is the currency `click_payout` is in (Ledger\RowCurrency), from
 * 1.9.80: the account's when the row was written, or another when an amount
 * was recorded as stated. '' is unknown: a row written while the upgrade had
 * not added the column, or whose account had no preferences to read at the
 * upgrade. `stated_amount` and `stated_currency` hold what a postback or
 * pixel sent when `click_payout` is that amount converted into the account's
 * currency; NULL on every other row. Only codes this code wrote are stored
 * and no key is over them, so they keep the table's collation.
 * `currency_basis` says how `currency` is known (RowCurrency::BASIS_*):
 * written with the row (0), labelled by the 1.9.80 upgrade with the
 * account's currency then (1), or said by the owner (2). Personal settings
 * lets the owner correct the rows that are not 0.
 *
 * `user_currency_click_time` is the reports' read of a period's revenue that
 * is not in the account's currency (Ledger\RevenueCurrencies): it reads only
 * those rows, which for nearly every account is none.
 *
 * `user_conv_time` is the money-loss alerts' read of an account's last week
 * of conversions (Prosper202\Alerts\AccountActivity), from 1.9.80.
 *
 * `reverses_conv_id` is indexed because every conversion's counted amount
 * (Attribution\CountedAmount — the worker, the journey drill-down, the
 * recent conversions) looks up the reversals naming it. Without the index
 * that lookup read the whole table: measured at 1M conversions (plan §8.1),
 * about 300 ms a conversion, which held the attribution worker to about 330
 * conversions a minute against its 1,000 target.
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
            self::attributionBackfill(),
            self::conversionUploads(),
            self::notificationPending(),
            self::notificationCorrectionUrls(),
            self::attributionRollupDirty(),
            self::attributionRollupDirtyClicks(),
            self::offlineConversionFeeds(),
            self::offlineConversionNames(),
            self::legacyGoogleFeedFetches(),
            self::postbackAuth(),
            self::destinations(),
            self::destinationDeliveries(),
            self::inboundLog(),
            self::heldPaymentNotifications(),
            self::shopifyPendingRefunds(),
            self::shopifyRefundTransactions(),
            self::exchangeRates(),
        ];
    }

    public static function conversionLogs(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CONVERSION_LOGS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CONVERSION_LOGS . "` (
                `conv_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `click_id` bigint(20) unsigned NOT NULL,
                `transaction_id` varchar(255) COLLATE utf8mb4_bin DEFAULT NULL,
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
                `dedupe_key` varchar(320) COLLATE utf8mb4_bin NOT NULL,
                `match_method` varchar(16) NOT NULL DEFAULT 'unknown',
                `currency` char(3) NOT NULL DEFAULT '',
                `stated_amount` decimal(11,5) DEFAULT NULL,
                `stated_currency` char(3) DEFAULT NULL,
                `currency_basis` tinyint(1) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`conv_id`),
                UNIQUE KEY `uniq_click_dedupe` (`click_id`,`dedupe_key`),
                KEY `click_transaction` (`click_id`,`transaction_id`),
                KEY `user_id` (`user_id`),
                KEY `campaign_id` (`campaign_id`),
                KEY `customer_id` (`customer_id`),
                KEY `reverses_conv_id` (`reverses_conv_id`),
                KEY `user_conv_time` (`user_id`,`conv_time`),
                KEY `user_currency_click_time` (`user_id`,`currency`,`click_time`)
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
     *
     * attempts / last_error / retry_at belong to the worker: a row whose
     * processing failed is kept with the error and retried after a backoff,
     * so one malformed conversion delays only itself, never the queue behind
     * it. A re-queue resets them — the change may be what fixes it.
     */
    /**
     * The upgrade's backfill of pre-upgrade conversions into MTA
     * (Attribution\ConversionBackfill): one row for the installation, or
     * none. The upgrade that turns an existing 202_conversion_logs into the
     * ledger writes it (clicks up to through_click_id are the ones recorded
     * before the ledger existed); the worker walks next_click_id up to it in
     * bounded chunks and stamps finished_at. A fresh install has nothing
     * from before, so it never gets a row. Not per user: the walk is over
     * the installation's clicks, and each click's row names its own user.
     */
    public static function attributionBackfill(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_BACKFILL,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_BACKFILL . "` (
                `backfill_id` tinyint(3) unsigned NOT NULL,
                `next_click_id` bigint(20) unsigned NOT NULL DEFAULT '0',
                `through_click_id` bigint(20) unsigned NOT NULL,
                `started_at` int(10) unsigned NOT NULL,
                `finished_at` int(10) unsigned DEFAULT NULL,
                `clicks_examined` bigint(20) unsigned NOT NULL DEFAULT '0',
                `baselines` int(10) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`backfill_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    public static function attributionPending(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_PENDING,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_PENDING . "` (
                `conv_id` int(11) unsigned NOT NULL,
                `enqueued_at` int(10) unsigned NOT NULL,
                `reason` varchar(32) NOT NULL,
                `enqueue_seq` int(10) unsigned NOT NULL DEFAULT '1',
                `attempts` smallint(5) unsigned NOT NULL DEFAULT '0',
                `last_error` varchar(255) DEFAULT NULL,
                `retry_at` int(10) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`conv_id`),
                KEY `enqueued_at` (`enqueued_at`),
                KEY `due` (`retry_at`,`enqueued_at`)
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
     * nothing. UNIQUE (conv_id, pixel_id, destination, kind, generation)
     * makes a retried request unable to queue the same notification twice.
     *
     * - destination: the URL's 0-based position in its pixel's code. A
     *   pixel may hold several space-separated URLs; each is its own row,
     *   with its own attempts, backoff and status, so a failure at one
     *   endpoint never resends to another that already accepted it;
     *
     * - kind: reached (the first row for an outcome), correction (a
     *   replacement whose predecessor was already sent, a new row for an
     *   outcome an earlier row announced, or a revived row whose retraction
     *   was delivered), retraction (an outcome retired with no replacement
     *   after its reached was sent);
     * - generation: 0 for a reached; the count of earlier rows of the same
     *   kind for the conversion at the destination for a correction or
     *   retraction, so a row retired, revived and retired again records
     *   each step (plan §5.7);
     * - status: pending, sent, failed (attempts exhausted), cancelled (a
     *   pending reached whose outcome was replaced before it went out, or a
     *   retraction whose outcome was revived before it went out) and
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
                `destination` smallint(5) unsigned NOT NULL DEFAULT '0',
                `kind` varchar(16) NOT NULL,
                `generation` smallint(5) unsigned NOT NULL DEFAULT '0',
                `status` varchar(16) NOT NULL,
                `url` text NOT NULL,
                `attempts` smallint(5) unsigned NOT NULL DEFAULT '0',
                `next_attempt_at` int(10) unsigned NOT NULL,
                `last_error` varchar(255) DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `sent_at` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`notification_id`),
                UNIQUE KEY `conv_destination_kind` (`conv_id`,`pixel_id`,`destination`,`kind`,`generation`),
                KEY `status_next` (`status`,`next_attempt_at`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Traffic-source notifications queued with the conversions they announce'"
        );
    }

    /**
     * Where a traffic source takes corrections (plan §5.5, PR 11): one URL
     * per server-to-server pixel (202_ppc_account_pixels, type 4).
     *
     * A postback that went out cannot be recalled, so when an outcome it
     * announced is replaced or retired the outbox records a `correction` or
     * `retraction`. With no row here (most networks have no endpoint for
     * one, so this is off by default) that record stays `suppressed`; with
     * one, it is queued to this URL like any postback, with
     * [[p202_goal_value]] (the new value), [[p202_previous_value]],
     * [[p202_original_conv_id]] and [[p202_notification_kind]] filled.
     *
     * Keyed by the pixel rather than a column on it: 202_ppc_account_pixels
     * is an old table, and its rows are rewritten by the traffic source
     * form, which knows nothing of corrections.
     */
    public static function notificationCorrectionUrls(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::NOTIFICATION_CORRECTION_URLS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::NOTIFICATION_CORRECTION_URLS . "` (
                `pixel_id` mediumint(8) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `correction_url` text NOT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`pixel_id`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='The correction URL of a traffic source postback pixel'"
        );
    }

    // The report rollup's dirty marks. They are the rollup's to read, but the
    // conversion path, the redirects and the LTV click stamp write them in
    // the transaction of the change they mark (AttributionRollup rule 2),
    // so they are recording's schema, as the MTA outbox is: recording never
    // writes an AttributionTables table (plan §7.4).

    /**
     * Hours whose sums are stale: a range of hours an account's data
     * changed in, written in the same transaction as the change (the
     * worker's credit rewrites, a CPC update, the rollup's own resolution
     * of a changed click). A report computes a dirty hour exactly; the
     * rollup re-sums it and deletes the row.
     */
    public static function attributionRollupDirty(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_ROLLUP_DIRTY,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_ROLLUP_DIRTY . "` (
                `dirty_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `hour_from` int(10) unsigned NOT NULL,
                `hour_to` int(10) unsigned NOT NULL,
                PRIMARY KEY (`dirty_id`),
                KEY `user_hour` (`user_id`,`hour_from`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Clicks changed after the fact (a rotator re-click rewriting its click,
     * a CPC set on one click). A click's change reaches the hours of every
     * conversion whose journey holds it, which the writer does not look up:
     * the rollup resolves the click into dirty hours, and until it has, the
     * account's reports are computed exactly.
     */
    public static function attributionRollupDirtyClicks(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::ATTRIBUTION_ROLLUP_DIRTY_CLICKS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::ATTRIBUTION_ROLLUP_DIRTY_CLICKS . "` (
                `dirty_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `click_id` bigint(20) unsigned NOT NULL,
                PRIMARY KEY (`dirty_id`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * The scheduled fetch URLs of offline conversion files
     * (Prosper202\OfflineConversion\FeedStore): one row per URL an ad
     * platform fetches. The URL carries a key of which only the SHA-256 is
     * kept, so a read of the table hands out no URL; replacing the key
     * rewrites key_hash, and deleting the row ends the URL.
     */
    public static function offlineConversionFeeds(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::OFFLINE_CONVERSION_FEEDS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::OFFLINE_CONVERSION_FEEDS . "` (
                `feed_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `format` varchar(16) NOT NULL,
                `key_hash` binary(32) NOT NULL,
                `days` tinyint(3) unsigned NOT NULL DEFAULT '7',
                `conversion_name` varchar(100) DEFAULT NULL,
                `ad_user_data` varchar(8) NOT NULL DEFAULT '',
                `ad_personalization` varchar(8) NOT NULL DEFAULT '',
                `created_at` int(10) unsigned NOT NULL,
                `key_created_at` int(10) unsigned NOT NULL,
                `last_fetched_at` int(10) unsigned DEFAULT NULL,
                `last_fetch_rows` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`feed_id`),
                UNIQUE KEY `key_hash` (`key_hash`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * The conversion action (Google Ads) or goal (Microsoft Advertising) a
     * campaign's conversions are uploaded as (Prosper202\OfflineConversion\
     * ConversionNames). aff_campaign_id 0 is the account's name for every
     * campaign without one of its own. platform is one of two fixed words.
     */
    public static function offlineConversionNames(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::OFFLINE_CONVERSION_NAMES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::OFFLINE_CONVERSION_NAMES . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `platform` varchar(16) COLLATE utf8mb4_bin NOT NULL,
                `aff_campaign_id` mediumint(8) unsigned NOT NULL,
                `conversion_name` varchar(100) NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`user_id`,`platform`,`aff_campaign_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * The schedules still fetching paid 1.9.56's retired Google Ads feed
     * (Prosper202\OfflineConversion\LegacyGoogleFeedFetches): one row per
     * conversion action and campaign the old address named (aff_campaign_id
     * 0 is its ac=--), with when it was last fetched. Written by a request
     * nobody signed, so it is bounded (MAX_ROWS) and names no account.
     * From 1.9.80, added by its rung.
     */
    public static function legacyGoogleFeedFetches(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::LEGACY_GOOGLE_FEED_FETCHES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::LEGACY_GOOGLE_FEED_FETCHES . "` (
                `fetch_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `aff_campaign_id` int(10) unsigned NOT NULL DEFAULT '0',
                `conversion_name` varchar(100) COLLATE utf8mb4_bin NOT NULL,
                `first_fetched_at` int(10) unsigned NOT NULL,
                `last_fetched_at` int(10) unsigned NOT NULL,
                `fetches` int(10) unsigned NOT NULL DEFAULT '1',
                PRIMARY KEY (`fetch_id`),
                UNIQUE KEY `schedule` (`aff_campaign_id`,`conversion_name`),
                KEY `last_fetched_at` (`last_fetched_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Each account's postback authentication (Prosper202\Conversion\
     * PostbackAuth): what its postbacks and pixels must present (auth_mode:
     * off, token, signed), its URL token and its signing secret, each sealed
     * (AES-256-GCM under the `postback_token` key in 202_deployment_secrets),
     * and for each the one a rotation replaced, valid until its expiry.
     * No row is an account made from 1.9.80 on that never had a token: it
     * reads as `token`, the DEFAULT; the 1.9.80 rung makes the table with a
     * row of `off` for every account already there.
     */
    public static function postbackAuth(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::POSTBACK_AUTH,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::POSTBACK_AUTH . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `auth_mode` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'token',
                `token_sealed` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `token_created_at` int(10) unsigned DEFAULT NULL,
                `previous_token_sealed` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `previous_token_expires_at` int(10) unsigned DEFAULT NULL,
                `secret_sealed` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `secret_created_at` int(10) unsigned DEFAULT NULL,
                `previous_secret_sealed` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `previous_secret_expires_at` int(10) unsigned DEFAULT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * The ad platforms an account sends its conversions to (Prosper202\Destinations):
     * type, mapping settings (JSON) and credentials sealed by DestinationStore, never served.
     */
    public static function destinations(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::DESTINATIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::DESTINATIONS . "` (
                `destination_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `type` varchar(32) COLLATE utf8mb4_bin NOT NULL,
                `name` varchar(100) NOT NULL,
                `enabled` tinyint(1) unsigned NOT NULL DEFAULT '1',
                `settings` text NOT NULL,
                `credentials` text NOT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                `deleted_at` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`destination_id`),
                KEY `user_enabled` (`user_id`,`enabled`,`deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Ad platforms conversions are sent to'"
        );
    }

    /**
     * The destinations' queue and delivery log, written with the conversion
     * (DestinationQueue) and sent by the worker under the outbox's rules. A
     * test fire has no conversion: conv_id NULL, which the unique key never
     * compares equal. summary says what was sent, never an identifier's value.
     */
    public static function destinationDeliveries(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::DESTINATION_DELIVERIES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::DESTINATION_DELIVERIES . "` (
                `delivery_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `destination_id` int(10) unsigned NOT NULL,
                `conv_id` int(11) unsigned DEFAULT NULL,
                `kind` varchar(16) COLLATE utf8mb4_bin NOT NULL,
                `status` varchar(16) COLLATE utf8mb4_bin NOT NULL,
                `attempts` smallint(5) unsigned NOT NULL DEFAULT '0',
                `next_attempt_at` int(10) unsigned NOT NULL,
                `last_status_code` smallint(5) unsigned DEFAULT NULL,
                `last_error` varchar(255) DEFAULT NULL,
                `summary` varchar(1000) DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `sent_at` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`delivery_id`),
                UNIQUE KEY `destination_conv_kind` (`destination_id`,`conv_id`,`kind`),
                KEY `status_next` (`status`,`next_attempt_at`),
                KEY `user_destination` (`user_id`,`destination_id`,`delivery_id`),
                KEY `user_destination_status` (`user_id`,`destination_id`,`kind`,`status`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Conversions queued for and sent to destinations'"
        );
    }

    /**
     * Refunds awaiting terminal Shopify transaction statuses, and the ones
     * closed without a reversal (refused_reason), which Setup › Inbound Log
     * lists while a sale of their order is on file (order_on_file).
     */
    public static function shopifyPendingRefunds(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::SHOPIFY_PENDING_REFUNDS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::SHOPIFY_PENDING_REFUNDS . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `refund_id` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `order_id` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `click_id` bigint(20) unsigned NOT NULL,
                `occurred_at` int(10) unsigned NOT NULL,
                `transactions` text NOT NULL,
                `completed_at` int(10) unsigned DEFAULT NULL,
                `refused_reason` varchar(500) DEFAULT NULL,
                `order_on_file` tinyint(1) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`user_id`,`refund_id`),
                KEY `pending_order` (`user_id`,`order_id`,`completed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /** Terminal refund transaction statuses, including those arriving before their refund. */
    public static function shopifyRefundTransactions(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::SHOPIFY_REFUND_TRANSACTIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::SHOPIFY_REFUND_TRANSACTIONS . "` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `transaction_id` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `order_id` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `status` varchar(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `amount` decimal(11,5) NOT NULL,
                `currency` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `received_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`user_id`,`transaction_id`),
                KEY `order_lookup` (`user_id`,`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * The postbacks, pixels and payment notifications this install answered,
     * and what became of each (Prosper202\InboundLog). user_id 0 is a request
     * no account could be found for. Bounded per account by the hourly prune
     * (InboundLog::KEEP_PER_ACCOUNT backed, KEEP_REFUSED others: accepted
     * is 1 for an account's request that wrote a conversion or event,
     * InboundLog::BACKED_OUTCOMES); fields hold the request with every
     * secret and visitor value redacted. From 1.9.80, added by its rung.
     */
    public static function inboundLog(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::INBOUND_LOG,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::INBOUND_LOG . "` (
                `inbound_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
                `received_at` int(10) unsigned NOT NULL,
                `endpoint` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `method` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `http_status` smallint(5) unsigned NOT NULL,
                `outcome` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `accepted` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `reason` varchar(500) NOT NULL DEFAULT '',
                `click_id` bigint(20) unsigned DEFAULT NULL,
                `conversion_id` int(11) unsigned DEFAULT NULL,
                `transaction_id` varchar(255) DEFAULT NULL,
                `fields` text,
                PRIMARY KEY (`inbound_id`),
                KEY `user_recent` (`user_id`,`inbound_id`),
                KEY `user_kind_recent` (`user_id`,`accepted`,`inbound_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * The exchange rates a postback's or pixel's amount is converted with
     * (Prosper202\Conversion\Ledger\ExchangeRateCache): one row per pair of
     * currencies some campaign needs, refreshed by the cron job once a day.
     * The conversion path reads it and never asks the rate service. Of the
     * install, not of an account: a rate is the same for every account.
     */
    public static function exchangeRates(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::EXCHANGE_RATES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::EXCHANGE_RATES . "` (
                `from_currency` char(3) COLLATE utf8mb4_bin NOT NULL,
                `to_currency` char(3) COLLATE utf8mb4_bin NOT NULL,
                `rate` decimal(20,10) NOT NULL,
                `fetched_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`from_currency`,`to_currency`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Payment notifications refused because they state another currency
     * than the account counts in (Prosper202\PaymentNotification\HeldNotifications):
     * what the processor read of each, kept so it can be recorded once the
     * account counts in that currency. No secret and nothing of the buyer.
     * A row goes when it is recorded; dedupe makes a provider's retry the
     * same row. From 1.9.80, added by its rung.
     */
    public static function heldPaymentNotifications(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::HELD_PAYMENT_NOTIFICATIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::HELD_PAYMENT_NOTIFICATIONS . "` (
                `held_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `source` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `kind` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `event` varchar(64) NOT NULL DEFAULT '',
                `transaction_id` varchar(255) COLLATE utf8mb4_bin DEFAULT NULL,
                `original_transaction_id` varchar(255) COLLATE utf8mb4_bin DEFAULT NULL,
                `amount` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `currency` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `click_id` bigint(20) unsigned NOT NULL,
                `purlink_subid` varchar(512) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `occurred_at` int(10) unsigned DEFAULT NULL,
                `received_at` int(10) unsigned NOT NULL,
                `notification_key` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                `body_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `dedupe` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `claimed_at` int(10) unsigned DEFAULT NULL,
                `tried_at` int(10) unsigned DEFAULT NULL,
                `last_answer` varchar(500) NOT NULL DEFAULT '',
                PRIMARY KEY (`held_id`),
                UNIQUE KEY `user_dedupe` (`user_id`,`dedupe`),
                KEY `user_source` (`user_id`,`source`,`held_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }
}
