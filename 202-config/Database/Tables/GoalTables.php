<?php
declare(strict_types=1);

namespace Prosper202\Database\Tables;

use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Schema\TableRegistry;

/**
 * The goals engine (plan §2.2, §5.5): what counts, what happened, and what
 * each subject has reached.
 *
 * - 202_goals: one row per goal, owned by a campaign, an app registration or
 *   the account (the scope every app of the account shares). The name and
 *   current version mirror the current definition for listing. A live
 *   goal's name is unique per owner, and the database enforces it:
 *   `live_name` is the name while the goal is live and NULL once archived
 *   (a UNIQUE key admits any number of NULLs), so an archived goal frees
 *   its name while two concurrent creates of one live name cannot both
 *   commit. MysqlGoalRepository turns that duplicate into a CONFLICT; the
 *   nameTaken() read before it is only there to answer early and by name.
 * - 202_goal_versions: every definition a goal has had. A version is
 *   immutable; an edit adds one. `effective_at` is when it started to apply:
 *   an event is evaluated under the version current at its own received_at.
 * - 202_campaign_goals: which goals a campaign pays for, at what payout (NULL
 *   = the goal's own value), and whether the traffic source is told.
 * - 202_goal_subjects: one row per subject (a click or an install), the row
 *   every write for that subject locks first, holding its event count, the
 *   order key of its newest event, and the versions a re-evaluation rebased
 *   it onto.
 * - 202_goal_events: the evidence, idempotent on (subject, event_id), with a
 *   fingerprint so a reused event id carrying different content is refused
 *   rather than silently dropped.
 * - 202_goal_progress: the evaluator's per-(subject, goal, version) state.
 * - 202_goal_outcomes: every time a goal was reached, whether or not the
 *   subject has a click; the funnel's source. Retired rows keep
 *   superseded_at, and every read goes through
 *   MysqlGoalRepository::liveOutcomes().
 *
 * Event ids and event names are compared byte for byte (utf8mb4_bin):
 * `Purchase` and `purchase` are two events, and a case-folding collation
 * would fold two event ids into one UNIQUE slot (CLAUDE.md #17).
 */
final class GoalTables
{
    /**
     * @return array<SchemaDefinition>
     */
    public static function getDefinitions(): array
    {
        return [
            self::goals(),
            self::goalVersions(),
            self::campaignGoals(),
            self::goalSubjects(),
            self::goalEvents(),
            self::goalProgress(),
            self::goalOutcomes(),
        ];
    }

    public static function goals(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::GOALS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::GOALS . "` (
                `goal_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `scope` varchar(16) NOT NULL,
                `scope_id` int(10) unsigned NOT NULL DEFAULT '0',
                `name` varchar(100) NOT NULL,
                `current_version` int(10) unsigned NOT NULL,
                `archived_at` int(10) unsigned DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                `live_name` varchar(100) GENERATED ALWAYS AS (IF(`archived_at` IS NULL, `name`, NULL)) STORED,
                PRIMARY KEY (`goal_id`),
                UNIQUE KEY `live_name` (`user_id`,`scope`,`scope_id`,`live_name`),
                KEY `user_scope` (`user_id`,`scope`,`scope_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Goals: named, versioned outcomes owned by a campaign, an app registration or the account'"
        );
    }

    public static function goalVersions(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::GOAL_VERSIONS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::GOAL_VERSIONS . "` (
                `goal_id` int(10) unsigned NOT NULL,
                `version` int(10) unsigned NOT NULL,
                `definition` text NOT NULL,
                `effective_at` int(10) unsigned NOT NULL,
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`goal_id`,`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Every definition a goal has had; immutable'"
        );
    }

    public static function campaignGoals(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::CAMPAIGN_GOALS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::CAMPAIGN_GOALS . "` (
                `campaign_id` mediumint(8) unsigned NOT NULL,
                `goal_id` int(10) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `payout` decimal(11,5) DEFAULT NULL,
                `notify_traffic_source` tinyint(1) unsigned NOT NULL DEFAULT '1',
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`campaign_id`,`goal_id`),
                KEY `goal_id` (`goal_id`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='The goals a campaign pays for, and at what payout'"
        );
    }

    public static function goalSubjects(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::GOAL_SUBJECTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::GOAL_SUBJECTS . "` (
                `subject_type` varchar(8) NOT NULL,
                `subject_id` bigint(20) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `event_count` int(10) unsigned NOT NULL DEFAULT '0',
                `last_effective_at` int(10) unsigned DEFAULT NULL,
                `last_received_at` int(10) unsigned DEFAULT NULL,
                `last_event_id` varchar(128) COLLATE utf8mb4_bin DEFAULT NULL,
                `rebases` text DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                `updated_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`subject_type`,`subject_id`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='One lock row per goal subject (a click or an install)'"
        );
    }

    public static function goalEvents(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::GOAL_EVENTS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::GOAL_EVENTS . "` (
                `event_row_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `subject_type` varchar(8) NOT NULL,
                `subject_id` bigint(20) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `event_id` varchar(128) COLLATE utf8mb4_bin NOT NULL,
                `name` varchar(64) COLLATE utf8mb4_bin NOT NULL,
                `properties` text NOT NULL,
                `revenue` varchar(40) DEFAULT NULL,
                `currency` char(3) DEFAULT NULL,
                `revenue_trusted` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `transaction_id` varchar(255) DEFAULT NULL,
                `occurred_at` int(10) unsigned NOT NULL,
                `received_at` int(10) unsigned NOT NULL,
                `fingerprint` char(64) NOT NULL,
                PRIMARY KEY (`event_row_id`),
                UNIQUE KEY `subject_event` (`subject_type`,`subject_id`,`event_id`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Events reported for a goal subject: the evidence goals are evaluated against'"
        );
    }

    public static function goalProgress(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::GOAL_PROGRESS,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::GOAL_PROGRESS . "` (
                `subject_type` varchar(8) NOT NULL,
                `subject_id` bigint(20) unsigned NOT NULL,
                `goal_id` int(10) unsigned NOT NULL,
                `goal_version` int(10) unsigned NOT NULL,
                `user_id` mediumint(8) unsigned NOT NULL,
                `count` int(10) unsigned NOT NULL DEFAULT '0',
                `sum` decimal(24,5) NOT NULL DEFAULT '0.00000',
                `times_reached` int(10) unsigned NOT NULL DEFAULT '0',
                `reached_at` int(10) unsigned DEFAULT NULL,
                PRIMARY KEY (`subject_type`,`subject_id`,`goal_id`,`goal_version`),
                KEY `goal_id` (`goal_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Per-subject progress toward each goal version'"
        );
    }

    public static function goalOutcomes(): SchemaDefinition
    {
        return SchemaBuilder::fromRawSql(
            TableRegistry::GOAL_OUTCOMES,
            "CREATE TABLE IF NOT EXISTS `" . TableRegistry::GOAL_OUTCOMES . "` (
                `outcome_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` mediumint(8) unsigned NOT NULL,
                `subject_type` varchar(8) NOT NULL,
                `subject_id` bigint(20) unsigned NOT NULL,
                `goal_id` int(10) unsigned NOT NULL,
                `goal_version` int(10) unsigned NOT NULL,
                `n` int(10) unsigned NOT NULL,
                `event_id` varchar(128) COLLATE utf8mb4_bin NOT NULL,
                `reached_at` int(10) unsigned NOT NULL,
                `value` decimal(11,5) DEFAULT NULL,
                `value_source` varchar(16) NOT NULL,
                `value_note` varchar(32) DEFAULT NULL,
                `ineligible_reason` varchar(16) DEFAULT NULL,
                `payable` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `campaign_id` mediumint(8) unsigned DEFAULT NULL,
                `conversion_id` int(11) unsigned DEFAULT NULL,
                `superseded_by` bigint(20) unsigned DEFAULT NULL,
                `superseded_reason` varchar(16) DEFAULT NULL,
                `superseded_at` int(10) unsigned DEFAULT NULL,
                `created_at` int(10) unsigned NOT NULL,
                PRIMARY KEY (`outcome_id`),
                UNIQUE KEY `subject_goal_n_event` (`subject_type`,`subject_id`,`goal_id`,`goal_version`,`n`,`event_id`),
                KEY `goal_live` (`goal_id`,`superseded_at`),
                KEY `subject` (`subject_type`,`subject_id`),
                KEY `user_id` (`user_id`),
                KEY `conversion_id` (`conversion_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Every time a goal was reached by a subject; retired rows keep superseded_at'"
        );
    }
}
