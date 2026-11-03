<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\FacebookPixelAssistantScript;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;
use Prosper202\Database\DataSeeder;
use Prosper202\Setup\FacebookPixelAssistantSettings;
use Tests\Api\V3\SetupScratchDatabase;

/**
 * The Bot202 Facebook Pixel Assistant against a real database: a page's
 * settings saved and read only by its account (as paid 1.9.56 left them in
 * 202_bot202_facebook_pixel_assistant), the landing page script built from
 * them and the click's traffic source pixel, the conversion's Purchase from
 * a type-6 pixel, and the lookup rows a fresh install seeds.
 *
 * Skips without a scratch database (SetupScratchDatabase); writes users
 * 5341 and 5342's rows.
 *
 * @group integration
 */
final class FacebookPixelAssistantIntegrationTest extends TestCase
{
    use SetupScratchDatabase;

    private const USER = 5341;
    private const OTHER = 5342;

    /** @var array<string, int> */
    private array $ids = [];

    public static function setUpBeforeClass(): void
    {
        self::connectScratchDatabase();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::restoreScratchDatabase();
            self::$db->close();
        }
        self::$db = null;
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            self::$db->query("DELETE fb FROM 202_bot202_facebook_pixel_assistant fb JOIN 202_landing_pages lp ON lp.landing_page_id = fb.landing_page_id WHERE lp.user_id = $u");
            self::$db->query("DELETE p FROM 202_ppc_account_pixels p JOIN 202_ppc_accounts a ON a.ppc_account_id = p.ppc_account_id WHERE a.user_id = $u");
            foreach (['202_dataengine', '202_landing_pages', '202_aff_campaigns', '202_ppc_accounts', '202_ppc_networks', '202_users_pref'] as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = $u");
            }
        }
    }

    protected function setUp(): void
    {
        self::requireScratchDatabase();
        self::cleanUp();
        foreach ([self::USER => 'mine', self::OTHER => 'theirs'] as $user => $tag) {
            $campaign = self::exec("INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = 1, aff_campaign_name = 'offer $tag',"
                . " aff_campaign_url = 'https://shop.example/', aff_campaign_payout = 10, aff_campaign_time = 0, aff_campaign_foreign_payout = 0");
            self::exec("UPDATE 202_aff_campaigns SET aff_campaign_id_public = " . ($campaign + 70000) . " WHERE aff_campaign_id = $campaign");
            $this->ids["page $tag"] = self::exec("INSERT INTO 202_landing_pages SET user_id = $user, aff_campaign_id = $campaign,"
                . " landing_page_nickname = 'page $tag', landing_page_url = 'https://lander.example/', landing_page_time = 0, landing_page_type = 0");
            $this->ids["offer $tag"] = $campaign + 70000;
            $network = self::exec("INSERT INTO 202_ppc_networks SET user_id = $user, ppc_network_name = 'source $tag', ppc_network_time = 0");
            $this->ids["account $tag"] = self::exec("INSERT INTO 202_ppc_accounts SET user_id = $user, ppc_network_id = $network, ppc_account_name = 'account $tag', ppc_account_time = 0");
            self::exec("INSERT INTO 202_users_pref SET user_id = $user, user_account_currency = 'EUR'");
        }
    }

    private function settings(): FacebookPixelAssistantSettings
    {
        return new FacebookPixelAssistantSettings(new Connection(self::$db));
    }

    private const ON = ['status' => 1, 'dynamic_epv' => 1, 'content_name' => 'Spring sale', 'content_type' => 1, 'outbound_clicks' => 2];

    public function testAPagesSettingsAreSavedAndReadOnlyByItsAccount(): void
    {
        $mine = $this->ids['page mine'];
        $theirs = $this->ids['page theirs'];

        $this->settings()->save(self::USER, $mine, self::ON);
        // Another account's page id writes nothing and reads nothing.
        $this->settings()->save(self::USER, $theirs, self::ON);
        self::assertSame(self::ON, $this->settings()->load(self::USER, $mine));
        self::assertNull($this->settings()->load(self::USER, $theirs));
        self::assertNull($this->settings()->load(self::OTHER, $mine));
        self::assertSame('0', self::row("SELECT COUNT(*) AS n FROM 202_bot202_facebook_pixel_assistant WHERE landing_page_id = $theirs")['n']);

        // Saving again updates the one row; switching off keeps the rest.
        $this->settings()->save(self::USER, $mine, ['content_name' => 'Summer'] + self::ON);
        $this->settings()->switchOff(self::OTHER, $mine);
        self::assertSame(1, $this->settings()->load(self::USER, $mine)['status'], 'another account cannot switch it off');
        $this->settings()->switchOff(self::USER, $mine);
        self::assertSame(array_merge(self::ON, ['status' => 0, 'content_name' => 'Summer']), $this->settings()->load(self::USER, $mine));
        self::assertSame('1', self::row("SELECT COUNT(*) AS n FROM 202_bot202_facebook_pixel_assistant WHERE landing_page_id = $mine")['n']);
    }

    /** A row as paid 1.9.56 left it is read as it is: no migration. */
    public function testAPaidInstallsRowIsReadAsItIs(): void
    {
        $mine = $this->ids['page mine'];
        self::exec("INSERT INTO 202_bot202_facebook_pixel_assistant SET landing_page_id = $mine, b202_fbpa_status = 1,"
            . " b202_fbpa_dynamic_epv = 0, b202_fbpa_content_name = 'Old name', b202_fbpa_content_type = 5, b202_fbpa_outbound_clicks = 1");

        self::assertSame(
            ['status' => 1, 'dynamic_epv' => 0, 'content_name' => 'Old name', 'content_type' => 5, 'outbound_clicks' => 1],
            $this->settings()->load(self::USER, $mine)
        );
    }

    public function testTheLandingPageScriptUsesTheAccountsOwnPixelAndEarnings(): void
    {
        $mine = $this->ids['page mine'];
        $account = $this->ids['account mine'];
        $this->settings()->save(self::USER, $mine, self::ON);
        self::exec("INSERT INTO 202_ppc_account_pixels SET ppc_account_id = $account, pixel_type_id = 6, pixel_code = '123456789012345'");
        $now = time();
        foreach ([[30.0, 4], [10.0, 4]] as $i => [$income, $out]) {
            self::exec('INSERT INTO 202_dataengine SET user_id = ' . self::USER . ', click_id = ' . (990000 + $i) . ", click_time = $now,"
                . " ppc_account_id = $account, landing_page_id = $mine, payout = 0, income = $income, click_out = $out");
        }
        // Older than seven days: not counted.
        self::exec('INSERT INTO 202_dataengine SET user_id = ' . self::USER . ", click_id = 990009, click_time = " . ($now - 9 * 86400)
            . ", ppc_account_id = $account, landing_page_id = $mine, payout = 0, income = 900, click_out = 1");

        $answer = FacebookPixelAssistantScript::forClick(new Connection(self::$db), self::USER, $mine, $account, 4242, true);
        self::assertSame(1, preg_match('/p202FbPixelAssistant\((\{.*\})\)/', $answer, $m), $answer);
        // 40 income over 8 outbound clicks in the last seven days; JSON writes 5.0 as 5.
        self::assertEquals([
            'pixelId' => '123456789012345', 'eventId' => 'p202-4242', 'contentName' => 'Spring sale', 'contentType' => 'product',
            'contentId' => 't202id' . $this->ids['offer mine'], 'clickEvent' => 'AddToCart', 'value' => 5.0, 'currency' => 'EUR',
        ], json_decode($m[1], true));

        // Another account's traffic source: its pixel is not this page's.
        $other = $this->ids['account theirs'];
        self::exec("INSERT INTO 202_ppc_account_pixels SET ppc_account_id = $other, pixel_type_id = 6, pixel_code = '555555555555555'");
        self::assertSame('', FacebookPixelAssistantScript::forClick(new Connection(self::$db), self::USER, $mine, $other, 4242, true));
        // Another account's page, and a visitor held back: nothing.
        self::assertSame('', FacebookPixelAssistantScript::forClick(new Connection(self::$db), self::OTHER, $mine, $other, 4242, true));
        self::assertSame('', FacebookPixelAssistantScript::forClick(new Connection(self::$db), self::USER, $mine, $account, 4242, false));
    }

    public function testATypeSixPixelFiresAPurchaseOnTheConversion(): void
    {
        $account = $this->ids['account mine'];
        self::exec("INSERT INTO 202_ppc_account_pixels SET ppc_account_id = $account, pixel_type_id = 6, pixel_code = '123456789012345'");

        $out = TrafficSourcePixels::fire(new Connection(self::$db), $account, ['payout' => '12', 'transactionid' => 'T1'], static fn (): bool => true);

        self::assertStringContainsString('https://www.facebook.com/tr?id=123456789012345&amp;ev=Purchase&amp;noscript=1&amp;cd%5Bvalue%5D=12.00&amp;cd%5Bcurrency%5D=EUR&amp;eid=T1', $out['markup']);
        self::assertSame([6], $out['types']);
    }

    /** A fresh install seeds the lookup rows; seeding again keeps them. */
    public function testTheLookupRowsAreSeeded(): void
    {
        $seeder = new DataSeeder(self::$db);
        $seeder->seedBot202FacebookPixelLookups();
        $seeder->seedBot202FacebookPixelLookups();

        $types = [];
        foreach (self::$db->query('SELECT content_type_id, content_type, content_type_description FROM 202_bot202_facebook_pixel_content_type ORDER BY content_type_id')->fetch_all(MYSQLI_NUM) as [$id, $type, $description]) {
            $types[(int) $id] = [$type, $description];
        }
        $events = [];
        foreach (self::$db->query('SELECT event_type_id, event_type, event_type_description FROM 202_bot202_facebook_pixel_click_events ORDER BY event_type_id')->fetch_all(MYSQLI_NUM) as [$id, $type, $description]) {
            $events[(int) $id] = [$type, $description];
        }
        self::assertSame(FacebookPixelAssistantSettings::CONTENT_TYPES, $types);
        self::assertSame(FacebookPixelAssistantSettings::CLICK_EVENTS, $events);
    }
}
