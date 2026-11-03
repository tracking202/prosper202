<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Database\Connection;
use Tracking202\Report\ReportWindow;

/**
 * The account-wide conversion log (tracking202/logs/): every ledger row of
 * one account, newest first, a page at a time, deleted and reversal rows
 * included. Why a row does or does not count toward its click is the
 * per-click breakdown's answer (ClickBreakdown), not this list's.
 *
 * Read-only. Every read is scoped to the account passed in.
 */
/**
 * @phpstan-type Filters array{
 *     campaign_id: ?int, click_id: ?int, from: ?int, to: ?int,
 *     time_basis: string, type: ?string, deleted: ?string
 * }
 */
final class ConversionLog
{
    public const PER_PAGE = 50;

    public const DEFAULT_RANGE = 'last30';

    /** The range picker's "use the two dates" value (P202_RANGE_CUSTOM). */
    public const CUSTOM_RANGE = 'custom';

    /** Which time the date range is applied to. */
    public const TIME_BASES = ['conversion' => 'Conversion time', 'click' => 'Click time'];

    public const DELETED = ['no' => 'Live only', 'yes' => 'Deleted only'];

    private const MAX_PAGE = 100000;

    public function __construct(private Connection $conn)
    {
    }

    /**
     * The page's query string, read and checked. A value that cannot be
     * read is an error naming its field, never dropped: a dropped filter
     * would list every conversion as if they were the ones asked for.
     *
     * @param array<string, mixed> $query normally $_GET
     * @param string $zone the account's time zone, for the date range
     * @return array{
     *     filters: Filters,
     *     form: array<string, string>,
     *     errors: array<string, string>,
     *     page: int
     * }
     */
    public static function readQuery(array $query, string $zone, int $now): array
    {
        $text = static fn (string $name): string => is_string($query[$name] ?? null) ? trim($query[$name]) : '';
        $errors = [];
        $filters = ['campaign_id' => null, 'click_id' => null, 'from' => null, 'to' => null,
            'time_basis' => 'conversion', 'type' => null, 'deleted' => null];
        $form = [];

        $ids = ['campaign_id' => 'a campaign from the list', 'click_id' => 'a click id (subid), digits only'];
        foreach ($ids as $name => $what) {
            $form[$name] = $text($name);
            if ($form[$name] === '') {
                continue;
            }
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $form[$name]) !== 1) {
                $errors[$name] = 'Enter ' . $what . '.';
                continue;
            }
            $filters[$name] = (int) $form[$name];
        }

        $form['type'] = $text('type');
        if ($form['type'] !== '') {
            if (ConversionSource::tryFrom($form['type']) === null) {
                $errors['type'] = 'Choose a type from the list.';
            } else {
                $filters['type'] = $form['type'];
            }
        }

        $form['deleted'] = $text('deleted');
        if ($form['deleted'] !== '') {
            if (!isset(self::DELETED[$form['deleted']])) {
                $errors['deleted'] = 'Choose live or deleted conversions.';
            } else {
                $filters['deleted'] = $form['deleted'];
            }
        }

        $form['time_basis'] = $text('time_basis') !== '' ? $text('time_basis') : 'conversion';
        if (!isset(self::TIME_BASES[$form['time_basis']])) {
            $errors['time_basis'] = 'Choose conversion time or click time.';
        } else {
            $filters['time_basis'] = $form['time_basis'];
        }

        $form['range'] = $text('range') !== '' ? $text('range') : self::DEFAULT_RANGE;
        $form['from'] = $text('from');
        $form['to'] = $text('to');
        $tz = new \DateTimeZone($zone);
        if ($form['range'] === self::CUSTOM_RANGE) {
            $from = self::day($form['from'], $tz);
            $to = self::day($form['to'], $tz);
            if ($from === null || $to === null) {
                $errors['range'] = 'Enter both dates as YYYY-MM-DD.';
            } elseif ($from > $to) {
                $errors['range'] = 'The start date is after the end date.';
            } else {
                $filters['from'] = $from;
                // The last second of the end day, in the account's zone.
                $filters['to'] = (new \DateTimeImmutable('@' . $to))->setTimezone($tz)
                    ->modify('+1 day')->getTimestamp() - 1;
            }
        } elseif (!in_array($form['range'], ReportWindow::PRESETS, true)) {
            $errors['range'] = 'Choose a date range from the list.';
        } elseif ($form['range'] !== 'alltime') {
            $window = ReportWindow::preset($form['range'], $zone, $now);
            $filters['from'] = $window['from'];
            $filters['to'] = $window['to'];
            // Shown in the date inputs, so the reader sees what the preset means.
            $form['from'] = (new \DateTimeImmutable('@' . $window['from']))->setTimezone($tz)->format('Y-m-d');
            $form['to'] = (new \DateTimeImmutable('@' . $window['to']))->setTimezone($tz)->format('Y-m-d');
        }

        $page = 0;
        $rawPage = $text('offset');
        if ($rawPage !== '' && preg_match('/^[0-9]{1,9}$/D', $rawPage) === 1) {
            $page = min((int) $rawPage, self::MAX_PAGE);
        }

        return ['filters' => $filters, 'form' => $form, 'errors' => $errors, 'page' => $page];
    }

    /** A YYYY-MM-DD day's first second in $tz, or null when it is not a real day. */
    private static function day(string $value, \DateTimeZone $tz): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            return null;
        }

        return $day->getTimestamp();
    }

    /**
     * One page of the account's conversion rows, newest conversion first.
     *
     * @param Filters $filters as readQuery() gives them
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(int $userId, array $filters, int $page): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('ConversionLog: an account id is required');
        }
        $where = ['cl.user_id = ?'];
        $types = 'i';
        $binds = [$userId];
        $timeColumn = ($filters['time_basis'] ?? 'conversion') === 'click' ? 'cl.click_time' : 'cl.conv_time';
        $clauses = [
            ['campaign_id', 'cl.campaign_id = ?', 'i'],
            ['click_id', 'cl.click_id = ?', 'i'],
            ['from', $timeColumn . ' >= ?', 'i'],
            ['to', $timeColumn . ' <= ?', 'i'],
            ['type', 'cl.source = ?', 's'],
        ];
        foreach ($clauses as [$key, $clause, $type]) {
            if (($filters[$key] ?? null) !== null) {
                $where[] = $clause;
                $types .= $type;
                $binds[] = $filters[$key];
            }
        }
        if (($filters['deleted'] ?? null) === 'yes') {
            $where[] = 'cl.deleted = 1';
        } elseif (($filters['deleted'] ?? null) === 'no') {
            $where[] = 'cl.deleted = 0';
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $this->conn->prepareRead('SELECT COUNT(*) AS total FROM 202_conversion_logs cl WHERE ' . $whereSql);
        $this->conn->bind($stmt, $types, $binds);
        $total = (int) ($this->conn->fetchOne($stmt)['total'] ?? 0);

        $stmt = $this->conn->prepareRead(
            'SELECT cl.conv_id, cl.click_id, cl.campaign_id, cl.transaction_id, cl.click_payout,
                    cl.click_time, cl.conv_time, cl.ip, cl.pixel_type, cl.user_agent, cl.deleted,
                    cl.source, cl.reverses_conv_id, ac.aff_campaign_name
             FROM 202_conversion_logs cl
             LEFT JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = cl.campaign_id AND ac.user_id = cl.user_id
             WHERE ' . $whereSql . '
             ORDER BY cl.conv_time DESC, cl.conv_id DESC
             LIMIT ? OFFSET ?'
        );
        $this->conn->bind($stmt, $types . 'ii', array_merge($binds, [self::PER_PAGE, max(0, $page) * self::PER_PAGE]));

        $rows = [];
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $clickTime = (int) $r['click_time'];
            $convTime = (int) $r['conv_time'];
            $txid = $r['transaction_id'] !== null && $r['transaction_id'] !== '' ? (string) $r['transaction_id'] : null;
            $type = self::typeOf((string) $r['source'], (int) $r['pixel_type'], (string) $r['user_agent']);
            $rows[] = [
                'conv_id' => (int) $r['conv_id'],
                'click_id' => (int) $r['click_id'],
                'campaign_id' => (int) $r['campaign_id'],
                'campaign_name' => $r['aff_campaign_name'] !== null ? (string) $r['aff_campaign_name'] : null,
                'transaction_id' => $txid,
                'amount' => Amount::fromUnits(Amount::toUnits((string) $r['click_payout'])),
                'click_time' => $clickTime,
                'conv_time' => $convTime,
                'elapsed' => self::elapsed($clickTime, $convTime),
                'ip' => (string) $r['ip'],
                'type' => $type->label(),
                'reverses_conv_id' => $r['reverses_conv_id'] !== null ? (int) $r['reverses_conv_id'] : null,
                'deleted' => (int) $r['deleted'] === 1,
                'user_agent' => (string) $r['user_agent'],
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The account's campaigns for the filter, id => name.
     *
     * @return array<int, string>
     */
    public function campaigns(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT aff_campaign_id, aff_campaign_name FROM 202_aff_campaigns
             WHERE user_id = ? AND aff_campaign_deleted = 0 ORDER BY aff_campaign_name, aff_campaign_id'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $out[(int) $row['aff_campaign_id']] = (string) $row['aff_campaign_name'];
        }

        return $out;
    }

    /** What produced the row; a row from before the ledger is read from its pixel type. */
    public static function typeOf(string $source, int $pixelType, string $userAgent): ConversionSource
    {
        return ConversionSource::tryFrom($source) ?? ConversionSource::fromLegacyRow($pixelType, $userAgent);
    }

    /** Click to conversion, "2d 3h 4m 5s"; null when the click time is unknown or later. */
    public static function elapsed(int $clickTime, int $convTime): ?string
    {
        if ($clickTime <= 0 || $convTime < $clickTime) {
            return null;
        }
        $s = $convTime - $clickTime;
        $parts = [];
        foreach (['d' => 86400, 'h' => 3600, 'm' => 60] as $unit => $size) {
            if ($s >= $size || $parts !== []) {
                $parts[] = intdiv($s, $size) . $unit;
                $s %= $size;
            }
        }
        $parts[] = $s . 's';

        return implode(' ', $parts);
    }
}
