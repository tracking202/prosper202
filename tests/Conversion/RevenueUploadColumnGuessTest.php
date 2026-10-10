<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\RevenueUploadImporter;

/**
 * The commission column a revenue report's header names, as the API uses it
 * when no column is given and the Upload Revenue page pre-selects it.
 *
 * The guess took the first header with any money word, so a network's usual
 * "Date,Sub ID,Sale Amount,Commission" recorded each order's total as the
 * commission, and "Sale ID,Sub ID,Commission" each sale's id -- written
 * before the "guessed" note could say so.
 */
final class RevenueUploadColumnGuessTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, ?string, ?string}>
     */
    public static function headers(): iterable
    {
        yield 'commission over an order total' => [['Date', 'Sub ID', 'Sale Amount', 'Commission'], 'Sub ID', 'Commission'];
        yield 'never an id' => [['Sale ID', 'Sub ID', 'Commission'], 'Sub ID', 'Commission'];
        yield 'payout over revenue' => [['Sub ID', 'Revenue', 'Payout'], 'Sub ID', 'Payout'];
        yield 'never a rate' => [['subid', 'Commission Rate', 'Commission'], 'subid', 'Commission'];
        yield 'never a date' => [['subid', 'Payout Date', 'Payout'], 'subid', 'Payout'];
        yield 'never a status' => [['subid', 'Commission Status', 'Earnings'], 'subid', 'Earnings'];
        yield 'a plain amount' => [['subid', 'amount'], 'subid', 'amount'];
        yield 'the subid after the money' => [['Order Total', 'Affiliate Commission', 'sub_id'], 'sub_id', 'Affiliate Commission'];
        yield 'nothing plain: left to the person' => [['subid', 'Order Total'], 'subid', null];
        yield 'only an id: left to the person' => [['subid', 'Sale ID'], 'subid', null];
    }

    /**
     * @dataProvider headers
     * @param list<string> $header
     */
    public function testTheCommissionColumnIsTheAffiliatesMoney(array $header, ?string $subid, ?string $amount): void
    {
        $guess = RevenueUploadImporter::guessColumns($header);
        self::assertSame($subid, $guess['subid'] === null ? null : $header[$guess['subid']], 'subid');
        self::assertSame($amount, $guess['amount'] === null ? null : $header[$guess['amount']], 'commission');
    }
}
