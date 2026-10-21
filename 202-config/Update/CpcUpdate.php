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
 * again in the transaction that writes (apply()): a window that includes
 * today keeps gaining clicks, and "Update 4 clicks" must never change a
 * fifth or one the person did not count.
 *
 * A redirector re-click gives a click another row at its own visit's time,
 * and a click costs what its first visit cost, copied onto every row
 * (FirstVisit; the reports read the latest row's copy, the pixels the first
 * row's). So a click is chosen by its first visit, its time and every
 * filter, and every row of it is written: choosing rows by their own time
 * changed the first row of a click re-clicked on a later day, which no
 * report reads, and repriced a click that arrived on another day when the
 * range held only its re-click.
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

    /**
     * Of a click's rows, keeps its first visit. A click visited more than
     * once has a 202_click_latest_visits record (LatestVisit::refresh()), and
     * of its rows the one with no earlier row of the account is kept. A click
     * with no record has one row, or is a re-click written before 1.9.78
     * that ReClickBackfill has not reached: every report counts such a click
     * at each of its visits until then (LatestVisit::isLatest()), so each of
     * its rows is taken as a first visit here too. Only a recorded click pays
     * for the probe, which reads the click_id index in every partition of
     * 202_clicks.
     */
    private const FIRST_VISIT_JOIN = ' LEFT JOIN 202_click_latest_visits AS cpc_record'
        . ' ON (cpc_record.click_id = 202_clicks.click_id)'
        . ' LEFT JOIN 202_clicks AS cpc_earlier ON (cpc_record.click_id IS NOT NULL'
        . ' AND cpc_earlier.click_id = 202_clicks.click_id AND cpc_earlier.user_id = 202_clicks.user_id'
        . ' AND cpc_earlier.click_time < 202_clicks.click_time)';

    /** Every visit of a click scope() chose, between two times (bound in that order). */
    private const VISIT_JOIN = ' INNER JOIN 202_clicks AS cpc_visit ON (cpc_visit.click_id = 202_clicks.click_id'
        . ' AND cpc_visit.user_id = 202_clicks.user_id AND cpc_visit.click_time >= ? AND cpc_visit.click_time <= ?)';

    /** The rows one read of apply() takes at most, as the lock counted them. */
    private const WINDOW_ROWS = 20000;

    /** The clicks one write names. */
    private const CHUNK = 1000;

    /** 202_clicks_spy.click_cpc is DECIMAL(4,2): a CPC above this does not fit it. */
    private const SPY_MAX = '99.99';

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
     * The clicks a request names, as joins and a WHERE with bound values that
     * select each one's first visit (FIRST_VISIT_JOIN): the same clause counts
     * them for the check and chooses them on apply, so the number the person
     * confirms is the number that changes.
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
        $rows = self::rowScope($values, $userId, $throughClickId);
        $rows['joins'] .= self::FIRST_VISIT_JOIN;
        $rows['where'] .= ' AND cpc_earlier.click_id IS NULL';

        return $rows;
    }

    /**
     * scope() without the first-visit test: every row the range and the
     * filters name, which apply() locks.
     *
     * @param array<string, mixed> $values
     * @return array{joins: string, where: string, types: string, params: list<int>}
     */
    private static function rowScope(array $values, int $userId, ?int $throughClickId): array
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
     * one the update chooses its clicks by.
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
     * The update: every row of the clicks the check counted gets the new
     * CPC, in one transaction with the marks that make the reports rebuild
     * the hours those rows are counted in.
     *
     * The confirm names the clicks the check counted: at most the highest id
     * it saw, and exactly as many. Every row the range and the filters name
     * is locked first: a click recorded late below the boundary waits for
     * this to commit, and so does a re-click of a chosen click, which locks
     * its first visit's row (MysqlConversionLedger::lockClick()). The rest is
     * read without a lock, from a snapshot that begins after those locks, so
     * it holds every visit the chosen clicks have: the first-visit test reads
     * the click_id index in every partition, and a locking read there holds
     * the gaps the current week's re-clicks insert into (measured: a re-click
     * of an older click waited out its lock timeout). Each click's rows are
     * then written by id within the times read. When the clicks read are not
     * as many as were confirmed (a click edited into the selection, or
     * recorded late below the boundary), everything is rolled back and null
     * returned: the caller checks again (preview()) and asks again.
     *
     * The report rows are sent back to the data engine's dirty-hours pass in
     * the same transaction: the range for the request's traffic source
     * account and campaign, and the hour of every row of these clicks that
     * pass does not reach (a visit after the range, or one whose own account
     * or campaign is not the request's) for that row's account. Queued, not
     * rolled up here by id: a month of clicks is hundreds of thousands of
     * two-statement rollups inside the request, where the queue is a row per
     * stretch of hours and the pass rolls each up in one statement.
     *
     * @param array<string, mixed> $values parse()'s, with no errors, labels() passed
     * @param array{count: int, through: int} $snapshot what was confirmed (snapshot())
     * @return int|null how many clicks' cost changed (a click whose every row
     *                  already cost this much is not counted), or null when
     *                  the selection no longer counts what was confirmed and
     *                  nothing was written
     */
    public static function apply(Connection $conn, array $values, int $userId, array $snapshot): ?int
    {
        $rows = self::rowScope($values, $userId, $snapshot['through']);
        $scope = self::scope($values, $userId, $snapshot['through']);
        // Thrown to roll the writes back; told apart from a failure by identity.
        $moved = new \RuntimeException('Update CPC: the clicks read are not the clicks confirmed');
        try {
            return $conn->transaction(static function () use (
                $conn,
                $rows,
                $scope,
                $values,
                $userId,
                $snapshot,
                $moved
            ): int {
                $stmt = $conn->prepareWrite(
                    'SELECT 202_clicks.click_time DIV 3600 AS h, COUNT(*) AS n FROM 202_clicks' . $rows['joins']
                    . $rows['where'] . ' GROUP BY h FOR UPDATE'
                );
                $conn->bind($stmt, $rows['types'], $rows['params']);
                $from = (int) $values['from_time'];
                $to = (int) $values['to_time'];
                $windows = self::windows($conn->fetchAll($stmt), $from, $to);

                $cpc = (string) $values['cpc'];
                $counted = 0;
                $changed = 0;
                $uncovered = [];
                $afterRange = [];
                $last = $windows === [] ? 0 : self::latestVisitTime($conn, $userId);
                $spyFrom = $windows === [] ? PHP_INT_MAX : self::spyFrom($conn, $userId);
                // A click with no record is chosen at each of its rows, and
                // the first window that chose it wrote every row after.
                $written = [];
                foreach ($windows as [$start, $end]) {
                    $read = self::visits($conn, $scope, $values, [$start, $end, $last, $spyFrom], $written);
                    $counted += count($read['clicks']);
                    if ($counted > $snapshot['count']) {
                        throw $moved;
                    }
                    $changed += count($read['changing']);
                    foreach ($read['uncovered'] as $account => $hours) {
                        $uncovered[$account] = ($uncovered[$account] ?? []) + $hours;
                    }
                    $afterRange += $read['after_range'];
                    foreach (array_chunk(array_keys($read['clicks']), self::CHUNK) as $chunk) {
                        self::writeVisits($conn, $cpc, $userId, $chunk, $start, $end);
                    }
                    foreach (array_chunk(array_keys($read['spied']), self::CHUNK) as $chunk) {
                        self::writeSpy($conn, $cpc, $userId, $chunk);
                    }
                    foreach (array_chunk($read['later'], self::CHUNK, true) as $chunk) {
                        self::writeVisits($conn, $cpc, $userId, array_keys($chunk), $end + 1, max($chunk));
                    }
                    $written += $read['later'];
                }
                if ($counted !== $snapshot['count']) {
                    throw $moved;
                }

                self::queueRollup($conn, $values, $userId, $uncovered);
                // And the attribution report rollup, which sums click_cpc by
                // hour (AttributionRollup rule 2).
                RollupDirty::timeRange($conn, $userId, $from, $to);
                RollupDirty::hourRuns($conn, [$userId => array_keys($afterRange)]);

                return $changed;
            });
        } catch (\RuntimeException $e) {
            if ($e !== $moved) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * The stretches apply() reads the clicks in: each hour of the range the
     * lock found rows in, cut into parts of about WINDOW_ROWS rows at most.
     *
     * @param list<array<string, mixed>> $hours h (unix time DIV 3600) and n, the rows in it
     * @return list<array{int, int}> first and last second, in order
     */
    private static function windows(array $hours, int $from, int $to): array
    {
        $windows = [];
        foreach ($hours as $row) {
            $hour = self::int($row, 'h');
            $start = max($from, $hour * 3600);
            $end = min($to, $hour * 3600 + 3599);
            $parts = intdiv(self::int($row, 'n') + self::WINDOW_ROWS - 1, self::WINDOW_ROWS);
            $span = intdiv($end - $start + $parts, $parts);
            for ($at = $start; $at <= $end; $at += $span) {
                $windows[] = [$at, min($end, $at + $span - 1)];
            }
        }
        sort($windows);

        return $windows;
    }

    /** The account's latest visit time, which bounds every row apply() reads. */
    private static function latestVisitTime(Connection $conn, int $userId): int
    {
        $stmt = $conn->prepareWrite('SELECT MAX(click_time) AS t FROM 202_clicks WHERE user_id = ?');
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            throw new \RuntimeException('Update CPC: reading the latest visit returned no row');
        }

        return self::int($row, 't');
    }

    /**
     * The time of the account's oldest 202_clicks_spy row (PHP_INT_MAX for
     * none): the Spy view holds a visit's copy, at the visit's time, for a
     * day, so a visit older than this has none to write.
     */
    private static function spyFrom(Connection $conn, int $userId): int
    {
        $stmt = $conn->prepareWrite('SELECT MIN(click_time) AS t FROM 202_clicks_spy WHERE user_id = ?');
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            throw new \RuntimeException('Update CPC: reading the Spy view\'s oldest row returned no row');
        }

        return ($row['t'] ?? null) === null ? PHP_INT_MAX : self::int($row, 't');
    }

    /**
     * Every row of the clicks whose first visit is in [$start, $end], read
     * with what the writes and the marks need, but those in $written.
     *
     * @param array{joins: string, where: string, types: string, params: list<int>} $scope
     * @param array<string, mixed> $values
     * @param array{int, int, int, int} $times the window's first and last second, the account's
     *                                        latest visit, and its oldest Spy row (spyFrom())
     * @param array<int, int> $written clicks an earlier window read and wrote every row of
     * @return array{clicks: array<int, true>, changing: array<int, true>, later: array<int, int>,
     *               uncovered: array<int, array<int, true>>, after_range: array<int, true>,
     *               spied: array<int, true>}
     *         spied: the clicks with a visit the Spy view may hold;
     *         later: click => its latest visit after $end; uncovered: traffic source account => the
     *         hours of the rows the request's dirty hour does not reach; after_range: the hours of
     *         the rows after the request's range (hours are unix time DIV 3600)
     */
    private static function visits(
        Connection $conn,
        array $scope,
        array $values,
        array $times,
        array $written
    ): array {
        [$start, $end, $last, $spyFrom] = $times;
        $joins = $scope['joins'] . self::VISIT_JOIN;
        $stmt = $conn->prepareWrite(
            'SELECT 202_clicks.click_id, cpc_visit.click_time, cpc_visit.ppc_account_id, cpc_visit.aff_campaign_id,'
            . ' cpc_visit.click_cpc <> CAST(? AS DECIMAL(7,5)) AS differs FROM 202_clicks' . $joins
            . $scope['where'] . ' AND 202_clicks.click_time >= ? AND 202_clicks.click_time <= ?'
        );
        $conn->bind(
            $stmt,
            'sii' . $scope['types'] . 'ii',
            array_merge([(string) $values['cpc'], $start, $last], $scope['params'], [$start, $end])
        );
        $from = (int) $values['from_time'];
        $to = (int) $values['to_time'];
        $account = (int) $values['ppc_account_id'];
        $campaign = (int) $values['aff_campaign_id'];
        $read = [
            'clicks' => [], 'changing' => [], 'later' => [], 'uncovered' => [], 'after_range' => [], 'spied' => [],
        ];
        foreach ($conn->fetchAll($stmt) as $row) {
            $click = self::int($row, 'click_id');
            if (isset($written[$click])) {
                continue;
            }
            $time = self::int($row, 'click_time');
            $rowAccount = self::int($row, 'ppc_account_id');
            $read['clicks'][$click] = true;
            if (self::int($row, 'differs') === 1) {
                $read['changing'][$click] = true;
            }
            if ($time > $end) {
                $read['later'][$click] = max($read['later'][$click] ?? 0, $time);
            }
            if ($time >= $spyFrom) {
                $read['spied'][$click] = true;
            }
            if ($time > $to) {
                $read['after_range'][intdiv($time, 3600)] = true;
            }
            // DataEngine::processDirtyHours() reaches the rows in the range of
            // the request's traffic source account and campaign.
            $reached = $time >= $from && $time <= $to
                && ($account === 0 || $rowAccount === $account)
                && ($campaign === 0 || self::int($row, 'aff_campaign_id') === $campaign);
            if (!$reached) {
                $read['uncovered'][$rowAccount][intdiv($time, 3600)] = true;
            }
        }

        return $read;
    }

    /**
     * The clicks' rows in [$from, $to]: bounded by time as well as by id, so
     * a write reaches only the partitions those rows are in.
     *
     * @param list<int> $clicks
     */
    private static function writeVisits(
        Connection $conn,
        string $cpc,
        int $userId,
        array $clicks,
        int $from,
        int $to
    ): void {
        $stmt = $conn->prepareWrite(
            'UPDATE 202_clicks SET click_cpc = ? WHERE user_id = ? AND click_time >= ? AND click_time <= ?'
            . ' AND click_id IN ('
            . implode(', ', array_fill(0, count($clicks), '?')) . ')'
        );
        $conn->bind($stmt, 'siii' . str_repeat('i', count($clicks)), array_merge([$cpc, $userId, $from, $to], $clicks));
        $conn->executeUpdate($stmt);
    }

    /**
     * The clicks' 202_clicks_spy copies: written by the ids it holds, since
     * an UPDATE by an id it lacks locks the gap that id falls in, where a
     * re-click writes its row.
     *
     * @param list<int> $clicks
     */
    private static function writeSpy(Connection $conn, string $cpc, int $userId, array $clicks): void
    {
        $in = implode(', ', array_fill(0, count($clicks), '?'));
        $stmt = $conn->prepareWrite(
            "SELECT DISTINCT click_id FROM 202_clicks_spy WHERE user_id = ? AND click_id IN ($in)"
        );
        $conn->bind($stmt, 'i' . str_repeat('i', count($clicks)), array_merge([$userId], $clicks));
        $held = array_map(static fn (array $row): int => self::int($row, 'click_id'), $conn->fetchAll($stmt));
        if ($held === []) {
            return;
        }
        $stmt = $conn->prepareWrite(
            'UPDATE 202_clicks_spy SET click_cpc = LEAST(CAST(? AS DECIMAL(7,5)), ' . self::SPY_MAX . ')'
            . ' WHERE user_id = ? AND click_id IN (' . implode(', ', array_fill(0, count($held), '?')) . ')'
        );
        $conn->bind($stmt, 'si' . str_repeat('i', count($held)), array_merge([$cpc, $userId], $held));
        $conn->executeUpdate($stmt);
    }

    /**
     * The data engine's queue (DataEngine::processDirtyHours(), which reads a
     * row's traffic source account and campaign and no other filter): the
     * request's range for its account and campaign, and each stretch of
     * hours of the rows that does not reach, for their own account. A row
     * already queued is armed again, so a pass that has just rolled it up
     * runs it once more.
     *
     * @param array<string, mixed> $values
     * @param array<int, array<int, true>> $uncovered traffic source account => hours
     */
    private static function queueRollup(Connection $conn, array $values, int $userId, array $uncovered): void
    {
        $stmt = $conn->prepareWrite(
            'INSERT INTO 202_dirty_hours SET ppc_account_id = ?, aff_campaign_id = ?, user_id = ?, click_time_from = ?,'
            . ' click_time_to = ?, aff_network_id = ?, text_ad_id = ?, landing_page_id = ?, ppc_network_id = ?'
            . ' ON DUPLICATE KEY UPDATE processed = 0, deleted = 0'
        );
        $conn->bind($stmt, 'iiiiiiiii', [
            (int) $values['ppc_account_id'], (int) $values['aff_campaign_id'], $userId, (int) $values['from_time'],
            (int) $values['to_time'], (int) $values['aff_network_id'], (int) $values['text_ad_id'],
            (int) $values['landing_page_id'], (int) $values['ppc_network_id'],
        ]);
        $conn->executeUpdate($stmt);

        $rows = [];
        foreach ($uncovered as $account => $hours) {
            foreach (self::runs(array_keys($hours)) as [$first, $last]) {
                array_push($rows, $account, $userId, $first * 3600, $last * 3600 + 3599);
            }
        }
        foreach (array_chunk($rows, 4 * 500) as $chunk) {
            $count = intdiv(count($chunk), 4);
            $stmt = $conn->prepareWrite(
                'INSERT INTO 202_dirty_hours (ppc_account_id, aff_campaign_id, user_id, click_time_from, click_time_to,'
                . ' landing_page_id) VALUES ' . implode(', ', array_fill(0, $count, '(?, 0, ?, ?, ?, 0)'))
                . ' ON DUPLICATE KEY UPDATE processed = 0, deleted = 0'
            );
            $conn->bind($stmt, str_repeat('iiii', $count), $chunk);
            $conn->executeUpdate($stmt);
        }
    }

    /**
     * Hours as runs of consecutive hours.
     *
     * @param list<int> $hours any order, repeats allowed
     * @return list<array{int, int}> first and last hour of each run
     */
    private static function runs(array $hours): array
    {
        $hours = array_values(array_unique($hours));
        sort($hours);
        $runs = [];
        foreach ($hours as $hour) {
            $at = count($runs) - 1;
            if ($at >= 0 && $runs[$at][1] === $hour - 1) {
                $runs[$at][1] = $hour;
            } else {
                $runs[] = [$hour, $hour];
            }
        }

        return $runs;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException("Update CPC: $column read as " . get_debug_type($value));
    }
}
