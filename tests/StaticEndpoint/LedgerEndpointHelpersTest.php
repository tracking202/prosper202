<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\RevenueUploadImporter;
use Tests\Support\FakeMysqliConnection;

/**
 * The request-side helpers the conversion ledger added to the static
 * endpoints: which click a pixel names, whether a postback is a reversal,
 * and the one traffic-source sender.
 */
final class LedgerEndpointHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine {
                public function setDirtyHour($click_id) {}
                public function getSummary($s,$e,$p,$u=1,$up=false,$n=false) { return ""; }
            }');
        }
        if (!function_exists('replaceTokens')) {
            // The real one lives in connect2.php, which needs a database. The
            // sender is tested for what it does with the URLs; token
            // expansion is replaceTokens()'s own business.
            eval('function replaceTokens($url, $tokens = [], $fillblanks = 0) {
                foreach ($tokens as $k => $v) { $url = str_ireplace("[[" . $k . "]]", rawurlencode((string) $v), (string) $url); }
                return $url;
            }');
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
    }

    // --- which click a pixel names -----------------------------------------

    /**
     * @dataProvider clickRequests
     * @param array<string, mixed> $get
     * @param array<string, mixed> $cookies
     * @param array{click_id: int|null, malformed: string|null} $expected
     */
    public function testClickIdFromRequest(array $get, array $cookies, int $cid, array $expected): void
    {
        self::assertSame($expected, p202ClickIdFromRequest($get, $cookies, $cid));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, int, array{click_id: int|null, malformed: string|null}}> */
    public static function clickRequests(): iterable
    {
        $none = ['click_id' => null, 'malformed' => null];
        yield 'subid wins' => [['subid' => '42', 'sid' => '7'], ['tracking202subid' => '9'], 0, ['click_id' => 42, 'malformed' => null]];
        yield 'sid when no subid' => [['sid' => '7'], [], 0, ['click_id' => 7, 'malformed' => null]];
        yield 'campaign cookie before the general one' => [[], ['tracking202subid_a_5' => '8', 'tracking202subid' => '9'], 5, ['click_id' => 8, 'malformed' => null]];
        yield 'campaign cookie ignored without a campaign' => [[], ['tracking202subid_a_0' => '8', 'tracking202subid' => '9'], 0, ['click_id' => 9, 'malformed' => null]];
        yield 'general cookie' => [[], ['tracking202subid' => '9'], 0, ['click_id' => 9, 'malformed' => null]];
        yield 'nothing names a click' => [[], [], 0, $none];
        yield 'an unfilled template value is absent' => [['subid' => ''], ['tracking202subid' => '9'], 0, ['click_id' => 9, 'malformed' => null]];
        yield 'a fractional subid is refused, not cast' => [['subid' => '123.9'], ['tracking202subid' => '9'], 0, ['click_id' => null, 'malformed' => 'subid']];
        yield 'a malformed cookie is refused, not skipped' => [[], ['tracking202subid' => 'abc'], 0, ['click_id' => null, 'malformed' => 'tracking202subid']];
        yield 'zero is not a click' => [['subid' => '0'], [], 0, ['click_id' => null, 'malformed' => 'subid']];
        yield 'an array is not a click' => [['subid' => ['1']], [], 0, ['click_id' => null, 'malformed' => 'subid']];
    }

    // --- reversals ---------------------------------------------------------

    public function testReversalIsMarkedByStatusAndCarriesTheNetworksRef(): void
    {
        self::assertSame(['reversal' => true, 'reversal_ref' => 'R-9'], p202ExtractReversal(['status' => ' Reversed ', 'reversal_id' => 'R-9']));
        self::assertSame(['reversal' => false, 'reversal_ref' => ''], p202ExtractReversal(['status' => 'approved']));
        self::assertSame(['reversal' => false, 'reversal_ref' => ''], p202ExtractReversal([]));
        self::assertSame(['reversal' => false, 'reversal_ref' => ''], p202ExtractReversal(['status' => ['reversed']]));
    }

    // --- the traffic-source sender -----------------------------------------

    public function testEveryPixelOfTheAccountFiresWithTheTransactionId(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels WHERE ppc_account_id = ?', [
            ['pixel_code' => 'https://img.example/p?tx=[[transactionid]]', 'pixel_type_id' => 1],
            ['pixel_code' => 'https://s2s.example/a?tx=[[transactionid]] https://s2s.example/b?id=[[subid]]', 'pixel_type_id' => 4],
            ['pixel_code' => '<script>track("[[subid]]")</script>', 'pixel_type_id' => 5],
        ]);
        $fetched = [];
        $out = p202FireTrafficSourcePixels($db, 3, ['subid' => '77', 'transactionid' => 'A&B'], function (string $url) use (&$fetched): bool {
            $fetched[] = $url;
            return true;
        });

        self::assertSame(['https://s2s.example/a?tx=A%26B', 'https://s2s.example/b?id=77'], $fetched, 'every server-to-server URL, tokens filled');
        self::assertSame(2, $out['server_calls']);
        self::assertSame(0, $out['server_failures']);
        self::assertSame([1, 4, 5], $out['types']);
        self::assertStringContainsString("<img src='https://img.example/p?tx=A%26B'", $out['markup']);
        self::assertStringContainsString('track("77")', $out['markup'], 'raw code gets its tokens replaced');
        $stmt = $db->statementsContaining('202_ppc_account_pixels');
        self::assertSame([3], $stmt[0]->boundValues);
    }

    public function testAFailedServerCallIsCountedNotHidden(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels', [['pixel_code' => 'https://s2s.example/a', 'pixel_type_id' => 4]]);
        $out = p202FireTrafficSourcePixels($db, 3, [], static fn (string $url): bool => false);
        self::assertSame(1, $out['server_failures']);
    }

    public function testMarkupAttributesAreEscaped(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels', [['pixel_code' => "https://x.example/'onerror='alert(1)", 'pixel_type_id' => 1]]);
        $out = p202FireTrafficSourcePixels($db, 3, [], static fn (string $url): bool => true);
        self::assertStringNotContainsString("'onerror='", $out['markup']);
    }

    public function testNoAccountFiresNothing(): void
    {
        $db = new FakeMysqliConnection();
        $out = p202FireTrafficSourcePixels($db, 0, []);
        self::assertSame(['markup' => '', 'types' => [], 'server_calls' => 0, 'server_failures' => 0, 'browser_skipped' => 0], $out);
        self::assertSame([], $db->statements);
    }

    // --- revenue upload amounts ---------------------------------------------

    /** @dataProvider uploadAmounts */
    public function testUploadAmounts(string $cell, ?string $amount): void
    {
        self::assertSame($amount, RevenueUploadImporter::parseAmount($cell));
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function uploadAmounts(): iterable
    {
        yield 'plain' => ['12.5', '12.50000'];
        yield 'dollar sign' => ['$12.50', '12.50000'];
        yield 'thousands separator' => ['$1,234.56', '1234.56000'];
        yield 'spaces' => [' 3 ', '3.00000'];
        yield 'negative (a charge-back line)' => ['-4.00', '-4.00000'];
        yield 'empty' => ['', null];
        yield 'text' => ['pending', null];
        yield 'two points' => ['1.2.3', null];
        yield 'exponent' => ['1e3', null];
    }
}
