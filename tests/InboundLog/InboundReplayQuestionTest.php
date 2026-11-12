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
    private static function row(array $fields, string $auth = 'none', ?int $click = null): array
    {
        return ['click_id' => $click, 'fields' => $fields, 'auth' => $auth, 'received_at' => self::ARRIVED];
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

    public function testAReversalIsAskedAsAReversal(): void
    {
        $asked = InboundReplay::question(self::row(['subid' => '5002', 'status' => 'reversed', 'txid' => 'ORD-7', 'reversal_id' => 'RF-1']), [], self::ARRIVED);

        self::assertStringStartsWith('Record this reversal now? It takes money off a sale already recorded.', $asked);
        self::assertStringContainsString('a reversal of transaction ORD-7 (reversal id RF-1)', $asked);
        self::assertStringNotContainsString('transaction id ORD-7', $asked, 'never read as a sale of that transaction');

        $negative = InboundReplay::question(self::row(['subid' => '5002', 'amount' => '-12.50', 'txid' => 'ORD-7']), [], self::ARRIVED);
        self::assertStringStartsWith('Record this reversal now?', $negative);
        self::assertStringContainsString('amount -12.50, which reverses transaction ORD-7 if it is on file', $negative);
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

    public function testTheOrderNumberNamesAreTheEndpointsOwn(): void
    {
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';

        self::assertSame(P202_ORDER_NUMBER_NAMES, InboundReplay::ORDER_NUMBER_NAMES);
    }
}
