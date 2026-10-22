<?php

declare(strict_types=1);

namespace Prosper202\Update;

use Prosper202\Database\Connection;
use Prosper202\Report\RollupDirty;
use Tracking202\Report\ReportFilterInput;

/**
 * Update CPC: set what a set of past clicks cost.
 *
 * One implementation behind both surfaces that do it — the Update CPC page
 * (tracking202/update/cpc.php) and POST /api/v3/clicks/cpc — so the clicks a
 * request names, the count it confirms and what the write marks for the
 * reports cannot drift apart between them (CLAUDE.md #5).
 *
 * Two steps. The check counts the clicks and notes the highest click id
 * among them (preview()). The update carries both back (snapshot()) and runs
 * only if the selection bounded by that id still counts the same, counted
 * again under a lock in the transaction that writes (apply()): a window that
 * includes today keeps gaining clicks, and "Update 4 clicks" must never
 * change a fifth or one the person did not count.
 *
 * The days are the account's days: parse() reads them with mktime(), in the
 * process's default time zone, which the caller sets to the account's first
 * (the page through AUTH::set_timezone(), the API around the call).
 */
final class CpcUpdate
{
    /** The largest CPC 202_clicks.click_cpc (DECIMAL(7,5)) can hold. */
    public const MAX = '99.99999';

    /** The id filters a request may narrow by: field => what it names, for its sentence. */
    public const ID_FIELDS = [
        'aff_network_id' => 'category',
        'aff_campaign_id' => 'campaign',
        'ppc_network_id' => 'traffic source',
        'ppc_account_id' => 'traffic source account',
        'landing_page_id' => 'landing page',
        'text_ad_id' => 'text ad',
    ];

    /** The method-of-promotion values, with what each means. */
    public const METHODS = [
        '' => 'Direct links and landing pages',
        'directlink' => 'Direct links only',
        'landingpage' => 'Landing pages only',
    ];

    private function __construct()
    {
    }

    /**
     * Read an Update CPC request: which clicks, over which days, at what CPC.
     *
     * Pure: the caller has set the account's timezone (the days are the
     * account's days, 00:00:00 to 23:59:59). Ids are 0 for "all"; every id
     * that is not a whole number is refused under its field rather than read
     * as 0, which would widen the update to every click (error pattern #11:
     * a value that cannot be read must not resolve to the widest reading).
     *
     * @param array<string, mixed> $in the page's $_GET or $_POST, or the API's normalised body
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public static function parse(array $in): array
    {
        $values = [];
        $errors = [];
        foreach (self::ID_FIELDS as $field => $what) {
            $raw = $in[$field] ?? '';
            $raw = is_string($raw) ? trim($raw) : null;
            if ($raw === '' || $raw === '0') {
                $values[$field] = 0;
            } elseif ($raw !== null && ctype_digit($raw) && strlen($raw) <= 9) {
                $values[$field] = (int) $raw;
            } else {
                $values[$field] = 0;
                $errors[$field] = 'Choose a ' . $what . ' from the list.';
            }
        }

        $method = $in['method_of_promotion'] ?? '';
        $method = is_string($method) ? $method : null;
        if ($method === '' || $method === 'directlink' || $method === 'landingpage') {
            $values['method_of_promotion'] = $method;
        } else {
            $values['method_of_promotion'] = '';
            $errors['method_of_promotion'] = 'Choose direct links, landing pages, or both.';
        }

        $days = [];
        foreach (['from' => 'first', 'to' => 'last'] as $field => $which) {
            $raw = $in[$field] ?? '';
            $raw = is_string($raw) ? trim($raw) : '';
            $values[$field] = $raw;
            if ($raw === '') {
                $errors[$field] = 'Enter the ' . $which . ' day to update.';
                continue;
            }
            $date = ReportFilterInput::parseDate($raw);
            if ($date === null) {
                $errors[$field] = "'" . $raw . "' is not a date. Use the date picker, or type it as YYYY-MM-DD.";
                continue;
            }
            $days[$field] = $date;
        }
        if (isset($days['from'], $days['to'])) {
            [$fy, $fm, $fd] = $days['from'];
            [$ty, $tm, $td] = $days['to'];
            $values['from_time'] = (int) mktime(0, 0, 0, $fm, $fd, $fy);
            $values['to_time'] = (int) mktime(23, 59, 59, $tm, $td, $ty);
            if ($values['from_time'] > $values['to_time']) {
                $errors['to'] = 'The last day is before the first day.';
            }
        }

        $cpc = $in['cpc'] ?? '';
        $cpc = is_string($cpc) ? trim(ltrim(trim($cpc), '$')) : '';
        $values['cpc'] = $cpc;
        if ($cpc === '') {
            $errors['cpc'] = 'Enter the CPC these clicks cost, for example 0.25.';
        } elseif (!preg_match('/^\d{1,2}(\.\d{1,5})?$|^\.\d{1,5}$/', $cpc)) {
            $errors['cpc'] = is_numeric($cpc) && (float) $cpc > (float) self::MAX
                ? 'A CPC can be at most $' . self::MAX . '.'
                : "'" . $cpc . "' is not a CPC. Use a number of dollars with up to five decimals, for example 0.00125.";
        } else {
            // Normalised as the column stores it, so the preview and the update
            // say and write the same number.
            $values['cpc'] = number_format((float) $cpc, 5, '.', '');
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * The clicks a request names, as joins and a WHERE with bound values: the
     * same clause counts them for the check and updates them on apply, so the
     * number the person confirms is the number that changes.
     *
     * The joins and the method-of-promotion test are the classic handler's
     * (tracking202/ajax/update_cpc2.php before U5): a landing-page click is one
     * whose 202_clicks_site row names a landing page URL. Pure. $values is
     * parse()'s, with no errors.
     *
     * $throughClickId bounds the set to the clicks that existed when the person
     * checked it (the check's highest click id): the confirm step updates the
     * clicks the person counted, never ones recorded since (see snapshot()).
     * Null only for the check itself.
     *
     * A click's category and traffic source are read from its campaign and
     * traffic-source account only when those are the click's own account's:
     * a tracker could name another account's before the API checked linked
     * ids (229df10), and that account's row must not decide which of this
     * account's clicks are repriced. Such a click matches neither filter.
     *
     * @param array<string, mixed> $values
     * @return array{joins: string, where: string, types: string, params: list<int>}
     */
    public static function scope(array $values, int $userId, ?int $throughClickId = null): array
    {
        $joins = ' LEFT JOIN 202_clicks_advance ON (202_clicks_advance.click_id = 202_clicks.click_id)'
            . ' LEFT JOIN 202_clicks_site ON (202_clicks_site.click_id = 202_clicks.click_id)'
            . ' LEFT JOIN 202_aff_campaigns ON (202_clicks.aff_campaign_id = 202_aff_campaigns.aff_campaign_id AND 202_aff_campaigns.user_id = 202_clicks.user_id)'
            . ' LEFT JOIN 202_ppc_accounts ON (202_ppc_accounts.ppc_account_id = 202_clicks.ppc_account_id AND 202_ppc_accounts.user_id = 202_clicks.user_id)';
        $where = ' WHERE 202_clicks.user_id = ? AND 202_clicks.click_time >= ? AND 202_clicks.click_time <= ?';
        $types = 'iii';
        $params = [$userId, (int) $values['from_time'], (int) $values['to_time']];
        $filters = [
            'aff_network_id' => '202_aff_campaigns.aff_network_id',
            'aff_campaign_id' => '202_clicks.aff_campaign_id',
            'text_ad_id' => '202_clicks_advance.text_ad_id',
            'landing_page_id' => '202_clicks.landing_page_id',
            'ppc_network_id' => '202_ppc_accounts.ppc_network_id',
            'ppc_account_id' => '202_clicks.ppc_account_id',
        ];
        foreach ($filters as $field => $column) {
            if ((int) ($values[$field] ?? 0) > 0) {
                $where .= ' AND ' . $column . ' = ?';
                $types .= 'i';
                $params[] = (int) $values[$field];
            }
        }
        if ($throughClickId !== null) {
            $where .= ' AND 202_clicks.click_id <= ?';
            $types .= 'i';
            $params[] = $throughClickId;
        }
        if (($values['method_of_promotion'] ?? '') === 'landingpage') {
            $where .= ' AND 202_clicks_site.click_landing_site_url_id != 0';
        } elseif (($values['method_of_promotion'] ?? '') === 'directlink') {
            $where .= ' AND 202_clicks_site.click_landing_site_url_id = 0';
        }
        return ['joins' => $joins, 'where' => $where, 'types' => $types, 'params' => $params];
    }

    /**
     * What the person confirmed on the check: how many clicks it counted, and
     * the highest click id among them. The confirm carries both, and the
     * update runs only if the same bounded selection still counts the same.
     *
     * Null when either is missing or is not a whole number: a confirm that
     * cannot say what it confirmed is refused and checked again, never read as
     * "no limit" (error pattern #11).
     *
     * @param array<string, mixed> $in the page's $_POST, or the API's normalised body
     * @return array{count: int, through: int}|null
     */
    public static function snapshot(array $in): ?array
    {
        $read = static function (mixed $raw): ?int {
            if (!is_string($raw) || $raw === '' || strlen($raw) > 18 || !ctype_digit($raw)) {
                return null;
            }
            return (int) $raw;
        };
        $count = $read($in['expect_clicks'] ?? null);
        $through = $read($in['through_click_id'] ?? null);
        if ($count === null || $through === null) {
            return null;
        }
        return ['count' => $count, 'through' => $through];
    }

    /**
     * The names of the rows the request narrows to, after checking each is the
     * account's: an id of another account's row is refused under its field
     * ("You can not modify other peoples cpc history."), never widened to all.
     *
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     * @return array<string, string> field => what the summary says
     */
    public static function labels(Connection $conn, array $values, int $userId, array &$errors): array
    {
        $lookups = [
            'aff_network_id' => ['202_aff_networks', 'aff_network_name', 'Every category'],
            'aff_campaign_id' => ['202_aff_campaigns', 'aff_campaign_name', 'Every campaign'],
            'ppc_network_id' => ['202_ppc_networks', 'ppc_network_name', 'Every traffic source'],
            'ppc_account_id' => ['202_ppc_accounts', 'ppc_account_name', 'Every account'],
            'landing_page_id' => ['202_landing_pages', 'landing_page_nickname', 'Every landing page'],
            'text_ad_id' => ['202_text_ads', 'text_ad_name', 'Every text ad'],
        ];
        $labels = [];
        foreach ($lookups as $field => [$table, $nameColumn, $all]) {
            $id = (int) ($values[$field] ?? 0);
            if ($id === 0) {
                $labels[$field] = $all;
                continue;
            }
            $row = OwnedRow::find($conn, $table, $field, $id, $userId);
            if ($row === null) {
                $errors[$field] = 'You can not modify other peoples cpc history.';
                continue;
            }
            $labels[$field] = (string) $row[$nameColumn];
        }
        $labels['method_of_promotion'] = self::METHODS[$values['method_of_promotion']] ?? '';
        return $labels;
    }

    /**
     * The check: how many clicks match now, and the highest click id among
     * them, which the confirm carries back. Counted with scope()'s clause, the
     * one the update runs.
     *
     * @param array<string, mixed> $values parse()'s, with no errors, labels() passed
     * @return array{matching: int, through_click_id: int}
     */
    public static function preview(Connection $conn, array $values, int $userId): array
    {
        $scope = self::scope($values, $userId);
        $stmt = $conn->prepareWrite('SELECT COUNT(DISTINCT 202_clicks.click_id) AS matching, COALESCE(MAX(202_clicks.click_id), 0) AS through_click_id FROM 202_clicks' . $scope['joins'] . $scope['where']);
        $conn->bind($stmt, $scope['types'], $scope['params']);
        $count = $conn->fetchOne($stmt);
        if ($count === null) {
            // A COUNT always answers one row; none means the read failed.
            throw new \RuntimeException('Update CPC: counting the clicks returned no row');
        }
        return ['matching' => (int) $count['matching'], 'through_click_id' => (int) $count['through_click_id']];
    }

    /**
     * The update: the clicks the check counted get the new CPC, in one
     * transaction with the marks that make the reports rebuild their hours.
     *
     * The confirm names the clicks the check counted: at most the highest id
     * it saw, and exactly as many. Counted again under a lock and written in
     * the same transaction, so the number confirmed is the number that
     * changes; when the selection moved (a click edited into it, or recorded
     * late below the boundary), nothing is written and null is returned — the
     * caller checks again (preview()) and asks again.
     *
     * @param array<string, mixed> $values parse()'s, with no errors, labels() passed
     * @param array{count: int, through: int} $snapshot what was confirmed (snapshot())
     * @return int|null how many clicks' cost changed (MySQL's affected rows: a
     *                  click that already cost this much is not counted), or
     *                  null when the selection no longer counts what was
     *                  confirmed and nothing was written
     */
    public static function apply(Connection $conn, array $values, int $userId, array $snapshot): ?int
    {
        $scope = self::scope($values, $userId, $snapshot['through']);
        return $conn->transaction(static function () use ($conn, $scope, $values, $userId, $snapshot): ?int {
            $stmt = $conn->prepareWrite('SELECT COUNT(DISTINCT 202_clicks.click_id) AS matching FROM 202_clicks' . $scope['joins'] . $scope['where'] . ' FOR UPDATE');
            $conn->bind($stmt, $scope['types'], $scope['params']);
            $row = $conn->fetchOne($stmt);
            if ($row === null) {
                // A COUNT always answers one row; none means the read failed.
                throw new \RuntimeException('Update CPC: counting the clicks returned no row');
            }
            if ((int) $row['matching'] !== $snapshot['count']) {
                return null;
            }

            $stmt = $conn->prepareWrite('UPDATE 202_clicks' . $scope['joins'] . ' SET 202_clicks.click_cpc = ?' . $scope['where']);
            $conn->bind($stmt, 's' . $scope['types'], array_merge([(string) $values['cpc']], $scope['params']));
            $updated = $conn->executeUpdate($stmt);

            // The data engine rebuilds the hours these clicks fall in, for
            // the slice they were chosen by.
            $dirty = $conn->prepareWrite('INSERT IGNORE INTO 202_dirty_hours SET ppc_account_id = ?, aff_campaign_id = ?, user_id = ?, click_time_from = ?, click_time_to = ?, aff_network_id = ?, text_ad_id = ?, landing_page_id = ?, ppc_network_id = ?');
            $conn->bind($dirty, 'iiiiiiiii', [(int) $values['ppc_account_id'], (int) $values['aff_campaign_id'], $userId, (int) $values['from_time'], (int) $values['to_time'],
                (int) $values['aff_network_id'], (int) $values['text_ad_id'], (int) $values['landing_page_id'], (int) $values['ppc_network_id']]);
            $conn->executeUpdate($dirty);
            // And the attribution report rollup, which sums click_cpc by
            // hour, for the same range (AttributionRollup rule 2).
            RollupDirty::timeRange($conn, $userId, (int) $values['from_time'], (int) $values['to_time']);
            return $updated;
        });
    }
}
