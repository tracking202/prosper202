<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Auth;
use Api\V3\Controllers\StagedChangesController;
use Api\V3\Support\ServerStateStore;
use Prosper202\Database\Tables\UserTables;
use Prosper202\User\ServedPreferences;
use Tests\TestCase;

/**
 * `PUT /users/{id}/preferences` is stageable, and its handler answers with
 * the whole `202_users_pref` row. So the applier, who is deliberately *not*
 * the proposer, is served every column of that table. ServedPreferences
 * answers its secrets only as `{"stored": …}`; the staged-apply redactor is
 * the second line between an approver and the proposer's credentials, and
 * this holds it to the same columns.
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
 *    secrets (ServedPreferences::SERVED), so a NEW column is unclassified
 *    and must be redacted — adding a credential column and forgetting the
 *    needle fails here, and the only way to quiet it is for a person to
 *    state, on that list, that the column is not a secret;
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
     * to whoever applies a staged preferences write: the ones GET serves.
     * Everything else in the table must be redacted here as well, the
     * second line behind ServedPreferences.
     */
    private const NON_SECRET_COLUMNS = ServedPreferences::SERVED;

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
            . 'ServedPreferences::SERVED to record that it holds no credential.',
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
            'ServedPreferences::SERVED names columns that 202_users_pref no longer has'
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
                '202_api_keys' => ['user_id' => 5, 'user_active' => 1, 'scope' => '*'],
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
