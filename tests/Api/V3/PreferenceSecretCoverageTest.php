<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Auth;
use Api\V3\Controllers\StagedChangesController;
use Api\V3\Support\ServerStateStore;
use Prosper202\Database\Tables\UserTables;
use Tests\TestCase;

/**
 * `PUT /users/{id}/preferences` is stageable, and its handler answers with
 * `SELECT * FROM 202_users_pref` — the only `SELECT *` on the whole v3
 * controller surface. So the applier, who is deliberately *not* the
 * proposer, is served every column of that table, and the staged-apply
 * redactor is the only thing standing between an approver and the
 * proposer's credentials.
 *
 * The sibling guard in StagedChangesControllerTest enumerates the ten fields
 * the preferences *write* surface accepts; that is a much narrower set than
 * the row that comes back, so a credential column added to the table trips
 * nothing there. This walks the real table definition instead.
 *
 * Why it cannot go vacuous:
 *  - the column list comes from `UserTables::usersPref()`, and the parse is
 *    pinned to a plausible count and to named anchor columns, so a regex
 *    that stopped matching fails rather than passing on an empty set;
 *  - classification is an allowlist of the columns known *not* to be
 *    secrets, so a NEW column is unclassified and must be redacted — adding
 *    a credential column and forgetting the needle fails here, and the only
 *    way to quiet it is for a person to state, in this file, that the column
 *    is not a secret;
 *  - the allowlisted columns are asserted to survive intact, so a needle
 *    broad enough to swallow ordinary preference columns (`_key` would take
 *    `user_pref_keyword`) fails too;
 *  - the row is pushed through the real `apply()` path, not through the
 *    private redactor, so this measures what an applier is actually served
 *    (CLAUDE.md #9: the seam is the thing under test).
 */
final class PreferenceSecretCoverageTest extends TestCase
{
    /**
     * Columns of `202_users_pref` that are NOT credentials, and may be shown
     * to whoever applies a staged preferences write. Everything else in the
     * table must be redacted. Add a column here only after deciding it
     * carries nothing secret; if it does carry a secret, add a needle to
     * StagedChangesController::SECRET_KEY_SUBSTRINGS instead.
     *
     * (`cb_verified` is a flag, not the key; `revcontent_user_id` is an
     * account id whose paired `revcontent_user_secret` is the credential;
     * `lpo_status` and `lpo_ctx_kw` are the LPO on/off state, while
     * `lpo_site_key` and `lpo_bridge_config` are not.)
     */
    private const NON_SECRET_COLUMNS = [
        'user_id', 'user_pref_limit', 'user_pref_show', 'user_pref_time_from',
        'user_pref_time_to', 'user_pref_time_predefined', 'user_pref_adv',
        'user_pref_ppc_network_id', 'user_pref_ppc_account_id',
        'user_pref_aff_network_id', 'user_pref_aff_campaign_id',
        'user_pref_text_ad_id', 'user_pref_method_of_promotion',
        'user_pref_landing_page_id', 'user_pref_country_id',
        'user_pref_region_id', 'user_pref_device_id', 'user_pref_browser_id',
        'user_pref_platform_id', 'user_pref_isp_id', 'user_pref_subid',
        'user_pref_ip', 'user_pref_dynamic_bid', 'user_pref_referer',
        'user_pref_keyword', 'user_pref_breakdown', 'user_pref_chart',
        'user_cpc_or_cpv', 'user_keyword_searched_or_bidded',
        'user_pref_privacy', 'user_pref_referer_data', 'user_tracking_domain',
        'user_pref_group_2', 'user_pref_group_3', 'user_pref_group_4',
        'user_pref_group_1', 'cache_time', 'cb_verified', 'maxmind_isp',
        'chart_time_range', 'user_pref_cloak_referer', 'auto_cron',
        'user_daily_email', 'user_auto_database_optimization_days',
        'user_delete_data_clickid', 'user_account_currency',
        'revcontent_user_id', 'facebook_ads_linked', 'user_pref_ad_settings',
        'user_ltv_customer_cparam', 'user_ltv_personalization_fields',
        'user_ltv_score_weights', 'user_ltv_rec_fatigue', 'lpo_status',
        'lpo_ctx_kw',
    ];

    public function testEveryCredentialColumnOfUsersPrefIsRedactedOnApply(): void
    {
        $columns = self::usersPrefColumns();

        // The audit is only worth its assertions if discovery actually found
        // the table: pin the anchors and the size, so a broken parse cannot
        // report "nothing leaked" over an empty column list.
        self::assertGreaterThan(50, count($columns), 'the users_pref column parse found almost nothing');
        foreach (['user_id', 'user_pref_limit', 'ipqs_api_key', 'lpo_bridge_config'] as $anchor) {
            self::assertContains($anchor, $columns, "column discovery missed $anchor");
        }

        $audit = $this->auditApplyResult($columns);

        self::assertSame([], $audit['leaked'], sprintf(
            'these 202_users_pref columns reach the applier verbatim: %s. Either add a needle to '
            . 'StagedChangesController::SECRET_KEY_SUBSTRINGS, or list the column in '
            . 'PreferenceSecretCoverageTest::NON_SECRET_COLUMNS to record that it holds no credential.',
            implode(', ', $audit['leaked'])
        ));
        self::assertSame([], $audit['over_redacted'], sprintf(
            'these ordinary preference columns are being redacted, which makes an applied write '
            . 'unreadable: %s. Narrow the needle that matches them.',
            implode(', ', $audit['over_redacted'])
        ));

        // An allowlist that outlives its columns silently stops classifying
        // anything; every entry must still be a real column.
        self::assertSame(
            [],
            array_values(array_diff(self::NON_SECRET_COLUMNS, $columns)),
            'NON_SECRET_COLUMNS names columns that 202_users_pref no longer has'
        );
    }

    /**
     * The non-vacuity proof, in-tree: a credential column nobody has
     * classified must show up as leaked. `acme_partner_key` matches no
     * needle — that is the point — so the audit that reports [] above
     * reports it here.
     */
    public function testAnUnclassifiedCredentialColumnIsReportedAsLeaked(): void
    {
        $planted = array_merge(self::usersPrefColumns(), ['acme_partner_key']);

        $audit = $this->auditApplyResult($planted);

        self::assertSame(['acme_partner_key'], $audit['leaked']);
        self::assertSame([], $audit['over_redacted']);
    }

    /**
     * Push a synthetic `SELECT *` row — one sentinel value per column —
     * through stage() and apply(), and sort the columns by what the applier
     * got back.
     *
     * @param string[] $columns
     * @return array{leaked: string[], over_redacted: string[]}
     */
    private function auditApplyResult(array $columns): array
    {
        $row = [];
        foreach ($columns as $column) {
            $row[$column] = 'sentinel-' . $column;
        }

        $result = $this->applyPreferencesWriteReturning($row);

        $leaked = [];
        $overRedacted = [];
        foreach ($columns as $column) {
            self::assertArrayHasKey($column, $result, "apply() dropped $column from the result entirely");
            $shown = $result[$column];

            if (!in_array($column, self::NON_SECRET_COLUMNS, true)) {
                // Exactly the placeholder, not merely "changed": a value
                // mangled into a shortened form of itself has still leaked.
                if ($shown !== '[redacted]') {
                    $leaked[] = $column;
                }
            } elseif ($shown !== $row[$column]) {
                $overRedacted[] = $column;
            }
        }

        return ['leaked' => $leaked, 'over_redacted' => $overRedacted];
    }

    /**
     * Stage a preferences write, apply it with a dispatcher that answers
     * with $handlerRow, and hand back the `result` the applier is served.
     *
     * @param array<string, mixed> $handlerRow
     * @return array<string, mixed>
     */
    private function applyPreferencesWriteReturning(array $handlerRow): array
    {
        $dir = sys_get_temp_dir() . '/p202-pref-secret-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            $store = new ServerStateStore($dir);
            $db = $this->createMysqliMock([
                "SHOW COLUMNS FROM 202_api_keys LIKE 'scope'" => ['Field' => 'scope'],
                '202_api_keys' => ['user_id' => 5, 'scope' => '*'],
                '202_user_role' => [['role_name' => 'admin']],
            ]);
            $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
            $controller = new StagedChangesController($store, $auth);

            $changeId = (string)$controller
                ->stage('PUT', '/users/5/preferences', ['user_pref_limit' => 50], null)['data']['change_id'];
            $applied = $controller->apply(
                $changeId,
                static fn(): array => ['data' => $handlerRow]
            );

            $result = $applied['data']['result'];
            self::assertIsArray($result, 'apply() must hand the applier the write result');
            return $result;
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * The real column list of `202_users_pref`, read from the definition the
     * installer runs. A column line starts with a backticked name followed
     * by its type; index lines (`PRIMARY KEY (...)`) do not.
     *
     * @return string[]
     */
    private static function usersPrefColumns(): array
    {
        $sql = UserTables::usersPref()->createStatement;
        self::assertMatchesRegularExpression('/CREATE TABLE .*202_users_pref/', $sql);
        $matched = preg_match_all('/^\s*`([a-z0-9_]+)`\s+(?!\()/mi', $sql, $m);
        self::assertIsInt($matched);
        self::assertGreaterThan(0, $matched, 'the users_pref column parse matched nothing');
        return array_map('strtolower', $m[1]);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
