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
 */
final class RevenueUploadImporter
{
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
     *     totals: array<int, string>,
     *     recorded: int,
     *     skipped: int
     * } totals: click_id => the sum of this file's recorded lines for it.
     */
    public function import(int $userId, string $fileName, $handle, int $subidColumn, int $amountColumn): array
    {
        if ($subidColumn < 0 || $amountColumn < 0) {
            throw new RuntimeException('choose the subid column and the commission column');
        }

        $batchId = $this->createBatch($userId, $fileName);

        $lines = [];
        $totals = [];
        $recorded = 0;
        $skipped = 0;
        $lineNo = 0;
        while (($row = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            $lineNo++;
            if (!is_array($row) || $row === [null]) {
                continue; // a blank line
            }
            $subid = trim((string) ($row[$subidColumn] ?? ''));
            $rawAmount = trim((string) ($row[$amountColumn] ?? ''));

            $clickId = ClickId::parse($subid);
            if ($clickId === null) {
                // The first line is the header row the column picker showed;
                // it is expected not to hold a subid.
                if ($lineNo > 1) {
                    $skipped++;
                    $lines[] = ['line' => $lineNo, 'subid' => $subid, 'amount' => $rawAmount, 'status' => 'skipped',
                        'reason' => 'not a subid (a click id is a whole number)'];
                }
                continue;
            }

            $amount = self::parseAmount($rawAmount);
            if ($amount === null) {
                $skipped++;
                $lines[] = ['line' => $lineNo, 'subid' => $subid, 'amount' => $rawAmount, 'status' => 'skipped',
                    'reason' => 'the commission is not a number'];
                continue;
            }

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

            if (!$result['clickFound']) {
                $skipped++;
                $lines[] = ['line' => $lineNo, 'subid' => $subid, 'amount' => $rawAmount, 'status' => 'skipped',
                    'reason' => 'no click with this subid in your account'];
                continue;
            }

            $recorded++;
            $totals[$clickId] = Amount::fromUnits(Amount::toUnits($totals[$clickId] ?? '0') + Amount::toUnits($amount));
            $lines[] = ['line' => $lineNo, 'subid' => $subid, 'amount' => $amount, 'status' => 'recorded', 'reason' => ''];
        }

        $this->finishBatch($batchId, $lineNo, $recorded, $skipped);

        return ['batch_id' => $batchId, 'lines' => $lines, 'totals' => $totals, 'recorded' => $recorded, 'skipped' => $skipped];
    }

    /**
     * A commission cell as a decimal string, or null when it is not one.
     * Currency symbols, thousands separators and surrounding spaces are what
     * network reports put around a number; anything else is not a number.
     */
    public static function parseAmount(string $raw): ?string
    {
        $clean = str_replace(['$', ',', ' ', "\u{00A0}"], '', trim($raw));
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
