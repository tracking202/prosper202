<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Tests\Support\CallArgs;
use Tests\Support\SourceScan;

/**
 * Every pixel that guesses a conversion's click from the visitor's address
 * (MatchMethod::SAME_IP) gets the guess from p202SameIpFallbackClick(), and
 * that function asks, before it looks: whose pixel this is, whether that
 * account has the fallback on (SameIpFallback::enabledFor()), and whether
 * the visitor may be recognised at all — p202MayRecogniseVisitor(), the
 * decision p202ClickIdentity() gives or withholds the p202vid cookie by.
 *
 * gpx.php, upx.php and px.php each made their own LastClickFromAddress
 * lookup, on by default for every account, under privacy too, and gpx.php
 * and upx.php among the install's first account's clicks for every
 * account's pixel. A pixel that looks a click up by address any other way
 * would make the guess the setting, the consent and the account were meant
 * to decide.
 *
 * What this reads is the source: the function's statements as written, and
 * each call's arguments. SameIpFallbackIntegrationTest runs the function
 * against a database; MatchMethodEndpointsInstanceTest and
 * SameIpFallbackInstanceTest run the pixels.
 */
final class SameIpFallbackReadsTheSettingTest extends TestCase
{
    private const HELPERS = '202-config/static-endpoint-helpers.php';
    private const FALLBACK = 'p202SameIpFallbackClick';

    /**
     * Address lookups that are not a conversion's guess, by file. Each is
     * a click-path redirect choosing which click a visit continues, not a
     * pixel crediting one; the setting is a conversion's.
     *
     * @var array<string, string>
     */
    private const ELSEWHERE = [
        'tracking202/redirect/off.php' => 'an offer reached with no click: which click the exit is recorded on',
        'tracking202/redirect/rtr.php' => 'a rotator\'s ?lpr= with no click: which click the rotation continues',
    ];

    /** @return array<string, string> repo path => source */
    private static function served(): array
    {
        return SourceScan::phpFiles();
    }

    /**
     * A function's statements, whitespace and comments dropped, and the
     * line its body opens on and closes on.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function body(string $source, string $function): array
    {
        $tokens = token_get_all($source);
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (!is_array($tokens[$j] ?? null) || strcasecmp($tokens[$j][1], $function) !== 0) {
                continue;
            }
            while ($j < $n && $tokens[$j] !== '{') {
                $j++;
            }
            $depth = 0;
            $text = '';
            $line = $tokens[$i][2];
            $first = $line;
            for ($k = $j; $k < $n; $k++) {
                $t = $tokens[$k];
                if (is_array($t)) {
                    $line = $t[2];
                }
                if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return [substr($text, 1), $first, $line];
                    }
                }
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $text .= is_array($t) ? $t[1] : $t;
            }
        }
        self::fail("$function() is not defined where this test reads it");
    }

    public function testEveryAddressLookupIsTheFallbackThatReadsTheSetting(): void
    {
        [, $from, $to] = self::body((string) file_get_contents(SourceScan::repoRoot() . '/' . self::HELPERS), self::FALLBACK);
        $problems = [];
        $seen = [];
        $fallbacks = 0;
        foreach (self::served() as $path => $source) {
            foreach (CallArgs::calls($source, ['find']) as $call) {
                if ($call['operator'] !== '::' || !str_ends_with($call['receiver'], 'LastClickFromAddress')) {
                    continue;
                }
                if ($path === self::HELPERS && $call['line'] >= $from && $call['line'] <= $to) {
                    $fallbacks++;
                    continue;
                }
                if (isset(self::ELSEWHERE[$path])) {
                    $seen[$path] = true;
                    continue;
                }
                $problems[] = $path . ':' . $call['line'];
            }
        }

        self::assertSame(1, $fallbacks, self::FALLBACK . '() makes the one lookup');
        self::assertSame([], $problems, "A click is looked up by address outside the same-IP fallback:\n  "
            . implode("\n  ", $problems)
            . "\nA pixel takes its guess from " . self::FALLBACK . '(), which asks the account\'s setting and'
            . ' the visitor\'s consent first; a lookup that is not a conversion\'s guess is listed in ELSEWHERE'
            . ' with why.');
        self::assertSame(array_keys(self::ELSEWHERE), array_keys($seen), 'a listed lookup that is gone is dropped from ELSEWHERE');
    }

    public function testEveryPixelThatRecordsAGuessGetsItFromTheFallback(): void
    {
        $recording = [];
        $problems = [];
        foreach (self::served() as $path => $source) {
            if (!str_starts_with($path, 'tracking202/') || !str_contains($source, 'MatchMethod::SAME_IP')) {
                continue;
            }
            $recording[] = $path;
            if (CallArgs::calls($source, [self::FALLBACK]) === []) {
                $problems[] = $path;
            }
        }

        self::assertSame(
            ['tracking202/static/gpx.php', 'tracking202/static/px.php', 'tracking202/static/upx.php'],
            $recording,
            'the pixels that record a guess'
        );
        self::assertSame([], $problems, 'These record MatchMethod::SAME_IP without ' . self::FALLBACK . "():\n  "
            . implode("\n  ", $problems));
    }

    /**
     * The account is the pixel's own: the campaign its cid names (gpx.php,
     * upx.php, through pixelAccount()) or its acip's (px.php, ofCampaign()),
     * never a number written into the endpoint.
     */
    public function testEveryFallbackIsHandedThePixelsOwnAccount(): void
    {
        $calls = [];
        foreach (self::served() as $path => $source) {
            foreach (CallArgs::calls($source, [self::FALLBACK]) as $call) {
                if ($call['operator'] !== '') {
                    continue;
                }
                $calls[$path . ':' . $call['line']] = array_map([CallArgs::class, 'text'], $call['args']);
            }
        }

        self::assertCount(3, $calls, 'gpx.php, upx.php and px.php');
        $expected = [
            'tracking202/static/gpx.php' => "\\Prosper202\\Conversion\\SameIpFallback::pixelAccount(new\\Prosper202\\Database\\Connection(\$db),\$_GET['cid']??null,'gpx')",
            'tracking202/static/upx.php' => "\\Prosper202\\Conversion\\SameIpFallback::pixelAccount(new\\Prosper202\\Database\\Connection(\$db),\$_GET['cid']??null,'upx')",
            'tracking202/static/px.php' => "\\Prosper202\\Conversion\\SameIpFallback::ofCampaign(\$aff_campaign_row,'px')",
        ];
        foreach ($calls as $site => $args) {
            $path = substr($site, 0, (int) strrpos($site, ':'));
            self::assertArrayHasKey($path, $expected, "$site calls " . self::FALLBACK . '() from a file this test does not know');
            self::assertSame(['$db', $expected[$path], '$_GET'], $args, "$site: the account is the pixel's campaign's, and the request is this one");
        }
    }

    /**
     * In this order, each refusal returning before the lookup: whose pixel,
     * the account's privacy setting in force, the visitor's consent, the
     * account's setting. Spelled exactly: a rewrite fails here and is read
     * again, rather than being reasoned about.
     */
    public function testTheFallbackAsksBeforeItLooks(): void
    {
        [$body] = self::body((string) file_get_contents(SourceScan::repoRoot() . '/' . self::HELPERS), self::FALLBACK);
        self::assertSame(
            "if(\$account===null){returnnull;}"
            . "p202ApplyOwnerPrivacy(\$account['user_id']);"
            . "if(!p202MayRecogniseVisitor(\$get,\$account['campaign_allows'])){returnnull;}"
            . "\$conn=new\\Prosper202\\Database\\Connection(\$db);"
            . "if(!\\Prosper202\\Conversion\\SameIpFallback::enabledFor(\$conn,\$account['user_id'])){returnnull;}"
            . "return\\Prosper202\\Click\\LastClickFromAddress::find(\$conn,p202StoredVisitorIp(),\$account['user_id'],"
            . "time()-\\Prosper202\\Conversion\\SameIpFallback::LOOKBACK,!trackingEnabled());",
            $body
        );
    }

    /** The p202vid cookie and the guess are given or withheld by one decision. */
    public function testTheVisitorCookieAsksTheSameDecision(): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-config/connect2.php');
        [$decision] = self::body($source, 'p202MayRecogniseVisitor');
        self::assertSame(
            'returntrackingEnabled()&&\\Prosper202\\Identity\\RequestSignals::consentGiven($get,$campaignAllows);',
            $decision
        );
        [$identity] = self::body($source, 'p202ClickIdentity');
        self::assertStringStartsWith('$recognised=p202MayRecogniseVisitor($get,$campaignAllows);', $identity);
        self::assertStringNotContainsString('trackingEnabled(', $identity, 'the cookie asks the shared decision, not its own');
    }
}
