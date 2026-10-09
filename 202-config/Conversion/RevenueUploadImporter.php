<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use Prosper202\Click\ClickId;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\DedupeKey;
use Prosper202\Database\Connection;
use RuntimeException;

/**
 * Import a network's revenue report (CSV) into the conversion ledger.
 *
 * Before the ledger, the upload summed each subid's amounts within the file
 * and overwrote the click's payout with the total, leaving no row: the lines
 * could not be listed, broken down, attributed or reversed. Now every CSV
 * line is a ledger row of its own, tagged with the upload's batch, and the
 * click's value is derived from the rows (ClickValueCalculator, rule 2): the
 * newest batch's lines for a click are summed and replace every earlier
 * batch and every earlier plain conversion — the same "sum within the file,
 * replace across files" as before, with the lines now visible.
 *
 * Every line is accounted for in the result. A line whose subid is not an
 * exact click id of this account, or whose amount is not a number, is
 * skipped with the reason, never silently (CLAUDE.md error pattern #4).
 * The result counts every line; it lists the ones not recorded — the header
 * and each skipped line — up to LISTED_LINES of them, counts the rest
 * (`unlisted`) and counts every skipped line by its reason (`reasons`). A
 * recorded line is in its click's sum in `totals`.
 *
 * Neither surface holds the report's lines in memory: the route takes an
 * 8 MB report, and keeping every line read as an array (and listing every
 * one) took 376 MB to preview 440,000 lines — past the 128 MB a PHP request
 * gets by default, so the request died (measured). What grows with the
 * report is the per-click totals and, in preview(), the set of subids read.
 *
 * One reader behind every surface that uploads a report — the Upload Revenue
 * Reports page and POST /api/v3/conversions/uploads — and behind preview(),
 * so a dry run reads each line exactly as the import will.
 */
final class RevenueUploadImporter
{
    /** The status of a line not recorded: skipped with a reason, or read as the header. */
    public const SKIPPED = 'skipped';
    public const HEADER = 'header';

    /**
     * The most lines a result lists. A report whose subid column is the wrong
     * one skips every line, and listing 440,000 of them made an answer of
     * tens of megabytes that the CLI (which reads 10 MB) could not read; the
     * counts by reason say what the unlisted lines are.
     */
    public const LISTED_LINES = 1000;

    private const NO_CLICK = 'no click with this subid in your account';

    public function __construct(
        private Connection $conn,
        private MysqlConversionRepository $conversions,
    ) {
    }

    /**
     * @param resource $handle An open CSV stream positioned at its start.
     * @return array{
     *     batch_id: int,
     *     lines: list<array{line: int, subid: string, amount: string, status: string, reason: string}>,
     *     unlisted: int,
     *     reasons: array<string, int>,
     *     totals: array<int, string>,
     *     total: string,
     *     recorded: int,
     *     skipped: int
     * } lines: the lines not recorded, in file order, at most LISTED_LINES —
     *   status skipped, or header (line 1 when it holds no subid); unlisted:
     *   how many more were not recorded; reasons: every skipped line counted
     *   by its reason; totals: click_id => the sum of this file's recorded
     *   lines for it; total: the sum of every recorded line.
     * @throws BatchInterrupted when a line's write fails after the batch was
     *         created: the lines before it are committed rows of the batch
     */
    public function import(int $userId, string $fileName, $handle, int $subidColumn, int $amountColumn): array
    {
        if ($subidColumn < 0 || $amountColumn < 0) {
            throw new RuntimeException('choose the subid column and the commission column');
        }

        $batchId = $this->createBatch($userId, $fileName);

        $unrecorded = self::unrecorded();
        $totals = [];
        $total = '0.00000';
        $recorded = 0;
        $skipped = 0;
        $lineNo = 0;
        while (($line = self::readLine($handle, $lineNo, $subidColumn, $amountColumn)) !== false) {
            if ($line === null) {
                continue; // a blank line
            }
            if ($line['status'] !== null) {
                // The header, or a line that cannot be read.
                if ($line['status'] === self::SKIPPED) {
                    $skipped++;
                }
                self::notRecorded($unrecorded, $line);
                continue;
            }
            $clickId = (int) $line['click_id'];
            $amount = (string) $line['amount'];

            try {
                $result = $this->conversions->record($userId, [
                    'click_id' => $clickId,
                    'payout' => $amount,
                    'source' => ConversionSource::REVENUE_UPLOAD->value,
                    'source_ref' => \Prosper202\Conversion\Ledger\SourceRef::uploadBatch($batchId),
                    'dedupe_key' => DedupeKey::upload($batchId, $lineNo),
                    'user_agent' => 'revenue-upload',
                    'pixel_type' => 0,
                    // A network's report of revenue already counted is not a new
                    // sale: it writes no LTV purchase and emits no bridge event,
                    // exactly as the upload never did.
                    'skip_ltv' => true,
                    'skip_bridge' => true,
                ]);
            } catch (\Throwable $e) {
                // Every line before this one is a committed row of the batch:
                // say so, and leave the batch's counts saying what was read so
                // far (best-effort; the rows are what count).
                try {
                    $this->finishBatch($batchId, $lineNo - 1, $recorded, $skipped);
                } catch (\Throwable $finish) {
                    error_log('revenue upload: batch ' . $batchId . ' counts not updated after line ' . $lineNo . ' failed: ' . $finish->getMessage());
                }
                throw new BatchInterrupted('line ' . $lineNo, $recorded, $batchId, $e);
            }

            if (!$result['clickFound']) {
                $skipped++;
                self::notRecorded($unrecorded, ['line' => $lineNo, 'subid' => $line['subid'], 'amount' => $line['raw_amount'], 'status' => self::SKIPPED,
                    'reason' => self::NO_CLICK]);
                continue;
            }

            $recorded++;
            $totals[$clickId] = Amount::fromUnits(Amount::toUnits($totals[$clickId] ?? '0') + Amount::toUnits($amount));
            $total = Amount::fromUnits(Amount::toUnits($total) + Amount::toUnits($amount));
        }

        try {
            $this->finishBatch($batchId, $lineNo, $recorded, $skipped);
        } catch (\Throwable $e) {
            throw new BatchInterrupted('closing batch ' . $batchId, $recorded, $batchId, $e);
        }

        return ['batch_id' => $batchId] + $unrecorded
            + ['totals' => $totals, 'total' => $total, 'recorded' => $recorded, 'skipped' => $skipped];
    }

    /**
     * What import() would record, writing nothing: the same lines read by the
     * same rules, each line whose subid is a click of the account counted as
     * would_record (and summed into the totals), and every other line skipped
     * or read as the header with the reason import() gives, listed as
     * import() lists them.
     *
     * The report is read twice — once for the subids, asked of the database in
     * chunks, then again for the lines — rather than held in memory between
     * the two, so the stream has to be seekable (an upload's file, or
     * php://temp).
     *
     * @param resource $handle An open, seekable CSV stream positioned at its start.
     * @return array{
     *     lines: list<array{line: int, subid: string, amount: string, status: string, reason: string}>,
     *     unlisted: int,
     *     reasons: array<string, int>,
     *     totals: array<int, string>,
     *     total: string,
     *     would_record: int,
     *     skipped: int
     * } as import() answers, with would_record for recorded
     */
    public function preview(int $userId, $handle, int $subidColumn, int $amountColumn): array
    {
        if ($subidColumn < 0 || $amountColumn < 0) {
            throw new RuntimeException('choose the subid column and the commission column');
        }
        $start = ftell($handle);
        if ($start === false || !stream_get_meta_data($handle)['seekable']) {
            throw new RuntimeException('the report cannot be read twice: preview() needs a seekable stream');
        }

        // Which of the subids are the account's clicks, read in chunks: the
        // question record() answers with clickFound.
        $ids = [];
        $lineNo = 0;
        while (($line = self::readLine($handle, $lineNo, $subidColumn, $amountColumn)) !== false) {
            if ($line !== null && $line['status'] === null) {
                $ids[(int) $line['click_id']] = true;
            }
        }
        $owned = [];
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            $stmt = $this->conn->prepareWrite(
                'SELECT click_id FROM 202_clicks WHERE user_id = ? AND click_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')'
            );
            $this->conn->bind($stmt, 'i' . str_repeat('i', count($chunk)), array_merge([$userId], $chunk));
            foreach ($this->conn->fetchAll($stmt) as $row) {
                $owned[(int) $row['click_id']] = true;
            }
        }
        unset($ids);

        if (fseek($handle, $start) !== 0) {
            throw new RuntimeException('the report could not be read a second time');
        }
        $unrecorded = self::unrecorded();
        $totals = [];
        $total = '0.00000';
        $wouldRecord = 0;
        $skipped = 0;
        $lineNo = 0;
        while (($line = self::readLine($handle, $lineNo, $subidColumn, $amountColumn)) !== false) {
            if ($line === null) {
                continue; // a blank line
            }
            if ($line['status'] !== null) {
                if ($line['status'] === self::SKIPPED) {
                    $skipped++;
                }
                self::notRecorded($unrecorded, $line);
                continue;
            }
            $clickId = (int) $line['click_id'];
            if (!isset($owned[$clickId])) {
                $skipped++;
                self::notRecorded($unrecorded, ['line' => $line['line'], 'subid' => $line['subid'], 'amount' => $line['raw_amount'], 'status' => self::SKIPPED,
                    'reason' => self::NO_CLICK]);
                continue;
            }
            $wouldRecord++;
            $totals[$clickId] = Amount::fromUnits(Amount::toUnits($totals[$clickId] ?? '0') + Amount::toUnits((string) $line['amount']));
            $total = Amount::fromUnits(Amount::toUnits($total) + Amount::toUnits((string) $line['amount']));
        }

        return $unrecorded + ['totals' => $totals, 'total' => $total, 'would_record' => $wouldRecord, 'skipped' => $skipped];
    }

    /**
     * An empty list of the lines not recorded, as notRecorded() fills it.
     *
     * @return array{lines: list<array<string, mixed>>, unlisted: int, reasons: array<string, int>}
     */
    private static function unrecorded(): array
    {
        return ['lines' => [], 'unlisted' => 0, 'reasons' => []];
    }

    /**
     * A line that was not recorded: counted by its reason when it was skipped,
     * and listed while fewer than LISTED_LINES are, otherwise counted as
     * unlisted.
     *
     * @param array{lines: list<array<string, mixed>>, unlisted: int, reasons: array<string, int>} $unrecorded
     * @param array<string, mixed> $line
     */
    private static function notRecorded(array &$unrecorded, array $line): void
    {
        if ($line['status'] === self::SKIPPED) {
            $reason = (string) $line['reason'];
            $unrecorded['reasons'][$reason] = ($unrecorded['reasons'][$reason] ?? 0) + 1;
        }
        if (count($unrecorded['lines']) < self::LISTED_LINES) {
            $unrecorded['lines'][] = ['line' => $line['line'], 'subid' => $line['subid'], 'amount' => $line['amount'], 'status' => $line['status'], 'reason' => $line['reason']];
            return;
        }
        $unrecorded['unlisted']++;
    }

    /**
     * The next CSV record, read by the rules import() and preview() share:
     * false at the end of the stream, null for a blank line, otherwise the
     * line — with status null when it holds a subid and an amount to record
     * (click_id, amount, raw_amount), or its final status (header, skipped)
     * and the reason.
     *
     * The first line is the header row the column picker showed; it is
     * expected not to hold a subid. It is still listed, as the header, so a
     * file with no header whose first subid is malformed shows that line
     * instead of losing it.
     *
     * @param resource $handle
     * @param int $lineNo the number of the record before this one; advanced past it
     * @return array<string, mixed>|null|false
     */
    private static function readLine($handle, int &$lineNo, int $subidColumn, int $amountColumn): array|null|false
    {
        $row = fgetcsv($handle, 100000, ',', '"', '\\');
        if ($row === false) {
            return false;
        }
        $lineNo++;
        if (!is_array($row) || $row === [null]) {
            return null;
        }
        $subid = trim((string) ($row[$subidColumn] ?? ''));
        $rawAmount = trim((string) ($row[$amountColumn] ?? ''));

        $clickId = ClickId::parse($subid);
        if ($clickId === null) {
            return $lineNo > 1
                ? ['line' => $lineNo, 'subid' => $subid, 'amount' => $rawAmount, 'status' => self::SKIPPED,
                    'reason' => 'not a subid (a click id is a whole number)']
                : ['line' => $lineNo, 'subid' => $subid, 'amount' => $rawAmount, 'status' => self::HEADER,
                    'reason' => 'read as the header row (not a subid)'];
        }

        $amount = self::parseAmount($rawAmount);
        if ($amount === null) {
            return ['line' => $lineNo, 'subid' => $subid, 'amount' => $rawAmount, 'status' => self::SKIPPED,
                'reason' => 'the commission is not a number'];
        }

        return ['line' => $lineNo, 'subid' => $subid, 'click_id' => $clickId, 'amount' => $amount, 'raw_amount' => $rawAmount, 'status' => null];
    }

    /**
     * Words that name the affiliate's money, the likeliest first: a network
     * report's "Commission" or "Payout" is the affiliate's take, its
     * "Revenue", "Amount" or "Sale" more often the order's value.
     */
    private const AMOUNT_WORDS = ['commission', 'payout', 'earning', 'revenue', 'income', 'amount', 'sale'];

    /**
     * A header that names one of these is not a money column, whatever money
     * word it also holds: "Sale ID", "Commission Rate", "Payout Date",
     * "Sale Count", "Commission Status".
     */
    private const NOT_AMOUNT = '/\bid\b|_id\b|\bno\b|\bnumber\b|#|date|time|count|\bqty\b|quantity|rate|%|percent|status|type|currency/';

    /**
     * The subid and commission columns a revenue report's header names, when
     * it names them plainly: the column picker pre-selects them and says so,
     * the API uses them when no column is named, and the person changes them
     * if the guess is wrong (UI standard, rule 4).
     *
     * The subid is the first header that names one. The commission is the
     * header with the likeliest money word (AMOUNT_WORDS), the first of those
     * on a tie, never one that names an id, a date, a count, a rate or a
     * status (NOT_AMOUNT). It took the first header with any money word, so
     * "Date,Sub ID,Sale Amount,Commission" recorded each order's total as the
     * commission, and "Sale ID,Sub ID,Commission" each sale's id (measured).
     * Null when nothing qualifies — the column is then left for the person to
     * choose, never guessed at random.
     *
     * @param list<string> $header
     * @return array{subid: ?int, amount: ?int}
     */
    public static function guessColumns(array $header): array
    {
        $subid = null;
        $amount = null;
        $amountRank = PHP_INT_MAX;
        foreach ($header as $index => $name) {
            $name = strtolower(trim((string) $name));
            if ($subid === null && preg_match('/sub[\s_-]*id|click[\s_-]*id|\bt202|\baff[\s_-]*sub|\bsid\b/', $name)) {
                $subid = (int) $index;
                continue;
            }
            if (preg_match(self::NOT_AMOUNT, $name)) {
                continue;
            }
            foreach (self::AMOUNT_WORDS as $rank => $word) {
                if ($rank < $amountRank && str_contains($name, $word)) {
                    $amount = (int) $index;
                    $amountRank = $rank;
                    break;
                }
            }
        }
        return ['subid' => $subid, 'amount' => $amount];
    }

    /**
     * A commission cell as a decimal string, or null when it is not one.
     * Currency symbols, thousands separators and surrounding spaces are what
     * network reports put around a number; anything else is not a number.
     *
     * A comma is read only as a thousands separator: between groups of three
     * digits, before any decimal point, the first group not starting with 0
     * ("1,234.56", "1,000,000"; "0,125" is a decimal comma). Every comma
     * was deleted wherever it stood, so a report written with a decimal comma
     * had "12,50" recorded as 1250.00000, "1.234,56" as 1.23456 and "1,23" as
     * 123.00000, each line marked recorded (measured through POST
     * /conversions/uploads). A comma anywhere else is not a number, and the
     * line is skipped with the reason, never guessed at (CLAUDE.md #4).
     */
    public static function parseAmount(string $raw): ?string
    {
        $clean = str_replace(['$', ' ', "\u{00A0}"], '', trim($raw));
        if (str_contains($clean, ',')) {
            if (preg_match('/^-?[1-9]\d{0,2}(,\d{3})+(\.\d+)?$/D', $clean) !== 1) {
                return null;
            }
            $clean = str_replace(',', '', $clean);
        }
        if (preg_match('/^-?\d+(\.\d+)?$/D', $clean) !== 1) {
            return null;
        }
        try {
            return Amount::fromUnits(Amount::toUnits($clean));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function createBatch(int $userId, string $fileName): int
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_conversion_uploads (user_id, file_name, line_count, recorded_count, skipped_count, uploaded_at)
             VALUES (?, ?, 0, 0, 0, ?)'
        );
        $this->conn->bind($stmt, 'isi', [$userId, mb_substr($fileName, 0, 255), time()]);
        $batchId = $this->conn->executeInsert($stmt);
        if ($batchId <= 0) {
            throw new RuntimeException('the upload batch was not created');
        }

        return $batchId;
    }

    private function finishBatch(int $batchId, int $lines, int $recorded, int $skipped): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_conversion_uploads SET line_count = ?, recorded_count = ?, skipped_count = ? WHERE batch_id = ?'
        );
        $this->conn->bind($stmt, 'iiii', [$lines, $recorded, $skipped, $batchId]);
        $this->conn->executeUpdate($stmt);
    }
}
