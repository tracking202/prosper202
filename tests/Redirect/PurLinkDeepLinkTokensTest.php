<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\PurLinkDestination;

/**
 * A link's own destination (PurLinkDestination) is filled by
 * replaceTrackerPlaceholders() like the campaign's URL, and may hold only
 * the tokens the campaign's URL holds; any other is left empty. Each
 * spelling goes through the function as shipped (the runner lifts it out
 * of connect2.php), so what the check lets through is compared with what
 * the replacer fills, not with a second reading of what a token is.
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
     * @return iterable<string, array{string, string, bool, ?string}> the campaign, the link's query, whether the
     *     replacer fills more in it than the campaign's tokens, and the query the link is kept with (the same, or
     *     with the tokens the campaign's URL lacks left empty), null when the link is refused
     */
    public static function spellings(): iterable
    {
        // Kept as written: nothing but the campaign's tokens is filled.
        yield 'the campaign\'s token' => ['subid', 'sid=[[subid]]&u=deep', false, 'sid=[[subid]]&u=deep'];
        yield 'the campaign\'s token, once in capitals' => [
            'subid', 'sid=[[subid]]&again=[[SUBID]]', false, 'sid=[[subid]]&again=[[SUBID]]',
        ];
        yield 'no token' => ['subid', 'u=deep', false, 'u=deep'];
        yield 'single brackets' => ['subid', 'a[]=1&b[c1]=2', false, 'a[]=1&b[c1]=2'];
        yield 'brackets still encoded' => ['subid', 'x=%5B%5Bc1%5D%5D', false, 'x=%5B%5Bc1%5D%5D'];
        $wide = "x=\u{FF3B}\u{FF3B}c1\u{FF3D}\u{FF3D}";
        yield 'brackets of another script' => ['subid', $wide, false, $wide];
        yield 'both of the campaign\'s tokens' => [
            'subid and c2', 'sid=[[subid]]&b=[[c2]]&u=deep', false, 'sid=[[subid]]&b=[[c2]]&u=deep',
        ];
        // Kept, the token the campaign's URL lacks left empty; filled were it left in.
        $kept = 'sid=[[subid]]&u=deep&x=';
        yield 'a click value' => ['subid', $kept . '[[c1]]', true, $kept];
        yield 'in capitals' => ['subid', $kept . '[[C1]]', true, $kept];
        yield 'in mixed case' => ['subid', $kept . '[[cPc2]]', true, $kept];
        yield 'the cost' => ['subid', $kept . '[[cpc]]', true, $kept];
        yield 'the traffic source account' => ['subid', $kept . '[[sourceid]]', true, $kept];
        yield 'the install token' => ['subid', $kept . '[[p202_install_token]]', true, $kept];
        yield 'a token the request fills' => ['subid', $kept . '[[random]]', true, $kept];
        yield 'the referrer under its second name' => ['subid', $kept . '[[referrer]]', true, $kept];
        yield 'a token no click has' => ['subid', $kept . '[[transactionid]]', true, $kept];
        yield 'two side by side' => ['subid', $kept . '[[c1]][[cpc]]', true, $kept];
        yield 'one beside the campaign\'s' => ['subid and c2', 'b=[[c2]][[c1]]&u=deep', true, 'b=[[c2]]&u=deep'];
        // Refused: brackets that are not one whole token, which filling or emptying a token could make one.
        yield 'a name completed by the campaign\'s token' => ['subid', 'x=[[c[[subid]]]]', true, null];
        yield 'a name around an empty token' => ['subid', 'x=[[cp[[c2]]c]]', true, null];
        yield 'a name around the campaign\'s empty token' => ['subid and c2', 'x=[[cp[[c2]]c]]', true, null];
        yield 'brackets joined by an empty token' => ['subid', 'x=[[[c2]][cpc]]', true, null];
        yield 'brackets joined by the campaign\'s empty token' => ['subid and c2', 'x=[[[c2]][cpc]]', true, null];
        yield 'closing brackets joined by its empty token' => ['subid and c2', 'x=[[cpc][[c2]]]', true, null];
        yield 'a name in a pair around a token emptied after its turn' => ['subid', 'x=[[[[c2]]c1]]', true, null];
        yield 'the campaign\'s token around one it lacks' => ['subid', 'x=[[su[[c2]]bid]]', true, null];
        yield 'a token the campaign lacks around two more' => ['subid', 'x=[[c[[c2]]p[[c3]]c]]', true, null];
        // Refused though the replacer would fill nothing: not shown to stay so.
        yield 'an opening pair' => ['subid', 'x=[[c1', false, null];
        yield 'a closing pair' => ['subid', 'x=c1]]', false, null];
        yield 'a name the replacer does not know' => ['subid', 'x=[[nothing]]', false, null];
    }

    /** @dataProvider spellings */
    public function testOnlyTheCampaignsTokensAreFilledInALinksOwnDestination(
        string $campaign,
        string $query,
        bool $fillsMore,
        ?string $keptAs
    ): void {
        [$url, $own] = self::CAMPAIGNS[$campaign];
        $place = 'https://shop.example/go?';
        $link = $place . $query;
        $onlyOwn = static fn (string $in): string => str_ireplace(array_keys($own), array_values($own), $in);

        self::assertSame(
            $fillsMore,
            self::replaced($link) !== $onlyOwn($link),
            'what the replacer does with this spelling: ' . self::replaced($link)
        );

        $chosen = PurLinkDestination::choose($url, $link, ['aff_campaign_rotate' => 0, 'aff_campaign_url' => $url]);
        self::assertSame($keptAs === null ? $url : $place . $keptAs, $chosen);
        self::assertSame($onlyOwn($chosen), self::replaced($chosen));
    }
}
