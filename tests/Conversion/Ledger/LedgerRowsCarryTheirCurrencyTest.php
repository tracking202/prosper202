<?php

declare(strict_types=1);

namespace Tests\Conversion\Ledger;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;
use Tests\Support\SqlLiteralText;

/**
 * Every ledger row records the currency its amount is in, and every reader
 * of a ledger amount has decided what it does about that currency.
 *
 * Two floors, both over the served tree's SQL:
 *
 * - a row is inserted only by the two ledger writers, which stamp it
 *   (LedgerCurrencyIntegrationTest runs them); an INSERT anywhere else would
 *   write a row with no currency, which reads as "not recorded" for good;
 * - a file whose SQL reads 202_conversion_logs.click_payout is listed here
 *   with what it does: shows the row's own currency (and then its SQL names
 *   the column), nets amounts of one click or one sale against each other
 *   (reversals take their sale's currency, so those are one currency's),
 *   or adds amounts across clicks, where RevenueCurrencies says how much of
 *   the period is in which currency. A new reader fails until it is listed.
 *
 * What this does not see: a read assembled from a helper that holds the
 * column name (CountedAmount::sqlOrNull(), LedgerReportSql::partsTable()),
 * which is the listed helper's caller's to decide; OfflineExport is such a
 * caller and is held by its own tests.
 */
final class LedgerRowsCarryTheirCurrencyTest extends TestCase
{
    private const WRITERS = [
        '202-config/Conversion/Ledger/MysqlConversionLedger.php',
        '202-config/Conversion/MysqlConversionRepository.php',
    ];

    private const ROW = 'row';
    private const NETS = 'nets';
    private const TOTALS = 'totals';

    /** file => [what it does, why] */
    private const READERS = [
        '202-config/Conversion/ConversionLog.php' => [self::ROW, 'the conversion log shows each row in its own currency'],
        '202-config/Conversion/Ledger/ClickBreakdown.php' => [self::ROW, 'the click breakdown shows each row in its own currency, and says when the counted ones differ'],
        '202-config/Conversion/Ledger/MysqlConversionLedger.php' => [self::ROW, 'the writer: the baseline takes its click\'s rows\' currency'],
        '202-config/Conversion/MysqlConversionRepository.php' => [self::ROW, 'the writer: a reversal takes its sale\'s currency'],
        '202-config/Conversion/Ledger/RevenueCurrencies.php' => [self::ROW, 'the per-currency totals themselves'],
        '202-config/Destinations/DestinationQueue.php' => [self::ROW, 'an ad platform is sent the row\'s currency, and nothing for a row with none'],
        '202-config/Click/PurLinkPixels.php' => [self::ROW, 'the pixel\'s tokens carry the row\'s currency'],
        '202-config/migrations/run_ltv_backfill.php' => [self::ROW, 'the revenue event it makes is in the row\'s currency'],
        'api/v3/Controllers/ConversionsController.php' => [self::ROW, 'each conversion is answered with its currency; the match-method totals list other currencies beside them'],
        '202-config/functions-upgrade.php' => [self::ROW, 'the upgrade: it gives the rows their currency'],
        '202-config/PaymentNotification/Processor.php' => [self::NETS, 'compares a refund with the sale it reverses, on one click, and reads the account\'s currency to say when a provider states another'],
        '202-config/Update/SubidBatch.php' => [self::NETS, 'compares an uploaded line with the sale already on its click; the message mentions a currency sign'],
        '202-config/Attribution/CountedAmount.php' => [self::NETS, 'a sale less its own reversals, which take the sale\'s currency'],
        '202-config/Notifications/NotificationOutbox.php' => [self::NETS, 'the traffic source\'s postback URL has a payout token and none for a currency: the number is sent as recorded'],
        '202-config/Conversion/Ledger/LedgerReportSql.php' => [self::TOTALS, 'the reports\' counted parts, added per click and group; RevenueCurrencies says what the period holds in other currencies'],
        '202-config/Attribution/AttributionReports.php' => [self::TOTALS, 'multi-touch credit adds conversions\' amounts across clicks, in the account\'s currency as its reports label them'],
        '202-config/Attribution/AttributionWorker.php' => [self::TOTALS, 'splits one conversion\'s amount into credits, which the attribution reports add'],
        '202-config/Ltv/MysqlRecommendationRepository.php' => [self::TOTALS, 'reads whether a customer bought (an amount of 0 or more), not how much'],
        '202-config/Conversion/Ledger/Amount.php' => [self::NETS, 'reads the column\'s width from its definition, no row'],
    ];

    public function testALedgerRowIsInsertedOnlyByTheWritersThatStampIt(): void
    {
        $inserters = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            if (preg_match('/\b(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?202_conversion_logs\b/i', SqlLiteralText::of($source)) === 1) {
                $inserters[] = $file;
            }
        }
        sort($inserters);

        self::assertSame(self::WRITERS, $inserters, 'A ledger row is inserted by MysqlConversionRepository (through'
            . ' record()) or the ledger\'s legacy baseline, which stamp its currency. Record through the repository.');

        $files = SourceScan::phpFiles();
        self::assertStringContainsString(
            "array_push(\$columns, 'currency', 'stated_amount', 'stated_currency')",
            $files['202-config/Conversion/MysqlConversionRepository.php'],
            'record() names the currency columns on its insert'
        );
        self::assertStringContainsString(
            'UPDATE 202_conversion_logs SET currency = ?, currency_basis = ? WHERE conv_id = ?',
            $files['202-config/Conversion/Ledger/MysqlConversionLedger.php'],
            'the legacy baseline is stamped in the transaction that inserts it'
        );
    }

    public function testEveryReaderOfALedgerAmountSaysWhatItDoesAboutTheCurrency(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            $sql = SqlLiteralText::of($source);
            if (preg_match('/202_conversion_logs/i', $sql) === 1 && preg_match('/\bclick_payout\b/i', $sql) === 1) {
                $found[$file] = preg_match('/\bcurrency\b/i', $sql) === 1;
            }
        }

        $unlisted = array_keys(array_diff_key($found, self::READERS));
        self::assertSame([], $unlisted, "These files read a ledger amount and are not listed:\n  " . implode("\n  ", $unlisted)
            . "\nA row's amount is in the currency the row recorded (RowCurrency), which need not be the account's now."
            . ' Select `currency` and show it with the amount, or list the file with why it needs none.');
        $gone = array_keys(array_diff_key(self::READERS, $found));
        self::assertSame([], $gone, 'These entries read no ledger amount any more; remove them: ' . implode(', ', $gone));

        foreach (self::READERS as $file => [$does, $why]) {
            self::assertNotSame('', $why, $file);
            if ($does === self::ROW) {
                self::assertTrue($found[$file], "$file is listed as showing the row's currency, and its SQL names no currency column");
            }
        }
    }

    /** @dataProvider plantedReads */
    public function testAPlantedReadIsSeen(string $code, bool $seen): void
    {
        $sql = SqlLiteralText::of("<?php\n" . $code);
        $reads = preg_match('/202_conversion_logs/i', $sql) === 1 && preg_match('/\bclick_payout\b/i', $sql) === 1;

        self::assertSame($seen, $reads, $code);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function plantedReads(): iterable
    {
        yield 'a plain read' => ['$db->query("SELECT click_payout FROM 202_conversion_logs WHERE conv_id = 1");', true];
        yield 'aliased, backticks' => ['$db->query("SELECT cl.`click_payout` FROM `202_conversion_logs` cl");', true];
        yield 'a sum' => ['$db->query("SELECT SUM(click_payout) FROM 202_conversion_logs");', true];
        yield 'split across .=' => ['$sql = "SELECT click_payout"; $sql .= " FROM 202_conversion_logs"; $db->query($sql);', true];
        yield 'the click\'s own value' => ['$db->query("SELECT click_payout FROM 202_clicks WHERE click_id = 1");', false];
        yield 'no amount' => ['$db->query("SELECT conv_id FROM 202_conversion_logs");', false];
    }
}
