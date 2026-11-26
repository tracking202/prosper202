<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * Two payment platforms' sales on one click can carry the same transaction
 * id (X5TX), so a statement that finds a platform's sale by its id has to
 * say whose: TransactionSale::restriction() (its own, or one no other
 * platform recorded, as the writer keys it), or `source = ?` (its own only).
 * This holds every statement on 202_conversion_logs (and every fragment
 * naming no table) in the payment-notification code and the conversion
 * writer that compares a transaction_id to one of those.
 *
 * What it reads: strings as PHP assembles them (AssembledStrings), so a
 * comparison and its restriction are seen only in one chain of `.`
 * operands. What it does not see: a statement elsewhere that a platform's
 * code calls, and a comparison spelled other than `transaction_id =`.
 */
final class SalesAreReadByTheirPlatformTest extends TestCase
{
    /** @return list<string> repository-relative paths */
    private static function scanned(): array
    {
        $root = SourceScan::repoRoot();
        $files = ['202-config/Conversion/MysqlConversionRepository.php'];
        foreach (new \RecursiveIteratorIterator(SourceScan::tree($root . '/202-config/PaymentNotification')) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    public function testEveryReadOfASaleByItsIdSaysWhosePlatform(): void
    {
        $root = SourceScan::repoRoot();
        $found = 0;
        $unread = [];
        foreach (self::scanned() as $file) {
            foreach (AssembledStrings::of((string) file_get_contents($root . '/' . $file)) as $string) {
                $text = $string['text'];
                if (preg_match('/\btransaction_id\s*=/', $text) !== 1) {
                    continue;
                }
                // A fragment naming no table is read as the ledger's: it is
                // joined onto a statement this scan sees apart from it.
                preg_match_all('/\b202_[a-z0-9_]+/', $text, $tables);
                if ($tables[0] !== [] && !in_array('202_conversion_logs', $tables[0], true)) {
                    continue;
                }
                $found++;
                $restricted = str_contains($text, 'source = ?') || array_filter(
                    $string['calls'],
                    static fn (array $call): bool => $call['method'] === 'restriction'
                        && str_ends_with($call['class'], 'Conversion\\Ledger\\TransactionSale')
                ) !== [];
                if (!$restricted) {
                    $unread[] = $file . ':' . $string['line'] . ' '
                        . trim((string) preg_replace('/\s+/', ' ', str_replace(AssembledStrings::UNREAD, '…', $text)));
                }
            }
        }

        self::assertGreaterThanOrEqual(8, $found, 'the scan found fewer comparisons than the tree holds');
        self::assertSame([], $unread, 'Each statement finds a sale by its transaction id with no platform named.'
            . ' Add TransactionSale::restriction() (or source = ?) to it.');
    }
}
