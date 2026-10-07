<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Tests\Support\SqlJoinScan;

/**
 * A row names another account's record only if its own account owns it.
 *
 * Every Setup record — campaign, category, traffic source and its account,
 * landing page, text ad, redirector, tracker — and every other table with a
 * user_id column belongs to one account. Clicks, conversions and the rest
 * name them by id, and before 229df10 nothing stopped a write naming
 * another account's id; those rows remain in installs. A read that joins
 * the named table on its id alone then serves another account's name (a
 * campaign, a landing page URL, an offer URL) inside this account's data,
 * and a write that does worse — values a conversion at another account's
 * payout. e2f274f fixed GET /clicks, 00e1fb6 the reports; this test is what
 * stops the next one.
 *
 * The invariant, per site: a JOIN onto an account-owned table ties that
 * table's user_id to the row it comes from — `ref.user_id = row.user_id`,
 * `= ?`, `= {$userId}`, `USING (… user_id …)`, or in the same query's WHERE
 * — so a row naming another account's record reads as naming nothing and is
 * still counted (ReportsController::dimensionJoin()'s semantics). The same
 * holds for a comma join, and for a subquery (IN, EXISTS, scalar, derived)
 * selecting from such a table: its WHERE must tie it.
 *
 * The account-owned tables are read from the schema (every table definition
 * with a user_id column), so a new table is covered when it is defined.
 *
 * What the scan reads (SqlJoinScan; testTheScannerReadsEverySpellingItClaims()
 * plants each): single- and double-quoted strings, heredocs and nowdocs,
 * concatenation with `.` (what is not a literal is a hole), interpolation,
 * sprintf() formats (a conversion reads as its argument), multi-line SQL,
 * upper or lower case, INNER / LEFT [OUTER] / RIGHT / CROSS / plain JOIN and
 * STRAIGHT_JOIN, `AS alias`, bare aliases, no alias, `backticks`, index
 * hints, parenthesized ON clauses, the tie on either side of `=`, SQL `--`,
 * `#` and `/* *\/` comments (a commented-out tie is no tie).
 *
 * What it refuses by name rather than reads (each fails unless listed below
 * with its reason): a table, alias or ON condition built at runtime (a hole
 * that is not a value — `= {$x}` and `IN ({$ids})` are values), an OR at the
 * top of the condition, a NATURAL join, a join with no ON, USING without
 * user_id, the word JOIN with nothing readable after it, a subquery whose
 * WHERE is built at runtime, and a tied ON clause whose string the next
 * string appends an OR to.
 *
 * What it does not see, and where that is covered instead: a table owned
 * through its parent (no user_id column: rotator rules, traffic-source
 * variables and pixels, goal versions, journeys, credits); a lookup by id
 * that is not a join (`SELECT … FROM 202_aff_campaigns WHERE
 * aff_campaign_id = ?` with an id read from another row — CLAUDE.md #27);
 * the SQL a runtime builder produces, which testRuntimeBuiltSqlIsTied()
 * runs; and a hole after a tied condition whose name says it is the next
 * clause (`$whereClause`, `{$joins}`), which is trusted to be one.
 */
final class AccountScopedJoinTest extends TestCase
{
    /** Never descended into: not the application's PHP. */
    private const PRUNED = ['vendor', 'node_modules', '.git', '.claude', 'tests', 'docs', 'documentation', 'go-cli', 'sdk', '202-css', '202-img', '202-js'];

    /** The schema's own definitions: DDL, no reads. */
    private const SKIPPED_FILES = ['202-config/Database/Tables/', '202-config/PHPStan/'];

    // Why a site in HARMLESS cannot cross accounts. Each names the writer
    // that makes it so; when that writer changes, so must the entry.

    private const SAME_CLICK = 'one click\'s own rows, joined on the click\'s id: they are written with the click, by the same redirect, so they are its account\'s';
    private const SAME_CLICK_CONVERSIONS = 'conversions of one click (`x.click_id = row.click_id`, or a reversal of the row): a conversion is recorded only on its own account\'s click (MysqlConversionRepository::recordLocked() locks `click_id = ? AND user_id = ?`), so every row of a click is that click\'s account\'s';
    private const JOURNEY_TOUCH = 'a credit\'s or journey\'s click: every touch is the conversion\'s own click (recorded only on the account\'s own click) or one JourneyBuilder read from the account\'s own visitor rows (`cv.user_id = ?`), and credits are computed only from journeys';
    private const VISITOR_CLICK = 'the click of the account\'s own visitor row (`cv.user_id = ?`): 202_clicks_visitor is keyed by click_id and written for the click with the click\'s owner (IdentityGraph::attachClick())';
    private const CREDIT_CONVERSION = 'the conversion a credit belongs to; the WHERE returned beside this join (creditSource(), effectiveSource()) is `cl.user_id = ?`';
    private const MODEL_OVERRIDE = 'read only for attribution_model_id, which picks a model only through `om.user_id = cl.user_id` (or `om.user_id = %d`): a campaign names only its own account\'s models (CampaignsController::attributionModelLink(), aff_campaigns.php), so another account\'s campaign selects none; kept the same in AttributionReports::creditSource(), AttributionRollup::effectiveSource() and CURRENT_OVERRIDES_SQL, which RollupMatchesFullComputationTest holds equal';
    private const ROLLUP_GUARD = 'a rollup guard subquery over the rollup\'s own tables, scoped `x.user_id = $u` (the plan\'s account); the runtime parts are integers and integer lists';
    private const RETENTION_SKIP = 'keeps a click id that has any row at or after the retention cutoff, whoever\'s row it is, on purpose: the delete that follows removes the id from every click table without a user_id (ClickRetention::deleteClicks()), so a tie here would let one account\'s expired row take another\'s recent one with it';
    private const DIRTY_MARKS = 'marks the hours a changed click or conversion sits in, for whichever account owns them: the account read is the row\'s own (its journey meta row by conv_id, the credit\'s model by model_id), and it is the one marked';
    private const RUNTIME_CHECKED = 'built per dimension at runtime; testRuntimeBuiltSqlIsTied() runs the builder for every dimension and scans what it returns';
    private const JOIN_REWRITE = 'str_replace() arguments that turn dimensionJoin()\'s INNER JOIN into a LEFT JOIN for the group report; the join itself is testRuntimeBuiltSqlIsTied()\'s';
    private const NOT_SQL = 'an exception message ("has no name join"), not SQL';
    private const CALLER_SCOPE = 'the caller\'s click scope over 202_dataengine (the report\'s own user filter); the rows it selects are one click\'s conversions';
    private const INSTALL_REGISTRATION = 'an install\'s registration: InstallIntake::insert() writes registration_id and user_id together from one registration, and no statement updates either';
    private const CLICK_DUPLICATES = 'counts other installs and install conversions on the token\'s click, read only when the click is the registration\'s own account\'s (InstallClassifier::withClick() refuses another\'s first); an install is attributed only to its own account\'s click, and a conversion is recorded only on its own account\'s click';
    private const INSTALL_CLICK = 'an install\'s click: InstallIntake records a click_id only for an attributed install, and InstallClassifier::withClick() attributes only a click of the install\'s own account';
    private const SUBJECT_CLICK = 'a click subject is the account\'s own click: GoalEngine::lockSubject() writes the subject row with the user the subject was read for (clickSubject(): `click_id = ? AND user_id = ?`)';
    private const WHERE_UNREAD = 'tied in its WHERE (`c.user_id = ?`), which the scan cannot read past the optional campaign filter appended to it';
    private const OUTCOME_BY_ID = 'the outcome the derived table `ac` selected, by its primary key; `ac` reads only the account\'s outcomes (`WHERE user_id = ?`)';
    private const OUTCOME_GOAL = 'an outcome\'s goal: the goals engine writes outcomes only for the goals it read for the subject\'s own account (MysqlGoalRepository specs: `g.user_id = ?`)';
    private const POSTBACK_FIRST_COPY = 'the first-copy subquery repeats the outer query\'s WHERE ($cvWhereClause, from buildFilters(), which begins `user_id = ?`)';
    private const FIELD_OF_VALUE = 'the definition of the account\'s own field value (`v.user_id = ?`): values are written only for a field read for the same account (MysqlCustomerFieldRepository::setValue() takes a row from list()/findByKey())';
    private const FIELD_VALUES_OF_OWN_FIELD = 'values of the account\'s own field (`f.user_id = ?`), written only under the same account (MysqlCustomerFieldRepository::setValue())';
    private const CUSTOMER_FIELD_FILTER = 'a custom-field filter join (`cfv0`…) on the values of the account\'s own customers (`c.user_id = ?` in buildCustomerScope()), with the field id bound';
    private const CUSTOMER_ROWS = 'revenue events and subscriptions of one customer id, which is the account\'s (checked by the outer WHERE or the caller): both are written only for a customer resolved within their account (insertRevenueEvent(), MysqlSubscriptionRepository::upsert() checks customerBelongsToUser())';
    private const EVENT_LINES = 'a revenue event\'s own line items (or a line item\'s event): line items are written only with their event and its user (MysqlCustomerRepository::insertLineItems(), the reversal copies `SELECT user_id … FROM` the original), and the driving rows are the account\'s';
    private const LTV_SPEND = 'the spend subquery\'s WHERE is $spendWhere, built two statements up as `user_id = ?` and time bounds';
    private const LTV_PRODUCT_SCOPE = 'the line items\' WHERE is $pcWhereClause, built above as `li.user_id = ?` and time bounds';
    private const LTV_PRODUCTS = 'the product of the account\'s own line items: a line item names only a product upserted for its account (MysqlCustomerRepository::upsertProduct($userId, …)); $mrrSubquery is the per-product MRR join after it, scoped `s.user_id = ?`';
    private const REVENUE_CONVERSION = 'the conversion of the account\'s own revenue event (`re.user_id = ?` or `r.user_id`): a revenue event is written for its conversion in the same account (MysqlConversionRepository::recordLocked())';
    private const WEBHOOK_DELIVERY = 'the dispatcher reads every account\'s due deliveries with their own endpoint: a delivery is written only for a webhook read for the same account (MysqlWebhookRepository::enqueue(): `WHERE user_id = ?`)';
    private const CLICK_TRACKER = 'the click\'s own tracker: 202_cpa_trackers records the tracker the click came through, and the redirect takes the click\'s user_id from that tracker';
    private const AUTH_KEY = 'the comma join\'s WHERE ties it: `2u.user_id = 2a.user_id`, with `2a.user_id` the signed-in account';
    private const UPGRADE = 'an upgrade or migration step over every account\'s rows: it serves nothing on one account\'s behalf';

    // Why a site is in KNOWN_UNSCOPED (it can show or use another
    // account's record, and is left to the change that owns the file).

    private const LEGACY_REPORT = 'legacy report page or data engine (the Analyze/overview/Visitors work owns these files): names, or groups by, another account\'s record when a click names one';
    private const SETUP_PAGE = 'legacy Setup or Get Links page: names another account\'s record when a Setup record names one';
    private const TRACKING_PATH = 'tracking path (a redirect or static endpoint resolving public ids from the URL): follows a tracker\'s, landing page\'s or rotator rule\'s stored id to another account\'s record; reported, not changed here';
    private const LEGACY_API = 'legacy API v1/v2 report: names another account\'s landing page or campaign when a click names one';
    private const CRON = 'daily-email cron (the cron work owns it): names another account\'s campaign when a click names one';

    /**
     * Category (b): reads that join an account-owned table without tying
     * it, and cannot show or use another account's row — each with why.
     * Keyed file => "kind | table | condition" (testEveryAllowlistedSiteStillExists()
     * keeps the list honest: an entry that matches nothing must go).
     *
     * @var array<string, array<string, string>>
     */
    private const HARMLESS = [
        '202-config/Attribution/AttributionReports.php' => [
            'no user_id tie | 202_clicks | c.click_id = cr.click_id {$joins}' => self::JOURNEY_TOUCH,
            'no user_id tie | 202_conversion_logs | cl.conv_id = cr.conv_id' => self::CREDIT_CONVERSION,
            'no user_id tie | 202_aff_campaigns | oc.aff_campaign_id = cl.campaign_id' => self::MODEL_OVERRIDE,
            'no user_id tie | 202_clicks | c.click_id = j.click_id {$joins}' => self::JOURNEY_TOUCH,
            'no user_id tie | 202_clicks | c.click_id = cr.click_id' => self::JOURNEY_TOUCH,
            'no user_id tie | 202_clicks | c.click_id = j.click_id' => self::JOURNEY_TOUCH,
            'condition built at runtime | 202_attribution_rollup_state | s.user_id = {$u} AND s.built_through_hour > {(int) $plan[\'maxHour\']} {($effective ? \' AND s.default_model_id = \'.(int) $plan[\'default\'] : \'\')}' => self::ROLLUP_GUARD,
            'condition built at runtime | 202_attribution_rollup_dirty | d.user_id = {$u} AND ( {implode(\' OR \', array_map(static fn (array $r): string => \'(d.hour_from <= \'.(int) $r[1].\' AND d.hour_to >= \'.(int) $r[0].\')\', $plan[\'runs\']))} )' => self::ROLLUP_GUARD,
            'condition built at runtime | 202_attribution_rollup | r2.user_id = {$u} AND r2.part IN ( {AttributionRollup::PART_CREDITS} , {AttributionRollup::PART_COST} , {AttributionRollup::PART_ASSISTS} ) AND r2.dim = {AttributionRollup::DIMENSION_CODES[\'day\']} AN…' => self::ROLLUP_GUARD,
            'subquery table built at runtime | {$table} | . {{$name}} FROM {{$table}} dn WHERE dn.{{$id}} = g.k {($owned ? \' AND dn.user_id = \'.$userId : \'\')}' => self::RUNTIME_CHECKED,
            'no table after JOIN | ? | no name join' => self::NOT_SQL,
            'no user_id tie | 202_clicks | c.click_id = cr.click_id {$joins} #2' => self::JOURNEY_TOUCH,
            'no user_id tie | 202_clicks | c.click_id = j.click_id {$joins} #2' => self::JOURNEY_TOUCH,
        ],
        '202-config/Attribution/AttributionRollup.php' => [
            'no user_id tie | 202_clicks | c.click_id = cr.click_id' => self::JOURNEY_TOUCH,
            'no user_id tie | 202_clicks | c.click_id = cr.click_id {$joins}' => self::JOURNEY_TOUCH,
            'no user_id tie | 202_clicks | c.click_id = cr.click_id {$joins} #2' => self::JOURNEY_TOUCH,
            'no user_id tie | 202_clicks | c.click_id = j.click_id {$joins}' => self::JOURNEY_TOUCH,
            'no user_id tie | 202_clicks | c.click_id = j.click_id' => self::JOURNEY_TOUCH,
            'no user_id tie | 202_conversion_logs | cl.conv_id = cr.conv_id' => self::CREDIT_CONVERSION,
            'no user_id tie | 202_aff_campaigns | oc.aff_campaign_id = cl.campaign_id' => self::MODEL_OVERRIDE,
            'no user_id tie | 202_aff_campaigns | oc.attribution_model_id = om.model_id' => self::MODEL_OVERRIDE,
        ],
        '202-config/Attribution/CountedAmount.php' => [
            'alias built at runtime | 202_conversion_logs | $r' => self::SAME_CLICK_CONVERSIONS,
        ],
        '202-config/Attribution/JourneyBuilder.php' => [
            'no user_id tie | 202_clicks | c.click_id = cv.click_id' => self::VISITOR_CLICK,
        ],
        '202-config/Click/ClickRetention.php' => [
            'no user_id tie | 202_clicks | n.click_id = c.click_id AND n.click_time >= ?' => self::RETENTION_SKIP,
        ],
        '202-config/Conversion/Ledger/LedgerReportSql.php' => [
            'alias built at runtime | 202_conversion_logs | $t' => self::SAME_CLICK_CONVERSIONS,
            'alias built at runtime | 202_conversion_logs | $b' => self::SAME_CLICK_CONVERSIONS,
            'no user_id tie | 202_conversion_logs | lx.click_id = lp.click_id AND lx.conv_id > lp.conv_id AND lx.reverses_conv_id IS NULL AND lx.deleted = 0 AND lx.payable = 1 AND ( lx.superseded_reason IS NULL OR lx.superseded_reason = \'\' )' => self::SAME_CLICK_CONVERSIONS,
            'subquery with runtime text before its WHERE | 202_conversion_logs | $goalId' => self::SAME_CLICK_CONVERSIONS,
            'condition built at runtime | 202_dataengine | {$clickScope}' => self::CALLER_SCOPE,
        ],
        '202-config/Goals/GoalEngine.php' => [
            'no user_id tie | 202_clicks | c.click_id = i.click_id' => self::INSTALL_CLICK,
            'no user_id tie | 202_clicks | c.click_id = s.subject_id' => self::SUBJECT_CLICK,
        ],
        '202-config/Goals/WebEvents.php' => [
            'no user_id tie | 202_clicks | c.click_id = o.click_id' => self::WHERE_UNREAD,
        ],
        '202-config/Ltv/MysqlCustomerCrmRepository.php' => [
            'no user_id tie | 202_customer_fields | f.field_id = v.field_id' => self::FIELD_OF_VALUE,
            'no user_id tie | 202_revenue_events | customer_id = ?' => self::CUSTOMER_ROWS,
            'no user_id tie | 202_subscriptions | customer_id = ?' => self::CUSTOMER_ROWS,
        ],
        '202-config/Ltv/MysqlLtvRepository.php' => [
            'condition built at runtime | 202_clicks | {{$spendWhere}}' => self::LTV_SPEND,
            'no user_id tie | 202_revenue_line_items | li_s.event_id = re_s.event_id AND li_s.product_id IS NOT NULL' => self::EVENT_LINES,
            'subquery without a WHERE | 202_revenue_line_items | 202_revenue_line_items' => self::LTV_PRODUCT_SCOPE,
            'no user_id tie | 202_revenue_events | re.event_id = li.event_id {{$customerJoin}} {{$pcWhereClause}}' => self::EVENT_LINES,
            'condition built at runtime | 202_products | p.product_id = pc.product_id {{$mrrSubquery}}' => self::LTV_PRODUCTS,
            'alias built at runtime | 202_customer_field_values | {$alias}' => self::CUSTOMER_FIELD_FILTER,
        ],
        '202-config/Ltv/MysqlPersonalizationRepository.php' => [
            'no user_id tie | 202_customer_field_values | v.field_id = f.field_id AND v.customer_id = ?' => self::FIELD_VALUES_OF_OWN_FIELD,
        ],
        '202-config/Ltv/MysqlRecommendationRepository.php' => [
            'no user_id tie | 202_conversion_logs | cl.conv_id = re.conv_id AND cl.deleted = 0 AND cl.campaign_id = r.campaign_id' => self::REVENUE_CONVERSION,
            'no user_id tie | 202_conversion_logs | cl.conv_id = re.conv_id AND cl.deleted = 0' => self::REVENUE_CONVERSION,
            'no user_id tie | 202_conversion_logs | cl.conv_id = re.conv_id AND cl.deleted = 0 #2' => self::REVENUE_CONVERSION,
            'no user_id tie | 202_conversion_logs | cl.conv_id = re.conv_id AND cl.deleted = 0 #3' => self::REVENUE_CONVERSION,
            'no user_id tie | 202_conversion_logs | cl.conv_id = re.conv_id AND cl.deleted = 0 #4' => self::REVENUE_CONVERSION,
        ],
        '202-config/Ltv/MysqlSubscriptionRepository.php' => [
            'no user_id tie | 202_subscriptions | customer_id = ?' => self::CUSTOMER_ROWS,
        ],
        '202-config/Ltv/MysqlWebhookRepository.php' => [
            'no user_id tie | 202_ltv_webhooks | w.webhook_id = d.webhook_id' => self::WEBHOOK_DELIVERY,
        ],
        '202-config/Report/MysqlReportRepository.php' => [
            'table built at runtime | {$bd[\'table\']} | INNER JOIN {{$bd[\'table\']}} ref ON {$on}' => self::RUNTIME_CHECKED,
        ],
        '202-config/Report/RollupDirty.php' => [
            'no user_id tie | 202_attribution_models | m.model_id = cr.model_id' => self::DIRTY_MARKS,
            'no user_id tie | 202_attribution_journey_meta | jm.conv_id = j.conv_id' => self::DIRTY_MARKS,
        ],
        '202-config/Update/SubidBatch.php' => [
            'no user_id tie | 202_conversion_logs | cl.click_id = c.click_id AND cl.deleted = 0' => self::SAME_CLICK_CONVERSIONS,
            'no user_id tie | 202_conversion_logs | cl.click_id = c.click_id AND cl.deleted = 0 #2' => self::SAME_CLICK_CONVERSIONS,
            'no user_id tie | 202_conversion_logs | cl.click_id = c.click_id AND cl.deleted = 0 #3' => self::SAME_CLICK_CONVERSIONS,
        ],
        '202-config/connect2.php' => [
            'no user_id tie | 202_trackers | `2trk`.tracker_id_public = `2cpa`.tracker_id_public' => self::CLICK_TRACKER,
            'USING without user_id | 202_clicks | ) LEFT JOIN 202_clicks USING ( click_id ) WHERE gclid = \'{0}\'' => self::SAME_CLICK,
        ],
        '202-config/functions-auth.php' => [
            'comma join | 202_users | SELECT * FROM 202_auth_keys `2a` , 202_users `2u` WHERE `2a`.expires > UNIX_TIMESTAMP' => self::AUTH_KEY,
        ],
        '202-config/functions-upgrade.php' => [
            'no user_id tie | 202_conversion_logs | transaction_id IS NOT NULL' => self::UPGRADE,
            'subquery without a WHERE | 202_users_pref | 202_users_pref' => self::UPGRADE,
        ],
        '202-config/migrations/run_ltv_backfill.php' => [
            'no user_id tie | 202_revenue_events | re.conv_id = cl.conv_id' => self::UPGRADE,
        ],
        '202-config/static-endpoint-helpers.php' => [
            'no user_id tie | 202_trackers | t.tracker_id_public = cp.tracker_id_public' => self::CLICK_TRACKER,
        ],
        '202-cronjobs/ltv_maintenance.php' => [
            'no user_id tie | 202_subscriptions | customer_id IN ( {{$in}} )' => self::CUSTOMER_ROWS,
            'no user_id tie | 202_revenue_events | customer_id BETWEEN {{$start}} AND {{$end}}' => self::CUSTOMER_ROWS,
            'no user_id tie | 202_subscriptions | customer_id BETWEEN {{$start}} AND {{$end}}' => self::CUSTOMER_ROWS,
        ],
        'api/v3/Apps/Android/InstallIntake.php' => [
            'no user_id tie | 202_app_registrations | r.registration_id = i.registration_id' => self::INSTALL_REGISTRATION,
            'no user_id tie | 202_app_installs | click_id = ? AND match_state = \'attributed\' AND install_row_id <> ?' => self::CLICK_DUPLICATES,
            'no user_id tie | 202_conversion_logs | click_id = ? AND dedupe_key = \'install\'' => self::CLICK_DUPLICATES,
        ],
        'api/v3/Apps/Android/Integrity/IntegrityVerifier.php' => [
            'no user_id tie | 202_app_registrations | r.registration_id = i.registration_id' => self::INSTALL_REGISTRATION,
            'no user_id tie | 202_app_registrations | r.registration_id = i.registration_id #2' => self::INSTALL_REGISTRATION,
        ],
        'api/v3/Apps/Android/Integrity/UnverifiableInstalls.php' => [
            'no user_id tie | 202_app_registrations | r.registration_id = 202_app_installs.registration_id' => self::INSTALL_REGISTRATION,
        ],
        'api/v3/Apps/Android/LockedInstall.php' => [
            'no user_id tie | 202_app_registrations | r.registration_id = i.registration_id' => self::INSTALL_REGISTRATION,
        ],
        'api/v3/Apps/Android/OrphanedPendingClicks.php' => [
            'no user_id tie | 202_app_registrations | r.registration_id = 202_app_installs.registration_id' => self::INSTALL_REGISTRATION,
        ],
        'api/v3/Apps/Android/PendingClickSettler.php' => [
            'no user_id tie | 202_app_registrations | r.registration_id = i.registration_id' => self::INSTALL_REGISTRATION,
        ],
        'api/v3/Controllers/AppNotificationsController.php' => [
            'no user_id tie | 202_goal_outcomes | o.outcome_id = ac.outcome_id' => self::OUTCOME_BY_ID,
            'no user_id tie | 202_goals | g.goal_id = o.goal_id' => self::OUTCOME_GOAL,
        ],
        'api/v3/Controllers/AppPostbacksController.php' => [
            'subquery with runtime text before its WHERE | 202_app_postbacks | $trusted' => self::POSTBACK_FIRST_COPY,
        ],
        'api/v3/Controllers/ReportsController.php' => [
            'no table after JOIN | ? | INNER JOIN' => self::JOIN_REWRITE,
            'no table after JOIN | ? | LEFT JOIN' => self::JOIN_REWRITE,
            'table built at runtime | {$bd[\'table\']} | INNER JOIN {{$bd[\'table\']}} ref ON {$on}' => self::RUNTIME_CHECKED,
        ],
        'tracking202/ajax/ltv_products.php' => [
            'no user_id tie | 202_revenue_events | re.event_id = li.event_id' => self::EVENT_LINES,
        ],
        'tracking202/redirect/off.php' => [
            'no user_id tie | 202_clicks | fc.click_id = `2cr`.click_id' => self::SAME_CLICK,
            'no user_id tie | 202_clicks | `2c`.click_id = `2cr`.click_id' => self::SAME_CLICK,
            'no user_id tie | 202_clicks_spy | `2c`.click_id = `2cs`.click_id' => self::SAME_CLICK,
        ],
        'tracking202/redirect/offrtr.php' => [
            'no user_id tie | 202_clicks | fc.click_id = `2c`.click_id' => self::SAME_CLICK,
            'no user_id tie | 202_clicks_spy | `2c`.click_id = `2cs`.click_id' => self::SAME_CLICK,
            'no user_id tie | 202_clicks | fc.click_id = `2c`.click_id #2' => self::SAME_CLICK,
            'no user_id tie | 202_clicks_spy | `2c`.click_id = `2cs`.click_id #2' => self::SAME_CLICK,
        ],
    ];

    /**
     * Category (c) left for another change: a read that can show another
     * account's name, in a file another piece of work owns (the legacy
     * Analyze pages and data engine, the redirects and static endpoints
     * that resolve public ids from URLs, the crons). This list only ever
     * shrinks.
     *
     * @var array<string, array<string, string>>
     */
    private const KNOWN_UNSCOPED = [
        '202-account/index.php' => [
            'no user_id tie | 202_ppc_networks | a.ppc_network_id = n.ppc_network_id' => self::SETUP_PAGE,
            'no user_id tie | 202_aff_networks | c.aff_network_id = n.aff_network_id' => self::SETUP_PAGE,
        ],
        '202-config/DataEngine/ClickRollupSql.php' => [
            'no user_id tie | 202_aff_campaigns | `2c`.aff_campaign_id = `2ac`.aff_campaign_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_aff_networks | `2ac`.aff_network_id = `2an`.aff_network_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_ppc_accounts | `2c`.ppc_account_id = `2pa`.ppc_account_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_ppc_networks | `2pa`.ppc_network_id = `2pn`.ppc_network_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_landing_pages | `2c`.landing_page_id = `2lp`.landing_page_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_text_ads | `2ca`.text_ad_id = `2ta`.text_ad_id' => self::LEGACY_REPORT,
        ],
        '202-config/DataEngine/GroupedReportRegistry.php' => [
            'no user_id tie | 202_text_ads | `2st`.text_ad_id = 202_text_ads.text_ad_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_landing_pages | `2st`.landing_page_id = 202_landing_pages.landing_page_id' => self::LEGACY_REPORT,
        ],
        '202-config/ReportSummaryForm.class.php' => [
            'no user_id tie | 202_aff_campaigns | `2c`.aff_campaign_id = `2ac`.aff_campaign_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_ppc_accounts | `2c`.ppc_account_id = `2pa`.ppc_account_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_ppc_networks | `2pa`.ppc_network_id = `2pn`.ppc_network_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_aff_networks | `2ac`.aff_network_id = `2an`.aff_network_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_landing_pages | `2c`.landing_page_id = `2lp`.landing_page_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_text_ads | `2c`.text_ad_id = `2ta`.text_ad_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_rotators | `2c`.rotator_id = `2rt`.id' => self::LEGACY_REPORT,
            'table built at runtime | \\Prosper202\\Conversion\\Ledger\\LedgerReportSql::partsTable($clickScope) | LEFT OUTER JOIN {\\Prosper202\\Conversion\\Ledger\\LedgerReportSql::partsTable($clickScope)} AS lcp ON ( `2c`.click_id = lcp.' => self::LEGACY_REPORT,
        ],
        '202-config/Tracker/MysqlTrackerRepository.php' => [
            'USING without user_id | 202_aff_campaigns | ) LEFT JOIN 202_aff_campaigns USING ( aff_campaign_id ) LEFT JOIN 202_ppc_accounts USING ( ppc_account_id' => self::TRACKING_PATH,
            'USING without user_id | 202_ppc_accounts | ) LEFT JOIN 202_ppc_accounts USING ( ppc_account_id ) LEFT JOIN ( SELECT ppc_network_id ,' => self::TRACKING_PATH,
        ],
        '202-config/class-dataengine.php' => [
            'USING without user_id | 202_landing_pages | LEFT OUTER JOIN 202_landing_pages USING ( landing_page_id ) {$this->mysql[\'user_id_query\']} AND `2st`.click_time >=' => self::LEGACY_REPORT,
            'no user_id tie | 202_aff_campaigns | `2c`.aff_campaign_id = `2ac`.aff_campaign_id' => self::LEGACY_REPORT,
            'USING without user_id | 202_landing_pages | LEFT JOIN 202_landing_pages USING ( landing_page_id )' => self::LEGACY_REPORT,
            'USING without user_id | 202_aff_campaigns | LEFT JOIN 202_aff_campaigns USING ( aff_campaign_id ) LEFT JOIN 202_aff_networks on ( `2st`.' => self::LEGACY_REPORT,
            'no user_id tie | 202_aff_networks | `2st`.aff_network_id = 202_aff_networks.`aff_network_id`' => self::LEGACY_REPORT,
            'no user_id tie | 202_ppc_accounts | `2st`.ppc_account_id = 202_ppc_accounts.ppc_account_id' => self::LEGACY_REPORT,
            'condition built at runtime | 202_ppc_networks | ( 202_ppc_accounts.ppc_network_id = 202_ppc_networks.ppc_network_id ) {$this->mysql[\'user_id_query\']} AND `2st`.{{$select_by_id}} IN ( {implode(",", $ids)} )' => self::LEGACY_REPORT,
            'condition built at runtime | 202_ppc_networks | ( 202_ppc_networks.ppc_network_id = `2st`.ppc_network_id ) {$filters[\'join\']} {$this->mysql[\'user_id_query\']} AND `2st`.variable_set_id != 0 AND click_time >= {$clickFrom} AND click_time <= {$clickTo…' => self::LEGACY_REPORT,
            'condition built at runtime | 202_ppc_networks | ( 202_ppc_networks.ppc_network_id = `2st`.ppc_network_id ) {$filters[\'join\']} {$this->mysql[\'user_id_query\']} AND `2st`.variable_set_id != 0 AND click_time >= {$clickFrom} AND click_time <= {$clickTo… #2' => self::LEGACY_REPORT,
            'USING without user_id | 202_aff_campaigns | LEFT JOIN 202_aff_campaigns USING ( aff_campaign_id )' => self::LEGACY_REPORT,
        ],
        '202-config/connect2.php' => [
            'USING without user_id | 202_aff_campaigns | ) LEFT JOIN 202_aff_campaigns USING ( aff_campaign_id ) LEFT JOIN 202_ppc_accounts USING ( ppc_account_id' => self::TRACKING_PATH,
            'USING without user_id | 202_ppc_accounts | ) LEFT JOIN 202_ppc_accounts USING ( ppc_account_id ) LEFT JOIN ( SELECT ppc_network_id ,' => self::TRACKING_PATH,
            'USING without user_id | 202_aff_campaigns | ) LEFT JOIN 202_aff_campaigns USING ( aff_campaign_id ) LEFT JOIN 202_ppc_accounts USING ( ppc_account_id #2' => self::TRACKING_PATH,
            'USING without user_id | 202_ppc_accounts | ) LEFT JOIN 202_ppc_accounts USING ( ppc_account_id ) LEFT JOIN 202_landing_pages USING ( landing_page_id' => self::TRACKING_PATH,
            'USING without user_id | 202_landing_pages | ) LEFT JOIN 202_landing_pages USING ( landing_page_id ) LEFT JOIN ( SELECT ppc_network_id ,' => self::TRACKING_PATH,
        ],
        '202-config/functions-tracking202.php' => [
            'no user_id tie | 202_ppc_accounts | `2c`.ppc_account_id = `2pa`.ppc_account_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_ppc_networks | `2pa`.ppc_network_id = `2pn`.ppc_network_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_aff_campaigns | `2c`.aff_campaign_id = `2ac`.aff_campaign_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_aff_networks | `2ac`.aff_network_id = `2an`.aff_network_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_ppc_accounts | `2pa2`.ppc_account_id = `2c`.ppc_account_id AND `2pa2`.ppc_network_id IS NOT NULL' => self::LEGACY_REPORT,
            'no user_id tie | 202_ppc_accounts | ppc_network_id = \'{0}\'' => self::LEGACY_REPORT,
        ],
        '202-config/functions-ui-overview.php' => [
            'no user_id tie | 202_ppc_networks | n.ppc_network_id = a.ppc_network_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_aff_networks | n.aff_network_id = c.aff_network_id' => self::LEGACY_REPORT,
            'table built at runtime | $lookup | AS d JOIN {$lookup} ON ( {$idColumn} = d.{$column} ) WHERE {$scope}' => self::LEGACY_REPORT,
        ],
        '202-cronjobs/daily-email.php' => [
            'USING without user_id | 202_aff_campaigns | ) LEFT JOIN 202_aff_campaigns AS `2ca` USING ( aff_campaign_id ) WHERE `2c`.click_time' => self::CRON,
            'USING without user_id | 202_aff_campaigns | ) LEFT JOIN 202_aff_campaigns AS `2ca` USING ( aff_campaign_id ) WHERE `2c`.aff_campaign_id' => self::CRON,
        ],
        'api/v1/functions.php' => [
            'USING without user_id | 202_aff_campaigns | 202_landing_pages LEFT JOIN 202_aff_campaigns USING ( aff_campaign_id ) WHERE 202_landing_pages.user_id = \'{0}\'' => self::LEGACY_API,
            'no user_id tie | 202_landing_pages | `2lp`.landing_page_id = `2c`.landing_page_id' => self::LEGACY_API,
            'table built at runtime | 202_…$type | LEFT OUTER JOIN 202_ {$type} AS `2l` ON ( `2l`.{$select_id} = `2ca`' => self::LEGACY_API,
            'table built at runtime | 202_…$type | LEFT OUTER JOIN 202_ {$type} AS `2l` ON ( `2l`.{$select_id} = `2ca` #2' => self::LEGACY_API,
        ],
        'api/v2/functions.php' => [
            'no user_id tie | 202_landing_pages | `2lp`.landing_page_id = `2c`.landing_page_id' => self::LEGACY_API,
            'table built at runtime | 202_…$type | LEFT OUTER JOIN 202_ {$type} AS `2l` ON ( `2l`.{$select_id} = `2ca`' => self::LEGACY_API,
            'table built at runtime | 202_…$type | LEFT OUTER JOIN 202_ {$type} AS `2l` ON ( `2l`.{$select_id} = `2ca` #2' => self::LEGACY_API,
        ],
        'tracking202/Report/ReportPrefsStore.php' => [
            'no user_id tie | 202_ppc_networks | n.ppc_network_id = a.ppc_network_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_aff_networks | n.aff_network_id = c.aff_network_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_aff_campaigns | c.aff_campaign_id = lp.aff_campaign_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_aff_campaigns | c.aff_campaign_id = t.aff_campaign_id' => self::LEGACY_REPORT,
            'condition built at runtime | 202_dataengine | `2st`.region_id = r.region_id AND {($dataUserId === null ? \'2st.user_id != 0\' : \'2st.user_id = \'.$dataUserId)} AND `2st`.click_time >= ? AND `2st`.click_time <= ?' => self::LEGACY_REPORT,
            'condition built at runtime | 202_dataengine | `2st`.isp_id = i.isp_id AND {($dataUserId === null ? \'2st.user_id != 0\' : \'2st.user_id = \'.$dataUserId)} AND `2st`.click_time >= ? AND `2st`.click_time <= ?' => self::LEGACY_REPORT,
        ],
        'tracking202/ajax/click_history.php' => [
            'no user_id tie | 202_conversion_logs | cvl.click_id = `2c`.click_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_aff_campaigns | `2c`.aff_campaign_id = `2ac`.aff_campaign_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_ppc_accounts | `2c`.ppc_account_id = `2pa`.ppc_account_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_ppc_networks | `2pa`.ppc_network_id = `2pn`.ppc_network_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_landing_pages | 202_landing_pages.landing_page_id = `2c`.landing_page_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_text_ads | `2c`.text_ad_id = `2ta`.text_ad_id' => self::LEGACY_REPORT,
        ],
        'tracking202/ajax/generate_tracking_link.php' => [
            'USING without user_id | 202_aff_campaigns | 202_trackers LEFT JOIN 202_aff_campaigns USING ( aff_campaign_id ) LEFT JOIN 202_aff_networks USING ( aff_network_id' => self::SETUP_PAGE,
            'USING without user_id | 202_aff_networks | ) LEFT JOIN 202_aff_networks USING ( aff_network_id ) LEFT JOIN 202_text_ads USING ( text_ad_id' => self::SETUP_PAGE,
            'USING without user_id | 202_text_ads | ) LEFT JOIN 202_text_ads USING ( text_ad_id ) LEFT JOIN 202_ppc_accounts USING ( ppc_account_id' => self::SETUP_PAGE,
            'USING without user_id | 202_ppc_accounts | ) LEFT JOIN 202_ppc_accounts USING ( ppc_account_id ) LEFT JOIN 202_ppc_networks USING ( ppc_network_id' => self::SETUP_PAGE,
            'USING without user_id | 202_ppc_networks | ) LEFT JOIN 202_ppc_networks USING ( ppc_network_id ) LEFT JOIN 202_landing_pages ON ( 202_trackers' => self::SETUP_PAGE,
            'no user_id tie | 202_landing_pages | 202_trackers.landing_page_id = 202_landing_pages.landing_page_id' => self::SETUP_PAGE,
            'no user_id tie | 202_rotators | 202_trackers.rotator_id = 202_rotators.id' => self::SETUP_PAGE,
            'USING without user_id | 202_aff_networks | 202_aff_campaigns LEFT JOIN 202_aff_networks USING ( aff_network_id ) WHERE aff_campaign_id = \'{0}\'' => self::SETUP_PAGE,
            'USING without user_id | 202_ppc_networks | 202_ppc_accounts LEFT JOIN 202_ppc_networks USING ( ppc_network_id ) WHERE ppc_account_id = \'{0}\'' => self::SETUP_PAGE,
        ],
        'tracking202/ajax/get_landing_code.php' => [
            'USING without user_id | 202_aff_campaigns | 202_landing_pages LEFT JOIN 202_aff_campaigns USING ( aff_campaign_id ) LEFT JOIN 202_aff_networks USING ( aff_network_id' => self::SETUP_PAGE,
            'USING without user_id | 202_aff_networks | ) LEFT JOIN 202_aff_networks USING ( aff_network_id ) WHERE landing_page_id = \'{0}\' AND 202_landing_pages' => self::SETUP_PAGE,
        ],
        'tracking202/ajax/rotator.php' => [
            'no user_id tie | 202_aff_campaigns | `2ro`.default_campaign = `2ac`.aff_campaign_id' => self::SETUP_PAGE,
            'no user_id tie | 202_landing_pages | `2ro`.default_lp = `2lp`.landing_page_id' => self::SETUP_PAGE,
        ],
        'tracking202/ajax/sort_rotator.php' => [
            'no user_id tie | 202_aff_campaigns | ac.aff_campaign_id = rr.redirect_campaign' => self::LEGACY_REPORT,
            'no user_id tie | 202_landing_pages | lp.landing_page_id = rr.redirect_lp AND lp.landing_page_deleted = 0' => self::LEGACY_REPORT,
        ],
        'tracking202/redirect/cl.php' => [
            'comma join | 202_aff_campaigns | , user_pref_cloak_referer FROM 202_clicks , 202_clicks_record , 202_clicks_site , 202_site_urls , 202_aff_campaigns , 202_users_pref' => self::TRACKING_PATH,
            'comma join | 202_users_pref | , user_pref_cloak_referer FROM 202_clicks , 202_clicks_record , 202_clicks_site , 202_site_urls , 202_aff_campaigns , 202_users_pref' => self::TRACKING_PATH,
        ],
        'tracking202/redirect/dl.php' => [
            'USING without user_id | 202_aff_campaigns | ) LEFT JOIN 202_aff_campaigns USING ( aff_campaign_id ) LEFT JOIN 202_ppc_accounts USING ( ppc_account_id' => self::TRACKING_PATH,
            'USING without user_id | 202_ppc_accounts | ) LEFT JOIN 202_ppc_accounts USING ( ppc_account_id ) LEFT JOIN ( SELECT ppc_network_id ,' => self::TRACKING_PATH,
        ],
        'tracking202/redirect/lp.php' => [
            'comma join | 202_aff_campaigns | . aff_campaign_cloaking FROM 202_landing_pages , 202_aff_campaigns WHERE 202_landing_pages.landing_page_id_public = \'{0}\' AND 202_aff_campaigns' => self::TRACKING_PATH,
        ],
        'tracking202/redirect/lpc.php' => [
            'USING without user_id | 202_aff_campaigns | 202_landing_pages LEFT JOIN 202_aff_campaigns USING ( aff_campaign_id ) WHERE landing_page_id_public = \'{0}\'' => self::TRACKING_PATH,
        ],
        'tracking202/redirect/offrtr.php' => [
            'no user_id tie | 202_aff_campaigns | ac.aff_campaign_id = rt.default_campaign' => self::TRACKING_PATH,
            'no user_id tie | 202_landing_pages | lp.landing_page_id = rt.default_lp' => self::TRACKING_PATH,
            'no user_id tie | 202_aff_campaigns | ca.aff_campaign_id = rur.redirect_campaign' => self::TRACKING_PATH,
            'no user_id tie | 202_landing_pages | lp.landing_page_id = rur.redirect_lp' => self::TRACKING_PATH,
        ],
        'tracking202/redirect/rtr.php' => [
            'no user_id tie | 202_rotators | rt.id = tr.rotator_id' => self::TRACKING_PATH,
            'no user_id tie | 202_aff_campaigns | ca.aff_campaign_id = rt.default_campaign' => self::TRACKING_PATH,
            'no user_id tie | 202_landing_pages | lp.landing_page_id = rt.default_lp' => self::TRACKING_PATH,
            'no user_id tie | 202_aff_campaigns | ca.aff_campaign_id = rur.redirect_campaign' => self::TRACKING_PATH,
            'no user_id tie | 202_landing_pages | lp.landing_page_id = rur.redirect_lp' => self::TRACKING_PATH,
        ],
        'tracking202/setup/_includes/landing_code_page.php' => [
            'no user_id tie | 202_aff_campaigns | ac.aff_campaign_id = lp.aff_campaign_id' => self::SETUP_PAGE,
        ],
        'tracking202/setup/aff_campaigns.php' => [
            'USING without user_id | 202_aff_networks | `2cp` LEFT JOIN 202_aff_networks AS `2an` USING ( aff_network_id ) WHERE `2cp`.user_id' => self::SETUP_PAGE,
            'no user_id tie | 202_dni_networks | af.dni_network_id = dni.id' => self::SETUP_PAGE,
        ],
        'tracking202/setup/get_trackers.php' => [
            'no user_id tie | 202_landing_pages | `2tr`.landing_page_id = `2lp`.landing_page_id' => self::SETUP_PAGE,
            'no user_id tie | 202_aff_campaigns | `2tr`.aff_campaign_id = `2ac`.aff_campaign_id' => self::SETUP_PAGE,
            'no user_id tie | 202_ppc_accounts | `2tr`.ppc_account_id = `2pa`.ppc_account_id' => self::SETUP_PAGE,
            'no user_id tie | 202_aff_campaigns | ac.aff_campaign_id = lp.aff_campaign_id' => self::SETUP_PAGE,
            'no user_id tie | 202_ppc_networks | pn.ppc_network_id = pa.ppc_network_id' => self::SETUP_PAGE,
            'no user_id tie | 202_landing_pages | tr.landing_page_id = lp.landing_page_id' => self::SETUP_PAGE,
            'no user_id tie | 202_aff_campaigns | tr.aff_campaign_id = ac.aff_campaign_id' => self::SETUP_PAGE,
            'no user_id tie | 202_rotators | tr.rotator_id = ro.id' => self::SETUP_PAGE,
            'no user_id tie | 202_ppc_accounts | tr.ppc_account_id = ppc.ppc_account_id' => self::SETUP_PAGE,
        ],
        'tracking202/setup/landing_pages.php' => [
            'USING without user_id | 202_aff_campaigns | 202_landing_pages LEFT JOIN 202_aff_campaigns USING ( aff_campaign_id ) WHERE 202_landing_pages.user_id = \'{0}\'' => self::SETUP_PAGE,
        ],
        'tracking202/setup/text_ads.php' => [
            'USING without user_id | 202_aff_campaigns | 202_text_ads LEFT JOIN 202_aff_campaigns USING ( aff_campaign_id ) LEFT JOIN 202_landing_pages USING ( landing_page_id' => self::SETUP_PAGE,
            'USING without user_id | 202_landing_pages | ) LEFT JOIN 202_landing_pages USING ( landing_page_id ) WHERE 202_text_ads.user_id = \'{0}\'' => self::SETUP_PAGE,
        ],
        'tracking202/static/get_custom_vars.php' => [
            'USING without user_id | 202_ppc_accounts | 202_trackers LEFT JOIN 202_ppc_accounts USING ( ppc_account_id ) LEFT JOIN ( SELECT ppc_network_id ,' => self::TRACKING_PATH,
        ],
        'tracking202/static/gpb.php' => [
            'USING without user_id | 202_clicks | ) LEFT JOIN `202_clicks` AS `2c` USING ( `click_id` ) LEFT JOIN `202_tracking_c1` AS' => self::TRACKING_PATH,
            'condition built at runtime | 202_trackers | ( `2cpa`.`tracker_id_public` = `2trc`.`tracker_id_public` ) {$site_urls}' => self::TRACKING_PATH,
        ],
        'tracking202/static/gpx.php' => [
            'USING without user_id | 202_trackers | ) LEFT JOIN 202_trackers USING ( tracker_id_public ) WHERE click_id = \'{0}\'' => self::TRACKING_PATH,
        ],
        'tracking202/static/landing.php' => [
            'USING without user_id | 202_ppc_accounts | 202_trackers LEFT JOIN 202_ppc_accounts USING ( ppc_account_id ) LEFT JOIN ( SELECT ppc_network_id ,' => self::TRACKING_PATH,
            'no user_id tie | 202_trackers | tr.aff_campaign_id = lp.aff_campaign_id' => self::TRACKING_PATH,
            'USING without user_id | 202_ppc_accounts | aff_campaign_id LEFT JOIN 202_ppc_accounts USING ( ppc_account_id ) LEFT JOIN ( SELECT ppc_network_id ,' => self::TRACKING_PATH,
            'no user_id tie | 202_aff_campaigns | lpc.aff_campaign_id = lp.aff_campaign_id' => self::TRACKING_PATH,
            'no user_id tie | 202_trackers | tr.tracker_id_public = ?' => self::TRACKING_PATH,
            'no user_id tie | 202_aff_campaigns | trc.aff_campaign_id = tr.aff_campaign_id' => self::TRACKING_PATH,
        ],
        'tracking202/static/record_adv.php' => [
            'no user_id tie | 202_aff_campaigns | ac.aff_campaign_id = tr.aff_campaign_id' => self::TRACKING_PATH,
            'USING without user_id | 202_ppc_accounts | aff_campaign_id LEFT JOIN 202_ppc_accounts AS `2ppc` USING ( ppc_account_id ) LEFT JOIN ( SELECT' => self::TRACKING_PATH,
        ],
        'tracking202/static/record_simple.php' => [
            'comma join | 202_aff_campaigns | . identity_signals FROM 202_landing_pages , 202_aff_campaigns WHERE 202_landing_pages.landing_page_id_public = \'{0}\' AND 202_aff_campaigns' => self::TRACKING_PATH,
            'USING without user_id | 202_ppc_accounts | `2tr` LEFT JOIN 202_ppc_accounts AS `2ppc` USING ( ppc_account_id ) LEFT JOIN ( SELECT' => self::TRACKING_PATH,
        ],
        'tracking202/static/upx.php' => [
            'USING without user_id | 202_clicks | ) LEFT JOIN `202_clicks` AS `2c` USING ( `click_id` ) LEFT JOIN `202_tracking_c1` AS' => self::TRACKING_PATH,
            'condition built at runtime | 202_trackers | ( `2cpa`.`tracker_id_public` = `2trc`.`tracker_id_public` ) {$site_urls}' => self::TRACKING_PATH,
        ],
        'tracking202/update/_includes/update_ui.php' => [
            'no user_id tie | 202_ppc_networks | pn.ppc_network_id = pa.ppc_network_id' => self::SETUP_PAGE,
        ],
        'tracking202/visitors/download/index.php' => [
            'no user_id tie | 202_aff_campaigns | `2c`.aff_campaign_id = `2ac`.aff_campaign_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_ppc_accounts | `2c`.ppc_account_id = `2pa`.ppc_account_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_ppc_networks | `2pa`.ppc_network_id = `2pn`.ppc_network_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_landing_pages | 202_landing_pages.landing_page_id = `2c`.landing_page_id' => self::LEGACY_REPORT,
            'no user_id tie | 202_text_ads | 202_text_ads.text_ad_id = `2c`.text_ad_id' => self::LEGACY_REPORT,
        ],
    ];

    /** @var array<string, true>|null */
    private static ?array $tables = null;

    /** @var array<string, list<array{line: int, key: string}>>|null */
    private static ?array $tree = null;

    /** @return array<string, true> lower-case name => true, for every table definition with a user_id column */
    public static function accountTables(): array
    {
        if (self::$tables !== null) {
            return self::$tables;
        }
        $root = dirname(__DIR__, 2);
        $tables = [];
        $files = glob($root . '/202-config/Database/Tables/*Tables.php');
        if ($files === false || $files === []) {
            throw new \RuntimeException('no table definitions under 202-config/Database/Tables');
        }
        foreach ($files as $file) {
            $class = 'Prosper202\\Database\\Tables\\' . basename($file, '.php');
            foreach ($class::getDefinitions() as $definition) {
                if (preg_match('/^\s*`user_id`\s/m', $definition->createStatement) === 1) {
                    $tables[strtolower($definition->tableName)] = true;
                }
            }
        }

        return self::$tables = $tables;
    }

    /**
     * Every finding that is not a tie, for one PHP source, in source order.
     *
     * @return list<array{line: int, kind: string, table: string, detail: string, key: string}>
     */
    public static function scan(string $source): array
    {
        $chains = SqlJoinScan::chains($source);
        $out = [];
        foreach ($chains as $i => $chain) {
            foreach (SqlJoinScan::findings($chain['text'], $chain['holes'], self::accountTables()) as $f) {
                if ($f['kind'] === 'tied' && ($f['open'] ?? false) && isset($chains[$i + 1])) {
                    // The condition ran to the end of its string: an OR the
                    // next string starts with widens it.
                    $next = SqlJoinScan::sqlTokens($chains[$i + 1]['text'], $chains[$i + 1]['holes']);
                    if ($next !== [] && in_array($next[0]['u'], ['OR', 'XOR', '||'], true)) {
                        $f['kind'] = 'OR appended by the next string';
                    }
                }
                if ($f['kind'] === 'tied') {
                    continue;
                }
                $out[] = [
                    'line' => $chain['line'],
                    'kind' => $f['kind'],
                    'table' => $f['table'],
                    'detail' => $f['detail'],
                    'key' => $f['kind'] . ' | ' . $f['table'] . ' | ' . $f['detail'],
                ];
            }
        }

        return $out;
    }

    /** @return array<string, list<array{line: int, key: string}>> repo-relative file => its findings */
    public static function treeFindings(): array
    {
        if (self::$tree !== null) {
            return self::$tree;
        }
        $root = dirname(__DIR__, 2);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            \Tests\Support\SourceScan::tree($root),
            static fn (\SplFileInfo $f): bool => !in_array($f->getFilename(), self::PRUNED, true)
        ));
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);
        $out = [];
        foreach ($files as $path) {
            foreach (self::SKIPPED_FILES as $skipped) {
                if (str_starts_with($path, $skipped)) {
                    continue 2;
                }
            }
            $source = file_get_contents($root . '/' . $path);
            if ($source === false) {
                throw new \RuntimeException("Could not read $path");
            }
            if (stripos($source, 'join') === false && stripos($source, 'from') === false && stripos($source, 'update') === false) {
                continue;
            }
            // The same text twice in a file is two sites: the second is
            // keyed "… #2", so a new copy of an allowlisted join is new.
            $seen = [];
            foreach (self::scan($source) as $f) {
                $n = $seen[$f['key']] = ($seen[$f['key']] ?? 0) + 1;
                $out[$path][] = ['line' => $f['line'], 'key' => $f['key'] . ($n > 1 ? " #$n" : '')];
            }
        }

        return self::$tree = $out;
    }

    public function testTheAccountTablesComeFromTheSchema(): void
    {
        $tables = self::accountTables();
        foreach (['202_aff_campaigns', '202_aff_networks', '202_ppc_networks', '202_ppc_accounts', '202_landing_pages', '202_text_ads', '202_rotators', '202_trackers', '202_clicks', '202_conversion_logs', '202_customers'] as $table) {
            self::assertArrayHasKey($table, $tables, "$table has a user_id column");
        }
        foreach (['202_clicks_advance', '202_keywords', '202_locations_country', '202_rotator_rules'] as $table) {
            self::assertArrayNotHasKey($table, $tables, "$table has no user_id column");
        }
        self::assertGreaterThan(60, count($tables));
    }

    public function testEveryReadOfAnAccountTableFromAnotherRowIsTiedToItsAccount(): void
    {
        $unexpected = [];
        foreach (self::treeFindings() as $file => $findings) {
            foreach ($findings as $f) {
                if (!isset(self::HARMLESS[$file][$f['key']]) && !isset(self::KNOWN_UNSCOPED[$file][$f['key']])) {
                    $unexpected[] = "  $file:{$f['line']}  {$f['key']}";
                }
            }
        }
        self::assertSame([], $unexpected, sprintf(
            "These reads join a table that belongs to an account without tying it to the row's account:\n%s\n"
            . "A row can name another account's record by id (nothing stopped a write naming one before 229df10),\n"
            . "so add `AND ref.user_id = row.user_id` (or `= ?` with the account) to the ON clause, as\n"
            . "ReportsController::dimensionJoin() does: such a row then reads as naming nothing and is still counted.\n"
            . 'If the read cannot cross accounts, or the scan cannot read it, list it in HARMLESS with the reason.',
            implode("\n", $unexpected)
        ));
    }

    public function testEveryAllowlistedSiteStillExists(): void
    {
        $tree = self::treeFindings();
        $stale = [];
        foreach (['HARMLESS' => self::HARMLESS, 'KNOWN_UNSCOPED' => self::KNOWN_UNSCOPED] as $list => $entries) {
            foreach ($entries as $file => $keys) {
                $present = array_column($tree[$file] ?? [], 'key');
                foreach (array_keys($keys) as $key) {
                    if (!in_array($key, $present, true)) {
                        $stale[] = "  $list: $file  $key";
                    }
                }
            }
        }
        self::assertSame([], $stale, "These entries match no site any more — remove them:\n" . implode("\n", $stale));
    }

    /**
     * The joins built at runtime (allowlisted above as unreadable) are run
     * here: every dimension's SQL, scanned as the database receives it.
     */
    public function testRuntimeBuiltSqlIsTied(): void
    {
        $checked = 0;
        $reports = new \ReflectionClass(\Api\V3\Controllers\ReportsController::class);
        $join = $reports->getMethod('dimensionJoin');
        foreach (array_keys($reports->getConstant('BREAKDOWNS')) as $dimension) {
            $sql = 'SELECT 1 FROM 202_dataengine de ' . $join->invoke(null, $dimension) . ' WHERE de.user_id = ?';
            $checked += $this->assertTied($sql, "ReportsController::dimensionJoin('$dimension')");
        }
        foreach (\Prosper202\Report\MysqlReportRepository::dimensions() as $dimension) {
            $sql = 'SELECT 1 FROM 202_dataengine de ' . \Prosper202\Report\MysqlReportRepository::dimensionJoin($dimension) . ' WHERE de.user_id = ?';
            $checked += $this->assertTied($sql, "MysqlReportRepository::dimensionJoin('$dimension')");
        }
        foreach (\Prosper202\Attribution\AttributionReports::dimensions() as $dimension) {
            [, , $joins] = \Prosper202\Attribution\AttributionReports::dimensionSql($dimension, 'c.click_time');
            $checked += $this->assertTied("SELECT 1 FROM 202_clicks c $joins WHERE c.user_id = ?", "AttributionReports::dimensionSql('$dimension')");
            $name = \Prosper202\Attribution\AttributionReports::nameSql($dimension, 7);
            $checked += $this->assertTied("SELECT $name FROM (SELECT 1 AS k, 1 AS named) g", "AttributionReports::nameSql('$dimension')");
        }
        self::assertGreaterThanOrEqual(20, $checked, 'the account dimensions of all three builders were read');
    }

    /** @return int how many account-table reads the SQL holds, all tied */
    private function assertTied(string $sql, string $label): int
    {
        $findings = SqlJoinScan::findings($sql, [], self::accountTables());
        foreach ($findings as $f) {
            self::assertSame('tied', $f['kind'], "$label: {$f['table']} — {$f['detail']}");
        }

        return count($findings);
    }

    /**
     * Each spelling the scan claims to read, planted untied (it must be
     * reported) and tied (it must not), and each it refuses.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function spellings(): array
    {
        $j = 'LEFT JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id';
        $tie = ' AND ac.user_id = c.user_id';
        $none = [];
        $untied = ['no user_id tie'];

        return [
            'single-quoted' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c $j';", $untied],
            'single-quoted, tied' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c $j$tie';", $none],
            'tie reversed' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c $j AND c.user_id = ac.user_id';", $none],
            'double-quoted with interpolation' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c $j WHERE c.click_id = {\$id}\";", $untied],
            'tied to an interpolated user id' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c $j AND ac.user_id = '{\$mysql['user_id']}'\";", $none],
            'tied to a bound value' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c $j AND ac.user_id = ?';", $none],
            'tied to a value not named as a user' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c $j AND ac.user_id = {\$other}\";", $untied],
            'tied to itself' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c $j AND ac.user_id = ac.user_id';", $untied],
            'tie not exact' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c $j AND ac.user_id = c.user_id + 0';", $untied],
            'tie as IN' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c $j AND ac.user_id IN (1, 2)';", $untied],
            'heredoc' => ["<?php \$s = <<<SQL\nSELECT 1\nFROM 202_clicks c\n$j\nSQL;\n", $untied],
            'heredoc, tied' => ["<?php \$s = <<<SQL\nSELECT 1\nFROM 202_clicks c\n$j\n  AND ac.user_id = {\$userId}\nSQL;\n", $none],
            'nowdoc' => ["<?php \$s = <<<'SQL'\nSELECT 1 FROM 202_clicks c $j\nSQL;\n", $untied],
            'concatenated' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c ' . self::COLS . ' $j' . \"\\n\";", $untied],
            'concatenated tie' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c $j' . ' AND ac.user_id = ' . (int) \$userId;", $none],
            'multi-line' => ["<?php \$s = 'SELECT 1\n  FROM 202_clicks c\n  LEFT JOIN 202_aff_campaigns ac\n    ON ac.aff_campaign_id = c.aff_campaign_id\n  WHERE 1';", $untied],
            'lower case' => ["<?php \$s = 'select 1 from 202_clicks c left join 202_aff_campaigns ac on ac.aff_campaign_id = c.aff_campaign_id';", $untied],
            'INNER JOIN' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c INNER JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id';", $untied],
            'plain JOIN' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id';", $untied],
            'LEFT OUTER JOIN' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c LEFT OUTER JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id';", $untied],
            'RIGHT JOIN' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c RIGHT JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id';", $untied],
            'STRAIGHT_JOIN' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c STRAIGHT_JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id';", $untied],
            'AS alias' => ["<?php \$s = 'SELECT 1 FROM 202_clicks AS c2 LEFT JOIN 202_aff_campaigns AS ac2 ON (c2.aff_campaign_id = ac2.aff_campaign_id)';", $untied],
            'AS alias, tied' => ["<?php \$s = 'SELECT 1 FROM 202_clicks AS c2 LEFT JOIN 202_aff_campaigns AS ac2 ON (c2.aff_campaign_id = ac2.aff_campaign_id AND ac2.user_id = c2.user_id)';", $none],
            'a digit-led alias (the legacy 2c)' => ["<?php \$s = 'SELECT 1 FROM 202_clicks 2c LEFT JOIN 202_aff_campaigns 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)';", $untied],
            'a digit-led alias, tied' => ["<?php \$s = 'SELECT 1 FROM 202_clicks 2c LEFT JOIN 202_aff_campaigns 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id AND 2ac.user_id = 2c.user_id)';", $none],
            'no alias' => ["<?php \$s = 'SELECT 1 FROM 202_clicks LEFT JOIN 202_aff_campaigns ON 202_aff_campaigns.aff_campaign_id = 202_clicks.aff_campaign_id';", $untied],
            'no alias, tied' => ["<?php \$s = 'SELECT 1 FROM 202_clicks LEFT JOIN 202_aff_campaigns ON 202_aff_campaigns.aff_campaign_id = 202_clicks.aff_campaign_id AND 202_aff_campaigns.user_id = 202_clicks.user_id';", $none],
            'backticks' => ["<?php \$s = 'SELECT 1 FROM `202_clicks` AS `c` LEFT JOIN `202_aff_campaigns` AS `ac` ON (`ac`.`aff_campaign_id` = `c`.`aff_campaign_id`)';", $untied],
            'backticks, tied' => ["<?php \$s = 'SELECT 1 FROM `202_clicks` AS `c` LEFT JOIN `202_aff_campaigns` AS `ac` ON (`ac`.`aff_campaign_id` = `c`.`aff_campaign_id` AND `ac`.`user_id` = `c`.`user_id`)';", $none],
            'index hint' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c LEFT JOIN 202_aff_campaigns ac USE INDEX (user_id) ON ac.aff_campaign_id = c.aff_campaign_id';", $untied],
            'tied in the WHERE' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id WHERE ac.user_id = ? AND c.click_id = ?';", $none],
            'WHERE with an OR' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id WHERE ac.user_id = ? OR c.click_id = ?';", $untied],
            'USING' => ["<?php \$s = 'SELECT 1 FROM 202_trackers LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)';", ['USING without user_id']],
            'USING, tied' => ["<?php \$s = 'SELECT 1 FROM 202_trackers LEFT JOIN 202_aff_campaigns USING (aff_campaign_id, user_id)';", $none],
            'sprintf, tied' => ["<?php \$s = sprintf('SELECT 1 FROM 202_clicks c $j AND ac.user_id = %d', \$userId);", $none],
            'sprintf, positional, tied' => ["<?php \$s = sprintf('SELECT %1\$s FROM 202_clicks c $j AND ac.user_id = %2\$d', \$cols, \$userId);", $none],
            'sprintf, not a user' => ["<?php \$s = sprintf('SELECT 1 FROM 202_clicks c $j AND ac.user_id = %d', \$campaignId);", $untied],
            'comma join' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c, 202_aff_campaigns ac WHERE ac.aff_campaign_id = c.aff_campaign_id';", ['comma join']],
            'IN subquery' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c WHERE c.aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_network_id = 3)';", $untied],
            'IN subquery, tied' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c WHERE c.aff_campaign_id IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE user_id = ? AND aff_network_id = 3)';", $none],
            'correlated subquery' => ["<?php \$s = 'SELECT (SELECT ac.aff_campaign_name FROM 202_aff_campaigns ac WHERE ac.aff_campaign_id = c.aff_campaign_id) FROM 202_clicks c';", $untied],
            'correlated subquery, tied' => ["<?php \$s = 'SELECT (SELECT ac.aff_campaign_name FROM 202_aff_campaigns ac WHERE ac.aff_campaign_id = c.aff_campaign_id AND ac.user_id = c.user_id) FROM 202_clicks c';", $none],
            'derived table' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c JOIN (SELECT aff_campaign_id FROM 202_aff_campaigns GROUP BY aff_campaign_id) d ON d.aff_campaign_id = c.aff_campaign_id';", ['subquery without a WHERE']],
            'multi-table UPDATE' => ["<?php \$s = 'UPDATE 202_clicks c, 202_aff_campaigns ac SET c.click_payout = ac.aff_campaign_payout WHERE ac.aff_campaign_id = c.aff_campaign_id';", ['comma join']],
            'UPDATE JOIN' => ["<?php \$s = 'UPDATE 202_clicks c JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id SET c.click_payout = ac.aff_campaign_payout';", $untied],
            'a table built at runtime' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c LEFT JOIN {\$table} ac ON ac.id = c.x\";", ['table built at runtime']],
            'a quoted table built at runtime' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c LEFT JOIN `\$table` ac ON ac.id = c.x\";", ['table built at runtime']],
            'a name finished at runtime' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c LEFT JOIN 202_{\$type} ac ON ac.id = c.x\";", ['table built at runtime']],
            'a name that cannot be an account table' => ["<?php \$s = \"SELECT 1 FROM 202_clicks_tracking ct JOIN 202_tracking_{\$c} t ON t.id = ct.x\";", $none],
            'an alias built at runtime' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c LEFT JOIN 202_aff_campaigns {\$a} ON {\$a}.aff_campaign_id = c.x\";", ['alias built at runtime']],
            'a condition built at runtime' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c $j{\$more}\";", ['condition built at runtime']],
            'a runtime part beside the tie' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c $j$tie {\$more}\";", ['condition built at runtime']],
            'the next clause named' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c $j$tie {\$whereClause}\";", $none],
            'an OR at the top' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c $j$tie OR 1 = 1';", ['OR at the top of the condition']],
            'an OR around the tie' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c LEFT JOIN 202_aff_campaigns ac ON (ac.aff_campaign_id = c.x OR ac.user_id = c.user_id)';", ['OR at the top of the condition']],
            'LEFT( does not end the condition' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c $j$tie AND LEFT(ac.n, 1) = 'a' OR 1\";", ['OR at the top of the condition']],
            'an OR the next string appends' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c $j$tie';\n\$s .= ' OR 1 = 1';", ['OR appended by the next string']],
            'NATURAL JOIN' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c NATURAL JOIN 202_aff_campaigns';", ['NATURAL join']],
            'no ON' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c CROSS JOIN 202_aff_campaigns ac WHERE 1';", ['no ON clause']],
            'JOIN with nothing after it' => ["<?php \$s = str_replace('INNER JOIN', 'LEFT JOIN', \$sql);", ['no table after JOIN', 'no table after JOIN']],
            'a subquery WHERE built at runtime' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c WHERE c.x IN (SELECT aff_campaign_id FROM 202_aff_campaigns WHERE {\$scope})\";", ['condition built at runtime']],
            'a commented-out tie (--)' => ["<?php \$s = \"SELECT 1 FROM 202_clicks c $j -- AND ac.user_id = c.user_id\n WHERE 1\";", $untied],
            'a commented-out tie (/* */)' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c $j /* AND ac.user_id = c.user_id */';", $untied],
            'not an account table' => ["<?php \$s = 'SELECT 1 FROM 202_clicks c LEFT JOIN 202_keywords k ON k.keyword_id = c.keyword_id';", $none],
            'an array key is not SQL' => ["<?php \$a = ['join' => 1, \$b['join']];", $none],
            'a quote between PHP blocks is not a string' => ["<?php if (1) { ?>\"<?php } \$s = 'SELECT 1 FROM 202_clicks c $j';", $untied],
        ];
    }

    /**
     * @dataProvider spellings
     * @param list<string> $kinds
     */
    public function testTheScannerReadsEverySpellingItClaims(string $php, array $kinds): void
    {
        self::assertSame($kinds, array_column(self::scan($php), 'kind'), $php);
    }
}
