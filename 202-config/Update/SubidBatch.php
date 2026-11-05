<?php

declare(strict_types=1);

namespace Prosper202\Update;

use Prosper202\Click\ClickRef;
use Prosper202\Click\FirstVisit;
use Prosper202\Click\LatestVisit;
use Prosper202\Click\PurLinkExit;
use Prosper202\Conversion\BatchInterrupted;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\DedupeKey;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * The subid operations of the Update section, one implementation behind the
 * pages (tracking202/update/subids.php, delete-subids.php, clear-subids.php)
 * and the API (POST /api/v3/conversions/subids, …/subids/delete,
 * …/subids/reset), so what a subid list marks or clears cannot differ between
 * them (CLAUDE.md #5):
 *
 * - mark(): each subid's click is recorded as converted, through the ledger's
 *   single writer (MysqlConversionRepository::record(), source subid_upload,
 *   once per click, or once per transaction id when the line gives one), at
 *   the line's payout and time when it gives them, and a converting click is
 *   never left filtered;
 * - clear(): each subid's conversions are cleared through the ledger
 *   (clearClicks()) and its click unfiltered;
 * - reset(): every converted click of a category, or of one campaign in it,
 *   is cleared the same way, and the data engine is told which hours to
 *   rebuild.
 *
 * Every item is its own transaction (the ledger opens one per click), so a
 * failure part-way leaves the items before it written: that is reported as
 * BatchInterrupted, saying how many, never as a failure that reads as
 * "nothing happened" (CLAUDE.md #13). Repeating any of these is safe: a
 * marked click is not marked again, and a cleared one clears nothing more.
 *
 * Each list item is accounted for in the result (CLAUDE.md #4): a line that
 * is not a subid, a click that is not the account's, a click already
 * converted, and a repeat of an earlier line each say so.
 */
final class SubidBatch
{
    public const MARKED = 'marked';
    public const WOULD_MARK = 'would_mark';
    public const ALREADY_CONVERTED = 'already_converted';
    public const CLEARED = 'cleared';
    public const WOULD_CLEAR = 'would_clear';
    public const NOT_FOUND = 'not_found';
    public const NOT_A_SUBID = 'not_a_subid';
    public const DUPLICATE_IN_LIST = 'duplicate_in_list';
    public const INVALID_LINE = 'invalid_line';

    /** The largest payout a click's report row holds (202_dataengine.payout is decimal(8,2)). */
    private const PAYOUT_MAX = '999999.99';

    /** 202_conversion_logs.transaction_id is varchar(255). */
    private const TRANSACTION_ID_MAX = 255;

    /** Clicks read per statement by the previews (an IN list's size). */
    private const PREVIEW_CHUNK = 500;

    private readonly MysqlConversionRepository $conversions;

    /** The installation key that verified this batch's signed PurLink SubIDs. */
    private ?string $purLinkKey = null;

    public function __construct(private readonly Connection $conn, ?MysqlConversionRepository $conversions = null)
    {
        $this->conversions = $conversions ?? new MysqlConversionRepository($conn);
    }

    /**
     * The lines of a pasted list, trimmed, blanks dropped, whatever line
     * ending the browser sent.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        $lines = preg_split('/\R/', trim($text));
        if ($lines === false) {
            return [];
        }
        return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    }

    /**
     * Read a list of subids into one entry per non-blank item: its click id,
     * or why it has none. `line` is the item's 1-based position in the list as
     * given, blanks counted, so a caller that sends every line of a file reads
     * its own line numbers back. A blank item is skipped and not reported.
     *
     * Pure. Only a string or an integer is read as a subid; anything else (a
     * boolean, a float, a list) is not one, so it is reported, never cast
     * into some click's id (CLAUDE.md #18).
     *
     * @param list<mixed> $items strings, as typed or read from a file
     * @return list<array{line: int, subid: string, click_id: int|null, status: string|null, first_line?: int}>
     *         status null: the click is to be processed; not_a_subid; or
     *         duplicate_in_list, naming the first line with the same click
     */
    public static function read(array $items): array
    {
        return self::parse($items, false, null, time());
    }

    /**
     * read() for Update Subids, whose items may carry up to three more
     * comma-separated fields, as it took them in 1.9.56:
     * `SUBID, PAYOUT, TRANSACTION ID, TIMESTAMP`, each optional and left empty
     * to skip it. A field that cannot be read makes the line invalid_line,
     * with `error` saying why; a time is read in $zone (a time with no zone
     * to read it in is refused). A repeat is the same click with the same
     * transaction id (or none); one stating another payout or time than the
     * earlier line is invalid_line.
     *
     * @param list<mixed> $items
     * @return list<array{line: int, subid: string, click_id: int|null, status: string|null, first_line?: int, payout?: string, transaction_id?: string, conv_time?: int, error?: string}>
     */
    public static function readLines(array $items, ?\DateTimeZone $zone = null, ?int $now = null): array
    {
        return self::parse($items, true, $zone, $now ?? time());
    }

    /**
     * @param list<mixed> $items
     * @return list<array<string, mixed>>
     */
    private static function parse(array $items, bool $withFields, ?\DateTimeZone $zone, int $now): array
    {
        $entries = [];
        $firstLine = [];
        foreach (array_values($items) as $index => $item) {
            $text = is_string($item) ? trim($item) : (is_int($item) ? (string) $item : null);
            if ($text === '') {
                continue;
            }
            $fields = $text === null ? [] : ($withFields ? array_map('trim', explode(',', $text)) : [$text]);
            $subid = $text === null ? '' : $fields[0];
            $entry = ['line' => $index + 1, 'subid' => $subid, 'click_id' => $text === null ? null : ClickRef::parse($subid)?->clickId, 'status' => null];
            if ($entry['click_id'] === null) {
                $entry['status'] = self::NOT_A_SUBID;
                $entries[] = $entry;
                continue;
            }

            $error = null;
            if (count($fields) > 4) {
                $error = 'has ' . count($fields) . ' fields; a line is SUBID, PAYOUT, TRANSACTION ID, TIMESTAMP (a comma inside a value is not read)';
            }
            if ($error === null && ($fields[1] ?? '') !== '') {
                $error = self::payoutError($fields[1]);
                $entry['payout'] = $fields[1];
                // "1,250", "1,250.00", "12,5" and "1000,50" split into a payout and a short numeric
                // transaction id; a payout written with its decimals ("12.00, 50") is not refused.
                if ($error === null && preg_match('/^\d+$/D', $fields[1]) === 1 && preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $fields[2] ?? '') === 1) {
                    $error = 'payout "' . $fields[1] . ',' . $fields[2] . '" looks like an amount with a thousands separator or a decimal comma:'
                        . ' write it as ' . $fields[1] . $fields[2] . ' or ' . $fields[1] . '.' . $fields[2]
                        . ' (if ' . $fields[2] . ' is the transaction id, write the payout with its decimals, as ' . $fields[1] . '.00)';
                }
                // "1.250,00" splits into a payout of 1.250 and a transaction id of 00.
                if ($error === null && preg_match('/^\d{1,3}(?:\.\d{3})+$/D', $fields[1]) === 1 && preg_match('/^\d{1,2}$/D', $fields[2] ?? '') === 1) {
                    $error = 'payout "' . $fields[1] . ',' . $fields[2] . '" looks like an amount written with a dot for thousands and a comma for decimals:'
                        . ' write it as ' . str_replace('.', '', $fields[1]) . '.' . $fields[2];
                }
            }
            if ($error === null && ($fields[2] ?? '') !== '') {
                $error = self::transactionIdError($fields[2]);
                $entry['transaction_id'] = $fields[2];
            }
            if ($error === null && ($fields[3] ?? '') !== '') {
                $time = $zone === null ? null : self::parseTime($fields[3], $zone);
                if ($zone === null) {
                    $error = 'has a time, but no time zone was given to read it in';
                } elseif ($time === null) {
                    $error = 'time "' . $fields[3] . '" is not a date and time this reads: write it as 11/03/2026 2:30:00 PM, 2026-11-03 14:30:00 or a Unix timestamp';
                } elseif ($time > $now + 300) {
                    $error = 'time "' . $fields[3] . '" is in the future';
                } else {
                    $entry['conv_time'] = $time;
                }
            }
            if ($error !== null) {
                $entry['status'] = self::INVALID_LINE;
                $entry['error'] = $error;
                $entries[] = $entry;
                continue;
            }

            // A signed SubID is its own key: a forged copy naming the same click is no repeat of it.
            $key = (ClickRef::parse($subid)?->purLinkSubid ?? $entry['click_id']) . "\0" . ($entry['transaction_id'] ?? '');
            if (isset($firstLine[$key])) {
                $first = $entries[$firstLine[$key]];
                // A repeat stating another payout or time is not that line again (CLAUDE.md #15).
                $differs = self::statedDifference($entry, $first['payout'] ?? null, $first['conv_time'] ?? null, $zone);
                if ($differs !== null) {
                    $entry['status'] = self::INVALID_LINE;
                    $entry['error'] = 'repeats line ' . $first['line'] . ' (the same click' . (isset($entry['transaction_id']) ? ' and transaction id' : '')
                        . ') with ' . $differs . ': give a different sale its own transaction id, or remove the repeat';
                } else {
                    $entry['status'] = self::DUPLICATE_IN_LIST;
                    $entry['first_line'] = $first['line'];
                }
            } else {
                $firstLine[$key] = count($entries);
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * What a line states that differs from a payout and time on record (or
     * on an earlier line), or null. Only what the line states is compared:
     * one with no payout or time takes a default, which a repeat cannot be
     * held to.
     *
     * @param array<string, mixed> $entry
     */
    private static function statedDifference(array $entry, ?string $payout, ?int $convTime, ?\DateTimeZone $zone = null): ?string
    {
        $differences = [];
        if (isset($entry['payout']) && ($payout === null || Amount::toUnits($entry['payout']) !== Amount::toUnits($payout))) {
            $differences[] = 'payout ' . $entry['payout'] . ' (' . ($payout === null ? 'none given' : self::amount($payout)) . ' there)';
        }
        if (isset($entry['conv_time']) && $entry['conv_time'] !== $convTime) {
            $at = static fn (int $t): string => (new \DateTimeImmutable('@' . $t))->setTimezone($zone ?? new \DateTimeZone('UTC'))->format('Y-m-d H:i:s T');
            $differences[] = 'time ' . $at((int) $entry['conv_time']) . ' (' . ($convTime === null ? 'none given' : $at($convTime)) . ' there)';
        }

        return $differences === [] ? null : implode(' and ', $differences);
    }

    /** An amount as text with at least two decimals and no trailing zeros past them. */
    private static function amount(string $value): string
    {
        return (string) preg_replace('/(\.\d\d\d*?)0+$/D', '$1', Amount::fromUnits(Amount::toUnits($value)));
    }

    /**
     * Why $payout is not an amount Update Subids records, or null when it
     * is: a plain decimal, not negative, below a million.
     */
    public static function payoutError(string $payout): ?string
    {
        if (preg_match('/^-\s*\d/', $payout) === 1) {
            return 'payout "' . $payout . '" is negative; a reversal is not recorded here';
        }
        if (preg_match('/^(\d+)(?:\.(\d{1,5}))?$/D', $payout, $m) !== 1) {
            return 'payout "' . $payout . '" is not a number: write it as digits with a decimal point (at most five decimals), such as 12.50, with no currency sign or thousands separator';
        }
        // Compared as text: a long run of digits is no int.
        $whole = ltrim($m[1], '0');
        $fraction = str_pad($m[2] ?? '', 5, '0');
        [$maxWhole, $maxFraction] = explode('.', self::PAYOUT_MAX);
        if (strlen($whole) > strlen($maxWhole) || ($whole === $maxWhole && strcmp($fraction, str_pad($maxFraction, 5, '0')) > 0)) {
            return 'payout "' . $payout . '" is too large: it can be at most ' . self::PAYOUT_MAX;
        }

        return null;
    }

    private static function transactionIdError(string $transactionId): ?string
    {
        if (!mb_check_encoding($transactionId, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $transactionId) === 1) {
            return 'transaction id holds characters that cannot be stored';
        }
        if (strlen($transactionId) > self::TRANSACTION_ID_MAX) {
            return 'transaction id is longer than ' . self::TRANSACTION_ID_MAX . ' bytes';
        }

        return null;
    }

    /**
     * A line's time as a Unix timestamp: digits are one already; otherwise
     * month/day/year (two or four digits) or year-month-day, with an optional
     * time (12-hour with AM/PM, or 24-hour), read in $zone. Null when it is
     * none of these or not a real date.
     */
    public static function parseTime(string $text, \DateTimeZone $zone): ?int
    {
        $text = trim($text);
        if (preg_match('/^\d{9,10}$/D', $text) === 1) {
            return (int) $text;
        }
        $clock = '(?:[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?)?';
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})' . $clock . '$/D', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4}|\d{2})' . $clock . '$#D', $text, $m) === 1) {
            [$month, $day, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if (strlen($m[3]) === 2) {
                $year += 2000;
            }
        } else {
            return null;
        }
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        $hour = ($m[4] ?? '') === '' ? 0 : (int) $m[4];
        $minute = ($m[5] ?? '') === '' ? 0 : (int) $m[5];
        $second = ($m[6] ?? '') === '' ? 0 : (int) $m[6];
        $meridiem = strtolower($m[7] ?? '');
        if ($meridiem !== '') {
            if ($hour < 1 || $hour > 12) {
                return null;
            }
            $hour = ($hour % 12) + ($meridiem === 'pm' ? 12 : 0);
        }
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $at = (new \DateTimeImmutable('now', $zone))->setDate($year, $month, $day)->setTime($hour, $minute, $second);
        // A wall time the clocks skipped (spring forward) is moved by PHP; it never happened.
        if ($at->format('Y-n-j G:i:s') !== sprintf('%d-%d-%d %d:%02d:%02d', $year, $month, $day, $hour, $minute, $second)) {
            return null;
        }

        return $at->getTimestamp();
    }

    /**
     * The lines read() refused, for SubidLinesRefused.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array{line: int, subid: string, error: string}>
     */
    private static function invalidLines(array $entries): array
    {
        $invalid = [];
        foreach ($entries as $entry) {
            if ($entry['status'] === self::INVALID_LINE) {
                $invalid[] = ['line' => (int) $entry['line'], 'subid' => (string) $entry['subid'], 'error' => (string) $entry['error']];
            }
        }

        return $invalid;
    }

    /**
     * Mark each subid's click as converted: at the line's payout, else
     * $defaultPayout, else the campaign's; at the line's time, else now.
     *
     * Through the single canonical writer, which locks the click, records the
     * row and derives the click's value from its rows (and refreshes the
     * click's report row once committed). A line without a transaction id is
     * once_per_click: a click that is already a lead changes nothing. A line
     * with one records that sale, once: the same id on the same click again
     * is already_converted, unless it states another payout or time, which
     * refuses the list (refuseChangedSales()); another id is another sale.
     * A sale recorded and deleted since keeps its id: its line is
     * already_converted with deleted true, and nothing is recorded.
     *
     * @param list<mixed> $items
     * @param string|null $defaultPayout payoutError() must have accepted it
     * @param \DateTimeZone|null $zone the zone the lines' times are in
     * @return array{marked: int, lines: list<array<string, mixed>>}
     * @throws SubidLinesRefused when a line cannot be read; nothing is written
     * @throws BatchInterrupted when a click's write fails; the clicks before it stay marked
     */
    public function mark(int $userId, array $items, ?string $defaultPayout = null, ?\DateTimeZone $zone = null): array
    {
        $entries = $this->readForWrite($userId, $items, $defaultPayout, $zone);
        $marked = 0;
        foreach ($entries as $i => $entry) {
            if ($entry['status'] !== null) {
                continue;
            }
            $clickId = (int) $entry['click_id'];
            $data = [
                'click_id' => $clickId,
                'source' => ConversionSource::SUBID_UPLOAD->value,
                'user_agent' => 'subid-upload',
                'pixel_type' => 0,
            ];
            if (isset($entry['transaction_id'])) {
                $data['transaction_id'] = $entry['transaction_id'];
            } else {
                $data['once_per_click'] = true;
            }
            $payout = $entry['payout'] ?? $defaultPayout;
            if ($payout !== null) {
                $data['payout'] = $payout;
            }
            if (isset($entry['conv_time'])) {
                $data['conv_time'] = $entry['conv_time'];
            }
            $unfilter = function (int $lockedClickId) use ($userId): void {
                // A converting click is never left filtered.
                foreach (['UPDATE 202_clicks SET click_filtered = 0 WHERE click_id = ? AND user_id = ?',
                    'UPDATE 202_clicks_spy SET click_filtered = 0 WHERE click_id = ? AND user_id = ?'] as $sql) {
                    $stmt = $this->conn->prepareWrite($sql);
                    $this->conn->bind($stmt, 'ii', [$lockedClickId, $userId]);
                    $this->conn->executeUpdate($stmt);
                }
            };
            $purLink = ClickRef::parse($entry['subid'])?->purLinkSubid;
            if ($purLink !== null && $this->purLinkKey === null) {
                throw new \LogicException('a signed SubID reached mark() unverified');
            }
            try {
                // The list named the click; a signed PurLink SubID records on its offer.
                $result = $purLink === null
                    ? $this->conversions->record($userId, $data, MatchMethod::CLICK_ID, $unfilter)
                    : (new PurLinkExit($this->conn))->recordConversion($purLink, $this->purLinkKey, $data, MatchMethod::CLICK_ID, $unfilter)
                        ?? ['clickFound' => false, 'duplicate' => false];
            } catch (\Throwable $e) {
                throw new BatchInterrupted('line ' . $entry['line'] . ' (subid ' . $entry['subid'] . ')', $marked, null, $e);
            }

            if (!$result['clickFound']) {
                $entries[$i]['status'] = self::NOT_FOUND;
            } elseif ($result['duplicate']) {
                $entries[$i]['status'] = self::ALREADY_CONVERTED;
                if ($result['deleted'] ?? false) {
                    // Recorded before and deleted since: the deleted row keeps
                    // its key, so nothing is recorded, and the line says so.
                    $entries[$i]['deleted'] = true;
                }
            } else {
                $entries[$i]['status'] = self::MARKED;
                $marked++;
            }
        }

        return ['marked' => $marked, 'lines' => $entries];
    }

    /**
     * What mark() would do, writing nothing: each click that is the
     * account's and not yet a lead would be marked.
     *
     * Read from the click's lead flag, the value record() checks. The ledger
     * can still answer already_converted for a click this calls would_mark:
     * one in an accumulate-mode campaign whose plain conversion was deleted,
     * because a deleted conversion keeps its place in the click's ledger.
     *
     * A line with a transaction id would be marked unless that id is
     * already on the click, as record() keys it.
     *
     * @param list<mixed> $items
     * @return array{would_mark: int, lines: list<array<string, mixed>>}
     * @throws SubidLinesRefused when a line cannot be read, as mark() refuses it
     */
    public function markPreview(int $userId, array $items, ?string $defaultPayout = null, ?\DateTimeZone $zone = null): array
    {
        $entries = $this->readForWrite($userId, $items, $defaultPayout, $zone);
        $clicks = $this->ownedClicks($userId, $entries);
        $count = 0;
        foreach ($entries as $i => $entry) {
            if ($entry['status'] !== null) {
                continue;
            }
            $click = $clicks[(int) $entry['click_id']] ?? null;
            if ($click === null) {
                $entries[$i]['status'] = self::NOT_FOUND;
            } elseif (isset($entry['transaction_id']) && ($onFile = $this->transactionOnFile((int) $entry['click_id'], $entry['transaction_id'])) !== null) {
                $entries[$i]['status'] = self::ALREADY_CONVERTED;
                if ($onFile === 'deleted') {
                    $entries[$i]['deleted'] = true;
                }
            } elseif (!isset($entry['transaction_id']) && (int) $click['click_lead'] === 1) {
                $entries[$i]['status'] = self::ALREADY_CONVERTED;
            } else {
                $entries[$i]['status'] = self::WOULD_MARK;
                $count++;
            }
        }

        return ['would_mark' => $count, 'lines' => $entries];
    }

    /**
     * Clear each subid's conversions: the reverse of mark().
     *
     * Through the ledger (clearClicks()), so the rows and the click agree:
     * every live row of the click is soft-deleted, its revenue voided, and the
     * click's value recomputed from what is left. The click keeps its visit
     * and is no longer filtered. A subid of another account is left alone and
     * reported not_found.
     *
     * @param list<mixed> $items
     * @return array{cleared: int, lines: list<array<string, mixed>>}
     * @throws BatchInterrupted when a click's clear fails; the clicks before it stay cleared
     */
    public function clear(int $userId, array $items): array
    {
        $entries = $this->verifyPurLinks($userId, self::read($items));
        $cleared = 0;
        foreach ($entries as $i => $entry) {
            if ($entry['status'] !== null) {
                continue;
            }
            $clickId = (int) $entry['click_id'];
            try {
                $found = $this->conversions->clearClicks($userId, [$clickId]);
            } catch (\Throwable $e) {
                throw new BatchInterrupted('line ' . $entry['line'] . ' (subid ' . $entry['subid'] . ')', $cleared, null, $e);
            }
            if ($found === 0) {
                $entries[$i]['status'] = self::NOT_FOUND;
                continue;
            }
            $entries[$i]['status'] = self::CLEARED;
            $cleared++;

            // After the clear has committed: a failure here leaves the
            // conversions cleared and only the filtered flag behind, which
            // the next write to the click corrects, so it is logged and the
            // list goes on (as delete-subids.php always did). Both failure
            // shapes are caught: Connection's QueryException where mysqli
            // reports by return value (the pages), mysqli_sql_exception
            // where it throws (the API) — escaping here would report a
            // committed clear as a failure (CLAUDE.md #13).
            foreach (['UPDATE 202_clicks SET click_filtered = 0 WHERE click_id = ? AND user_id = ?',
                'UPDATE 202_clicks_spy SET click_filtered = 0 WHERE click_id = ? AND user_id = ?'] as $sql) {
                try {
                    $stmt = $this->conn->prepareWrite($sql);
                    $this->conn->bind($stmt, 'ii', [$clickId, $userId]);
                    $this->conn->executeUpdate($stmt);
                } catch (QueryException | \mysqli_sql_exception $e) {
                    error_log('delete subids: clearing the filtered flag failed for click ' . $clickId . ': ' . $e->getMessage());
                }
            }
            // clearClicks() refreshed the click's report row before the
            // filtered flag changed; refresh it again so the reports read
            // the flag too (best-effort, logged).
            $this->conversions->refreshClickReport($clickId);
        }

        return ['cleared' => $cleared, 'lines' => $entries];
    }

    /**
     * What clear() would do, writing nothing: which clicks are the account's,
     * and how many live conversions each holds (the rows clear() deletes).
     *
     * @param list<mixed> $items
     * @return array{would_clear: int, conversions: int, lines: list<array<string, mixed>>}
     */
    public function clearPreview(int $userId, array $items): array
    {
        $entries = $this->verifyPurLinks($userId, self::read($items));
        $clicks = $this->ownedClicks($userId, $entries);
        $count = 0;
        $conversions = 0;
        foreach ($entries as $i => $entry) {
            if ($entry['status'] !== null) {
                continue;
            }
            $click = $clicks[(int) $entry['click_id']] ?? null;
            if ($click === null) {
                $entries[$i]['status'] = self::NOT_FOUND;
                continue;
            }
            $entries[$i]['status'] = self::WOULD_CLEAR;
            $entries[$i]['conversions'] = (int) $click['live_conversions'];
            $count++;
            $conversions += (int) $click['live_conversions'];
        }

        return ['would_clear' => $count, 'conversions' => $conversions, 'lines' => $entries];
    }

    /**
     * Read what a campaign reset names: a category that is the account's
     * (required), and optionally one of its campaigns. The sentences are the
     * page's; each surface may say them its own way, keyed by field.
     *
     * `problems` names which check each error failed (missing, not_owned,
     * other_category), so a caller can say it in its own words without
     * reading the sentence.
     *
     * @param string $rawNetwork the category id as given ('' or '0' = none)
     * @param string $rawCampaign the campaign id as given ('' or '0' = every campaign in the category)
     * @return array{network_id: int, campaign_id: int, network: array<string, mixed>|null, campaign: array<string, mixed>|null, errors: array<string, string>, problems: array<string, string>}
     */
    public function resetScope(int $userId, string $rawNetwork, string $rawCampaign): array
    {
        $errors = [];
        $problems = [];
        $network = null;
        $campaign = null;
        $networkId = 0;
        $campaignId = 0;
        $rawNetwork = trim($rawNetwork);
        $rawCampaign = trim($rawCampaign);

        if ($rawNetwork === '' || $rawNetwork === '0') {
            $errors['aff_network_id'] = 'You have to at least select an affiliate network to clear out.';
            $problems['aff_network_id'] = 'missing';
        } elseif (!ctype_digit($rawNetwork) || strlen($rawNetwork) > 18
            || ($network = OwnedRow::find($this->conn, '202_aff_networks', 'aff_network_id', (int) $rawNetwork, $userId)) === null) {
            $errors['aff_network_id'] = 'Choose one of your categories from the list.';
            $problems['aff_network_id'] = 'not_owned';
        } else {
            $networkId = (int) $rawNetwork;
        }

        if ($rawCampaign !== '' && $rawCampaign !== '0') {
            $campaign = ctype_digit($rawCampaign) && strlen($rawCampaign) <= 18
                ? OwnedRow::find($this->conn, '202_aff_campaigns', 'aff_campaign_id', (int) $rawCampaign, $userId)
                : null;
            if ($campaign === null) {
                $errors['aff_campaign_id'] = 'Choose one of your campaigns from the list.';
                $problems['aff_campaign_id'] = 'not_owned';
            } elseif ($networkId > 0 && (int) $campaign['aff_network_id'] !== $networkId) {
                // Without the page script the campaign list is not narrowed to
                // the category; a campaign from another one is refused rather
                // than cleared under a category it is not in.
                $errors['aff_campaign_id'] = 'That campaign is not in the category you chose.';
                $problems['aff_campaign_id'] = 'other_category';
            } else {
                $campaignId = (int) $campaign['aff_campaign_id'];
            }
        }

        return ['network_id' => $networkId, 'campaign_id' => $campaignId, 'network' => $network, 'campaign' => $campaign, 'errors' => $errors, 'problems' => $problems];
    }

    /**
     * The clicks a reset clears: the converted clicks of the campaign, or of
     * every campaign in the category — a click that is a lead, or holds a
     * live conversion row. A click's category is its campaign's only when
     * the campaign is the click's own account's (a tracker could name
     * another account's before the API checked linked ids, 229df10): that
     * account's row never decides which of this account's sales are cleared.
     *
     * A click is the campaign's when its latest visit is
     * (LatestVisit::isLatest()), as every report counts it and a conversion
     * credits it: a click that arrived on the campaign and was re-clicked
     * onto another is that one's. Each click once, at the earliest of those
     * rows: a click visited again (a rotator re-click) has a row per visit
     * under one click_id, and reading rows counted it once per visit in the
     * preview and in reset()'s answer; a re-click the 1.9.79 backfill has
     * not reached has every visit latest.
     *
     * @return list<array{click_id: int, click_time: int}>
     */
    public function resetClicks(int $userId, int $networkId, int $campaignId): array
    {
        if ($campaignId > 0) {
            $select = "SELECT c.click_id, MIN(c.click_time) AS click_time FROM 202_clicks AS c
                WHERE c.user_id = ? AND c.aff_campaign_id = ?
                AND (c.click_lead = 1 OR EXISTS (SELECT 1 FROM 202_conversion_logs AS cl WHERE cl.click_id = c.click_id AND cl.deleted = 0))
                AND " . LatestVisit::isLatest('c') . "
                GROUP BY c.click_id ORDER BY c.click_id";
            $scopeId = $campaignId;
        } else {
            $select = "SELECT c.click_id, MIN(c.click_time) AS click_time FROM 202_clicks AS c
                INNER JOIN 202_aff_campaigns AS ac ON ac.aff_campaign_id = c.aff_campaign_id AND ac.user_id = c.user_id
                WHERE c.user_id = ? AND ac.aff_network_id = ?
                AND (c.click_lead = 1 OR EXISTS (SELECT 1 FROM 202_conversion_logs AS cl WHERE cl.click_id = c.click_id AND cl.deleted = 0))
                AND " . LatestVisit::isLatest('c') . "
                GROUP BY c.click_id ORDER BY c.click_id";
            $scopeId = $networkId;
        }
        $stmt = $this->conn->prepareWrite($select);
        $this->conn->bind($stmt, 'ii', [$userId, $scopeId]);

        return array_map(
            static fn (array $row): array => ['click_id' => (int) $row['click_id'], 'click_time' => (int) $row['click_time']],
            $this->conn->fetchAll($stmt)
        );
    }

    /**
     * Clear every conversion of a category, or of one campaign in it, so a
     * report uploaded by mistake can be uploaded again. $networkId and
     * $campaignId are resetScope()'s, with no errors.
     *
     * Each converted click is cleared through the ledger (clearClicks()), one
     * transaction per click; then the data engine is told to rebuild the
     * hours from the earliest cleared click to now. When a click's clear
     * fails, the hours of the clicks already cleared are still marked before
     * the failure is reported.
     *
     * @return int how many clicks were cleared
     * @throws BatchInterrupted when a click's clear fails; the clicks before it stay cleared
     */
    public function reset(int $userId, int $networkId, int $campaignId): int
    {
        $rows = $this->resetClicks($userId, $networkId, $campaignId);
        $cleared = 0;
        $earliest = null;
        foreach ($rows as $row) {
            try {
                $found = $this->conversions->clearClicks($userId, [$row['click_id']]);
            } catch (\Throwable $e) {
                if ($earliest !== null) {
                    try {
                        $this->markDirtyHours($userId, $networkId, $campaignId, $earliest);
                    } catch (\Throwable $dirty) {
                        error_log('reset subids: marking the hours of ' . $cleared . ' cleared clicks failed: ' . $dirty->getMessage());
                    }
                }
                throw new BatchInterrupted('click ' . $row['click_id'], $cleared, null, $e);
            }
            if ($found > 0) {
                $cleared++;
                $earliest = $earliest === null ? $row['click_time'] : min($earliest, $row['click_time']);
            }
        }

        if ($earliest !== null) {
            // The data engine rebuilds the hours these clicks fall in. The
            // window starts at the earliest cleared click; the page used to
            // take whichever click the database returned first, which could
            // leave earlier hours showing the income just cleared. Every
            // clear has committed by now, so a failure here is reported as
            // what it is: the clicks cleared, their hours not marked.
            try {
                $this->markDirtyHours($userId, $networkId, $campaignId, $earliest);
            } catch (\Throwable $e) {
                throw new BatchInterrupted('marking the report hours of the cleared clicks', $cleared, null, $e);
            }
        }

        return $cleared;
    }

    private function markDirtyHours(int $userId, int $networkId, int $campaignId, int $from): void
    {
        $dirty = $this->conn->prepareWrite('INSERT IGNORE INTO 202_dirty_hours SET ppc_account_id = 0, aff_campaign_id = ?, aff_network_id = ?, landing_page_id = 0, user_id = ?, click_time_from = ?, click_time_to = ?');
        $this->conn->bind($dirty, 'iiiii', [$campaignId, $networkId, $userId, $from, time()]);
        $this->conn->executeUpdate($dirty);
    }

    /**
     * read(), refusing the whole list when a line cannot be read.
     *
     * @param list<mixed> $items
     * @return list<array<string, mixed>>
     */
    private function readForWrite(int $userId, array $items, ?string $defaultPayout, ?\DateTimeZone $zone): array
    {
        if ($defaultPayout !== null && ($problem = self::payoutError($defaultPayout)) !== null) {
            throw new \InvalidArgumentException('default ' . $problem);
        }
        $entries = $this->refuseChangedSales($userId, $this->refuseTimesBeforeTheirClick($userId, $this->verifyPurLinks($userId, self::readLines($items, $zone))), $zone);
        $invalid = self::invalidLines($entries);
        if ($invalid !== []) {
            throw new SubidLinesRefused($invalid);
        }

        return $entries;
    }

    /**
     * A line's time before its click's first visit is no time it converted:
     * the line is invalid_line. A click that is not the account's is left
     * for mark() to report not_found.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function refuseTimesBeforeTheirClick(int $userId, array $entries): array
    {
        $timed = [];
        foreach ($entries as $i => $entry) {
            if ($entry['status'] === null && isset($entry['conv_time'])) {
                $timed[(int) $entry['click_id']][] = $i;
            }
        }
        if ($timed === []) {
            return $entries;
        }
        // One click's first visit at a time: FirstVisit::of() is one click's,
        // and fetchOne() closes the statement.
        foreach ($timed as $clickId => $lines) {
            $stmt = $this->conn->prepareWrite('SELECT first_visit.click_time FROM ' . FirstVisit::of('?')
                . ' AS first_visit WHERE first_visit.user_id = ?');
            $this->conn->bind($stmt, 'ii', [$clickId, $userId]);
            $row = $this->conn->fetchOne($stmt);
            if ($row === null) {
                continue;
            }
            foreach ($lines as $i) {
                if ((int) $entries[$i]['conv_time'] < (int) $row['click_time']) {
                    $entries[$i]['status'] = self::INVALID_LINE;
                    $entries[$i]['error'] = 'time is before the click (' . gmdate('Y-m-d H:i:s', (int) $row['click_time']) . ' UTC)';
                }
            }
        }

        return $entries;
    }

    /**
     * A line whose transaction id is already on its click, stating another
     * payout or time than that sale holds, is invalid_line: the ledger key
     * locates the sale, and answering already_converted would drop the
     * difference (CLAUDE.md #15). Only what the line states is compared, as
     * statedDifference() says; a click that is not the account's is left
     * for mark() to report not_found.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function refuseChangedSales(int $userId, array $entries, ?\DateTimeZone $zone): array
    {
        $keyed = [];
        foreach ($entries as $i => $entry) {
            if ($entry['status'] === null && isset($entry['transaction_id']) && (isset($entry['payout']) || isset($entry['conv_time']))) {
                $keyed[$i] = DedupeKey::transaction($entry['transaction_id']);
            }
        }
        foreach (array_chunk($keyed, self::PREVIEW_CHUNK, true) as $chunk) {
            $clicks = array_values(array_unique(array_map(static fn (int $i): int => (int) $entries[$i]['click_id'], array_keys($chunk))));
            $keys = array_values(array_unique($chunk));
            $stmt = $this->conn->prepareWrite(
                'SELECT conv_id, click_id, dedupe_key, click_payout, conv_time, deleted FROM 202_conversion_logs
                 WHERE user_id = ? AND click_id IN (' . implode(', ', array_fill(0, count($clicks), '?')) . ')
                   AND dedupe_key IN (' . implode(', ', array_fill(0, count($keys), '?')) . ')'
            );
            $this->conn->bind($stmt, 'i' . str_repeat('i', count($clicks)) . str_repeat('s', count($keys)), array_merge([$userId], $clicks, $keys));
            $sales = [];
            foreach ($this->conn->fetchAll($stmt) as $row) {
                $sales[$row['click_id'] . "\0" . $row['dedupe_key']] = $row;
            }
            foreach ($chunk as $i => $key) {
                $sale = $sales[$entries[$i]['click_id'] . "\0" . $key] ?? null;
                if ($sale === null) {
                    continue;
                }
                $differs = self::statedDifference($entries[$i], (string) $sale['click_payout'], (int) $sale['conv_time'], $zone);
                if ($differs !== null) {
                    $entries[$i]['status'] = self::INVALID_LINE;
                    $entries[$i]['error'] = 'transaction id "' . $entries[$i]['transaction_id'] . '" is already recorded on this click as conversion '
                        . (int) $sale['conv_id'] . ((int) $sale['deleted'] === 1 ? ' (deleted since)' : '') . ', with another ' . $differs
                        . ': a recorded sale is not changed here; give a different sale its own transaction id';
                }
            }
        }

        return $entries;
    }

    /**
     * The row the click holds keyed by this transaction id, as record()'s
     * replay check reads it: 'live', 'deleted' (it keeps its key, so the id
     * is not recorded again), or null when there is none.
     */
    private function transactionOnFile(int $clickId, string $transactionId): ?string
    {
        $stmt = $this->conn->prepareWrite('SELECT deleted FROM 202_conversion_logs WHERE click_id = ? AND dedupe_key = ? LIMIT 1');
        $this->conn->bind($stmt, 'is', [$clickId, DedupeKey::transaction($transactionId)]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : ((int) $row['deleted'] === 1 ? 'deleted' : 'live');
    }

    /**
     * A signed PurLink SubID names its click only when its MAC verifies and
     * the click is the account's; otherwise its line is not_found.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function verifyPurLinks(int $userId, array $entries): array
    {
        foreach ($entries as $i => $entry) {
            $purLink = $entry['status'] === null ? ClickRef::parse($entry['subid'])?->purLinkSubid : null;
            if ($purLink === null) {
                continue;
            }
            $verified = ClickRef::verify($this->conn, $purLink);
            if ($verified === null || $verified['claims']['user_id'] !== $userId) {
                $entries[$i]['status'] = self::NOT_FOUND;
                continue;
            }
            $this->purLinkKey = $verified['key'];
        }

        return $entries;
    }

    /**
     * The account's clicks among the entries still to be processed, with their
     * lead flag and live conversion count, keyed by click id. Read in chunks.
     * The lead flag is the latest visit's, the one record() checks.
     *
     * @param list<array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function ownedClicks(int $userId, array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            if ($entry['status'] === null) {
                $ids[] = (int) $entry['click_id'];
            }
        }
        $clicks = [];
        foreach (array_chunk($ids, self::PREVIEW_CHUNK) as $chunk) {
            $stmt = $this->conn->prepareWrite(
                'SELECT c.click_id, ' . LatestVisit::anyVisitIsLead('c') . ' AS click_lead,
                        (SELECT COUNT(*) FROM 202_conversion_logs AS cl WHERE cl.click_id = c.click_id AND cl.deleted = 0) AS live_conversions
                 FROM 202_clicks AS c
                 WHERE c.user_id = ? AND c.click_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')
                   AND ' . LatestVisit::isLatest('c') . '
                 ORDER BY c.click_time'
            );
            $this->conn->bind($stmt, 'i' . str_repeat('i', count($chunk)), array_merge([$userId], $chunk));
            // A re-click the backfill has not reached has every visit latest:
            // the last row kept is its latest by time.
            foreach ($this->conn->fetchAll($stmt) as $row) {
                $clicks[(int) $row['click_id']] = $row;
            }
        }

        return $clicks;
    }
}
