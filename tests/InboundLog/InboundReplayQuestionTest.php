<?php

declare(strict_types=1);

namespace Tests\InboundLog;

use PHPUnit\Framework\TestCase;
use Prosper202\InboundLog\InboundReplay;

/**
 * What the owner is asked before a refused request is recorded from the
 * Inbound Log: what it says, a reversal as a reversal, when it arrived, and
 * which switched-on destination will not take it for its age.
 */
final class InboundReplayQuestionTest extends TestCase
{
    private const ARRIVED = 1_790_000_000;

    /** @param array<string, string> $fields */
    private static function row(array $fields, string $auth = 'none', ?int $click = null, string $endpoint = 'gpb'): array
    {
        return ['endpoint' => $endpoint, 'click_id' => $click, 'fields' => $fields, 'auth' => $auth, 'received_at' => self::ARRIVED];
    }

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
    }

    public function testASaleSaysItsClickAmountAndTransactionAndWhenItArrived(): void
    {
        $asked = InboundReplay::question(self::row(['subid' => '5002', 'amount' => '12.50', 'order_id' => 'ORD-7']), [], self::ARRIVED + 60);

        self::assertStringStartsWith('Record this request now? It says: click 5002, amount 12.50, transaction id ORD-7.', $asked);
        self::assertStringContainsString('when it arrived, ' . date('M j, Y g:i a', self::ARRIVED) . '.', $asked);
        self::assertStringContainsString('did not carry your postback token', $asked);
        self::assertStringNotContainsString('did not carry', InboundReplay::question(self::row(['subid' => '5'], 'token'), [], self::ARRIVED));
    }

    public function testAReversalIsAskedAsAReversalAndSaysWhatBecomesOfIt(): void
    {
        $reversal = self::row(['subid' => '5002', 'status' => 'reversed', 'txid' => 'ORD-7', 'reversal_id' => 'RF-1']);
        $asked = InboundReplay::question($reversal, [], self::ARRIVED, null, true);

        self::assertStringStartsWith('Record this reversal now? It takes money off a sale already recorded.', $asked);
        self::assertStringContainsString('a reversal of transaction ORD-7 (reversal id RF-1)', $asked);
        self::assertStringNotContainsString('transaction id ORD-7', $asked, 'never read as a sale of that transaction');

        $nothing = InboundReplay::question($reversal, [], self::ARRIVED, null, false);
        self::assertStringStartsWith('Record this request now?', $nothing, 'no sale on file: nothing is taken off');
        self::assertStringContainsString('No sale with transaction id ORD-7 is on file on this click, so it will be refused', $nothing);

        // A negative amount reverses its transaction where the endpoint reads the amount (gpb.php).
        $negative = self::row(['subid' => '5002', 'amount' => '-12.50', 'txid' => 'ORD-7']);
        self::assertStringStartsWith('Record this reversal now?', InboundReplay::question($negative, [], self::ARRIVED, null, true));
        self::assertStringContainsString('amount -12.50, a reversal of transaction ORD-7', InboundReplay::question($negative, [], self::ARRIVED, null, true));
        $correction = InboundReplay::question($negative, [], self::ARRIVED, null, false);
        self::assertStringStartsWith('Record this request now?', $correction);
        self::assertStringContainsString('so it is recorded as a negative correction of -12.50', $correction);
    }

    public function testTheQuestionSaysOnlyWhatTheEndpointReads(): void
    {
        // pb.php reads no amount: it records the campaign's payout, whatever amount the request sent.
        $pb = InboundReplay::question(self::row(['acip' => '11', 'subid' => '5', 'amount' => '-12.50', 'txid' => 'PB-T1'], 'none', null, 'pb'),
            [], self::ARRIVED, null, true);
        self::assertStringStartsWith('Record this request now?', $pb);
        self::assertStringContainsString("the campaign's payout, transaction id PB-T1", $pb);
        self::assertStringContainsString('pb.php does not read its amount, so it is not used.', $pb);
        self::assertStringNotContainsString('reversal', $pb);

        // px.php reads neither a transaction id nor status=reversed.
        $px = InboundReplay::question(self::row(['acip' => '11', 'txid' => 'PX-T1', 'status' => 'reversed'], 'none', 5, 'px'),
            [], self::ARRIVED, null, true);
        self::assertStringStartsWith('Record this request now?', $px);
        self::assertStringNotContainsString('PX-T1,', $px);
        self::assertStringNotContainsString('a reversal of', $px);
        self::assertStringContainsString('px.php does not read its transaction id or status=reversed, so it is not used.', $px);
    }

    public function testThePlanReadsTheRequestAsTheEndpointsOwnReadersDo(): void
    {
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
        $requests = [
            ['txid' => '  ORD-1 ', 'status' => ' Reversed ', 'reversal_id' => 'R1', 'amount' => '-3'],
            ['order_id' => 'ORD-2', 'transaction_id' => '', 'oid' => 'ORD-3', 'status' => 'reversal'],
            ['t202txid' => 'ORD-4', 'amount' => 'abc'],
            [],
        ];
        foreach (['pb', 'gpb', 'px', 'gpx', 'upx'] as $endpoint) {
            foreach ($requests as $request) {
                $plan = InboundReplay::plan(['endpoint' => $endpoint, 'fields' => $request]);
                $reads = ['pb' => [false, true, true], 'gpb' => [true, true, true], 'px' => [false, false, false],
                    'gpx' => [true, true, false], 'upx' => [true, true, false]][$endpoint];
                $reversal = p202ExtractReversal($request);
                self::assertSame($reads[1] ? p202ExtractTransactionId($request) : '', $plan['transaction'], $endpoint);
                self::assertSame($reads[2] && $reversal['reversal'], $plan['reversal'], $endpoint);
                self::assertSame($reads[0] && is_numeric($request['amount'] ?? null) ? trim($request['amount']) : null, $plan['amount'], $endpoint);
            }
        }
    }

    public function testAnAnalyticsDestinationIsNamedWhenTheRequestIsTooOldForIt(): void
    {
        $destinations = [
            ['type' => 'ga4', 'name' => 'Store analytics', 'enabled' => true],
            ['type' => 'ga4', 'name' => 'Switched off', 'enabled' => false],
            ['type' => 'google_ads', 'name' => 'Ads', 'enabled' => true],
        ];
        $old = InboundReplay::question(self::row(['subid' => '5']), $destinations, self::ARRIVED + 73 * 3600);
        $recent = InboundReplay::question(self::row(['subid' => '5']), $destinations, self::ARRIVED + 71 * 3600);

        self::assertStringContainsString('Google Analytics takes conversions up to 72 hours old, so Store analytics does not receive this one', $old);
        self::assertStringNotContainsString('Switched off', $old);
        self::assertStringNotContainsString('Google Analytics', $recent);
        self::assertSame('', InboundReplay::tooOldFor([['type' => 'ga4', 'name' => 'x', 'enabled' => false]], self::ARRIVED, self::ARRIVED + 99 * 3600));
    }

    public function testAChatGptAdsDestinationIsNamedPastItsSevenDays(): void
    {
        $chat = [['type' => 'openai_ads', 'name' => 'ChatGPT', 'enabled' => true, 'settings' => []]];
        $limit = \Prosper202\Destinations\OpenAiAdsDestination::MAX_AGE_SECONDS;
        self::assertSame(
            ' ChatGPT Ads takes conversions up to 7 days old, so ChatGPT does not receive this one.'
                . ' Your reports still count it.',
            InboundReplay::tooOldFor($chat, self::ARRIVED, self::ARRIVED + $limit + 1)
        );
        $atTheLimit = InboundReplay::tooOldFor($chat, self::ARRIVED, self::ARRIVED + $limit);
        self::assertSame('', $atTheLimit, 'at the limit it goes');
    }

    public function testAnAdsDestinationIsNamedWhenTheClickIsTooOldForIt(): void
    {
        $ads = [['type' => 'google_ads', 'name' => 'Search ads', 'enabled' => true, 'settings' => []]];
        $limit = \Prosper202\Destinations\GoogleAdsDestination::CLICK_MAX_AGE;
        $now = self::ARRIVED + 3600;
        $click = static fn (int $age, bool $google = true, int $campaign = 11): array
            => ['first_visit' => $now - $age, 'campaign_id' => $campaign, 'google_click' => $google];

        $old = InboundReplay::question(self::row(['subid' => '5']), $ads, $now, $click($limit + 86400));
        self::assertStringContainsString('Google Ads takes a conversion up to 90 days after its click, and this click is older,'
            . ' so Search ads does not receive it.', $old);
        self::assertStringEndsWith('does not receive it. Your reports still count it.', $old, 'no claim about destinations it does not judge');
        self::assertSame('', InboundReplay::tooOldFor($ads, self::ARRIVED, $now, $click($limit - 86400)), 'a click inside the limit');
        self::assertSame('', InboundReplay::tooOldFor($ads, self::ARRIVED, $now, $click($limit + 86400, false)),
            'no Google click id: the destination does not judge its age');
        self::assertSame('', InboundReplay::tooOldFor($ads, self::ARRIVED, $now, null), 'no click read');

        $filtered = [['type' => 'google_ads', 'name' => 'Tea ads', 'enabled' => true, 'settings' => ['campaign_ids' => [12]]]];
        self::assertSame('', InboundReplay::tooOldFor($filtered, self::ARRIVED, $now, $click($limit + 86400)),
            'a destination for other campaigns is not this conversion\'s');
        self::assertStringContainsString('Tea ads', InboundReplay::tooOldFor($filtered, self::ARRIVED, $now, $click($limit + 86400, true, 12)));
        $analytics = [['type' => 'ga4', 'name' => 'Tea analytics', 'enabled' => true, 'settings' => ['campaign_ids' => [12]]]];
        self::assertSame('', InboundReplay::tooOldFor($analytics, self::ARRIVED, self::ARRIVED + 99 * 3600, $click(0)));
    }

    public function testTheOrderNumberNamesAreTheEndpointsOwn(): void
    {
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';

        self::assertSame(P202_ORDER_NUMBER_NAMES, InboundReplay::ORDER_NUMBER_NAMES);
    }
}
