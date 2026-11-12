<?php

declare(strict_types=1);

namespace Tests\Setup;

use Api\V3\Controllers\PpcNetworksController;
use Api\V3\Controllers\TrackersController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Setup\ChatGptAdsSource;
use Tests\Api\V3\SetupScratchDatabase;

/**
 * "Add ChatGPT Ads" (ChatGptAdsSource::add(), POST /ppc-networks/chatgpt-ads)
 * and the pair GET /trackers/{id}/url answers for a tracker on its source,
 * against a real database: one source per account however often it is
 * asked, what a removed account or variable left is put back and what the
 * user changed is kept, and nothing of another account's is found or
 * touched (CLAUDE.md #27).
 *
 * Skips without a scratch database (SetupScratchDatabase); writes users
 * 5381 and 5382's rows.
 *
 * @group integration
 */
final class ChatGptAdsSourceIntegrationTest extends TestCase
{
    use SetupScratchDatabase;

    private const USER = 5381;
    private const OTHER = 5382;

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
            self::$db->query("DELETE v FROM 202_ppc_network_variables v JOIN 202_ppc_networks n ON n.ppc_network_id = v.ppc_network_id WHERE n.user_id = $u");
            foreach (['202_trackers', '202_ppc_accounts', '202_ppc_networks', '202_landing_pages', '202_aff_campaigns', '202_users'] as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = $u");
            }
        }
    }

    protected function setUp(): void
    {
        self::requireScratchDatabase();
        self::cleanUp();
        foreach ([self::USER, self::OTHER] as $id) {
            self::exec("INSERT INTO 202_users SET user_id = $id, user_name = 'chatgpt$id', user_pass = 'x',"
                . " user_email = 'c$id@example.com', user_dash_email = 'c$id@example.com', user_time_register = 0,"
                . " install_hash = 'h', user_hash = 'h'");
        }
        self::setTrackingDomain('track.example.com');
    }

    private static function source(): ChatGptAdsSource
    {
        return new ChatGptAdsSource(new Connection(self::$db));
    }

    private static function rowCount(string $sql): int
    {
        return (int) self::row($sql)['n'];
    }

    /** @return array<string, string> parameter => placeholder of the source's live variables */
    private static function variables(int $sourceId): array
    {
        $out = [];
        $result = self::$db->query("SELECT parameter, placeholder FROM 202_ppc_network_variables WHERE ppc_network_id = $sourceId AND deleted = 0 ORDER BY ppc_variable_id");
        while ($row = $result->fetch_assoc()) {
            $out[$row['parameter']] = $row['placeholder'];
        }

        return $out;
    }

    public function testTheFirstClickMakesTheSourceItsAccountAndItsVariables(): void
    {
        $made = self::source()->add(self::USER, 1_800_000_000);

        self::assertTrue($made['source_created']);
        self::assertTrue($made['account_created']);
        self::assertSame(['campaign_id', 'ad_id'], $made['variables_added']);
        $source = self::row('SELECT * FROM 202_ppc_networks WHERE ppc_network_id = ' . $made['ppc_network_id']);
        self::assertSame((string) self::USER, (string) $source['user_id']);
        self::assertSame('ChatGPT Ads', $source['ppc_network_name']);
        self::assertSame('chatgpt_ads', $source['ppc_network_platform']);
        self::assertSame('0', (string) $source['ppc_network_deleted']);
        $account = self::row('SELECT * FROM 202_ppc_accounts WHERE ppc_account_id = ' . $made['ppc_account_id']);
        self::assertSame((string) self::USER, (string) $account['user_id']);
        self::assertSame((string) $made['ppc_network_id'], (string) $account['ppc_network_id']);
        self::assertSame(['campaign_id' => '{campaign_id}', 'ad_id' => '{ad_id}'], self::variables($made['ppc_network_id']));
    }

    public function testASecondClickMakesNothingTwice(): void
    {
        $first = self::source()->add(self::USER);
        $second = self::source()->add(self::USER);

        self::assertSame([
            'ppc_network_id' => $first['ppc_network_id'],
            'ppc_account_id' => $first['ppc_account_id'],
            'source_created' => false,
            'account_created' => false,
            'variables_added' => [],
        ], $second);
        self::assertSame(1, self::rowCount('SELECT COUNT(*) AS n FROM 202_ppc_networks WHERE user_id = ' . self::USER));
        self::assertSame(1, self::rowCount('SELECT COUNT(*) AS n FROM 202_ppc_accounts WHERE user_id = ' . self::USER));
        self::assertSame(2, self::rowCount('SELECT COUNT(*) AS n FROM 202_ppc_network_variables WHERE ppc_network_id = ' . $first['ppc_network_id']));
    }

    /** A renamed source is still the one; a removed account and variable come back; a changed placeholder stays. */
    public function testASecondClickPutsBackWhatWasRemovedAndKeepsWhatWasChanged(): void
    {
        $first = self::source()->add(self::USER);
        $sid = $first['ppc_network_id'];
        self::exec("UPDATE 202_ppc_networks SET ppc_network_name = 'OpenAI' WHERE ppc_network_id = $sid");
        self::exec('UPDATE 202_ppc_accounts SET ppc_account_deleted = 1 WHERE ppc_account_id = ' . $first['ppc_account_id']);
        self::exec("UPDATE 202_ppc_network_variables SET deleted = 1 WHERE ppc_network_id = $sid AND parameter = 'ad_id'");
        self::exec("UPDATE 202_ppc_network_variables SET placeholder = '{campaign}' WHERE ppc_network_id = $sid AND parameter = 'campaign_id'");

        $second = self::source()->add(self::USER);

        self::assertSame($sid, $second['ppc_network_id']);
        self::assertFalse($second['source_created']);
        self::assertTrue($second['account_created']);
        self::assertNotSame($first['ppc_account_id'], $second['ppc_account_id']);
        self::assertSame(['ad_id'], $second['variables_added']);
        self::assertSame(['campaign_id' => '{campaign}', 'ad_id' => '{ad_id}'], self::variables($sid));
        self::assertSame('OpenAI', self::row("SELECT ppc_network_name FROM 202_ppc_networks WHERE ppc_network_id = $sid")['ppc_network_name']);
    }

    public function testARemovedSourceIsReplacedByANewOne(): void
    {
        $first = self::source()->add(self::USER);
        self::exec('UPDATE 202_ppc_networks SET ppc_network_deleted = 1 WHERE ppc_network_id = ' . $first['ppc_network_id']);

        $second = self::source()->add(self::USER);

        self::assertTrue($second['source_created']);
        self::assertNotSame($first['ppc_network_id'], $second['ppc_network_id']);
    }

    /** A source the user named ChatGPT Ads by hand is not the one: only the stored platform decides. */
    public function testASourceNamedChatGptAdsByHandIsNotTheOne(): void
    {
        $hand = self::exec('INSERT INTO 202_ppc_networks SET user_id = ' . self::USER . ", ppc_network_name = 'ChatGPT Ads', ppc_network_time = 0");
        $handAccount = self::exec('INSERT INTO 202_ppc_accounts SET user_id = ' . self::USER . ", ppc_network_id = $hand, ppc_account_name = 'mine', ppc_account_time = 0");

        self::assertFalse(self::source()->isAccountOnSource(self::USER, $handAccount));
        $made = self::source()->add(self::USER);
        self::assertTrue($made['source_created']);
        self::assertNotSame($hand, $made['ppc_network_id']);
        self::assertTrue(self::source()->isAccountOnSource(self::USER, $made['ppc_account_id']));
    }

    public function testAnotherAccountsSourceIsNeitherFoundNorTouched(): void
    {
        $theirs = self::source()->add(self::OTHER);
        $mine = self::source()->add(self::USER);

        self::assertTrue($mine['source_created'], "the other account's source is not this account's");
        self::assertNotSame($theirs['ppc_network_id'], $mine['ppc_network_id']);
        self::assertSame(1, self::rowCount('SELECT COUNT(*) AS n FROM 202_ppc_networks WHERE user_id = ' . self::OTHER));
        self::assertSame(1, self::rowCount('SELECT COUNT(*) AS n FROM 202_ppc_accounts WHERE user_id = ' . self::OTHER));
        self::assertFalse(self::source()->isAccountOnSource(self::USER, $theirs['ppc_account_id']), "another account's account");

        // An account of mine left on their source (a row written before links were checked) is not on one of mine.
        $stray = self::exec('INSERT INTO 202_ppc_accounts SET user_id = ' . self::USER . ', ppc_network_id = ' . $theirs['ppc_network_id'] . ", ppc_account_name = 'stray', ppc_account_time = 0");
        self::assertFalse(self::source()->isAccountOnSource(self::USER, $stray));
    }

    public function testTheApiAddsItOnceAndRefusesABody(): void
    {
        $api = new PpcNetworksController(self::$db, self::USER);
        $first = $api->addChatGptAds([])['data'];
        $second = $api->addChatGptAds([])['data'];

        self::assertTrue($first['source_created']);
        self::assertFalse($second['source_created']);
        self::assertSame($first['ppc_network_id'], $second['ppc_network_id']);
        try {
            $api->addChatGptAds(['ppc_network_name' => 'Mine']);
            self::fail('a body was taken');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ppc_network_name', $e->getFieldErrors());
        }
        self::assertSame(1, self::rowCount('SELECT COUNT(*) AS n FROM 202_ppc_networks WHERE user_id = ' . self::USER));
    }

    public function testTheTrackerUrlCarriesThePairForATrackerOnTheSourceOnly(): void
    {
        $made = self::source()->add(self::USER);
        $campaign = self::exec('INSERT INTO 202_aff_campaigns SET user_id = ' . self::USER . ", aff_network_id = 0, aff_campaign_name = 'Spring sale', aff_campaign_url = 'https://shop.example.com/offer', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");
        $page = self::exec('INSERT INTO 202_landing_pages SET user_id = ' . self::USER . ", aff_campaign_id = $campaign, landing_page_nickname = 'Sale page', landing_page_url = 'https://shop.example.com/sale', landing_page_time = 0");
        $plainSource = self::exec('INSERT INTO 202_ppc_networks SET user_id = ' . self::USER . ", ppc_network_name = 'Newsletter', ppc_network_time = 0");
        $plainAccount = self::exec('INSERT INTO 202_ppc_accounts SET user_id = ' . self::USER . ", ppc_network_id = $plainSource, ppc_account_name = 'weekly', ppc_account_time = 0");
        $trackers = new TrackersController(self::$db, self::USER);
        $tracker = static fn (array $body): int => (int) $trackers->create($body + ['aff_campaign_id' => $campaign])['data']['tracker_id'];
        $url = static fn (int $id, array $params = []): array => $trackers->getTrackingUrl($id, $params, self::server())['data'];

        $direct = $url($tracker(['ppc_account_id' => $made['ppc_account_id']]));
        $public = $direct['tracker_id_public'];
        self::assertSame([
            'destination' => 'tracking_link',
            'destination_url' => "https://track.example.com/tracking202/redirect/dl.php?t202id=$public",
            'tracking_template' => ChatGptAdsSource::TEMPLATE,
        ], $direct['chatgpt_ads']);
        self::assertSame("https://track.example.com/tracking202/redirect/dl.php?t202id=$public&campaign_id={campaign_id}&ad_id={ad_id}&t202kw=", $direct['direct_url'], 'the full link is as before');

        $onPage = $url($tracker(['ppc_account_id' => $made['ppc_account_id'], 'landing_page_id' => $page]), ['c1' => 'spring']);
        $public = $onPage['tracker_id_public'];
        self::assertSame([
            'destination' => 'landing_page',
            'destination_url' => 'https://shop.example.com/sale',
            'tracking_template' => ChatGptAdsSource::TEMPLATE . "&t202id=$public&c1=spring&t202kw=",
        ], $onPage['chatgpt_ads']);

        self::assertNull($url($tracker(['ppc_account_id' => $plainAccount]))['chatgpt_ads']);
        self::assertNull($url($tracker([]))['chatgpt_ads']);
    }
}
