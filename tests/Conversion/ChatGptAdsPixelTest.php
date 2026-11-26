<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\AdPlatformConsent;
use Prosper202\Conversion\OpenAiPixelId;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;
use Tests\Support\FakeMysqliConnection;

/**
 * A "ChatGPT Ads Pixel" traffic source pixel (type 7): the id it holds, and
 * the conversion image it fires under the event its ChatGPT Ads destination
 * sends the conversion as, so OpenAI counts the browser's event and the
 * server's once.
 */
final class ChatGptAdsPixelTest extends TestCase
{
    public function testThePixelIdIsReadFromAnIdOrOpenAisCode(): void
    {
        self::assertSame('px_A-1', OpenAiPixelId::fromCode('  px_A-1 '));
        self::assertSame('px_9', OpenAiPixelId::fromCode("<script>oaiq(\"init\", {pixelId: \"px_9\"});</script>"));
        self::assertSame('px_9', OpenAiPixelId::fromCode("oaiq('init',{'pixelId':'px_9'})"));
        self::assertSame('abc', OpenAiPixelId::fromCode('<img src="https://bzr.openai.com/v1/sdk/events?pid=abc&event=page_viewed">'));
        self::assertSame('abc', OpenAiPixelId::fromCode('https://bzr.openai.com/v1/sdk/events?event=x&amp;pid=abc'));
        foreach ([null, 7, '', 'px 1', str_repeat('a', 129), 'https://bzr.openai.com/v1/sdk/events?xpid=abc', 'oaiq("init", {other: "x"})'] as $refused) {
            self::assertNull(OpenAiPixelId::fromCode($refused), var_export($refused, true));
        }
        self::assertTrue(OpenAiPixelId::seededAsExpected('ChatGPT Ads Pixel'));
        self::assertFalse(OpenAiPixelId::seededAsExpected('Something else'));
        self::assertFalse(OpenAiPixelId::seededAsExpected(null));
        self::assertStringContainsString("VALUES (7, 'ChatGPT Ads Pixel')", OpenAiPixelId::seedStatement());
    }

    /** @param list<array<string, mixed>> $destinations */
    private static function db(array $destinations, string $currency = 'USD'): FakeMysqliConnection
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FROM 202_ppc_account_pixels WHERE ppc_account_id = ?', [
            ['pixel_id' => 4, 'pixel_code' => 'px_1', 'pixel_type_id' => 7],
        ]);
        $db->whenQueryContainsReturnRows('202_destinations', $destinations);
        $db->whenQueryContainsReturnRows('user_account_currency', [['user_account_currency' => $currency]]);

        return $db;
    }

    /**
     * @param array<string, scalar|null> $tokens
     * @return array<string, mixed>
     */
    private static function fire(FakeMysqliConnection $db, array $tokens, bool $mayTrack = true, bool $browser = true): array
    {
        return TrafficSourcePixels::fire(new Connection($db), 3, $tokens, static fn (): bool => true, $browser, true, static fn (): bool => $mayTrack, consent: AdPlatformConsent::given());
    }

    private const TOKENS = ['subid' => '12', 'payout' => '24.5', 'oppref' => 'op_77', TrafficSourcePixels::EVENT_ID => 'ORDER-9', TrafficSourcePixels::EVENT_CAMPAIGN => 42];

    public function testTheEventGoesUnderTheDestinationsTypeForTheCampaign(): void
    {
        $db = self::db([
            ['destination_id' => 1, 'settings' => json_encode(['pixel_id' => 'px_other', 'event_type' => 'lead_created'])],
            ['destination_id' => 2, 'settings' => json_encode(['pixel_id' => 'px_1', 'campaign_ids' => [5], 'event_type' => 'trial_started'])],
            ['destination_id' => 3, 'settings' => json_encode(['pixel_id' => 'px_1', 'campaign_ids' => [], 'event_type' => 'order_created', 'event_types' => ['42' => 'lead_created']])],
        ]);
        $out = self::fire($db, self::TOKENS);

        self::assertSame(
            "<img src='https://bzr.openai.com/v1/sdk/events?pid=px_1&amp;event=lead_created&amp;event_id=ORDER-9&amp;oppref=op_77"
            . "&amp;data%5Btype%5D=customer_action&amp;data%5Bamount%5D=2450&amp;data%5Bcurrency%5D=USD' height='1' width='1' style='display:none' alt='' />\n",
            $out['markup']
        );
        self::assertSame([7], $out['types']);
        // The destinations are the source's account's own.
        $read = $db->statementsContaining('202_destinations');
        self::assertCount(1, $read);
        self::assertStringContainsString('a.user_id = d.user_id AND a.ppc_account_id = ?', $read[0]->sql);
        self::assertSame([3, 'openai_ads'], $read[0]->boundValues);
    }

    public function testWithoutADestinationTheEventIsAnOrder(): void
    {
        $out = self::fire(self::db([], 'JPY'), ['payout' => '1500', TrafficSourcePixels::EVENT_ID => 'E1']);
        self::assertSame(
            "<img src='https://bzr.openai.com/v1/sdk/events?pid=px_1&amp;event=order_created&amp;event_id=E1"
            . "&amp;data%5Btype%5D=contents&amp;data%5Bamount%5D=1500&amp;data%5Bcurrency%5D=JPY' height='1' width='1' style='display:none' alt='' />\n",
            $out['markup']
        );
    }

    public function testACustomEventCarriesItsName(): void
    {
        $db = self::db([['destination_id' => 3, 'settings' => json_encode(['pixel_id' => 'px_1', 'event_type' => 'custom', 'custom_event_name' => 'quiz_done'])]]);
        $out = self::fire($db, ['payout' => '0', TrafficSourcePixels::EVENT_ID => 'E2']);
        self::assertSame(
            "<img src='https://bzr.openai.com/v1/sdk/events?pid=px_1&amp;event=custom&amp;event_id=E2&amp;custom_event_name=quiz_done"
            . "&amp;data%5Btype%5D=custom' height='1' width='1' style='display:none' alt='' />\n",
            $out['markup']
        );
    }

    public function testAnAmountWithoutAKnownMinorUnitIsLeftOut(): void
    {
        $out = self::fire(self::db([], 'XYZ'), ['payout' => '3', TrafficSourcePixels::EVENT_ID => 'E3']);
        self::assertStringNotContainsString('amount', $out['markup']);
        self::assertStringContainsString('event=order_created', $out['markup']);
    }

    /** Without an event id OpenAI would count it twice with a destination's; held back as a Meta Purchase is. */
    public function testTheEventIsHeldBackWithoutAnEventIdOrForAVisitorWhoMayNotBeTracked(): void
    {
        self::assertSame('', self::fire(self::db([]), ['payout' => '5'])['markup']);
        self::assertSame('', self::fire(self::db([]), self::TOKENS, false)['markup']);
        $out = self::fire(self::db([]), self::TOKENS, true, false);
        self::assertSame('', $out['markup']);
        self::assertSame(1, $out['browser_skipped']);
    }

    public function testADestinationThatCannotBeReadFiresNothing(): void
    {
        $db = self::db([]);
        $db->whenQueryContainsExecuteReturns('202_destinations', false);
        self::assertSame('', self::fire($db, self::TOKENS)['markup']);
    }
}
