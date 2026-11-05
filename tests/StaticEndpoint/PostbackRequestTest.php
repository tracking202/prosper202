<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;

/**
 * The global postback (gpb.php) reads a form-encoded POST body as well as
 * the query string, as paid 1.9.56 did: p202PostbackRequest() merges them,
 * the query winning any name it carries with a value, and gpb.php reads
 * every parameter from that merge, never from $_GET alone.
 */
final class PostbackRequestTest extends TestCase
{
    private const GPB = 'tracking202/static/gpb.php';

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine {
                public function setDirtyHour($click_id) {}
                public function getSummary($s,$e,$p,$u=1,$up=false,$n=false) { return ""; }
            }');
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function requests(): iterable
    {
        yield 'GET only' => [['subid' => '42', 'amount' => '5'], [], ['subid' => '42', 'amount' => '5']];
        yield 'POST body only' => [[], ['subid' => '42', 'amount' => '5', 't202txid' => 'ord-1'],
            ['subid' => '42', 'amount' => '5', 't202txid' => 'ord-1']];
        yield 'both: the query wins a name both send' => [['subid' => '42', 'amount' => '5'],
            ['subid' => '43', 'amount' => '9', 'txid' => 'ord-2'], ['subid' => '42', 'amount' => '5', 'txid' => 'ord-2']];
        yield 'blank query placeholders take the body\'s values' => [['amount' => '', 'subid' => ''],
            ['subid' => '42', 'amount' => '7.5'], ['amount' => '7.5', 'subid' => '42']];
        yield 'body names are lowercased, as $_GET\'s are' => [[], ['SubID' => '42', 'Amount' => '5'],
            ['subid' => '42', 'amount' => '5']];
        yield 'a blank body value never replaces a query value' => [['subid' => '42'], ['subid' => ''], ['subid' => '42']];
        yield 'a "0" in the query is a value, not a blank' => [['amount' => '0'], ['amount' => '5'], ['amount' => '0']];
        yield 'a query sid keeps a body subid out: they name one click' => [['sid' => '5'], ['subid' => '7', 'amount' => '2'],
            ['sid' => '5', 'amount' => '2']];
        yield 'a query subid keeps a body sid out' => [['subid' => '5'], ['sid' => '7'], ['subid' => '5']];
        yield 'a blank query subid beside a query sid still keeps the body out' => [['subid' => '', 'sid' => '5'],
            ['subid' => '7'], ['subid' => '', 'sid' => '5']];
        yield 'blank query click names take the body\'s' => [['subid' => '', 'sid' => ''], ['sid' => '7'],
            ['subid' => '', 'sid' => '7']];
        // Refused downstream (ClickId::parse), never replaced by the body's value.
        yield 'an array in the query is a value, not a blank' => [['subid' => ['1']], ['subid' => '42'],
            ['subid' => ['1']]];
        // As connect2.php's array_change_key_case() on $_GET: the later name wins.
        yield 'body names differing only in case: the later wins' => [[], ['SubID' => '41', 'subid' => '42'],
            ['subid' => '42']];
    }

    /**
     * @dataProvider requests
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $body
     * @param array<array-key, mixed> $expected
     */
    public function testMergesTheBodyUnderTheQuery(array $query, array $body, array $expected): void
    {
        self::assertSame($expected, p202PostbackRequest($query, $body));
    }

    /**
     * The readers pick the first alias with a value, so a body alias that
     * outranks the query's would win unless the whole group is kept out.
     *
     * @return iterable<string, array{string, string, callable(array<array-key, mixed>): mixed, mixed}>
     */
    public static function aliasCrossings(): iterable
    {
        $txid = static fn (array $r): string => p202ExtractTransactionId($r);
        // Providers run before setUpBeforeClass().
        self::setUpBeforeClass();
        $orderNames = \P202_ORDER_NUMBER_NAMES;
        foreach ($orderNames as $queryName) {
            foreach ($orderNames as $bodyName) {
                if ($queryName !== $bodyName) {
                    yield "order number: query $queryName, body $bodyName" => [$queryName, $bodyName, $txid, 'A'];
                }
            }
        }
        $customer = static fn (array $r): array => p202ExtractCustomer($r + ['cust' => 'c-1']);
        yield 'customer: query customer_ref, body cust' => ['customer_ref', 'cust',
            static fn (array $r): array => p202ExtractCustomer($r), ['customer_ref' => 'A']];
        yield 'customer type: query customer_ref_type, body cust_type' => ['customer_ref_type', 'cust_type', $customer,
            ['customer_ref' => 'c-1', 'customer_ref_type' => 'A']];
        yield 'product: query sku, body product_id' => ['sku', 'product_id',
            static fn (array $r): array => p202ExtractItems($r), [['sku' => 'A']]];
        yield 'product: query product_id, body sku' => ['product_id', 'sku',
            static fn (array $r): array => p202ExtractItems($r), [['external_product_id' => 'A']]];
        $signature = static fn (array $r): mixed => $r['cust_sig'] ?? null;
        yield 'customer signature: query cust, body cust_sig' => ['cust', 'cust_sig', $signature, null];
        yield 'customer signature: query cust_sig, body cust' => ['cust_sig', 'cust',
            static fn (array $r): array => p202ExtractCustomer($r), []];
    }

    /**
     * @dataProvider aliasCrossings
     * @param callable(array<array-key, mixed>): mixed $read
     */
    public function testAQueryAliasKeepsTheBodysWholeGroupOut(string $queryName, string $bodyName, callable $read, mixed $expected): void
    {
        $request = p202PostbackRequest(['subid' => '5', $queryName => 'A'], [$bodyName => 'B']);

        self::assertSame($expected, $read($request));
        self::assertArrayNotHasKey($bodyName, $request);
    }

    public function testABlankQueryAliasLeavesTheBodysGroupIn(): void
    {
        self::assertSame('B', p202ExtractTransactionId(p202PostbackRequest(['t202txid' => ''], ['order_id' => 'B'])));
    }

    public function testAWhitespaceQueryValueIsBlankAsTheReadersSeeIt(): void
    {
        self::assertSame('B', p202ExtractTransactionId(p202PostbackRequest(['txid' => ' '], ['order_id' => 'B'])));
        self::assertSame('42', p202PostbackRequest(['subid' => ' '], ['subid' => '42'])['subid']);
        self::assertSame(['customer_ref' => 'B'], p202ExtractCustomer(p202PostbackRequest(['cust' => "\t"], ['cust' => 'B'])));
    }

    public function testAQueryConsentSwitchKeepsTheBodysOutEvenWhenBlank(): void
    {
        $refused = p202PostbackRequest(['subid' => '5', 'p202_consent' => ''], ['p202_consent' => '1']);
        self::assertSame('', $refused['p202_consent']);
        self::assertFalse(\Prosper202\Identity\RequestSignals::consentGiven($refused));
        $given = p202PostbackRequest(['subid' => '5', 'p202_consent' => '1'], ['p202_consent' => '0']);
        self::assertTrue(\Prosper202\Identity\RequestSignals::consentGiven($given));
    }

    public function testGpbReadsEveryParameterFromTheMergedRequest(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . self::GPB);
        self::assertIsString($source);
        $reads = [];
        $line = 0;
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && in_array($token[1], ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE'], true)) {
                $reads[] = $token[1] . '@' . $token[2];
                $line = $token[2];
            }
        }
        self::assertCount(2, $reads, 'only the merge reads the superglobals: ' . implode(', ', $reads));
        $merge = explode("\n", $source)[$line - 1];
        self::assertStringContainsString('$request = p202PostbackRequest($_GET, $_POST);', $merge);
        foreach (['p202RecordWebEvent($db, (int) $mysql[\'click_id\'], $request', 'p202ExtractReversal($request)',
            'p202ExtractTransactionId($request)', 'p202ExtractCustomer($request)', 'p202ExtractItems($request)',
            "p202LinkConversionIdentity(\$db, (int) \$mysql['click_id'], \$request)"] as $call) {
            self::assertStringContainsString($call, $source);
        }
    }
}
