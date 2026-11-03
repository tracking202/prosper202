<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\FacebookPixelAssistantScript;
use Prosper202\Database\Connection;
use Tests\Support\FakeMysqliConnection;

/**
 * What record_simple.php answers for the Bot202 Facebook Pixel Assistant
 * (FacebookPixelAssistantScript::forClick()): landing.php's hook called
 * with the page's Meta Pixel settings, or nothing. The integration test
 * reads the same against a real database across two accounts; the browser
 * test fires what the hook is given.
 */
final class FacebookPixelAssistantScriptTest extends TestCase
{
    private const PAGE = 'FROM 202_bot202_facebook_pixel_assistant AS fb';
    private const PIXEL = 'FROM 202_ppc_account_pixels AS p';
    private const EPV = 'FROM 202_dataengine';
    private const CURRENCY = 'FROM 202_users_pref';

    private function db(array $page, array $pixels = [['pixel_id' => 9, 'pixel_code' => '123456789012345']]): FakeMysqliConnection
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows(self::PAGE, $page === [] ? [] : [$page + [
            'b202_fbpa_dynamic_epv' => '0', 'b202_fbpa_content_name' => 'Spring sale',
            'b202_fbpa_content_type' => '1', 'b202_fbpa_outbound_clicks' => '2', 'aff_campaign_id_public' => '42',
        ]]);
        $db->whenQueryContainsReturnRows(self::PIXEL, $pixels);
        $db->whenQueryContainsReturnRows(self::EPV, [['income' => '30.00000', 'click_out' => '8']]);
        $db->whenQueryContainsReturnRows(self::CURRENCY, [['user_account_currency' => 'GBP']]);

        return $db;
    }

    /** @return array<string, mixed> the settings the answer hands the hook */
    private static function settings(string $answer): array
    {
        self::assertSame(1, preg_match('/^\nif \(typeof window\.p202FbPixelAssistant === \'function\'\) \{ window\.p202FbPixelAssistant\((\{.*\})\); \}\n$/D', $answer, $m), $answer);

        return json_decode($m[1], true, 4, JSON_THROW_ON_ERROR);
    }

    public function testThePagesSettingsAreHandedToTheHook(): void
    {
        $db = $this->db(['b202_fbpa_content_type' => '6']);

        $answer = FacebookPixelAssistantScript::forClick(new Connection($db), 7, 41, 3, 9001, true);

        self::assertSame([
            'pixelId' => '123456789012345',
            'eventId' => 'p202-9001',
            'contentName' => 'Spring sale',
            'contentType' => 'home_listing',
            'contentId' => 't202id42',
            'clickEvent' => 'AddToCart',
        ], self::settings($answer));
        // Scoped to the user: the page through 202_landing_pages, the pixel through 202_ppc_accounts.
        self::assertSame([7, 41], $db->statementsContaining(self::PAGE)[0]->boundValues);
        self::assertSame([7, 3, 6], $db->statementsContaining(self::PIXEL)[0]->boundValues);
        self::assertSame([], $db->statementsContaining(self::EPV), 'no EPV read with dynamic EPV off');
    }

    public function testDynamicEpvIsIncomeOverOutboundClicksInTheAccountsCurrency(): void
    {
        $db = $this->db(['b202_fbpa_dynamic_epv' => '1']);
        $now = 1_700_000_000;

        $settings = self::settings(FacebookPixelAssistantScript::forClick(new Connection($db), 7, 41, 3, 9001, true, null, $now));

        self::assertSame(3.75, $settings['value']);
        self::assertSame('GBP', $settings['currency']);
        // Since the start of the hour seven days back.
        $since = (intdiv($now, 3600) - 168) * 3600;
        self::assertSame([7, $since, 3], $db->statementsContaining(self::EPV)[0]->boundValues);
    }

    public function testTheEpvIsReadThroughTheCacheWhenGiven(): void
    {
        $db = $this->db(['b202_fbpa_dynamic_epv' => '1']);
        $keys = [];
        $cache = static function (string $key, \Closure $read) use (&$keys): mixed {
            $keys[] = $key;

            return '1.20';
        };

        $settings = self::settings(FacebookPixelAssistantScript::forClick(new Connection($db), 7, 41, 3, 9001, true, $cache, 1_700_000_000));

        self::assertSame(1.2, $settings['value']);
        self::assertSame(['p202-fbpa-epv-7-3-' . ((intdiv(1_700_000_000, 3600) - 168) * 3600)], $keys);
        self::assertSame([], $db->statementsContaining(self::EPV), 'a cached EPV is not read again');
    }

    /** Nothing to divide, or no event to carry it: no value. */
    public function testNoValueWithoutEarningsOrAnOutboundEvent(): void
    {
        $db = $this->db(['b202_fbpa_dynamic_epv' => '1']);
        $db->whenQueryContainsReturnRows(self::EPV, [['income' => null, 'click_out' => null]]);
        self::assertArrayNotHasKey('value', self::settings(FacebookPixelAssistantScript::forClick(new Connection($db), 7, 41, 3, 1, true)));

        $db = $this->db(['b202_fbpa_dynamic_epv' => '1', 'b202_fbpa_outbound_clicks' => '0', 'b202_fbpa_content_type' => '0', 'b202_fbpa_content_name' => '']);
        self::assertSame(
            ['pixelId' => '123456789012345', 'eventId' => 'p202-1', 'contentId' => 't202id42'],
            self::settings(FacebookPixelAssistantScript::forClick(new Connection($db), 7, 41, 3, 1, true))
        );
        self::assertSame([], $db->statementsContaining(self::EPV));
    }

    /**
     * A visitor the privacy setting or the page's consent holds back is
     * answered nothing, and nothing is read for them.
     */
    public function testAVisitorHeldBackGetsNothing(): void
    {
        $db = $this->db([]);

        self::assertSame('', FacebookPixelAssistantScript::forClick(new Connection($db), 7, 41, 3, 1, false));
        self::assertSame([], $db->preparedSql);
    }

    public function testNothingWithoutTheSettingsATrafficSourceOrAPixelId(): void
    {
        // The assistant off (or another account's page): no row.
        $db = $this->db([]);
        self::assertSame('', FacebookPixelAssistantScript::forClick(new Connection($db), 7, 41, 3, 1, true));
        self::assertSame([], $db->statementsContaining(self::PIXEL));

        // No traffic source on the click.
        $db = $this->db(['b202_fbpa_status' => '1']);
        self::assertSame('', FacebookPixelAssistantScript::forClick(new Connection($db), 7, 41, 0, 1, true));
        self::assertSame([], $db->preparedSql);

        // The source's only assistant pixel holds no Pixel ID (logged); the next one that does is used.
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-fbpa-log');
        $previous = ini_set('error_log', $log);
        try {
            $db = $this->db(['b202_fbpa_status' => '1'], [['pixel_id' => 9, 'pixel_code' => 'not an id']]);
            self::assertSame('', FacebookPixelAssistantScript::forClick(new Connection($db), 7, 41, 3, 1, true));
            $db = $this->db(['b202_fbpa_status' => '1'], [['pixel_id' => 9, 'pixel_code' => 'x'], ['pixel_id' => 10, 'pixel_code' => "fbq('init', '555555555555555');"]]);
            self::assertSame('555555555555555', self::settings(FacebookPixelAssistantScript::forClick(new Connection($db), 7, 41, 3, 1, true))['pixelId']);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);
        self::assertStringContainsString('pixel 9 of ppc account 3 holds no Meta Pixel ID; skipped', $logged);
    }

    /** The click is already recorded: a failed read is logged and the page's script still runs. */
    public function testAFailedReadIsLoggedAndAnswersNothing(): void
    {
        $db = $this->db(['b202_fbpa_status' => '1']);
        $db->whenQueryContainsResultFails(self::PAGE);
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-fbpa-log');
        $previous = ini_set('error_log', $log);
        try {
            $answer = FacebookPixelAssistantScript::forClick(new Connection($db), 7, 41, 3, 77, true);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertSame('', $answer);
        self::assertStringContainsString('landing page 41 could not be read; its Meta Pixel events are not sent for click 77', $logged);
    }

    /** A content name is data in the answer, never script. */
    public function testTheContentNameCannotEndTheScript(): void
    {
        $db = $this->db(['b202_fbpa_content_name' => "</script><script>alert('x')</script>"]);

        $answer = FacebookPixelAssistantScript::forClick(new Connection($db), 7, 41, 3, 1, true);

        self::assertStringNotContainsString('<', $answer);
        self::assertStringNotContainsString("'x'", $answer);
        self::assertSame("</script><script>alert('x')</script>", self::settings($answer)['contentName']);
    }

    /**
     * A third-party pixel goes only to a visitor the privacy setting tracks
     * in full, who did not refuse consent, on a campaign whose identity
     * capture is on (as p202ClickIdentity() reads it for the click's own signals).
     */
    public function testMayTrackNeedsThePrivacySettingConsentAndTheCampaign(): void
    {
        self::assertTrue(FacebookPixelAssistantScript::mayTrack(true, [], '1'));
        self::assertTrue(FacebookPixelAssistantScript::mayTrack(true, ['p202_consent' => '1'], 1));
        self::assertTrue(FacebookPixelAssistantScript::mayTrack(true, [], null), 'no campaign leaves it on, as for the click');
        self::assertFalse(FacebookPixelAssistantScript::mayTrack(false, [], '1'), 'held back by the privacy setting');
        self::assertFalse(FacebookPixelAssistantScript::mayTrack(true, ['p202_consent' => '0'], '1'), 'consent refused');
        self::assertFalse(FacebookPixelAssistantScript::mayTrack(true, [], '0'), 'the campaign has identity capture off');
        self::assertFalse(FacebookPixelAssistantScript::mayTrack(true, [], 'yes'), 'a setting that cannot be read is off');
    }

    /** record_simple.php asks mayTrack() with the request and its campaign's setting, as it asks for the click's identity. */
    public function testRecordSimpleGatesTheAnswerOnMayTrack(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/tracking202/static/record_simple.php');
        $campaign = "array_key_exists('identity_signals', \$tracker_row) ? \$tracker_row['identity_signals'] : null";
        self::assertStringContainsString("RequestSignals::campaignAllows($campaign)", $source, 'the click identity reads the same setting');
        self::assertSame(1, substr_count($source, 'FacebookPixelAssistantScript::forClick('));
        self::assertMatchesRegularExpression(
            '/FacebookPixelAssistantScript::forClick\(\s*\$conn,[^;]*?FacebookPixelAssistantScript::mayTrack\(trackingEnabled\(\), \$_GET, '
                . preg_quote($campaign, '/') . '\),/s',
            $source
        );
    }
}
