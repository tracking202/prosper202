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
