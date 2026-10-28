<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;
use Tests\Support\FakeMysqliConnection;

/**
 * The traffic-source sender and its token replacement, as a class every path
 * can reach (connect2.php's replaceTokens() and the static endpoints'
 * p202FireTrafficSourcePixels() delegate to it).
 */
final class TrafficSourcePixelsTest extends TestCase
{
    public function testTokensAreEncodedAndUnsetOnesLeftInPlace(): void
    {
        $url = 'https://n.test/?s=[[subid]]&P=[[PAYOUT]]&t=[[t202txid]]&x=[[transactionid]]&r=[[referrer]]&c=[[c1]]&u=[[unknown]]';
        self::assertSame(
            'https://n.test/?s=12&P=1.5&t=a%20b%241&x=a%20b%241&r=[[referrer]]&c=x@y&u=[[unknown]]',
            TrafficSourcePixels::replaceTokens($url, ['subid' => '12', 'payout' => '1.5', 'transactionid' => 'a b$1', 'c1' => 'x@y'])
        );
    }

    public function testFillBlanksEmptiesKnownTokensOnly(): void
    {
        self::assertSame(
            '?s=&g=&u=[[unknown]]',
            TrafficSourcePixels::replaceTokens('?s=[[subid]]&g=[[p202_goal]]&u=[[unknown]]', [], 1)
        );
    }

    public function testTheGoalTokens(): void
    {
        self::assertSame(
            '?g=Reached%20level%203&id=12&v=4.00',
            TrafficSourcePixels::replaceTokens('?g=[[p202_goal]]&id=[[P202_GOAL_ID]]&v=[[p202_goal_value]]', [
                'p202_goal' => 'Reached level 3', 'p202_goal_id' => 12, 'p202_goal_value' => '4.00',
            ])
        );
    }

    public function testAValueIsNeverReadAsABackReference(): void
    {
        // Encoding already turns $ and \ into %24 and %5C; this pins that no
        // replacement path can see a raw "$1" as a back-reference.
        self::assertSame('?s=%241%5C1', TrafficSourcePixels::replaceTokens('?s=[[subid]]', ['subid' => '$1\\1']));
    }

    public function testWithoutABrowserOnlyServerPostbacksAreSent(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels WHERE ppc_account_id = ?', [
            ['pixel_code' => 'https://img.test/p?g=[[p202_goal]]', 'pixel_type_id' => 1],
            ['pixel_code' => 'https://s2s.test/a?g=[[p202_goal]] https://s2s.test/b', 'pixel_type_id' => 4],
            ['pixel_code' => '<script>x("[[p202_goal]]")</script>', 'pixel_type_id' => 5],
        ]);
        $fetched = [];
        $out = TrafficSourcePixels::fire(new Connection($db), 3, ['p202_goal' => 'Sale'], function (string $url) use (&$fetched): bool {
            $fetched[] = $url;
            return true;
        }, false);

        self::assertSame(['https://s2s.test/a?g=Sale', 'https://s2s.test/b'], $fetched);
        self::assertSame('', $out['markup'], 'nothing is rendered where no browser will load it');
        self::assertSame(2, $out['browser_skipped']);
        self::assertSame([1, 4, 5], $out['types']);
    }
}
