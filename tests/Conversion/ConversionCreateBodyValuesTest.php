<?php

declare(strict_types=1);

namespace Tests\Conversion;

use Api\V3\Controllers\ConversionsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * POST /conversions reads the ids a sale is keyed and identified by, and its
 * payout, as what the ledger can store, and refuses anything else naming
 * the field before a statement is prepared.
 *
 * Each body is decoded from JSON here, as api/v3/index.php decodes it
 * (json_decode($raw, true), no JSON_BIGINT_AS_STRING), because the defects
 * lived in what the decoder hands over: a JSON integer past PHP_INT_MAX
 * arrives as a float, and (string) printed 12345678901234567891 and
 * 12345678901234567892 alike as "1.2345678901235E+19", so the second sale
 * was answered duplicate and dropped. An object was "Array", true was "1"
 * (a reversal's default reference). An id past 255 bytes and a payout past
 * what Amount reads were a 500 naming nothing; 1234567 reached the INSERT,
 * past click_payout's decimal(11,5).
 */
final class ConversionCreateBodyValuesTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function body(string $json): array
    {
        $body = json_decode($json, true);
        self::assertIsArray($body, $json);

        return $body;
    }

    /**
     * Each body's fields after click 10, and the field it is refused naming.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableBodies(): iterable
    {
        $reversal = '"transaction_id":"T-1","status":"reversed","reversal_id":';
        yield 'transaction_id past PHP_INT_MAX' => ['"transaction_id":12345678901234567891', 'transaction_id'];
        yield 'transaction_id a fraction' => ['"transaction_id":1.5', 'transaction_id'];
        yield 'transaction_id true' => ['"transaction_id":true', 'transaction_id'];
        yield 'transaction_id false' => ['"transaction_id":false', 'transaction_id'];
        yield 'transaction_id an object' => ['"transaction_id":{"a":1}', 'transaction_id'];
        yield 'transaction_id a list' => ['"transaction_id":["T-1"]', 'transaction_id'];
        yield 'transaction_id 256 bytes' => ['"transaction_id":"' . str_repeat('x', 256) . '"', 'transaction_id'];
        yield 'transaction_id 200 characters, 400 bytes' => [
            '"transaction_id":"' . str_repeat('é', 200) . '"',
            'transaction_id',
        ];
        yield 'reversal_id past PHP_INT_MAX' => [$reversal . '98765432109876543210', 'reversal_id'];
        yield 'reversal_id true' => [$reversal . 'true', 'reversal_id'];
        yield 'reversal_id an object' => [$reversal . '{"r":1}', 'reversal_id'];
        yield 'reversal_id 256 bytes' => [$reversal . '"' . str_repeat('r', 256) . '"', 'reversal_id'];
        // Without the status the body was a new sale: money added where the
        // caller meant to take it back.
        yield 'reversal_id without status reversed' => ['"transaction_id":"T-1","reversal_id":"R-1"', 'reversal_id'];
        yield 'customer_ref past PHP_INT_MAX' => ['"customer_ref":12345678901234567891', 'customer_ref'];
        yield 'customer_ref true' => ['"customer_ref":true', 'customer_ref'];
        yield 'customer_ref an object' => ['"customer_ref":{"id":1}', 'customer_ref'];
        yield 'payout one past the column' => ['"payout":1000000', 'payout'];
        yield 'payout 1234567' => ['"payout":1234567', 'payout'];
        yield 'payout -1234567' => ['"payout":-1234567', 'payout'];
        yield 'payout that rounds past the column' => ['"payout":"999999.999995"', 'payout'];
        yield 'payout of 14 digits' => ['"payout":"99999999999999"', 'payout'];
        yield 'payout 10^14 as a JSON integer' => ['"payout":100000000000000', 'payout'];
        yield 'payout 1e300' => ['"payout":1e300', 'payout'];
    }

    /** @dataProvider unreadableBodies */
    public function testAValueTheLedgerCannotHoldIsRefusedNamingItFirst(string $fields, string $field): void
    {
        $json = '{"click_id":10,' . $fields . '}';
        $db = new FakeMysqliConnection();
        try {
            (new ConversionsController($db, 1))->create(self::body($json));
            self::fail($json . ' was accepted');
        } catch (ValidationException $e) {
            self::assertSame([$field], array_keys($e->getFieldErrors()), $e->getMessage());
        }
        self::assertSame([], $db->preparedSql, 'nothing was read or written for a refused body');
    }

    public function testThePayoutRangeIsTheColumnsAndSaysSo(): void
    {
        try {
            (new ConversionsController(new FakeMysqliConnection(), 1))
                ->create(self::body('{"click_id":10,"payout":1234567}'));
            self::fail('1234567 was accepted');
        } catch (ValidationException $e) {
            // 202_conversion_logs.click_payout is decimal(11,5).
            self::assertSame(
                ['payout' => 'Must be from -999999.99999 to 999999.99999, what one conversion holds'],
                $e->getFieldErrors()
            );
        }
    }

    /** A click 10 the account owns, so a body that is read gets as far as the ledger. */
    private static function ledger(): FakeMysqliConnection
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => '0.00000',
                'click_time' => 1700000000, 'click_lead' => 0]]
        );

        return $db;
    }

    /** @return iterable<string, array{string, string}> */
    public static function readableIds(): iterable
    {
        yield 'a string' => ['"T-1"', 'tx:T-1'];
        yield 'a string of digits past PHP_INT_MAX' => ['"12345678901234567891"', 'tx:12345678901234567891'];
        yield 'a JSON integer, as its digits' => ['9223372036854775807', 'tx:9223372036854775807'];
        yield 'a negative JSON integer' => ['-42', 'tx:-42'];
        yield '255 bytes' => ['"' . str_repeat('y', 255) . '"', 'tx:' . str_repeat('y', 255)];
    }

    /** @dataProvider readableIds */
    public function testATransactionIdThatCanBeReadIsKeyedExactly(string $id, string $key): void
    {
        $db = self::ledger();
        try {
            (new ConversionsController($db, 1))
                ->create(self::body('{"click_id":10,"payout":5,"transaction_id":' . $id . '}'));
        } catch (\Throwable) {
            // The fake yields no insert id, so the write stops after the
            // lookup this test is about.
        }
        $lookups = $db->statementsContaining('FROM 202_conversion_logs WHERE click_id = ? AND dedupe_key = ?');
        self::assertCount(1, $lookups, 'the body was refused before the ledger');
        self::assertSame([10, $key], $lookups[0]->boundValues);
    }

    /** @return iterable<string, array{string}> */
    public static function payoutsTheColumnHolds(): iterable
    {
        yield 'its maximum' => ['999999.99999'];
        yield 'its minimum' => ['-999999.99999'];
        yield 'one that rounds to its maximum' => ['"999999.999994"'];
        yield 'a JSON integer' => ['999999'];
    }

    /** @dataProvider payoutsTheColumnHolds */
    public function testAPayoutTheColumnHoldsReachesTheLedger(string $payout): void
    {
        $db = self::ledger();
        try {
            (new ConversionsController($db, 1))
                ->create(self::body('{"click_id":10,"transaction_id":"T-9","payout":' . $payout . '}'));
        } catch (\Throwable $e) {
            // As above: the fake stops the write after the click lock, and
            // the controller reports that as a refusal naming no field.
            if ($e instanceof ValidationException) {
                self::assertArrayNotHasKey('payout', $e->getFieldErrors(), $payout . ' was refused');
            }
        }
        self::assertCount(1, $db->statementsContaining('FOR UPDATE'), 'the payout was refused before the ledger');
    }
}
