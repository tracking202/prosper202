<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\PurLinkDestination;

/**
 * A link's own destination (PurLinkDestination) is filled by
 * replaceTrackerPlaceholders() like the campaign's URL, and may hold only
 * the tokens the campaign's URL holds. Each spelling here goes through the
 * function as shipped (the runner lifts it out of connect2.php), so what
 * the check lets through is compared with what the replacer fills, not with
 * a second reading of what a token is.
 */
final class PurLinkDeepLinkTokensTest extends TestCase
{
    private const CLICK = '1';
    /** The campaign's URL, and what its own tokens become for the runner's click (it has no c2). */
    private const CAMPAIGNS = [
        'subid' => ['https://shop.example/go?sid=[[subid]]', ['[[subid]]' => self::CLICK]],
        'subid and c2' => [
            'https://shop.example/go?sid=[[subid]]&b=[[c2]]',
            ['[[subid]]' => self::CLICK, '[[c2]]' => ''],
        ],
    ];

    private static function replaced(string $url): string
    {
        $runner = __DIR__ . '/fixtures/tracker-placeholders-runner.php';
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
            . escapeshellarg($url) . ' ' . escapeshellarg(self::CLICK) . ' row 6 2>&1');
        $answer = json_decode((string) $output, true);
        self::assertIsArray($answer, (string) $output);
        self::assertArrayHasKey('url', $answer, (string) $output);

        return (string) $answer['url'];
    }

    /**
     * @return iterable<string, array{string, string, bool, bool}> the campaign, the link's query, whether the
     *     replacer fills more in it than the campaign's tokens, whether the link is kept
     */
    public static function spellings(): iterable
    {
        // Kept: nothing but the campaign's tokens is filled.
        yield 'the campaign\'s token' => ['subid', 'sid=[[subid]]&u=deep', false, true];
        yield 'the campaign\'s token, once in capitals' => ['subid', 'sid=[[subid]]&again=[[SUBID]]', false, true];
        yield 'no token' => ['subid', 'u=deep', false, true];
        yield 'single brackets' => ['subid', 'a[]=1&b[c1]=2', false, true];
        yield 'brackets still encoded' => ['subid', 'x=%5B%5Bc1%5D%5D', false, true];
        yield 'brackets of another script' => ['subid', "x=\u{FF3B}\u{FF3B}c1\u{FF3D}\u{FF3D}", false, true];
        yield 'both of the campaign\'s tokens' => ['subid and c2', 'sid=[[subid]]&b=[[c2]]&u=deep', false, true];
        // Refused, and filled were they not.
        yield 'a click value' => ['subid', 'x=[[c1]]', true, false];
        yield 'in capitals' => ['subid', 'x=[[C1]]', true, false];
        yield 'in mixed case' => ['subid', 'x=[[cPc2]]', true, false];
        yield 'the cost' => ['subid', 'x=[[cpc]]', true, false];
        yield 'the traffic source account' => ['subid', 'x=[[sourceid]]', true, false];
        yield 'the install token' => ['subid', 'x=[[p202_install_token]]', true, false];
        yield 'a token the request fills' => ['subid', 'x=[[random]]', true, false];
        yield 'the referrer under its second name' => ['subid', 'x=[[referrer]]', true, false];
        yield 'a token no click has' => ['subid', 'x=[[transactionid]]', true, false];
        yield 'a name completed by the campaign\'s token' => ['subid', 'x=[[c[[subid]]]]', true, false];
        yield 'a name around an empty token' => ['subid', 'x=[[cp[[c2]]c]]', true, false];
        yield 'a name around the campaign\'s empty token' => ['subid and c2', 'x=[[cp[[c2]]c]]', true, false];
        yield 'brackets joined by an empty token' => ['subid', 'x=[[[c2]][cpc]]', true, false];
        yield 'brackets joined by the campaign\'s empty token' => ['subid and c2', 'x=[[[c2]][cpc]]', true, false];
        yield 'closing brackets joined by its empty token' => ['subid and c2', 'x=[[cpc][[c2]]]', true, false];
        yield 'a name in a pair around a token emptied after its turn' => ['subid', 'x=[[[[c2]]c1]]', true, false];
        // Refused though the replacer would fill nothing: not shown to stay so.
        yield 'an opening pair' => ['subid', 'x=[[c1', false, false];
        yield 'a closing pair' => ['subid', 'x=c1]]', false, false];
        yield 'a name the replacer does not know' => ['subid', 'x=[[nothing]]', false, false];
    }

    /** @dataProvider spellings */
    public function testOnlyTheCampaignsTokensAreFilledInALinksOwnDestination(
        string $campaign,
        string $query,
        bool $fillsMore,
        bool $kept
    ): void {
        [$url, $own] = self::CAMPAIGNS[$campaign];
        $link = 'https://shop.example/go?' . $query;
        $onlyOwn = static fn (string $in): string => str_ireplace(array_keys($own), array_values($own), $in);

        self::assertSame(
            $fillsMore,
            self::replaced($link) !== $onlyOwn($link),
            'what the replacer does with this spelling: ' . self::replaced($link)
        );

        $chosen = PurLinkDestination::choose($url, $link, ['aff_campaign_rotate' => 0, 'aff_campaign_url' => $url]);
        self::assertSame($kept ? $link : $url, $chosen);
        self::assertSame($onlyOwn($chosen), self::replaced($chosen));
    }
}
