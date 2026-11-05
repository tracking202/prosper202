<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;
use Prosper202\Notifications\PostbackSender;
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

    /**
     * Sent straight from here (no outbox), a postback the sender refuses is
     * that postback's failure: the next one is still sent, and the log line
     * names the pixel and the host, never the path or query.
     */
    public function testARefusedPostbackFailsAloneAndIsLoggedWithoutItsQuery(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels WHERE ppc_account_id = ?', [[
            'pixel_id' => 41,
            'pixel_code' => 'http://169.254.169.254/latest/meta-data/?key=SECRET&s=[[subid]]'
                . ' https://s2s.test/b?s=[[subid]]',
            'pixel_type_id' => 4,
        ]]);
        $fetched = [];
        $guard = new WebhookGuard(static fn (string $host): array => ['93.184.216.34'], '');
        $sender = new PostbackSender($guard, static function (array $options) use (&$fetched): array {
            $fetched[] = $options[CURLOPT_URL];

            return ['status' => 200, 'error' => null, 'primary_ip' => '93.184.216.34', 'redirect_url' => null];
        });
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-pixels-log');
        $previous = ini_set('error_log', $log);
        try {
            $out = TrafficSourcePixels::fire(new Connection($db), 3, ['subid' => '12'], $sender->send(...), false);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertSame(['https://s2s.test/b?s=12'], $fetched);
        self::assertSame([2, 1], [$out['server_calls'], $out['server_failures']]);
        $line = 'traffic source postback refused for pixel 41 of ppc account 3 (http://169.254.169.254): '
            . 'not sent: 169.254.169.254, which is a link-local address';
        self::assertStringContainsString($line, $logged);
        self::assertStringNotContainsString('SECRET', $logged);
        self::assertStringNotContainsString('meta-data', $logged);
    }

    /**
     * A Bot202 Facebook Pixel Assistant pixel (type 6) holds a Meta Pixel
     * ID; it was read by 1.9.80's renderer as no type at all, so it fired
     * nothing and said nothing. It fires a Purchase with the conversion's
     * payout, in the account's currency, with the transaction as event id.
     */
    public function testAFacebookPixelAssistantPixelFiresAPurchase(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels WHERE ppc_account_id = ?', [
            ['pixel_id' => 7, 'pixel_code' => '123456789012345', 'pixel_type_id' => 6],
            ['pixel_id' => 8, 'pixel_code' => 'https://img.test/p?s=[[subid]]', 'pixel_type_id' => 1],
        ]);
        $db->whenQueryContainsReturnRows('user_account_currency', [['user_account_currency' => 'EUR']]);

        $out = TrafficSourcePixels::fire(new Connection($db), 3, ['subid' => '12', 'payout' => '24.5', 'transactionid' => 'ORDER-9'], static fn (): bool => true, true, true, static fn (): bool => true);

        self::assertSame(
            "<img src='https://www.facebook.com/tr?id=123456789012345&amp;ev=Purchase&amp;noscript=1&amp;cd%5Bvalue%5D=24.50&amp;cd%5Bcurrency%5D=EUR&amp;eid=ORDER-9' height='1' width='1' style='display:none' alt='' />\n"
            . "<img src='https://img.test/p?s=12' height='0' width='0' style='display:none' />\n",
            $out['markup']
        );
        self::assertSame([6, 1], $out['types']);
        // The account's currency is read once, by the account: the owner's.
        $currency = $db->statementsContaining('user_account_currency');
        self::assertCount(1, $currency);
        self::assertSame([3], $currency[0]->boundValues);
    }

    /**
     * The Purchase is held back as the landing page's events are: for a
     * visitor the privacy setting holds back (an EU-covered one under 'eu'),
     * who refused consent, or when nothing answered. Types 1-3 still fire.
     */
    public function testAPurchaseIsHeldBackForAVisitorWhoMayNotBeTracked(): void
    {
        $fire = static function (?\Closure $mayTrack): array {
            $db = new FakeMysqliConnection();
            $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels WHERE ppc_account_id = ?', [
                ['pixel_id' => 7, 'pixel_code' => '123456789012345', 'pixel_type_id' => 6],
                ['pixel_id' => 8, 'pixel_code' => 'https://img.test/p?s=[[subid]]', 'pixel_type_id' => 1],
            ]);

            return TrafficSourcePixels::fire(new Connection($db), 3, ['subid' => '12', 'payout' => '5'], null, true, false, $mayTrack);
        };
        $image = "<img src='https://img.test/p?s=12' height='0' width='0' style='display:none' />\n";

        $asked = 0;
        $eu = $fire(static function () use (&$asked): bool {
            $asked++;

            return \Prosper202\Http\PrivacyMode::tracksInFull('eu', static fn (): bool => true);
        });
        self::assertSame($image, $eu['markup'], 'an EU-covered visitor under eu');
        self::assertSame(1, $asked);
        self::assertSame($image, $fire(static fn (): bool => false)['markup'], 'refused consent');
        self::assertSame($image, $fire(null)['markup'], 'nothing answered');
        self::assertStringContainsString('ev=Purchase', $fire(static fn (): bool => true)['markup'], 'otherwise it fires');
    }

    /** The answer for a conversion: the landing page's check, on the click's own campaign. */
    public function testWhetherTheVisitorMayBeTrackedIsTheLandingPagesCheck(): void
    {
        $ask = static function (bool $trackingEnabled, array $get, ?array $row, bool $fails = false): bool {
            $db = new FakeMysqliConnection();
            if ($fails) {
                $db->whenQueryContainsResultFails('202_aff_campaigns');
            } elseif ($row !== null) {
                $db->whenQueryContainsReturnRows('202_aff_campaigns', [$row]);
            }

            return TrafficSourcePixels::visitorMayBeTracked(new Connection($db), 42, $trackingEnabled, $get);
        };
        $eu = \Prosper202\Http\PrivacyMode::tracksInFull('eu', static fn (): bool => true);

        self::assertTrue($ask(true, [], ['identity_signals' => '1']));
        self::assertTrue($ask(true, ['p202_consent' => '1'], ['identity_signals' => null]), 'no campaign: on');
        self::assertFalse($ask($eu, [], ['identity_signals' => '1']), 'an EU-covered visitor under eu');
        self::assertFalse($ask(true, ['p202_consent' => '0'], ['identity_signals' => '1']), 'refused consent');
        self::assertFalse($ask(true, [], ['identity_signals' => '0']), 'identity capture off');
        self::assertFalse($ask(true, [], null), 'no such click');

        $log = (string) tempnam(sys_get_temp_dir(), 'p202-fb-log');
        $previous = ini_set('error_log', $log);
        try {
            self::assertFalse($ask(true, [], null, true), 'an unreadable click');
        } finally {
            ini_set('error_log', (string) $previous);
        }
        self::assertStringContainsString('Meta Purchase held back', (string) file_get_contents($log));
        unlink($log);
    }

    public function testAFacebookPixelAssistantPixelWithoutABrowserIsSkipped(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels WHERE ppc_account_id = ?', [
            ['pixel_id' => 7, 'pixel_code' => '123456789012345', 'pixel_type_id' => 6],
        ]);

        $out = TrafficSourcePixels::fire(new Connection($db), 3, ['payout' => '5'], static fn (): bool => true, false);

        self::assertSame('', $out['markup']);
        self::assertSame(1, $out['browser_skipped']);
        self::assertSame([], $db->statementsContaining('user_account_currency'));
    }

    public function testAFacebookPixelAssistantPixelWithNoIdFiresNothingAndIsLogged(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels WHERE ppc_account_id = ?', [
            ['pixel_id' => 7, 'pixel_code' => 'not a pixel id', 'pixel_type_id' => 6],
        ]);
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-fb-log');
        $previous = ini_set('error_log', $log);
        try {
            $out = TrafficSourcePixels::fire(new Connection($db), 3, ['payout' => '5'], static fn (): bool => true, true, true, static fn (): bool => true);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertSame('', $out['markup']);
        self::assertStringContainsString('traffic source pixel 7 of ppc account 3 is a Bot202 Facebook Pixel Assistant pixel without a Meta Pixel ID', $logged);
    }

    public function testAPurchaseWithoutAPayoutOrCurrencyCarriesNoValue(): void
    {
        self::assertSame(
            "<img src='https://www.facebook.com/tr?id=123456789012345&amp;ev=Purchase&amp;noscript=1' height='1' width='1' style='display:none' alt='' />\n",
            TrafficSourcePixels::facebookPurchase("fbq('init', '123456789012345');", ['payout' => ''], 'USD')
        );
        self::assertStringNotContainsString(
            'cd%5Bvalue',
            (string) TrafficSourcePixels::facebookPurchase('123456789012345', ['payout' => '5'], '')
        );
        self::assertNull(TrafficSourcePixels::facebookPurchase('x', ['payout' => '5'], 'USD'));
    }

    /** An account whose currency is unknown (no preferences row) sends no value rather than a guessed USD. */
    public function testAnUnknownCurrencySendsNoValue(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels WHERE ppc_account_id = ?', [
            ['pixel_id' => 7, 'pixel_code' => '123456789012345', 'pixel_type_id' => 6],
        ]);

        $out = TrafficSourcePixels::fire(new Connection($db), 3, ['payout' => '24.5', 'transactionid' => 'T'], static fn (): bool => true, true, true, static fn (): bool => true);

        self::assertStringContainsString('ev=Purchase', $out['markup']);
        self::assertStringNotContainsString('cd%5B', $out['markup']);
    }
}
