<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\CallsBefore;
use Tests\Support\LocalStringValue;
use Tests\Support\SourceScan;
use Tests\Support\SqlJoinScan;

/**
 * A page or class changes or removes a row it names by id only when the row
 * is the signed-in account's.
 *
 * Setup › Traffic Sources renamed a source with `UPDATE 202_ppc_networks SET
 * user_id = <the session's>, … WHERE ppc_network_id = <the posted id>`, so
 * any account that reached Setup could rename another account's traffic
 * source and take it into its own. The pages beside it read the row first
 * with `AND user_id = …` and then wrote it by id alone: right only while the
 * check and the write name the same id on every path, which nothing held.
 *
 * The invariant, per statement: an UPDATE or DELETE in a served page or
 * endpoint, or in a class under 202-config/ or api/v3/ (SCOPE), on a table
 * with a user_id column (read from the schema's definitions, as
 * AccountScopedJoinTest reads them) has a top-level conjunct
 * `[t.]user_id = <the account>` in its WHERE, where <the account> is `?`, a
 * fixed number (the install's own row: `user_id = 1`) or a hole whose PHP
 * source names a user id (SqlJoinScan::namesAUserId(): `$mysql['user_id']`,
 * `(int) $userId`) and is not a request superglobal. A user_id in the SET
 * list is not one. A table with no user_id column is owned through its
 * parent (a redirector's rules, a traffic source's variables): its row
 * cannot say whose it is. A statement naming several tables ties each table
 * it writes (multiTableFinding()): one with a user_id column by its own, one
 * without by an equality with a tied table, in the WHERE or an inner join's
 * ON.
 *
 * A write that is not tied is listed, by file: a page's in THROUGH_PARENT;
 * a class's in GUARDED, with the method of the class that ties the row
 * first, which testEveryGuardedWriteRunsAfterItsGuard() holds (CallsBefore,
 * CLAUDE.md #22); the generic writers' in GENERIC, whose every table
 * testTheGenericWritersTieEveryTableTheyWrite() reads; and the rest in
 * REVIEWED, each with a reviewer's reason and nothing to hold it but the
 * ceiling.
 *
 * What the scan reads: the SQL each PHP file writes as SqlJoinScan's string
 * chains (literals, heredocs, `.` concatenation, interpolation, sprintf()
 * formats, comments dropped), a class constant holding a name read as its
 * value (`TableRegistry::CLICKS`, `self::TABLE`), and a statement finished
 * by later statements — `$sql = "UPDATE … SET"; $sql .= "…"; $sql .= "
 * WHERE …";` — joined in source order up to the first other use of the
 * variable, passing over the INSERT or REPLACE the same builder is given in
 * its other branch (the pages' `if ($editing) { UPDATE … SET } else { INSERT
 * … SET }` shape). A bare local variable in a write is read as the string
 * it holds only where LocalStringValue vouches for it: one `$x = <string
 * chain>;` in a block enclosing the read, and nothing after it but reads
 * (testALocalIsReadOnlyInTheShapeItCanVouchFor).
 *
 * What it refuses by name rather than reads (each fails unless listed): a
 * table built at runtime (a variable, a constant it cannot resolve) or not
 * in the schema, a multi-table statement it cannot read (a RIGHT or NATURAL
 * join, USING, an unqualified SET column, a written name it cannot place), a
 * statement with no WHERE it can read (none, an append it cannot read, or a
 * reassignment before the WHERE), an OR at the top of the WHERE, and a WHERE
 * with a hole whose name says it is a clause (`$where`).
 *
 * What it does not see: a statement whose first word is not literal
 * (`$verb . ' FROM …'`); a variable's value in any other shape (`$scope`, a
 * parameter: such a write is listed); whether the value a user-id name holds is the session's
 * (it is trusted by its name, as AccountScopedJoinTest trusts it); whether a
 * conditional append runs on the same path as the statement it finishes;
 * whether a guard is handed the id the write uses; and the upgrade ladder
 * (NOT_READ).
 */
final class AccountScopedWriteTest extends TestCase
{
    /** The served pages and endpoints, and the classes, whose writes are read. */
    private const SCOPE = [
        'tracking202/setup/', 'tracking202/ajax/', 'tracking202/update/', '202-account/',
        'api/legacy/', 'api/v1/', 'api/v2/', 'api/v3/', '202-config/',
    ];

    /** Files under SCOPE whose writes are not read, with why. */
    private const NOT_READ = [
        '202-config/functions-upgrade.php' => 'the upgrade ladder: it runs as the install, from upgrade.php before'
            . ' any account signs in, over every account\'s rows; no request names a row',
    ];

    private const THROUGH = 'owned through its parent';

    private const ROTATOR_REMOVAL = 'the rules, criteria and redirects of the redirector removed in the same'
        . ' transaction, which first locks it `WHERE id = ? AND user_id = ?` and removes nothing when that finds'
        . ' none';
    private const SOURCE_VARIABLES = 'the variables of the traffic source the request names, which the owner'
        . ' check at the top found the account\'s own and not removed (`ppc_network_id = … AND user_id = …`), or'
        . ' the request ended with a 404; a variable id is updated only within that source';

    /**
     * Writes to a table owned through its parent, with the check that ties
     * the parent to the account first. Keyed file => ["table | condition" =>
     * why]; testEveryListedWriteStillExists() keeps the list honest, and it
     * may only shrink (CEILING).
     *
     * @var array<string, array<string, string>>
     */
    private const THROUGH_PARENT = [
        'tracking202/ajax/custom_variables.php' => [
            "202_ppc_network_variables | ppc_variable_id = '{\$variableId}'"
                . " AND ppc_network_id = '{\$ppcNetworkId}'" => self::SOURCE_VARIABLES,
            "202_ppc_network_variables | ppc_variable_id NOT IN ( {\$keptList} )"
                . " AND ppc_network_id = '{\$ppcNetworkId}'" => self::SOURCE_VARIABLES,
            "202_ppc_network_variables | ppc_network_id = '{\$ppcNetworkId}' AND deleted = '0'"
                => self::SOURCE_VARIABLES,
        ],
        'tracking202/setup/rotator.php' => [
            '202_rotator_rules_criteria | rotator_id = ?' => self::ROTATOR_REMOVAL,
            '202_rotator_rules_redirects | rule_id IN ( SELECT id FROM 202_rotator_rules WHERE rotator_id = ? )'
                => self::ROTATOR_REMOVAL,
            '202_rotator_rules | rotator_id = ?' => self::ROTATOR_REMOVAL,
        ],
    ];

    /** The number of THROUGH_PARENT entries: the list may only shrink. */
    private const CEILING = 6;

    private const R_WORKER = 'a cron run or queue worker\'s own work: the row is one its own due or claim read chose,'
        . ' across the install; no request names it';
    private const R_RETENTION = 'retention across the install: rows past their age, chosen by time; no request names a row';
    private const R_CLICK_LOCK = 'under MysqlConversionLedger::clickTransaction() (or lockClick()), whose lock reads the click'
        . ' by its id and the account (`click_id = ? AND user_id = ?`, a public conversion\'s account being the'
        . ' click\'s own) and writes nothing when it finds none';
    private const R_CREDENTIAL = 'the row the request\'s own secret found (a fetch key, a personalization token, a bearer'
        . ' key): the secret is the caller\'s standing, and no account signs in';
    private const R_INSTALL_ROW = 'an Android install row the intake wrote or its worker claimed for the app registration'
        . ' the SDK\'s token named, by its own id';

    /**
     * Writes in a class tied to the account by a guard method of the same
     * class that runs first: keyed file => ["kind | table | condition" =>
     * the guard's calls and the table its read ties].
     * testEveryGuardedWriteRunsAfterItsGuard() holds each.
     *
     * @var array<string, array<string, array{calls: list<string>, reads: string}>>
     */
    private const GUARDED = [
        'api/v3/Controllers/PpcAccountPixelsController.php' => [
            'owned through its parent | 202_ppc_account_pixels | pixel_id = ? AND ppc_account_id = ?' => ['calls' => ['account'], 'reads' => '202_ppc_accounts'],
            'owned through its parent | 202_ppc_account_pixels | pixel_id = ? AND ppc_account_id = ? #2' => ['calls' => ['account'], 'reads' => '202_ppc_accounts'],
        ],
        'api/v3/Controllers/PpcNetworkVariablesController.php' => [
            'owned through its parent | 202_ppc_network_variables | ppc_variable_id = ? AND ppc_network_id = ? AND deleted = 0' => ['calls' => ['network'], 'reads' => '202_ppc_networks'],
            'owned through its parent | 202_ppc_network_variables | ppc_variable_id = ? AND ppc_network_id = ? AND deleted = 0 #2' => ['calls' => ['network'], 'reads' => '202_ppc_networks'],
        ],
        'api/v3/Controllers/RotatorsController.php' => [
            'owned through its parent | 202_rotator_rules_criteria | rotator_id = ?' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules_redirects | rule_id IN ( SELECT id FROM 202_rotator_rules WHERE rotator_id = ? )' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules | rotator_id = ?' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules_criteria | rule_id IN ( {$in} )' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules_redirects | rule_id IN ( {$in} )' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules | rotator_id = ? AND id IN ( {$in} )' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules | id = ? AND rotator_id = ?' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules_criteria | id = ? AND rule_id = ?' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules_redirects | id = ? AND rule_id = ?' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules_criteria | rule_id = ?' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules_redirects | rule_id = ?' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules | id = ? AND rotator_id = ? #2' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules_criteria | rule_id = ? #2' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules_redirects | rule_id = ? #2' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
            'owned through its parent | 202_rotator_rules | id = ? AND rotator_id = ? #3' => ['calls' => ['lockRotator'], 'reads' => '202_rotators'],
        ],
    ];

    /**
     * The generic writers' statements, whose table and tie column are named
     * at runtime: testTheGenericWritersTieEveryTableTheyWrite() reads every
     * table and column they can be given.
     *
     * @var array<string, array<string, string>>
     */
    private const GENERIC = [
        '202-config/Crud/MysqlCrudRepository.php' => [
            'table built at runtime | {{$c->table}} | UPDATE {{$c->table}} SET {implode(\', \', $sets)} WHERE {{$c->primaryKey}} = ? AND {{$c->userIdColumn}} = ?' => 'testTheGenericWritersTieEveryTableTheyWrite',
            'table built at runtime | {{$c->table}} | DELETE FROM {{$c->table}} WHERE {{$c->primaryKey}} = ? AND {{$c->userIdColumn}} = ?' => 'testTheGenericWritersTieEveryTableTheyWrite',
            'table built at runtime | {{$c->table}} | UPDATE {{$c->table}} SET {{$c->deletedColumn}} = 1 WHERE {{$c->primaryKey}} = ? AND {{$c->userIdColumn}}' => 'testTheGenericWritersTieEveryTableTheyWrite',
        ],
        'api/v3/Controller.php' => [
            'table built at runtime | {$this->tableName()} | UPDATE {$this->tableName()} SET {implode(\', \', $sets)} WHERE {implode(\' AND \', $where)}' => 'testTheGenericWritersTieEveryTableTheyWrite',
            'table built at runtime | {$this->tableName()} | UPDATE {$this->tableName()} SET {$this->deletedColumn()} = 1 WHERE {implode(\' AND \', $where)}' => 'testTheGenericWritersTieEveryTableTheyWrite',
            'table built at runtime | {$this->tableName()} | DELETE FROM {$this->tableName()} WHERE {implode(\' AND \', $where)}' => 'testTheGenericWritersTieEveryTableTheyWrite',
        ],
    ];

    /**
     * Writes in a class a reviewer has read and found reach only the
     * account's rows, or rows no account owns, for the reason given: the
     * install's own work (cron runs, queue workers, retention), a row a
     * credential found, or ids a check of another class tied first. Nothing
     * here is verified by a machine: the reason is a reviewer's claim, the
     * list may only shrink (REVIEWED_CEILING), and a new write is tied or
     * guarded instead.
     *
     * @var array<string, array<string, string>>
     */
    private const REVIEWED = [
        '202-config/Attribution/AttributionRollup.php' => [
            'no user_id tie | 202_attribution_rollup_dirty_clicks | dirty_id IN ( {self::intList(array_map(static fn (array $r): int => (int) $r[\'dirty_id\'], $rows))} )' => self::R_WORKER,
            'no user_id tie | 202_attribution_rollup_dirty | dirty_id = ?' => self::R_WORKER,
            'no user_id tie | 202_attribution_rollup_dirty | dirty_id IN ( {self::intList($chunk)} )' => self::R_WORKER,
        ],
        '202-config/Attribution/AttributionStore.php' => [
            'table built at runtime | {$table} | DELETE FROM {$table} WHERE conv_id = ?' => 'the worker\'s journey and credit tables of a conversion it claimed from its queue (AttributionWorker); the table is one of the class\'s own list',
        ],
        '202-config/Attribution/AttributionWorker.php' => [
            'no user_id tie | 202_identity_merges | merge_id = ? AND requeued_at IS NULL' => self::R_WORKER,
            'no user_id tie | 202_identity_merges | merge_id = ? AND requeued_at IS NULL #2' => self::R_WORKER,
            'no user_id tie | 202_attribution_models | model_id = ? AND recompute_requested_at = ?' => self::R_WORKER,
            'no user_id tie | 202_attribution_models | model_id = ? AND recompute_requested_at = ? #2' => self::R_WORKER,
            'no user_id tie | 202_attribution_models | model_id = ? AND recompute_requested_at = ? #3' => self::R_WORKER,
            'owned through its parent | 202_attribution_pending | conv_id = ? AND enqueue_seq = ?' => self::R_WORKER,
            'owned through its parent | 202_attribution_pending | conv_id = ? AND enqueue_seq = ? #2' => self::R_WORKER,
        ],
        '202-config/Attribution/ConversionBackfill.php' => [
            'owned through its parent | 202_attribution_backfill | backfill_id = 1 AND next_click_id = ?' => 'the install\'s one backfill cursor row (backfill_id = 1), moved by the cron run',
        ],
        '202-config/Attribution/ExportStore.php' => [
            'no user_id tie | 202_attribution_exports | export_id = ? AND status = \'pending\' AND queued_at <= ?' => 'the export runner\'s (cron) claim and outcome of an export its due() read chose',
            'no user_id tie | 202_attribution_exports | status = \'running\' AND started_at < ?' => 'the export runner\'s (cron) claim and outcome of an export its due() read chose',
            'no user_id tie | 202_attribution_exports | export_id = ? AND status = \'running\'' => 'the export runner\'s (cron) claim and outcome of an export its due() read chose',
            'no user_id tie | 202_attribution_exports | export_id = ? AND status = \'running\' #2' => 'the export runner\'s (cron) claim and outcome of an export its due() read chose',
            'no user_id tie | 202_attribution_exports | export_id = ? AND status = \'running\' #3' => 'the export runner\'s (cron) claim and outcome of an export its due() read chose',
            'no user_id tie | 202_attribution_exports | export_id = ? AND status = \'running\' #4' => 'the export runner\'s (cron) claim and outcome of an export its due() read chose',
        ],
        '202-config/Click/ClickRetention.php' => [
            'table built at runtime | {{$table}} | DELETE FROM {{$table}} WHERE click_id IN ( {$in} )' => self::R_RETENTION,
        ],
        '202-config/Click/Filter/RepeatVisitCleanup.php' => [
            'no user_id tie | 202_last_ips | l.time < {$day} AND ( s.user_id IS NULL OR s.repeat_visits = 0 OR s.repeat_window <= 86400 OR l.time < {$now} - s.repeat_window )' => self::R_RETENTION,
        ],
        '202-config/Click/MysqlClickRepository.php' => [
            'no user_id tie | 202_clicks | click_id = ?' => self::R_CLICK_LOCK,
            'no user_id tie | 202_clicks | c.click_id = ? AND c.click_lead = 0' => self::R_CLICK_LOCK,
            'owned through its parent | 202_clicks_record | click_id = ?' => self::R_CLICK_LOCK,
            'owned through its parent | 202_clicks_record | click_id = ? #2' => self::R_CLICK_LOCK,
            'owned through its parent | 202_clicks_site | click_id = ?' => self::R_CLICK_LOCK,
            'owned through its parent | 202_clicks_tracking | click_id = ?' => self::R_CLICK_LOCK,
            'owned through its parent | 202_clicks_advance | click_id = ?' => self::R_CLICK_LOCK,
            'no user_id tie | 202_clicks | click_id = ? AND click_time BETWEEN ? AND ?' => 'ReClickBackfill\'s repair (cron) of a click id its own cursor read chose',
            'no user_id tie | 202_clicks | click_id = ? AND click_time BETWEEN ? AND ? #2' => 'ReClickBackfill\'s repair (cron) of a click id its own cursor read chose',
            'no user_id tie | 202_clicks_spy | click_id = ? AND click_time BETWEEN ? AND ?' => 'ReClickBackfill\'s repair (cron) of a click id its own cursor read chose',
            'no user_id tie | 202_clicks_spy | click_id = ? AND click_time BETWEEN ? AND ? #2' => 'ReClickBackfill\'s repair (cron) of a click id its own cursor read chose',
            'owned through its parent | 202_clicks_filter_reason | click_id = ? AND filter_reason = ?' => self::R_CLICK_LOCK,
            'owned through its parent | 202_clicks_advance | click_id = ? #2' => 'the click record_simple/record_adv just wrote (the template is the click being recorded)',
        ],
        '202-config/Click/PurLinkExitRetention.php' => [
            'no user_id tie | 202_purlink_exit_order | expires_at <= ?' => self::R_RETENTION,
            'no user_id tie | 202_purlink_pixel_fires | expires_at <= ?' => self::R_RETENTION,
        ],
        '202-config/Click/ReClickBackfill.php' => [
            'owned through its parent | 202_reclick_backfill | backfill_id = 1 AND next_click_id = ?' => 'the install\'s one backfill cursor row (backfill_id = 1), moved by the cron run',
            'owned through its parent | 202_reclick_backfill | backfill_id = 1' => 'the install\'s one backfill cursor row (backfill_id = 1), moved by the cron run',
        ],
        '202-config/Click/UnrecordedSourceVariables.php' => [
            'owned through its parent | 202_change_log | entity = ? AND changed_at < ?' => self::R_RETENTION,
        ],
        '202-config/Conversion/Ledger/MysqlConversionLedger.php' => [
            'no user_id tie | 202_conversion_logs | conv_id = ?' => self::R_CLICK_LOCK,
            'no user_id tie | 202_conversion_logs | click_id = ? AND superseded_reason = \'pre_ledger\' AND superseded_by IS NULL' => self::R_CLICK_LOCK,
            'no user_id tie | 202_conversion_logs | conv_id = ? #2' => self::R_CLICK_LOCK,
            'table built at runtime | {$table} | UPDATE {$table} SET click_lead = 1 , click_payout = ? WHERE click_id' => self::R_CLICK_LOCK,
            'table built at runtime | {$table} | UPDATE {$table} SET click_lead = 0 WHERE click_id = ?' => self::R_CLICK_LOCK,
            'no user_id tie | 202_conversion_logs | conv_id = ? #3' => self::R_CLICK_LOCK,
        ],
        '202-config/Conversion/Ledger/RowCurrency.php' => [
            'no WHERE read | 202_conversion_logs | UPDATE 202_conversion_logs SET currency = ? , currency_basis = IF ( currency_basis = 3 , 3 , 2 ) {$where} LIMIT {self::BATCH}' => 'tied: $where, built a few lines above, starts `WHERE user_id = ?` (bound to the account first); a variable the scan does not follow',
        ],
        '202-config/Conversion/MysqlConversionRepository.php' => [
            'no user_id tie | 202_conversion_logs | conv_id = ?' => 'the row recordLocked() just inserted, by its insert id, under the click lock',
            'no user_id tie | 202_conversion_logs | conv_id = ? AND deleted = 1 AND superseded_reason IN ( \'replay\' , \'reevaluation\' ) AND superseded_by IS NULL' => 'a row read just before under the click lock `WHERE … AND user_id = ? … FOR UPDATE`, by its id',
            'no user_id tie | 202_conversion_logs | conv_id = ? AND deleted = 0 AND superseded_reason IN ( \'replay\' , \'reevaluation\' )' => 'a row read just before under the click lock `WHERE … AND user_id = ? … FOR UPDATE`, by its id',
            'no user_id tie | 202_conversion_logs | conv_id = ? #2' => 'the rows read just before `WHERE click_id = ? AND user_id = ? … FOR UPDATE`, by their ids',
        ],
        '202-config/Conversion/RevenueUploadImporter.php' => [
            'no user_id tie | 202_conversion_uploads | batch_id = ?' => 'the upload batch this import inserted for the account, by its insert id',
        ],
        '202-config/DataEngine/ClickRollupSql.php' => [
            'multi-table DELETE | 202_clicks | DELETE de FROM 202_clicks AS 2c STRAIGHT_JOIN 202_click_latest_visits AS lv ON (' => 'the report rollup\'s own rows (202_dataengine) of the clicks the rollup is rebuilding, joined by click id; the rollup is install-wide work',
            'multi-table DELETE | 202_click_latest_visits | DELETE de FROM 202_click_latest_visits AS lv STRAIGHT_JOIN 202_clicks AS 2c FORCE INDEX' => 'the report rollup\'s own rows (202_dataengine) of the clicks the rollup is rebuilding, joined by click id; the rollup is install-wide work',
        ],
        '202-config/Destinations/DestinationQueue.php' => [
            'no user_id tie | 202_destination_deliveries | delivery_id = ? AND status = \'pending\' AND attempts = ?' => 'the queue worker\'s claim of a delivery its due() read chose',
            'no user_id tie | 202_destination_deliveries | delivery_id = ? AND status = \'pending\'' => 'the queue worker\'s own delivery, claimed above in sendDue()',
            'no user_id tie | 202_destination_deliveries | delivery_id = ?' => 'the queue worker\'s own delivery, claimed above in sendDue()',
        ],
        '202-config/Fraud/IpqsScoring.php' => [
            'no user_id tie | 202_ipqs_scores | scored_at < ?' => self::R_RETENTION,
        ],
        '202-config/GeoIp/RefreshStore.php' => [
            'owned through its parent | 202_geoip_refresh | refresh_id = ?' => 'the install\'s one GeoIP refresh row, cleared from Administration by an admin',
        ],
        '202-config/Goals/GoalEngine.php' => [
            'no user_id tie | 202_goal_subjects | subject_type = ? AND subject_id = ?' => 'the goal engine\'s rows of the subject it holds locked, for the account it was handed with the conversion (ingest/reevaluate run inside the conversion writer\'s click lock or the account\'s own reevaluation)',
            'no user_id tie | 202_goal_subjects | subject_type = ? AND subject_id = ? #2' => 'the goal engine\'s rows of the subject it holds locked, for the account it was handed with the conversion (ingest/reevaluate run inside the conversion writer\'s click lock or the account\'s own reevaluation)',
            'no user_id tie | 202_goal_outcomes | outcome_id = ? AND superseded_at IS NULL' => 'the goal engine\'s rows of the subject it holds locked, for the account it was handed with the conversion (ingest/reevaluate run inside the conversion writer\'s click lock or the account\'s own reevaluation)',
            'no user_id tie | 202_goal_outcomes | outcome_id = ? AND superseded_at IS NOT NULL' => 'the goal engine\'s rows of the subject it holds locked, for the account it was handed with the conversion (ingest/reevaluate run inside the conversion writer\'s click lock or the account\'s own reevaluation)',
            'no user_id tie | 202_goal_outcomes | outcome_id = ?' => 'the goal engine\'s rows of the subject it holds locked, for the account it was handed with the conversion (ingest/reevaluate run inside the conversion writer\'s click lock or the account\'s own reevaluation)',
            'no user_id tie | 202_goal_outcomes | outcome_id = ? #2' => 'the goal engine\'s rows of the subject it holds locked, for the account it was handed with the conversion (ingest/reevaluate run inside the conversion writer\'s click lock or the account\'s own reevaluation)',
            'no user_id tie | 202_goal_outcomes | outcome_id = ? #3' => 'the goal engine\'s rows of the subject it holds locked, for the account it was handed with the conversion (ingest/reevaluate run inside the conversion writer\'s click lock or the account\'s own reevaluation)',
            'no user_id tie | 202_goal_events | subject_type = ? AND subject_id = ? AND event_id = ?' => 'the goal engine\'s rows of the subject it holds locked, for the account it was handed with the conversion (ingest/reevaluate run inside the conversion writer\'s click lock or the account\'s own reevaluation)',
            'no user_id tie | 202_goal_progress | subject_type = ? AND subject_id = ?' => 'the goal engine\'s rows of the subject it holds locked, for the account it was handed with the conversion (ingest/reevaluate run inside the conversion writer\'s click lock or the account\'s own reevaluation)',
            'no user_id tie | 202_goal_progress | subject_type = ? AND subject_id = ? AND goal_id = ? {($keep === [] ? \'\' : \' AND goal_version NOT IN (\'.implode(\',\', array_fill(0, count($keep), \'?\')).\')\')}' => 'the goal engine\'s rows of the subject it holds locked, for the account it was handed with the conversion (ingest/reevaluate run inside the conversion writer\'s click lock or the account\'s own reevaluation)',
        ],
        '202-config/Identity/IdentityGraph.php' => [
            'owned through its parent | 202_identity_observations | click_id = ? AND signal_type IN ( ? , ? )' => 'a click its caller has shown is the account\'s (MysqlClickRepository under the click lock, ClickIdentity on the click path)',
        ],
        '202-config/Ltv/MysqlCompanyRepository.php' => [
            'OR in the WHERE | 202_customers | c.company IS NULL OR c.company <> co.name' => 'LtvMaintenance (cron): every account\'s customers, each from its own company (`co.user_id = c.user_id` in the join)',
        ],
        '202-config/Ltv/MysqlCustomerCrmRepository.php' => [
            'table built at runtime | {{$table}} | UPDATE {{$table}} SET {{$column}} = ? WHERE {{$column}} = ? {($hasUserId ? \' AND user_id = ?\' : \'\')}' => 'tied where the table has user_id; 202_clicks_tracking by the merge\'s source customer, which the merge locked first `WHERE customer_id IN (?, ?) AND user_id = ?`',
            'table built at runtime | {{$table}} | DELETE FROM {{$table}} WHERE customer_id = ? AND user_id = ?' => 'tied (`customer_id = ? AND user_id = ?`) on each of ERASED_TABLES, after customerBelongsToUser()',
            'owned through its parent | 202_identity_observations | signal_type = ? AND signal_hash = ?' => 'observations by a signal hash keyed by the account\'s own secret, read from its own aliases (`user_id = ?`) just above',
        ],
        '202-config/Ltv/MysqlPersonalizationRepository.php' => [
            'no user_id tie | 202_personalization_tokens | p13n_id = ? AND redeemed_at IS NULL' => self::R_CREDENTIAL,
            'no user_id tie | 202_personalization_tokens | replay_until < ?' => self::R_RETENTION,
        ],
        '202-config/Ltv/MysqlSubscriptionRepository.php' => [
            'no user_id tie | 202_subscriptions | subscription_id = ?' => 'the subscription read just before `WHERE user_id = ? AND external_sub_id = ? … FOR UPDATE`, by its id',
            'no user_id tie | 202_subscriptions | subscription_id = ? #2' => 'the subscription read just before `WHERE user_id = ? AND external_sub_id = ? … FOR UPDATE`, by its id',
        ],
        '202-config/Ltv/MysqlWebhookRepository.php' => [
            'no user_id tie | 202_ltv_webhook_deliveries | delivery_id = ? AND status = \'pending\' AND next_attempt_at <= ?' => 'the webhook dispatcher\'s (cron) claim and outcome of a delivery its due read chose',
            'no user_id tie | 202_ltv_webhook_deliveries | status = \'pending\' AND next_attempt_at <= ? AND created_at < ?' => 'the webhook dispatcher\'s (cron) claim and outcome of a delivery its due read chose',
            'no user_id tie | 202_ltv_webhook_deliveries | delivery_id = ?' => 'the webhook dispatcher\'s (cron) claim and outcome of a delivery its due read chose',
            'no user_id tie | 202_ltv_webhook_deliveries | delivery_id = ? #2' => 'the webhook dispatcher\'s (cron) claim and outcome of a delivery its due read chose',
            'no user_id tie | 202_ltv_webhooks | webhook_id = ? AND status = \'active\'' => 'the webhook dispatcher\'s (cron) claim and outcome of a delivery its due read chose',
            'no user_id tie | 202_ltv_webhook_deliveries | d.delivery_id = ? AND d.status = \'pending\' AND w.status = \'dead\'' => 'the webhook dispatcher\'s (cron) claim and outcome of a delivery its due read chose',
        ],
        '202-config/Messaging/MessagingService.class.php' => [
            'no user_id tie | 202_messaging_events | id = ?' => 'the messaging sync\'s outcome of an event it read for this service\'s account to send',
            'no user_id tie | 202_messaging_events | id = ? #2' => 'the messaging sync\'s outcome of an event it read for this service\'s account to send',
        ],
        '202-config/Notifications/NotificationOutbox.php' => [
            'no user_id tie | 202_notification_pending | conv_id = ? AND pixel_id = ? AND destination = ? AND kind = \'reached\' AND status = \'pending\' AND attempts = 0' => 'notifications of a conversion its caller holds under the click lock for the account (onReplaced(userId, …)), read just above by conv_id',
            'no user_id tie | 202_notification_pending | notification_id = ? AND status = \'cancelled\' AND attempts = 0' => 'a notification read just above for the revived conversion, which its caller holds under the click lock',
            'no user_id tie | 202_notification_pending | conv_id = ? AND pixel_id = ? AND destination = ? AND kind = \'reached\' AND status = \'pending\' AND attempts = 0 #2' => 'notifications of a conversion its caller holds under the click lock for the account, read just above by conv_id',
            'no user_id tie | 202_notification_pending | notification_id = ? AND status = \'pending\' AND attempts = ?' => 'the outbox worker\'s hold (consent withheld) of a notification its due read chose',
            'no user_id tie | 202_notification_pending | notification_id = ? AND status = \'pending\' AND attempts = ? #2' => 'the outbox worker\'s claim of a notification its due read chose',
            'no user_id tie | 202_notification_pending | notification_id = ?' => 'the outbox worker\'s own notification, claimed above',
            'no user_id tie | 202_notification_pending | notification_id = ? #2' => 'the outbox worker\'s own notification, claimed above',
            'no user_id tie | 202_notification_pending | notification_id = ? #3' => 'a notification read just above for a conversion its caller holds under the click lock',
        ],
        '202-config/OfflineConversion/FeedStore.php' => [
            'no user_id tie | 202_offline_conversion_feeds | feed_id = ?' => self::R_CREDENTIAL,
        ],
        '202-config/OfflineConversion/LegacyGoogleFeedFetches.php' => [
            'owned through its parent | 202_legacy_google_feed_fetches | aff_campaign_id = ? AND conversion_name = ?' => 'the old Google feed fetch (LegacyGoogleFeed): a campaign the fetch\'s own schedule key named',
            'owned through its parent | 202_legacy_google_feed_fetches | last_fetched_at < ?' => self::R_RETENTION,
            'owned through its parent | 202_legacy_google_feed_fetches | aff_campaign_id = ? AND last_fetched_at < ?' => 'bounding the rows kept for the campaign noteFetch() is noting',
            'owned through its parent | 202_legacy_google_feed_fetches | fetch_id = ?' => 'a row is what a fetch of the retired feed said, not an account\'s (the class says why): find() first shows it is one this account is shown, its campaign\'s or every account\'s (ac=--)',
        ],
        '202-config/PaymentNotification/HeldNotifications.php' => [
            'no user_id tie | 202_held_payment_notifications | received_at < ?' => self::R_RETENTION,
        ],
        '202-config/Rotator/CriterionRepair.php' => [
            'owned through its parent | 202_rotator_rules_criteria | id = ? AND CAST ( value AS BINARY ) = CAST ( ? AS BINARY )' => 'the 1.9.81 upgrade\'s repair (functions-upgrade.php, before any account signs in): every account\'s criteria, each by the id its own read of the table chose, rewritten only where the value is still the one read',
        ],
        '202-config/Setup/AccountPixels.php' => [
            'owned through its parent | 202_ppc_account_pixels | pixel_id = ? AND ppc_account_id = ?' => 'save()\'s caller has checked the account is the user\'s: tracking202/setup/ppc_accounts.php reads it `WHERE user_id = … AND ppc_account_id = …` and stops with wrong_user first; a pixel is written only by its id within that account',
            'owned through its parent | 202_ppc_account_pixels | pixel_id = ? AND ppc_account_id = ? #2' => 'save()\'s caller has checked the account is the user\'s: tracking202/setup/ppc_accounts.php reads it `WHERE user_id = … AND ppc_account_id = …` and stops with wrong_user first; a pixel is written only by its id within that account',
        ],
        '202-config/User/ApiKeyStore.php' => [
            'no user_id tie | 202_api_keys | ends_at IS NOT NULL AND ends_at <= ?' => self::R_RETENTION,
        ],
        '202-config/DataEngine/DirtyHours.php' => [
            'no user_id tie | 202_dirty_hours | id = ? AND {DirtyHourState::IS_QUEUED}' => 'the data engine\'s (cron) own queue and job rows',
            'no user_id tie | 202_dirty_hours | {DirtyHourState::IS_TAKEN}' => 'the data engine\'s (cron) own queue and job rows',
            'no user_id tie | 202_dirty_hours | id = ? AND {DirtyHourState::IS_TAKEN}' => 'the data engine\'s (cron) own queue and job rows',
        ],
        '202-config/DataEngine/RebuildWindows.php' => [
            'owned through its parent | 202_dataengine_job | time_from = ? AND time_to = ? AND {RebuildWindowState::IS_PENDING}' => 'the data engine\'s (cron) own queue and job rows',
            'owned through its parent | 202_dataengine_job | time_from = ? AND time_to = ? AND {RebuildWindowState::IS_PROCESSING}' => 'the data engine\'s (cron) own queue and job rows',
            'owned through its parent | 202_dataengine_job | time_from = ? AND time_to = ? AND {RebuildWindowState::IS_PROCESSING} #2' => 'the data engine\'s (cron) own queue and job rows',
        ],
        '202-config/functions-auth.php' => [
            'no user_id tie | 202_auth_keys | expires < UNIX_TIMESTAMP ( )' => self::R_RETENTION,
        ],
        '202-config/migrations/run_ltv_backfill.php' => [
            'no user_id tie | 202_conversion_logs | conv_id = ? AND customer_id IS NULL' => 'a one-off command-line backfill over every account\'s rows',
        ],
        '202-config/sessions.php' => [
            'owned through its parent | 202_sessions | session_id = \'{$id}\'' => 'the session handler\'s own row, by the id PHP hands it, or expired rows by time',
            'owned through its parent | 202_sessions | expires < UNIX_TIMESTAMP ( )' => 'the session handler\'s own row, by the id PHP hands it, or expired rows by time',
        ],
        '202-config/static-endpoint-helpers.php' => [
            'no user_id tie | 202_clicks | click_id = ?' => 'the click the conversion writer locked (p202RecordConversion()\'s clickSideUpdate is called with the locked click), the account the click\'s own',
            'no user_id tie | 202_clicks | click_id = ? #2' => 'the click the conversion writer locked (p202RecordConversion()\'s clickSideUpdate is called with the locked click), the account the click\'s own',
            'no user_id tie | 202_clicks_spy | click_id = ?' => 'the click the conversion writer locked (p202RecordConversion()\'s clickSideUpdate is called with the locked click), the account the click\'s own',
            'no user_id tie | 202_clicks_spy | click_id = ? #2' => 'the click the conversion writer locked (p202RecordConversion()\'s clickSideUpdate is called with the locked click), the account the click\'s own',
        ],
        'api/v3/Apps/Android/InstallEventsIntake.php' => [
            'no user_id tie | 202_app_installs | install_row_id = ? AND has_events = 0' => self::R_INSTALL_ROW,
        ],
        'api/v3/Apps/Android/InstallIntake.php' => [
            'no user_id tie | 202_app_installs | install_row_id = ?' => self::R_INSTALL_ROW,
            'no user_id tie | 202_app_installs | install_row_id = ? #2' => self::R_INSTALL_ROW,
            'no user_id tie | 202_app_installs | install_row_id = ? #3' => self::R_INSTALL_ROW,
            'no user_id tie | 202_app_installs | install_row_id = ? #4' => self::R_INSTALL_ROW,
            'no user_id tie | 202_app_installs | install_row_id = ? #5' => self::R_INSTALL_ROW,
        ],
        'api/v3/Apps/Android/Integrity/IntegrityVerifier.php' => [
            'no user_id tie | 202_app_installs | install_row_id = ? AND integrity_state = \'pending\' AND integrity_attempts = ? AND integrity_next_at <= ?' => self::R_INSTALL_ROW,
            'no user_id tie | 202_app_installs | install_row_id = ? AND integrity_state = \'pending\' AND integrity_attempts = ?' => self::R_INSTALL_ROW,
            'no user_id tie | 202_app_installs | install_row_id = ?' => self::R_INSTALL_ROW,
        ],
        'api/v3/Apps/Android/Integrity/UnverifiableInstalls.php' => [
            'no user_id tie | 202_app_installs | match_state = \'pending_integrity\' AND {$scope}' => '$scope is one of the callers\' fixed clauses: `registration_id = ? AND user_id = ?` (a registration\'s own) or the cron sweep\'s install ids',
            'no user_id tie | 202_app_installs | integrity_state = \'pending\' AND {$scope}' => '$scope is one of the callers\' fixed clauses: `registration_id = ? AND user_id = ?` (a registration\'s own) or the cron sweep\'s install ids',
        ],
        'api/v3/Apps/Android/OrphanedPendingClicks.php' => [
            'no user_id tie | 202_app_installs | match_state = \'pending_click\' AND {$scope}' => '$scope is one of the callers\' fixed clauses: `registration_id = ? AND user_id = ?` (a registration\'s own) or the cron sweep\'s install ids',
        ],
        'api/v3/Apps/Android/PendingClickSettler.php' => [
            'no user_id tie | 202_app_installs | install_row_id = ?' => self::R_INSTALL_ROW,
            'no user_id tie | 202_app_installs | install_row_id = ? #2' => self::R_INSTALL_ROW,
        ],
        'api/v3/Apps/AppDataPurge.php' => [
            'no user_id tie | 202_aff_campaigns | app_registration_id IN ( SELECT registration_id FROM 202_app_registrations WHERE user_id = ? )' => 'the account\'s purge: campaigns whose registration is the account\'s (`… WHERE user_id = ?` in the subquery)',
        ],
        'api/v3/Apps/AppRetention.php' => [
            'table built at runtime | {$class->table} | DELETE FROM {$class->table} WHERE {$class->predicate} AND {$class->timeColumn} < ? LIMIT {self::PRUNE_BATCH_LIMIT}' => self::R_RETENTION,
        ],
        'api/v3/Controller.php' => [
            'table built at runtime | {$this->tableName()} | UPDATE {$this->tableName()} SET {$column} = ? WHERE {$this->primaryKey()} = ?' => 'the row afterCreate() just inserted, by its insert id',
        ],
        'api/v3/Controllers/AppRegistrationsController.php' => [
            'no user_id tie | 202_app_postbacks | app_id = ? AND registration_id IS NULL AND ( user_id = 0 OR user_id = ? )' => 'a claim of the app\'s postbacks nobody owns (user_id = 0) or the account owns; another account\'s are never matched',
        ],
        'api/v3/Support/AiConnect.php' => [
            'no user_id tie | 202_api_keys | {$keyMatch}' => self::R_CREDENTIAL,
        ],
    ];

    private const REVIEWED_CEILING = 135;

    private const STATEMENT_ENDS = ['ORDER', 'LIMIT'];

    private const JOIN_WORDS = ['JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'STRAIGHT_JOIN', 'USING'];

    /** @var array<string, true>|null */
    private static ?array $schemaTables = null;

    /** @var array<string, list<array{line: int, key: string, at: int}>>|null */
    private static ?array $tree = null;

    /** @return array<string, true> lower-case name => true, for every table the schema defines */
    private static function schemaTables(): array
    {
        if (self::$schemaTables !== null) {
            return self::$schemaTables;
        }
        $root = dirname(__DIR__, 2);
        $files = glob($root . '/202-config/Database/Tables/*Tables.php');
        if ($files === false || $files === []) {
            throw new \RuntimeException('no table definitions under 202-config/Database/Tables');
        }
        $tables = [];
        foreach ($files as $file) {
            $class = 'Prosper202\\Database\\Tables\\' . basename($file, '.php');
            foreach ($class::getDefinitions() as $definition) {
                $tables[strtolower($definition->tableName)] = true;
            }
        }

        return self::$schemaTables = $tables;
    }

    /**
     * Every UPDATE and DELETE in one PHP source whose WHERE does not tie its
     * row to the account, in source order, as "kind | table | condition".
     *
     * @return list<array{line: int, key: string, at: int}>
     */
    public static function scan(string $source): array
    {
        $tokens = \PhpToken::tokenize($source);
        $chains = array_map(
            static fn (array $chain): array => self::withConstants($source, $chain),
            SqlJoinScan::chains($source)
        );
        $byStart = [];
        foreach ($chains as $chain) {
            $byStart[$chain['start']] = $chain;
        }
        $bodies = LocalStringValue::bodies($tokens);
        $out = [];
        foreach ($chains as $chain) {
            if (self::target(SqlJoinScan::sqlTokens($chain['text'], $chain['holes'])) === null) {
                continue;
            }
            $chain = self::withLocals($tokens, $bodies, $byStart, $chain);
            $holes = $chain['holes'];
            $sql = SqlJoinScan::sqlTokens($chain['text'], $holes);
            $target = self::target($sql);
            if ($target === null) {
                continue;
            }
            if (!isset($target['refused']) && !isset($target['multi']) && self::whereAt($sql, $target['next']) === null) {
                [$text, $holes] = self::assembled($tokens, $byStart, $chain);
                $sql = SqlJoinScan::sqlTokens($text, $holes);
            }
            $finding = self::finding($sql, $target, $holes);
            if ($finding !== null) {
                $out[] = ['line' => $chain['line'], 'key' => $finding, 'at' => $chain['start']];
            }
        }

        return $out;
    }

    /**
     * The chain with each operand that is a class constant holding a name
     * (`TableRegistry::CLICKS`, `self::TABLE`) read as its value, as PHP
     * assembles it; any other constant stays a hole.
     *
     * @param array{line: int, text: string, holes: list<string>, start: int, end: int} $chain
     * @return array{line: int, text: string, holes: list<string>, start: int, end: int}
     */
    private static function withConstants(string $source, array $chain): array
    {
        foreach ($chain['holes'] as $index => $hole) {
            $value = AssembledStrings::constantAt($source, trim($hole, '{} '), $chain['start']);
            if (is_string($value) && preg_match('/^[A-Za-z0-9_]+$/', $value) === 1) {
                $chain['text'] = str_replace("\x01{$index}\x02", $value, $chain['text']);
            }
        }

        return $chain;
    }

    /**
     * The chain with each operand that is a bare local variable read as the
     * string it holds, where LocalStringValue can vouch for it; any other
     * variable stays a hole.
     *
     * @param list<\PhpToken> $tokens
     * @param list<array{fn: int, open: int, close: int}> $bodies
     * @param array<int, array{line: int, text: string, holes: list<string>, start: int, end: int}> $byStart
     * @param array{line: int, text: string, holes: list<string>, start: int, end: int} $chain
     * @return array{line: int, text: string, holes: list<string>, start: int, end: int}
     */
    private static function withLocals(array $tokens, array $bodies, array $byStart, array $chain): array
    {
        $holes = $chain['holes'];
        foreach ($chain['holes'] as $index => $hole) {
            if (preg_match('/^\{?(\$[A-Za-z_]\w*)\}?$/', $hole, $m) !== 1) {
                continue;
            }
            $value = null;
            for ($k = $chain['start']; $k <= $chain['end']; $k++) {
                if ($tokens[$k]->id !== T_VARIABLE || $tokens[$k]->text !== $m[1]) {
                    continue;
                }
                $read = LocalStringValue::at($tokens, $bodies, $byStart, $k);
                if (!isset($read['chain']) || ($value !== null && $value['start'] !== $read['chain']['start'])) {
                    $value = null;
                    break;
                }
                $value = $read['chain'];
            }
            if ($value === null) {
                continue;
            }
            $text = '';
            SqlJoinScan::append($text, $holes, $value);
            $chain['text'] = str_replace("\x01{$index}\x02", $text, $chain['text']);
        }
        $chain['holes'] = $holes;

        return $chain;
    }

    /**
     * The table an UPDATE or DELETE writes, or null when the chain is not
     * one (a label such as "Update tracking link", a log line naming one).
     *
     * @param list<array{k: string, v: string, u: string}> $t
     * @return array{table: string, alias: string, next: int, refused?: string, multi?: int}|null
     */
    private static function target(array $t): ?array
    {
        if ($t === [] || $t[0]['k'] !== 'word' || !in_array($t[0]['u'], ['UPDATE', 'DELETE'], true)) {
            return null;
        }
        $verb = $t[0]['u'];
        $i = 1;
        while (self::isWord($t[$i] ?? null, ['LOW_PRIORITY', 'IGNORE', 'QUICK'])) {
            $i++;
        }
        if ($verb === 'DELETE') {
            if (!self::isWord($t[$i] ?? null, ['FROM'])) {
                // DELETE t FROM 202_x t …: refused when a table follows the FROM.
                for ($j = $i; $j < min(count($t), $i + 6); $j++) {
                    $after = $t[$j + 1] ?? null;
                    if (self::isWord($t[$j], ['FROM']) && $after !== null && self::isTable($after)) {
                        $table = strtolower($after['v']);

                        return ['table' => $table, 'alias' => '', 'next' => $j + 1, 'multi' => $i];
                    }
                }
                return null;
            }
            $i++;
        }
        $ref = $t[$i] ?? null;
        if ($ref === null) {
            return null;
        }
        if ($ref['k'] === 'hole') {
            $table = '{' . $ref['v'] . '}';

            return ['table' => $table, 'alias' => '', 'next' => $i + 1, 'refused' => 'table built at runtime'];
        }
        if (!in_array($ref['k'], ['word', 'name'], true)) {
            return null;
        }
        $table = strtolower($ref['v']);
        $known = self::isTable($ref);
        $j = $i + 1;
        $alias = '';
        $clause = array_merge(['SET', 'WHERE', 'ORDER', 'LIMIT'], self::JOIN_WORDS);
        if (self::isWord($t[$j] ?? null, ['AS']) && in_array($t[$j + 1]['k'] ?? '', ['word', 'name'], true)) {
            $alias = $t[$j + 1]['v'];
            $j += 2;
        } elseif (in_array($t[$j]['k'] ?? '', ['word', 'name'], true) && !in_array($t[$j]['u'], $clause, true)) {
            $alias = $t[$j]['v'];
            $j++;
        }
        $next = $t[$j] ?? null;
        $joins = $next !== null && (self::isPunct($next, ',') || self::isWord($next, self::JOIN_WORDS));
        if ($joins && $known) {
            return ['table' => $table, 'alias' => $alias, 'next' => $i, 'multi' => $i];
        }
        // A known table followed by nothing or by a hole is a statement whose
        // rest comes later (`.=`) or at runtime (`UPDATE t " . $set . " WHERE`).
        $rest = $next === null || $next['k'] === 'hole';
        $statement = $verb === 'DELETE'
            ? $known && ($rest || self::isWord($next, ['WHERE', 'ORDER', 'LIMIT']))
            : self::isWord($next, ['SET']) || ($known && $rest);
        if (!$statement) {
            return null;
        }
        if (!$known) {
            return ['table' => $table, 'alias' => $alias, 'next' => $j, 'refused' => 'table not in the schema'];
        }

        return ['table' => $table, 'alias' => $alias, 'next' => $j];
    }

    /**
     * @param array{k: string, v: string, u: string}|null $tok
     * @param list<string> $words upper case
     */
    private static function isWord(?array $tok, array $words): bool
    {
        return $tok !== null && $tok['k'] === 'word' && in_array($tok['u'], $words, true);
    }

    /** @param array{k: string, v: string, u: string} $tok */
    private static function isTable(array $tok): bool
    {
        return in_array($tok['k'], ['word', 'name'], true) && isset(self::schemaTables()[strtolower($tok['v'])]);
    }

    /** @param array{k: string, v: string, u: string} $tok */
    private static function isPunct(array $tok, string $char): bool
    {
        return $tok['k'] === 'punct' && $tok['v'] === $char;
    }

    /**
     * The index of the statement's own WHERE (outside every parenthesis),
     * from $from; null when it has none.
     *
     * @param list<array{k: string, v: string, u: string}> $t
     */
    private static function whereAt(array $t, int $from): ?int
    {
        $depth = 0;
        foreach ($t as $i => $tok) {
            if (self::isPunct($tok, '(')) {
                $depth++;
            } elseif (self::isPunct($tok, ')')) {
                $depth--;
            } elseif ($i >= $from && $depth === 0 && self::isWord($tok, ['WHERE'])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * A statement's text and holes: its chain, and when that is assigned to
     * a variable, every `.=` onto the variable after it, up to the first
     * other use of the variable (the call that runs it, or a reassignment)
     * or the next function. An assignment of an INSERT or REPLACE is passed
     * over: it is the same builder's other branch.
     *
     * @param list<\PhpToken> $tokens
     * @param array<int, array{line: int, text: string, holes: list<string>, start: int, end: int}> $byStart
     * @param array{line: int, text: string, holes: list<string>, start: int, end: int} $chain
     * @return array{0: string, 1: list<string>}
     */
    private static function assembled(array $tokens, array $byStart, array $chain): array
    {
        $text = $chain['text'];
        $holes = $chain['holes'];
        $eq = self::prevSignificant($tokens, $chain['start'] - 1);
        $var = $eq === null ? null : self::prevSignificant($tokens, $eq - 1);
        $before = $var === null ? null : self::prevSignificant($tokens, $var - 1);
        $assigned = $eq !== null && $tokens[$eq]->text === '=' && $var !== null && $tokens[$var]->id === T_VARIABLE
            && ($before === null || $tokens[$before]->id !== T_DOUBLE_COLON);
        if (!$assigned) {
            return [$text, $holes];
        }
        $name = $tokens[(int) $var]->text;
        $n = count($tokens);
        for ($k = $chain['end'] + 1; $k < $n; $k++) {
            $tok = $tokens[$k];
            if ($tok->id === T_FUNCTION || $tok->id === T_FN) {
                break;
            }
            if ($tok->id !== T_VARIABLE || $tok->text !== $name) {
                continue;
            }
            $op = self::nextSignificant($tokens, $k + 1);
            $value = $op === null ? null : self::nextSignificant($tokens, $op + 1);
            $operator = $op === null ? '' : $tokens[$op]->text;
            if ($operator === '.=') {
                if ($value !== null && isset($byStart[$value])) {
                    SqlJoinScan::append($text, $holes, $byStart[$value]);
                    $k = $byStart[$value]['end'];
                    continue;
                }
                SqlJoinScan::append($text, $holes, '.= at line ' . $tok->line);
                $k = (int) $op;
                continue;
            }
            if ($operator === '=' && $value !== null && isset($byStart[$value])) {
                $first = SqlJoinScan::sqlTokens($byStart[$value]['text'], $byStart[$value]['holes'])[0]['u'] ?? '';
                if ($first === 'INSERT' || $first === 'REPLACE') {
                    $k = $byStart[$value]['end'];
                    continue;
                }
            }
            break;
        }

        return [$text, $holes];
    }

    /**
     * Why a statement's WHERE does not tie its row to the account, as
     * "kind | table | condition"; null when it does.
     *
     * @param list<array{k: string, v: string, u: string}> $t
     * @param array{table: string, alias: string, next: int, refused?: string, multi?: int} $target
     * @param list<string> $holes
     */
    private static function finding(array $t, array $target, array $holes): ?string
    {
        $table = $target['table'];
        if (isset($target['refused'])) {
            return $target['refused'] . ' | ' . $table . ' | ' . self::render(array_slice($t, 0, 12), $holes);
        }
        if (isset($target['multi'])) {
            return self::multiTableFinding($t, $target['multi'], $target['next'], $holes);
        }
        $where = self::whereAt($t, $target['next']);
        if ($where === null) {
            return 'no WHERE read | ' . $table . ' | ' . self::render($t, $holes);
        }
        $condition = [];
        $depth = 0;
        for ($i = $where + 1, $n = count($t); $i < $n; $i++) {
            $tok = $t[$i];
            if (self::isPunct($tok, '(')) {
                $depth++;
            } elseif (self::isPunct($tok, ')')) {
                $depth--;
            }
            if ($depth === 0 && self::isWord($tok, self::STATEMENT_ENDS)) {
                break;
            }
            $condition[] = $tok;
        }
        $detail = self::render($condition, $holes);
        $conjuncts = self::conjuncts($condition);
        if ($conjuncts === null) {
            return 'OR in the WHERE | ' . $table . ' | ' . $detail;
        }
        foreach ($conjuncts as $conjunct) {
            if (self::isTie($conjunct, $table, $target['alias'], true)) {
                return null;
            }
        }
        foreach ($condition as $tok) {
            if ($tok['k'] === 'hole' && SqlJoinScan::namesAClause($tok['v'])) {
                return 'WHERE built at runtime | ' . $table . ' | ' . $detail;
            }
        }
        $kind = isset(AccountScopedJoinTest::accountTables()[$table]) ? 'no user_id tie' : self::THROUGH;

        return $kind . ' | ' . $table . ' | ' . $detail;
    }

    /**
     * The top-level conjuncts of a condition, a parenthesized conjunction
     * read as its own conjuncts (`(a AND b) AND c` is a, b, c); null when
     * an OR (or XOR, ||) is at its top. A BETWEEN's AND joins its bounds.
     *
     * @param list<array{k: string, v: string, u: string}> $condition
     * @return list<list<array{k: string, v: string, u: string}>>|null
     */
    private static function conjuncts(array $condition): ?array
    {
        $condition = self::unwrap($condition);
        $parts = [[]];
        $depth = 0;
        $between = false;
        foreach ($condition as $tok) {
            if (self::isPunct($tok, '(')) {
                $depth++;
            } elseif (self::isPunct($tok, ')')) {
                $depth--;
            }
            $or = self::isWord($tok, ['OR', 'XOR']) || ($tok['k'] === 'op' && $tok['v'] === '||');
            if ($depth === 0 && $or) {
                return null;
            }
            if ($depth === 0 && self::isWord($tok, ['BETWEEN'])) {
                $between = true;
            }
            $and = self::isWord($tok, ['AND']) || ($tok['k'] === 'op' && $tok['v'] === '&&');
            if ($depth === 0 && $and) {
                if ($between) {
                    $between = false;
                } else {
                    $parts[] = [];
                    continue;
                }
            }
            $parts[count($parts) - 1][] = $tok;
        }
        $out = [];
        foreach ($parts as $part) {
            $inner = self::unwrap($part);
            $split = $inner !== $part ? self::conjuncts($inner) : null;
            array_push($out, ...($split !== null && count($split) > 1 ? $split : [$part]));
        }

        return $out;
    }

    /** Words that cannot be a table's alias. */
    private const NOT_ALIASES = [
        'SET', 'WHERE', 'ORDER', 'LIMIT', 'ON', 'USING', 'FORCE', 'USE', 'IGNORE', 'PARTITION', 'GROUP',
        'HAVING', 'FOR', 'LOCK', 'UNION', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'OUTER', 'NATURAL',
        'STRAIGHT_JOIN', 'WINDOW',
    ];

    /**
     * The table references from $i to the first of $stop at depth 0: each
     * table (or '(' for a derived table) with its alias, how it is joined
     * ('first', 'inner' for `,`/JOIN/INNER/CROSS/STRAIGHT_JOIN, 'left') and
     * its ON condition; null when the list holds what this does not read
     * (a RIGHT or NATURAL join, USING, a qualified or runtime table name).
     *
     * @param list<array{k: string, v: string, u: string}> $t
     * @param list<string> $stop
     * @return array{refs: list<array{table: string, alias: string, join: string, on: list<array{k: string, v: string, u: string}>}>, end: int}|null
     */
    private static function refs(array $t, int $i, array $stop): ?array
    {
        $refs = [];
        $join = 'first';
        $n = count($t);
        while (true) {
            $tok = $t[$i] ?? null;
            if ($tok === null) {
                return null;
            }
            if (self::isPunct($tok, '(')) {
                $i = self::closeParen($t, $i) + 1;
                $table = '(';
            } elseif (in_array($tok['k'], ['word', 'name'], true) && !self::isWord($tok, self::NOT_ALIASES)) {
                $table = strtolower($tok['v']);
                $i++;
                if (isset($t[$i]) && self::isPunct($t[$i], '.')) {
                    return null;
                }
            } else {
                return null;
            }
            $alias = '';
            if (self::isWord($t[$i] ?? null, ['AS']) && in_array($t[$i + 1]['k'] ?? '', ['word', 'name'], true)) {
                $alias = $t[$i + 1]['v'];
                $i += 2;
            } elseif (isset($t[$i]) && in_array($t[$i]['k'], ['word', 'name'], true)
                && !self::isWord($t[$i], [...self::NOT_ALIASES, ...$stop])) {
                $alias = $t[$i]['v'];
                $i++;
            }
            while (self::isWord($t[$i] ?? null, ['FORCE', 'USE', 'IGNORE']) && self::isWord($t[$i + 1] ?? null, ['INDEX', 'KEY'])) {
                $i += 2;
                while ($i < $n && !self::isPunct($t[$i], '(')) {
                    $i++;
                }
                $i = self::closeParen($t, $i) + 1;
            }
            $on = [];
            if (self::isWord($t[$i] ?? null, ['USING'])) {
                return null;
            }
            if (self::isWord($t[$i] ?? null, ['ON'])) {
                $depth = 0;
                for ($i++; $i < $n; $i++) {
                    $c = $t[$i];
                    if (self::isPunct($c, '(')) {
                        $depth++;
                    } elseif (self::isPunct($c, ')')) {
                        $depth--;
                    }
                    if ($depth === 0 && (self::isPunct($c, ',') || self::isWord($c, [...self::JOIN_WORDS, 'NATURAL', 'OUTER', ...$stop]))) {
                        break;
                    }
                    $on[] = $c;
                }
            }
            $refs[] = ['table' => $table, 'alias' => $alias, 'join' => $join, 'on' => $on];
            $next = $t[$i] ?? null;
            if ($next === null || self::isWord($next, $stop)) {
                return ['refs' => $refs, 'end' => $i];
            }
            if (self::isPunct($next, ',')) {
                $join = 'inner';
                $i++;
                continue;
            }
            $words = [];
            while (self::isWord($t[$i] ?? null, ['LEFT', 'RIGHT', 'INNER', 'CROSS', 'OUTER', 'NATURAL', 'STRAIGHT_JOIN', 'JOIN'])) {
                $words[] = $t[$i]['u'];
                $i++;
                if (in_array(end($words), ['JOIN', 'STRAIGHT_JOIN'], true)) {
                    break;
                }
            }
            if ($words === [] || !in_array(end($words), ['JOIN', 'STRAIGHT_JOIN'], true)
                || array_intersect($words, ['RIGHT', 'NATURAL']) !== []) {
                return null;
            }
            $join = in_array('LEFT', $words, true) ? 'left' : 'inner';
        }
    }

    /** @param list<array{k: string, v: string, u: string}> $t */
    private static function closeParen(array $t, int $open): int
    {
        $depth = 0;
        for ($i = $open, $n = count($t); $i < $n; $i++) {
            if (self::isPunct($t[$i], '(')) {
                $depth++;
            } elseif (self::isPunct($t[$i], ')')) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $n;
    }

    /**
     * An UPDATE or DELETE that names more than one table (or a DELETE that
     * names its table by alias): each table it writes, an UPDATE's from its
     * SET list and a DELETE's from the names before FROM, is tied to the
     * account by a top-level conjunct `alias.user_id = <the account>` of the
     * WHERE or of an inner join's ON. A table with no user_id column is also
     * tied by an equality of its column with a tied table's (`c.pixel_id =
     * p.pixel_id`, p tied) in the same places; a table with one only by its
     * own user_id. Null when every table it writes is tied.
     *
     * @param list<array{k: string, v: string, u: string}> $t
     * @param list<string> $holes
     */
    private static function multiTableFinding(array $t, int $targetsAt, int $refsAt, array $holes): ?string
    {
        $verb = $t[0]['u'];
        $parsed = self::refs($t, $refsAt, $verb === 'UPDATE' ? ['SET'] : ['WHERE', 'ORDER', 'LIMIT']);
        $first = $parsed['refs'][0]['table'] ?? strtolower((string) ($t[$refsAt]['v'] ?? ''));
        $unread = 'multi-table ' . $verb . ' | ' . $first . ' | ' . self::render(array_slice($t, 0, 12), $holes);
        if ($parsed === null) {
            return $unread;
        }
        $refs = $parsed['refs'];
        $byName = [];
        foreach ($refs as $k => $ref) {
            if ($ref['table'] !== '(') {
                $byName[strtolower($ref['alias'] !== '' ? $ref['alias'] : $ref['table'])] = $k;
            }
        }
        $written = [];
        if ($verb === 'UPDATE') {
            $where = self::whereAt($t, $parsed['end']);
            $set = array_slice($t, $parsed['end'] + 1, ($where ?? count($t)) - $parsed['end'] - 1);
            $depth = 0;
            $item = [];
            foreach ([...$set, ['k' => 'punct', 'v' => ',', 'u' => '']] as $tok) {
                if (self::isPunct($tok, '(')) {
                    $depth++;
                } elseif (self::isPunct($tok, ')')) {
                    $depth--;
                }
                if ($depth === 0 && self::isPunct($tok, ',')) {
                    if (count($item) < 4 || !self::isPunct($item[1], '.') || $item[3]['v'] !== '=') {
                        return $unread;
                    }
                    $written[] = strtolower($item[0]['v']);
                    $item = [];
                    continue;
                }
                $item[] = $tok;
            }
        } else {
            $where = self::isWord($t[$parsed['end']] ?? null, ['WHERE']) ? $parsed['end'] : null;
            foreach (array_slice($t, $targetsAt, $refsAt - 1 - $targetsAt) as $tok) {
                if (in_array($tok['k'], ['word', 'name'], true)) {
                    $written[] = strtolower($tok['v']);
                } elseif (!self::isPunct($tok, ',') && !self::isPunct($tok, '.') && $tok['v'] !== '*') {
                    return $unread;
                }
            }
        }
        $condition = [];
        if ($where !== null) {
            $depth = 0;
            for ($i = $where + 1, $n = count($t); $i < $n; $i++) {
                if (self::isPunct($t[$i], '(')) {
                    $depth++;
                } elseif (self::isPunct($t[$i], ')')) {
                    $depth--;
                }
                if ($depth === 0 && self::isWord($t[$i], self::STATEMENT_ENDS)) {
                    break;
                }
                $condition[] = $t[$i];
            }
        }
        $detail = self::render($condition !== [] ? $condition : array_slice($t, 0, 12), $holes);
        $conjuncts = self::conjuncts($condition);
        if ($conjuncts === null) {
            return 'OR in the WHERE | ' . $first . ' | ' . $detail;
        }
        foreach ($refs as $ref) {
            $on = $ref['join'] === 'inner' ? self::conjuncts($ref['on']) : null;
            array_push($conjuncts, ...($on ?? []));
        }
        $owned = AccountScopedJoinTest::accountTables();
        $tied = [];
        foreach ($refs as $k => $ref) {
            foreach ($conjuncts as $c) {
                if ($ref['table'] !== '(' && self::isTie($c, $ref['table'], $ref['alias'], false)) {
                    $tied[$k] = true;
                }
            }
        }
        do {
            $grew = false;
            foreach ($conjuncts as $c) {
                $pair = self::columnPair(self::unwrap($c));
                if ($pair === null || !isset($byName[$pair[0]], $byName[$pair[1]])) {
                    continue;
                }
                foreach ([[$pair[0], $pair[1]], [$pair[1], $pair[0]]] as [$child, $parent]) {
                    $kc = $byName[$child];
                    if (isset($tied[$byName[$parent]]) && !isset($tied[$kc]) && !isset($owned[$refs[$kc]['table']])) {
                        $tied[$kc] = $grew = true;
                    }
                }
            }
        } while ($grew);
        if ($written === []) {
            return $unread;
        }
        foreach (array_unique($written) as $name) {
            if (!isset($byName[$name])) {
                return $unread;
            }
            $table = $refs[$byName[$name]]['table'];
            if (!isset(self::schemaTables()[$table])) {
                return 'table not in the schema | ' . $table . ' | ' . self::render(array_slice($t, 0, 12), $holes);
            }
            if (!isset($tied[$byName[$name]])) {
                return (isset($owned[$table]) ? 'no user_id tie' : self::THROUGH) . ' | ' . $table . ' | ' . $detail;
            }
        }

        return null;
    }

    /**
     * `a.x = b.y`: the two qualifiers, lower case; null for anything else.
     *
     * @param list<array{k: string, v: string, u: string}> $c
     * @return array{0: string, 1: string}|null
     */
    private static function columnPair(array $c): ?array
    {
        if (count($c) !== 7 || $c[3]['k'] !== 'op' || $c[3]['v'] !== '=') {
            return null;
        }
        foreach ([0, 4] as $o) {
            if (!in_array($c[$o]['k'], ['word', 'name'], true) || !self::isPunct($c[$o + 1], '.')
                || !in_array($c[$o + 2]['k'], ['word', 'name'], true)) {
                return null;
            }
        }

        return [strtolower($c[0]['v']), strtolower($c[4]['v'])];
    }

    /**
     * Whether a SELECT reads $table tied to the account: a top-level
     * conjunct of its WHERE or of an inner join's ON is `alias.user_id =
     * <the account>` (or `user_id = …` when it reads one table).
     *
     * @param list<array{k: string, v: string, u: string}> $t
     */
    private static function readsTied(array $t, string $table): bool
    {
        if (!self::isWord($t[0] ?? null, ['SELECT'])) {
            return false;
        }
        $ends = ['WHERE', 'GROUP', 'ORDER', 'LIMIT', 'FOR', 'LOCK', 'HAVING', 'UNION', 'WINDOW'];
        $depth = 0;
        $from = null;
        foreach ($t as $i => $tok) {
            if (self::isPunct($tok, '(')) {
                $depth++;
            } elseif (self::isPunct($tok, ')')) {
                $depth--;
            } elseif ($depth === 0 && self::isWord($tok, ['FROM'])) {
                $from = $i;
                break;
            }
        }
        $parsed = $from === null ? null : self::refs($t, $from + 1, $ends);
        if ($parsed === null) {
            return false;
        }
        $condition = [];
        if (self::isWord($t[$parsed['end']] ?? null, ['WHERE'])) {
            $depth = 0;
            for ($i = $parsed['end'] + 1, $n = count($t); $i < $n; $i++) {
                if (self::isPunct($t[$i], '(')) {
                    $depth++;
                } elseif (self::isPunct($t[$i], ')')) {
                    $depth--;
                }
                if ($depth === 0 && self::isWord($t[$i], array_slice($ends, 1))) {
                    break;
                }
                $condition[] = $t[$i];
            }
        }
        $conjuncts = self::conjuncts($condition) ?? [];
        foreach ($parsed['refs'] as $ref) {
            array_push($conjuncts, ...($ref['join'] === 'inner' ? self::conjuncts($ref['on']) ?? [] : []));
        }
        $single = count($parsed['refs']) === 1;
        foreach ($parsed['refs'] as $ref) {
            if ($ref['table'] !== strtolower($table)) {
                continue;
            }
            foreach ($conjuncts as $c) {
                if (self::isTie($c, $ref['table'], $ref['alias'], $single)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * `[t.]user_id = <the account>`, either way round.
     *
     * @param list<array{k: string, v: string, u: string}> $c
     */
    private static function isTie(array $c, string $table, string $alias, bool $bare): bool
    {
        $c = self::unwrap($c);
        $eq = null;
        foreach ($c as $k => $tok) {
            if ($tok['k'] === 'op' && $tok['v'] === '=') {
                if ($eq !== null) {
                    return false;
                }
                $eq = $k;
            }
        }
        if ($eq === null) {
            return false;
        }
        $left = array_slice($c, 0, $eq);
        $right = array_slice($c, $eq + 1);
        foreach ([[$left, $right], [$right, $left]] as [$column, $value]) {
            if (self::isOwnUserId($column, $table, $alias, $bare) && self::isAccount($value)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{k: string, v: string, u: string}> $side */
    private static function isOwnUserId(array $side, string $table, string $alias, bool $bare): bool
    {
        $named = static fn (array $tok): bool => in_array($tok['k'], ['word', 'name'], true);
        if (count($side) === 1 && $bare) {
            return $named($side[0]) && strtolower($side[0]['v']) === 'user_id';
        }
        if (count($side) === 3 && $named($side[0]) && $side[1]['v'] === '.' && $named($side[2])) {
            $qualifier = strtolower($side[0]['v']);

            return ($qualifier === $table || ($alias !== '' && $qualifier === strtolower($alias)))
                && strtolower($side[2]['v']) === 'user_id';
        }

        return false;
    }

    /** @param list<array{k: string, v: string, u: string}> $side */
    private static function isAccount(array $side): bool
    {
        if (count($side) !== 1) {
            return false;
        }
        $tok = $side[0];
        if ($tok['k'] === 'param') {
            return true;
        }
        if (in_array($tok['k'], ['word', 'str'], true) && preg_match('/^[1-9][0-9]*$/', $tok['v']) === 1) {
            return true;
        }
        $source = $tok['k'] === 'hole' ? $tok['v'] : ($tok['k'] === 'str' ? $tok['u'] : '');
        $bare = (string) preg_replace('/^\((?:int|string)\)\s*/i', '', trim($source, '{} '));
        $requested = preg_match('/^\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES|ENV)\b/', $bare) === 1;

        return $source !== '' && !$requested && SqlJoinScan::namesAUserId($source);
    }

    /**
     * @param list<array{k: string, v: string, u: string}> $c
     * @return list<array{k: string, v: string, u: string}>
     */
    private static function unwrap(array $c): array
    {
        while (count($c) >= 2 && self::isPunct($c[0], '(') && self::isPunct($c[count($c) - 1], ')')) {
            $depth = 0;
            foreach ($c as $i => $tok) {
                if (self::isPunct($tok, '(')) {
                    $depth++;
                } elseif (self::isPunct($tok, ')')) {
                    $depth--;
                    if ($depth === 0 && $i !== count($c) - 1) {
                        return $c;
                    }
                }
            }
            $c = array_slice($c, 1, -1);
        }

        return $c;
    }

    /**
     * @param list<array{k: string, v: string, u: string}> $t
     * @param list<string> $holes
     */
    private static function render(array $t, array $holes): string
    {
        $inString = static fn (string $v): string => (string) preg_replace_callback(
            "/\x01(\\d+)\x02/",
            static fn (array $m): string => '{' . ($holes[(int) $m[1]] ?? '') . '}',
            $v
        );
        $out = '';
        foreach ($t as $tok) {
            $out .= match ($tok['k']) {
                'hole' => '{' . $tok['v'] . '}',
                'str' => "'" . $inString($tok['v']) . "'",
                'name' => '`' . $tok['v'] . '`',
                default => $tok['v'],
            } . ' ';
        }
        $out = str_replace(' . ', '.', $out);

        return trim(mb_strimwidth($out, 0, 200, '…'));
    }

    /** @param list<\PhpToken> $tokens */
    private static function prevSignificant(array $tokens, int $i): ?int
    {
        for (; $i >= 0; $i--) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function nextSignificant(array $tokens, int $i): ?int
    {
        for ($n = count($tokens); $i < $n; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    private static function inScope(string $path): bool
    {
        if (isset(self::NOT_READ[$path])) {
            return false;
        }
        foreach (self::SCOPE as $dir) {
            if (str_starts_with($path, $dir)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<array{line: int, key: string, at: int}>> repo-relative file => its findings */
    public static function treeFindings(): array
    {
        if (self::$tree !== null) {
            return self::$tree;
        }
        $out = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $writes = stripos($source, 'update') !== false || stripos($source, 'delete') !== false;
            if (!$writes || !self::inScope($path)) {
                continue;
            }
            $seen = [];
            foreach (self::scan($source) as $f) {
                $n = $seen[$f['key']] = ($seen[$f['key']] ?? 0) + 1;
                $out[$path][] = ['line' => $f['line'], 'key' => $f['key'] . ($n > 1 ? " #$n" : ''), 'at' => $f['at']];
            }
        }

        return self::$tree = $out;
    }

    public function testEveryWriteOfARowNamedByIdNamesItsAccount(): void
    {
        $unexpected = [];
        foreach (self::treeFindings() as $file => $findings) {
            foreach ($findings as $f) {
                [$kind, $rest] = explode(' | ', $f['key'], 2);
                $listed = ($kind === self::THROUGH && isset(self::THROUGH_PARENT[$file][$rest]))
                    || isset(self::GUARDED[$file][$f['key']])
                    || isset(self::GENERIC[$file][$f['key']])
                    || isset(self::REVIEWED[$file][$f['key']]);
                if (!$listed) {
                    $unexpected[] = "  $file:{$f['line']}  {$f['key']}";
                }
            }
        }
        self::assertSame([], $unexpected, sprintf(
            "These writes name a row without tying it to the signed-in account:\n%s\n"
            . "A request can name any account's id, so add `AND user_id = <the account>` to the WHERE (`?`,\n"
            . "bound to the session's user id), and never set user_id from the session on an UPDATE. A table\n"
            . "owned through its parent has no user_id: check the parent is the account's before the write, on\n"
            . "every path, and list the write in THROUGH_PARENT (a page) or GUARDED (a class: the method that\n"
            . 'checks, which testEveryGuardedWriteRunsAfterItsGuard() holds).',
            implode("\n", $unexpected)
        ));
    }

    public function testEveryListedWriteStillExists(): void
    {
        $tree = self::treeFindings();
        $stale = [];
        $listed = 0;
        foreach (self::THROUGH_PARENT as $file => $keys) {
            $present = array_column($tree[$file] ?? [], 'key');
            foreach (array_keys($keys) as $key) {
                $listed++;
                if (!in_array(self::THROUGH . ' | ' . $key, $present, true)) {
                    $stale[] = "  THROUGH_PARENT: $file  $key";
                }
            }
        }
        $counts = [];
        foreach (['GUARDED' => self::GUARDED, 'GENERIC' => self::GENERIC, 'REVIEWED' => self::REVIEWED] as $name => $list) {
            $counts[$name] = 0;
            foreach ($list as $file => $keys) {
                $present = array_column($tree[$file] ?? [], 'key');
                foreach (array_keys($keys) as $key) {
                    $counts[$name]++;
                    if (!in_array($key, $present, true)) {
                        $stale[] = "  $name: $file  $key";
                    }
                }
            }
        }
        $message = "These entries match no write any more — remove them, and lower the ceiling:\n" . implode("\n", $stale);
        self::assertSame([], $stale, $message);
        self::assertLessThanOrEqual(self::CEILING, $listed, 'THROUGH_PARENT only shrinks: tie the new write instead');
        self::assertLessThanOrEqual(self::REVIEWED_CEILING, $counts['REVIEWED'], 'REVIEWED only shrinks: tie the new'
            . ' write, or guard it with a check GUARDED can hold');
        self::assertSame(self::REVIEWED_CEILING, $counts['REVIEWED'], 'REVIEWED shrank: lower REVIEWED_CEILING to match');
    }

    /**
     * Each GUARDED write is reached only after its guard (CallsBefore: a
     * statement directly in the body before it, in the method, the closure
     * it is written in, or every caller of the private method it is in),
     * and the guard reads its table tied to the account: a SELECT in the
     * guard's body, or in a method of the class it calls, has a top-level
     * `user_id = <the account>` on that table. A guard used as a statement
     * refuses by throwing; one that answers null or false is used in an if
     * that leaves. Not read: that the throw is the one taken when the read
     * finds nothing, and that the guard is handed the id the write binds.
     */
    public function testEveryGuardedWriteRunsAfterItsGuard(): void
    {
        $tree = self::treeFindings();
        $problems = [];
        foreach (self::GUARDED as $file => $entries) {
            $source = SourceScan::phpFiles()[$file] ?? '';
            foreach ($entries as $key => $guard) {
                $writes = array_values(array_filter($tree[$file] ?? [], static fn (array $f): bool => $f['key'] === $key));
                foreach ($writes as $write) {
                    $problems = [...$problems, ...self::guardProblems($source, $write['at'], $guard, "$file:{$write['line']}")];
                }
            }
        }
        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Why $guard does not hold the write at token $at of $source; [] when it
     * does.
     *
     * @param array{calls: list<string>, reads: string} $guard
     * @return list<string>
     */
    private static function guardProblems(string $source, int $at, array $guard, string $where): array
    {
        $problems = [];
        foreach ($guard['calls'] as $k => $method) {
            $body = CallsBefore::methodBody($source, $method);
            if ($body === null) {
                $problems[] = "$where: the guard $method() is not a method of this file";
                continue;
            }
            $throws = self::reachesThrow($source, $method, 3);
            if (!CallsBefore::guarded($source, $at, $method, $throws)) {
                $problems[] = "$where: a path reaches the write without $method() first";
            }
            if ($k === 0 && !self::readsTiedThrough($source, $method, $guard['reads'], 3)) {
                $problems[] = "$where: $method() reads no {$guard['reads']} row tied to the account";
            }
        }

        return $problems;
    }

    /** Whether the method, or one of the class's methods it calls, $depth deep, reads $table tied. */
    private static function readsTiedThrough(string $source, string $method, string $table, int $depth): bool
    {
        $body = CallsBefore::methodBody($source, $method);
        if ($body === null || $depth < 0) {
            return false;
        }
        foreach (SqlJoinScan::chains($source) as $chain) {
            if ($chain['start'] > $body[0] && $chain['end'] < $body[1]) {
                $chain = self::withConstants($source, $chain);
                if (self::readsTied(SqlJoinScan::sqlTokens($chain['text'], $chain['holes']), $table)) {
                    return true;
                }
            }
        }
        foreach (CallsBefore::callsIn($source, $body[0], $body[1]) as $callee) {
            if ($callee !== strtolower($method) && self::readsTiedThrough($source, $callee, $table, $depth - 1)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the method, or one of the class's methods it calls, $depth deep, throws. */
    private static function reachesThrow(string $source, string $method, int $depth): bool
    {
        $body = CallsBefore::methodBody($source, $method);
        if ($body === null || $depth < 0) {
            return false;
        }
        if (CallsBefore::throwsIn($source, $body[0], $body[1])) {
            return true;
        }
        foreach (CallsBefore::callsIn($source, $body[0], $body[1]) as $callee) {
            if ($callee !== strtolower($method) && self::reachesThrow($source, $callee, $depth - 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The two generic writers name their table at runtime and tie it by a
     * column they also name at runtime: every table they can write has the
     * column, and the column is user_id. api/v3/Controller.php's tables
     * are the concrete controllers' (RuntimeWriteTables::controllers());
     * MysqlCrudRepository's are TableConfig's.
     */
    public function testTheGenericWritersTieEveryTableTheyWrite(): void
    {
        $owned = AccountScopedJoinTest::accountTables();
        $problems = [];
        $controllers = 0;
        foreach (glob(SourceScan::repoRoot() . '/api/v3/Controllers/*Controller.php') ?: [] as $path) {
            $class = 'Api\\V3\\Controllers\\' . basename($path, '.php');
            if (!class_exists($class) || !is_subclass_of($class, \Api\V3\Controller::class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }
            $controllers++;
            $instance = $reflection->newInstanceWithoutConstructor();
            $table = strtolower((string) $reflection->getMethod('tableName')->invoke($instance));
            $column = $reflection->getMethod('userIdColumn')->invoke($instance);
            if ($column !== 'user_id' || !isset($owned[$table])) {
                $problems[] = "$class writes $table tied by " . var_export($column, true);
            }
        }
        $configs = 0;
        $tc = new \ReflectionClass(\Prosper202\Crud\TableConfig::class);
        foreach ($tc->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            if (!$method->isPublic() || $method->getNumberOfRequiredParameters() !== 0) {
                continue;
            }
            $config = $method->invoke(null);
            if (!$config instanceof \Prosper202\Crud\TableConfig) {
                continue;
            }
            $configs++;
            if ($config->userIdColumn !== 'user_id' || !isset($owned[strtolower($config->table)])) {
                $problems[] = "TableConfig::{$method->getName()}() writes {$config->table} tied by {$config->userIdColumn}";
            }
        }
        self::assertGreaterThanOrEqual(10, $controllers, 'the controllers were not found');
        self::assertGreaterThan(3, $configs, 'the table configs were not found');
        self::assertSame([], $problems, implode("\n", $problems));
    }

    public function testTheScopeIsServedCode(): void
    {
        $paths = array_keys(SourceScan::phpFiles());
        foreach (self::SCOPE as $dir) {
            $found = array_filter($paths, static fn (string $path): bool => str_starts_with($path, $dir));
            self::assertNotSame([], $found, "$dir holds no PHP: the scope names a directory that moved");
        }
        self::assertTrue(self::inScope('tracking202/setup/ppc_accounts.php'));
        self::assertTrue(self::inScope('api/v3/Controllers/RotatorsController.php'));
        foreach (array_keys(self::NOT_READ) as $path) {
            self::assertContains($path, $paths, "NOT_READ names $path, which is not in the tree");
            self::assertFalse(self::inScope($path));
        }
    }

    /**
     * @return array<string, array{string, list<string>}> PHP => the kinds it
     *         must be reported as ([] for a write that names its account)
     */
    public static function spellings(): array
    {
        $untied = ['no user_id tie'];
        $or = ['OR in the WHERE'];
        $noWhere = ['no WHERE read'];

        return [
            'tied, interpolated' => [<<<'PHP'
                $db->query("UPDATE 202_ppc_networks SET ppc_network_name = 'x'
                    WHERE ppc_network_id = '$id' AND user_id = '{$mysql['user_id']}'");
                PHP, []],
            'by id alone' => [<<<'PHP'
                $db->query("UPDATE 202_ppc_networks SET ppc_network_name = 'x' WHERE ppc_network_id = '$id'");
                PHP, $untied],
            'prepared, tied' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks SET ppc_network_name = ?
                    WHERE ppc_network_id = ? AND user_id = ?');
                PHP, []],
            'prepared, by id alone' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks SET ppc_network_name = ? WHERE ppc_network_id = ?');
                PHP, $untied],
            'tied through the alias' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks AS pn SET pn.ppc_network_name = ?
                    WHERE pn.ppc_network_id = ? AND pn.user_id = ?');
                PHP, []],
            'tied through the table name' => [<<<'PHP'
                $c->prepareWrite('UPDATE `202_ppc_networks` SET ppc_network_name = ?
                    WHERE `202_ppc_networks`.`user_id` = ? AND ppc_network_id = ?');
                PHP, []],
            'another alias\'s user_id' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks pn SET ppc_network_name = ?
                    WHERE pn.ppc_network_id = ? AND other.user_id = ?');
                PHP, $untied],
            'the tie written the other way round' => [<<<'PHP'
                $c->prepareWrite('DELETE FROM 202_trackers WHERE tracker_id = ? AND ? = user_id');
                PHP, []],
            'user_id in the SET only' => [<<<'PHP'
                $db->query("UPDATE 202_ppc_networks SET user_id = '{$mysql['user_id']}'
                    WHERE ppc_network_id = '$id'");
                PHP, $untied],
            'an OR beside the tie' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers SET click_cpc = ?
                    WHERE tracker_id = ? AND user_id = ? OR 1 = 1');
                PHP, $or],
            'an OR around the tie' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers SET click_cpc = ?
                    WHERE (tracker_id = ? AND user_id = ?) OR tracker_id = ?');
                PHP, $or],
            'parenthesized conjuncts' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers SET click_cpc = ? WHERE (tracker_id = ?) AND (user_id = ?)');
                PHP, []],
            'a commented-out tie' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers SET click_cpc = ? WHERE tracker_id = ? /* AND user_id = ? */');
                PHP, $untied],
            'a tie after a line comment' => [<<<'PHP'
                $c->prepareWrite("UPDATE 202_trackers SET click_cpc = ? WHERE tracker_id = ? -- AND user_id = ?\n");
                PHP, $untied],
            'user_id compared with a posted value' => [<<<'PHP'
                $db->query("DELETE FROM 202_trackers
                    WHERE tracker_id = '$id' AND user_id = '{$_POST['user_id']}'");
                PHP, $untied],
            'the session\'s user id' => [<<<'PHP'
                $db->query("UPDATE 202_users SET user_email = 'x'
                    WHERE user_id = '" . (int) $_SESSION['user_id'] . "'");
                PHP, []],
            'user_id compared with a value named otherwise' => [<<<'PHP'
                $db->query("DELETE FROM 202_trackers WHERE tracker_id = '$id' AND user_id = '{$owner}'");
                PHP, $untied],
            'the install\'s own row' => [<<<'PHP'
                $db->query("UPDATE 202_users_pref SET auto_cron = '1' WHERE user_id = '1'");
                PHP, []],
            'a cast user id' => [<<<'PHP'
                $db->query('UPDATE 202_trackers SET click_cpc = 1 '
                    . 'WHERE tracker_id = ' . $id . ' AND user_id = ' . (int) $userId);
                PHP, []],
            'a heredoc' => [<<<'PHP'
                $db->query(<<<SQL
                    UPDATE 202_trackers SET click_cpc = 1
                    WHERE tracker_id = {$id}
                    SQL);
                PHP, $untied],
            'lower case' => [<<<'PHP'
                $c->prepareWrite('update 202_trackers set click_cpc = ? where tracker_id = ?');
                PHP, $untied],
            'sprintf, tied' => [<<<'PHP'
                $db->query(sprintf(
                    "UPDATE 202_trackers SET click_cpc = 1 WHERE tracker_id = %d AND user_id = %d",
                    $id,
                    $userId
                ));
                PHP, []],
            'sprintf, untied' => [<<<'PHP'
                $db->query(sprintf(
                    "UPDATE 202_trackers SET click_cpc = 1 WHERE tracker_id = %d AND user_id = %d",
                    $id,
                    $other
                ));
                PHP, $untied],
            'BETWEEN beside the tie' => [<<<'PHP'
                $c->prepareWrite('DELETE FROM 202_trackers WHERE tracker_id BETWEEN ? AND ? AND user_id = ?');
                PHP, []],
            'the tie only in a subquery' => [<<<'PHP'
                $c->prepareWrite('DELETE FROM 202_trackers
                    WHERE tracker_id IN (SELECT tracker_id FROM 202_trackers WHERE user_id = ?)');
                PHP, $untied],
            'a DELETE by id alone' => [<<<'PHP'
                $c->prepareWrite('DELETE FROM 202_trackers WHERE tracker_id = ?');
                PHP, $untied],
            'a table owned through its parent' => [<<<'PHP'
                $c->prepareWrite('DELETE FROM 202_rotator_rules WHERE rotator_id = ?');
                PHP, [self::THROUGH]],
            'a table built at runtime' => [<<<'PHP'
                $db->query("UPDATE {$table} SET x = 1 WHERE id = ? AND user_id = ?");
                PHP, ['table built at runtime']],
            'a table not in the schema' => [<<<'PHP'
                $db->query("UPDATE 202_no_such_table SET x = 1 WHERE id = ? AND user_id = ?");
                PHP, ['table not in the schema']],
            'a multi-table UPDATE tied by nothing' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks pn
                    JOIN 202_ppc_accounts pa ON (pa.ppc_network_id = pn.ppc_network_id)
                    SET pn.ppc_network_name = ? WHERE pa.ppc_account_id = ?');
                PHP, $untied],
            'a multi-table UPDATE tied on the table it writes' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks pn
                    JOIN 202_ppc_accounts pa ON (pa.ppc_network_id = pn.ppc_network_id)
                    SET pn.ppc_network_name = ? WHERE pa.ppc_account_id = ? AND pn.user_id = ?');
                PHP, []],
            'a table with user_id tied only through the table it joins' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers t JOIN 202_aff_campaigns a ON a.aff_campaign_id = t.aff_campaign_id
                    SET t.click_cpc = ? WHERE a.user_id = ?');
                PHP, $untied],
            'a tie in an inner join\'s ON' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers t JOIN 202_aff_campaigns a
                    ON a.aff_campaign_id = t.aff_campaign_id AND t.user_id = ? SET t.click_cpc = ? WHERE t.tracker_id = ?');
                PHP, []],
            'a tie in a LEFT JOIN\'s ON' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers t LEFT JOIN 202_aff_campaigns a
                    ON a.aff_campaign_id = t.aff_campaign_id AND t.user_id = ? SET t.click_cpc = ? WHERE t.tracker_id = ?');
                PHP, $untied],
            'a bare user_id in a multi-table WHERE' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers t JOIN 202_aff_campaigns a ON a.aff_campaign_id = t.aff_campaign_id
                    SET t.click_cpc = ? WHERE user_id = ?');
                PHP, $untied],
            'a child tied through its tied parent' => [<<<'PHP'
                $c->prepareWrite('DELETE rr FROM 202_rotator_rules rr JOIN 202_rotators r ON r.id = rr.rotator_id
                    WHERE rr.id = ? AND r.user_id = ?');
                PHP, []],
            'a child tied through a parent tied in its ON' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_rotator_rules AS rr INNER JOIN 202_rotators AS r
                    ON (r.id = rr.rotator_id AND r.user_id = ?) SET rr.status = 0 WHERE rr.id = ?');
                PHP, []],
            'a child joined to its parent by a LEFT JOIN' => [<<<'PHP'
                $c->prepareWrite('DELETE rr FROM 202_rotator_rules rr LEFT JOIN 202_rotators r
                    ON r.id = rr.rotator_id AND r.user_id = ? WHERE rr.id = ?');
                PHP, [self::THROUGH]],
            'two tables deleted, one tied' => [<<<'PHP'
                $c->prepareWrite('DELETE t, a FROM 202_trackers t JOIN 202_aff_campaigns a ON a.aff_campaign_id = t.aff_campaign_id
                    WHERE t.user_id = ?');
                PHP, $untied],
            'an OR in a multi-table WHERE' => [<<<'PHP'
                $c->prepareWrite('DELETE t FROM 202_trackers t JOIN 202_aff_campaigns a ON a.aff_campaign_id = t.aff_campaign_id
                    WHERE t.user_id = ? OR a.aff_campaign_id = ?');
                PHP, $or],
            'a RIGHT JOIN' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers t RIGHT JOIN 202_aff_campaigns a ON a.aff_campaign_id = t.aff_campaign_id
                    SET t.click_cpc = ? WHERE t.user_id = ?');
                PHP, ['multi-table UPDATE']],
            'a derived table joined' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_customers c LEFT JOIN (SELECT customer_id, SUM(amount) AS s FROM 202_revenue_events
                    WHERE user_id = ? GROUP BY customer_id) e ON e.customer_id = c.customer_id
                    SET c.ltv = COALESCE(e.s, 0) WHERE c.user_id = ?');
                PHP, []],
            'a comma-joined UPDATE' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks, 202_ppc_accounts SET ppc_network_name = ?
                    WHERE ppc_account_id = ?');
                PHP, ['multi-table UPDATE']],
            'a DELETE that names its table by alias' => [<<<'PHP'
                $c->prepareWrite('DELETE t FROM 202_trackers t WHERE t.tracker_id = ?');
                PHP, $untied],
            'a DELETE that names its table by alias, tied' => [<<<'PHP'
                $c->prepareWrite('DELETE t FROM 202_trackers t WHERE t.tracker_id = ? AND t.user_id = ?');
                PHP, []],
            'a DELETE of a table the statement does not name' => [<<<'PHP'
                $c->prepareWrite('DELETE x FROM 202_trackers t WHERE t.tracker_id = ? AND t.user_id = ?');
                PHP, ['multi-table DELETE']],
            'a table named by its constant' => [<<<'PHP'
                $c->prepareWrite('UPDATE `' . \Prosper202\Database\Schema\TableRegistry::CLICKS . '` SET click_cpc = ? WHERE click_id = ?');
                PHP, $untied],
            'a table named by its constant, tied' => [<<<'PHP'
                $c->prepareWrite('DELETE FROM ' . \Prosper202\Database\Schema\TableRegistry::CLICKS . ' WHERE click_id = ? AND user_id = ?');
                PHP, []],
            'a constant the scan cannot resolve' => [<<<'PHP'
                $c->prepareWrite('DELETE FROM ' . self::NO_SUCH_TABLE . ' WHERE id = ? AND user_id = ?');
                PHP, ['table built at runtime']],
            'a parenthesized conjunction' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers SET click_cpc = ? WHERE (tracker_id = ? AND user_id = ?)');
                PHP, []],
            'a SET list built at runtime' => [<<<'PHP'
                $db->query("UPDATE 202_trackers " . $set . " WHERE tracker_id = '$id'");
                PHP, $untied],
            'a SET list built at runtime, tied' => [<<<'PHP'
                $db->query("UPDATE 202_trackers {$set} WHERE tracker_id = ? AND user_id = ?");
                PHP, []],
            'no WHERE' => [<<<'PHP'
                $db->query("UPDATE 202_trackers SET click_cpc = '1'");
                PHP, $noWhere],
            'a WHERE built at runtime' => [<<<'PHP'
                $db->query("UPDATE 202_trackers SET click_cpc = 1 WHERE tracker_id = ? {$where}");
                PHP, ['WHERE built at runtime']],
            // The shape the traffic source rename shipped in.
            'finished by appends, the INSERT twin passed over' => [<<<'PHP'
                if ($editing) {
                    $sql = " UPDATE 202_ppc_networks SET";
                } else {
                    $sql = "INSERT INTO `202_ppc_networks` SET";
                }
                $sql .= " `user_id`='" . $mysql['user_id'] . "', `ppc_network_name`='" . $name . "'";
                if ($editing) {
                    $sql .= "WHERE ppc_network_id='" . $mysql['ppc_network_id'] . "'";
                }
                _mysqli_query($sql);
                PHP, $untied],
            'finished by appends, tied' => [<<<'PHP'
                if ($editing) {
                    $sql = 'UPDATE `202_aff_networks` SET';
                } else {
                    $sql = 'INSERT INTO `202_aff_networks` SET';
                }
                $sql .= " `aff_network_name`='" . $name . "'";
                if ($editing) {
                    $sql .= "WHERE `aff_network_id`='" . $id . "' AND `user_id`='" . $mysql['user_id'] . "'";
                }
                $db->query($sql);
                PHP, []],
            'an append the scan cannot read' => [<<<'PHP'
                $sql = 'UPDATE 202_trackers SET click_cpc = 1';
                $sql .= $where;
                $db->query($sql);
                PHP, $noWhere],
            'reassigned before its WHERE' => [<<<'PHP'
                $sql = 'UPDATE 202_trackers SET click_cpc = 1';
                $sql = 'SELECT 1 FROM 202_trackers';
                $sql .= ' WHERE user_id = ?';
                PHP, $noWhere],
            'run before its WHERE is appended' => [<<<'PHP'
                $sql = 'UPDATE 202_trackers SET click_cpc = 1';
                $db->query($sql);
                $sql .= ' WHERE tracker_id = 1 AND user_id = ?';
                PHP, $noWhere],
            'a label that is not SQL' => [<<<'PHP'
                echo 'Update tracking link';
                echo "Delete export {$id} and its file?";
                echo 'Delete a rule from a rotator';
                record_mysql_error($db, 'UPDATE 202_rotators (redirector ' . $id . ')');
                PHP, []],
            'a lock that is not a write' => [<<<'PHP'
                $c->prepareWrite('SELECT id FROM 202_rotators WHERE id = ? FOR UPDATE');
                PHP, []],
        ];
    }

    /**
     * @dataProvider spellings
     * @param list<string> $kinds
     */
    public function testTheScannerReadsEverySpellingItClaims(string $php, array $kinds): void
    {
        $found = array_map(
            static fn (array $f): string => explode(' | ', $f['key'])[0],
            self::scan("<?php\n" . $php . "\n")
        );
        self::assertSame($kinds, $found, $php);
    }

    /**
     * The local-variable shapes: the one LocalStringValue reads, and one
     * plant per shape it must refuse, each with the refusal it names. Each
     * write is tied only through the variable, so a refused read is an
     * untied write.
     *
     * @return array<string, array{0: string, 1: string, 2?: string}> the
     *         method body; the refusal ('' for a shape that is read and
     *         tied, 'reads …' for one read as an untied value); the
     *         method's parameters
     */
    public static function localShapes(): array
    {
        $w = "\$w = 'id = ? AND user_id = ?';";
        $write = "\$this->c->prepareWrite('UPDATE 202_ppc_networks SET ppc_network_name = ? WHERE ' . \$w);";

        return [
            'assigned once, read' => ["$w $write", ''],
            'assigned once, interpolated' => ["$w \$this->c->prepareWrite(\"UPDATE 202_ppc_networks SET ppc_network_name = ? WHERE {\$w}\");", ''],
            'read twice, in a nested block' => ["$w \$this->c->prepareWrite('SELECT 1 FROM 202_ppc_networks WHERE ' . \$w); if (\$x) { $write }", ''],
            'an earlier value replaced' => ["\$w = \$this->rows(); foreach (\$w as \$r) { } $w $write", ''],
            'reassigned after' => ["$w $write \$w = 'id = ?';", 'assigned again'],
            'reassigned between: the later value is read' => ["$w \$w = 'id = ?'; $write", 'reads id = ?'],
            'reassigned between: the later value is read, tied' => ["\$w = 'id = ?'; $w $write", ''],
            'appended' => ["$w \$w .= ' OR 1'; $write", 'used otherwise'],
            'incremented' => ["$w \$w++; $write", 'used otherwise'],
            'assigned in an expression' => ["$w \$y = (\$w = 'id = ?'); $write", 'used otherwise'],
            'destructured' => ["$w [\$w] = ['id = ?']; $write", 'used otherwise'],
            'a foreach target' => ["$w foreach (['id = ?'] as \$w) { } $write", 'used otherwise'],
            'a call argument (by reference)' => ["$w \$this->fill(\$w); $write", 'used otherwise'],
            'unset' => ["$w unset(\$w); $write", 'used otherwise'],
            'referenced after' => ["$w \$r = &\$w; \$r = 'id = ?'; $write", 'taken by reference'],
            'referenced before' => ["\$r = &\$w; $w \$r = 'id = ?'; $write", 'taken by reference'],
            'assigned by reference' => ["\$w = &\$this->where; $w $write", 'assigned by reference'],
            'foreach by reference' => ["foreach (\$this->rows as &\$w) { } $w $write", 'taken by reference'],
            'a closure\'s use by reference' => ["\$f = function () use (&\$w): void { \$w = 'id = ?'; }; $w \$f(); $write", 'taken by reference'],
            'a closure\'s use by value' => ["$w \$f = function () use (\$w): void { }; $write", 'used otherwise'],
            'global' => ["global \$w; $w $write", 'used otherwise'],
            'static' => ["static \$w = ''; $w $write", 'used otherwise'],
            'a by-reference parameter' => ["$w $write", 'taken by reference', '&$w'],
            'extract()' => ["$w extract(\$this->vars); $write", 'extract()'],
            'compact()' => ["$w \$v = compact('w'); $write", 'compact()'],
            'parse_str()' => ["$w parse_str(\$q); $write", 'parse_str()'],
            'variable variable' => ["$w \$n = 'w'; \$\$n = 'id = ?'; $write", '$$name'],
            'dollar brace' => ["$w \${'w'} = 'id = ?'; $write", '$$name'],
            'eval' => ["$w eval(\$code); $write", 'eval'],
            'include' => ["$w include \$file; $write", 'include'],
            'require' => ["$w require \$file; $write", 'require'],
            'goto' => ["goto skip; $w skip: $write", 'goto'],
            'an arrow function' => ["$w \$f = fn () => \$w; $write", 'an arrow function'],
            'alternative syntax' => ["if (\$x): $w endif; $write", 'endif'],
            'assigned in a branch' => ["if (\$x) { $w } $write", 'outside the block'],
            'assigned in a braceless if' => ["if (\$x) $w $write", 'no `$w = …;` statement'],
            'assigned in another case' => ["switch (\$x) { case 1: $w break; case 2: $write }", 'no `$w = …;` statement'],
            'assigned before a case label' => ["switch (\$x) { case 1: foo(); $w case 2: $write }", 'case label'],
            'assigned a call' => ["\$w = \$this->where(); $write", 'not a string chain'],
            'assigned a ternary' => ["\$w = 'id = ? AND user_id = ?' . '' ?: 'id = ?'; $write", 'not a string chain'],
            'only a parameter' => ["$write", 'no `$w = …;` statement', '$w'],
            'read in a nested closure' => ["$w \$this->tx(function (): void { \$this->c->prepareWrite('UPDATE 202_ppc_networks SET ppc_network_name = ? WHERE ' . \$w); });", 'no `$w = …;` statement'],
            'read at file scope' => ['', 'outside a function'],
            'read with an index' => ["\$w = 'id = ? AND user_id = ?'; \$this->c->prepareWrite('UPDATE 202_ppc_networks SET ppc_network_name = ? WHERE ' . \$w[0]);", 'index'],
        ];
    }

    /**
     * @dataProvider localShapes
     */
    public function testALocalIsReadOnlyInTheShapeItCanVouchFor(string $body, string $refusal, string $params = ''): void
    {
        $source = $refusal === 'outside a function'
            ? "<?php\n\$w = 'id = ? AND user_id = ?';\n\$c->prepareWrite('UPDATE 202_ppc_networks SET ppc_network_name = ? WHERE ' . \$w);\n"
            : "<?php\nfinal class Planted {\n    public function f($params): void { $body }\n}\n";
        $findings = array_map(static fn (array $f): string => $f['key'], self::scan($source));
        if ($refusal === '') {
            self::assertSame([], $findings, $source);

            return;
        }
        if (str_starts_with($refusal, 'reads ')) {
            self::assertSame(['no user_id tie | 202_ppc_networks | ' . substr($refusal, 6)], $findings, $source);

            return;
        }
        if ($refusal === 'index') {
            // `$w[0]` is not a bare variable: the hole is never followed.
            self::assertSame(['no user_id tie | 202_ppc_networks | {$w[0]}'], $findings, $source);

            return;
        }
        self::assertSame(['no user_id tie | 202_ppc_networks | {$w}'], $findings, $source);
        $tokens = \PhpToken::tokenize($source);
        $byStart = [];
        foreach (SqlJoinScan::chains($source) as $chain) {
            $byStart[$chain['start']] = $chain;
        }
        $use = null;
        foreach ($tokens as $k => $tok) {
            $prev = $tokens[$k - 1]->text ?? '';
            if ($tok->text === '$w' && ($prev === '.' || ($k > 1 && $tokens[$k - 2]->text === '.'))) {
                $use = $k;
            }
        }
        self::assertNotNull($use, 'the planted read was not found');
        $read = LocalStringValue::at($tokens, LocalStringValue::bodies($tokens), $byStart, (int) $use);
        self::assertArrayHasKey('refused', $read, $source);
        self::assertStringContainsString($refusal, $read['refused'], $source);
    }

    /**
     * @return array<string, array{string, string, string}> the method holding
     *         the write, the rest of the class, and the problem it must be
     *         refused with ('' for a guarded write)
     */
    public static function guardShapes(): array
    {
        $write = "\$this->prepare('DELETE FROM 202_rotator_rules WHERE rotator_id = ?');";
        $unguarded = 'without owned() first';

        return [
            'the guard first' => ["public function f(int \$id): void { \$this->owned(\$id); $write }", '', ''],
            'the guard assigned' => ["public function f(int \$id): void { \$row = \$this->owned(\$id); $write }", '', ''],
            'the guard after the write' => ["public function f(int \$id): void { $write \$this->owned(\$id); }", '', $unguarded],
            'the guard in a braceless if' => ["public function f(int \$id): void { if (\$id > 0) \$this->owned(\$id); $write }", '', $unguarded],
            'the guard in a block' => ["public function f(int \$id): void { if (\$id > 0) { \$this->owned(\$id); } $write }", '', $unguarded],
            'the guard in a loop' => ["public function f(array \$ids): void { foreach (\$ids as \$id) { \$this->owned(\$id); } $write }", '', $unguarded],
            'the guard in the other branch' => ["public function f(int \$id): void { if (\$id > 0) { \$this->owned(\$id); } else { $write } }", '', $unguarded],
            'the guard before the closure' => ["public function f(int \$id): void { \$this->owned(\$id); \$this->tx(function () use (\$id): void { $write }); }", '', ''],
            'the guard in the closure' => ["public function f(int \$id): void { \$this->tx(function () use (\$id): void { \$this->owned(\$id); $write }); }", '', ''],
            'the guard after the closure' => ["public function f(int \$id): void { \$this->tx(function () use (\$id): void { $write }); \$this->owned(\$id); }", '', $unguarded],
            'every caller guarded' => ["private function w(int \$id): void { $write }", "public function a(int \$id): void { \$this->owned(\$id); \$this->w(\$id); }"
                . " public function b(int \$id): void { \$this->tx(function () use (\$id): void { \$this->owned(\$id); \$this->w(\$id); }); }", ''],
            'one caller not guarded' => ["private function w(int \$id): void { $write }", "public function a(int \$id): void { \$this->owned(\$id); \$this->w(\$id); }"
                . " public function b(int \$id): void { \$this->w(\$id); }", $unguarded],
            'a private method no one calls' => ["private function w(int \$id): void { $write }", '', $unguarded],
            'a private method named as a callable' => ["private function w(int \$id): void { $write }", "public function a(int \$id): void { \$this->owned(\$id); \$this->w(\$id); }"
                . " public function b(): array { return [\$this, 'w']; }", $unguarded],
            'a private method taken as a first-class callable' => ["private function w(int \$id): void { $write }", "public function a(int \$id): void { \$this->owned(\$id); \$this->w(\$id); }"
                . " public function b(): \\Closure { return \$this->w(...); }", $unguarded],
            'a public method guarded by its caller' => ["public function w(int \$id): void { $write }", "public function a(int \$id): void { \$this->owned(\$id); \$this->w(\$id); }", $unguarded],
            'the guard of another object' => ["public function f(int \$id): void { \$this->other->owned(\$id); $write }", '', $unguarded],
            'the guard named in a string' => ["public function f(int \$id): void { \$m = 'owned'; $write }", '', $unguarded],
            'a guard that answers null, in an if that leaves' => ["public function f(int \$id): void { if (\$this->found(\$id) === null) { return; } $write }", '', ''],
            'a guard that answers null, negated' => ["public function f(int \$id): void { if (!\$this->found(\$id)) throw new \\RuntimeException('no'); $write }", '', ''],
            'a guard that answers null, as a statement' => ["public function f(int \$id): void { \$this->found(\$id); $write }", '', 'without found() first'],
            'a guard that answers null, the wrong way round' => ["public function f(int \$id): void { if (\$this->found(\$id) !== null) { return; } $write }", '', 'without found() first'],
            'a guard whose if does not leave' => ["public function f(int \$id): void { if (\$this->found(\$id) === null) { \$this->log(); } $write }", '', 'without found() first'],
            'a guard whose if has an else' => ["public function f(int \$id): void { if (\$this->found(\$id) === null) { return; } else { \$this->log(); } $write }", '', 'without found() first'],
            'a guard whose read is not tied' => ["public function f(int \$id): void { \$this->loose(\$id); $write }", '', 'reads no 202_rotators row tied'],
            'a guard that reads through a helper' => ["public function f(int \$id): void { \$this->viaHelper(\$id); $write }", '', ''],
        ];
    }

    /** @dataProvider guardShapes */
    public function testTheGuardCheckReadsEveryShapeItClaims(string $method, string $rest, string $problem): void
    {
        $guard = preg_match('/->(found|loose|viaHelper)\(/', $method, $m) === 1 ? $m[1] : 'owned';
        $source = "<?php\nfinal class G\n{\n"
            . "    private function owned(int \$id): void { \$s = \$this->prepare('SELECT id FROM 202_rotators WHERE id = ? AND user_id = ?'); if (!\$s) { throw new \\RuntimeException('no'); } }\n"
            . "    private function found(int \$id): ?array { \$s = \$this->prepare('SELECT r.id FROM 202_rotators AS r WHERE r.id = ? AND r.user_id = ?'); return null; }\n"
            . "    private function loose(int \$id): void { \$s = \$this->prepare('SELECT id FROM 202_rotators WHERE id = ?'); throw new \\RuntimeException('no'); }\n"
            . "    private function viaHelper(int \$id): void { \$this->owned(\$id); }\n"
            . "    $method\n    $rest\n}\n";
        $writes = self::scan($source);
        self::assertCount(1, $writes, 'the write is read');
        $problems = self::guardProblems($source, $writes[0]['at'], ['calls' => [$guard], 'reads' => '202_rotators'], 'G');
        if ($problem === '') {
            self::assertSame([], $problems, $source);
        } else {
            self::assertCount(1, $problems, $source . implode("\n", $problems));
            self::assertStringContainsString($problem, $problems[0]);
        }
    }
}
