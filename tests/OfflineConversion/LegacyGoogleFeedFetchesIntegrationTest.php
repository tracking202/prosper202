<?php

declare(strict_types=1);

namespace Tests\OfflineConversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\OfflineConversion\ConversionNames;
use Prosper202\OfflineConversion\FeedStore;
use Prosper202\OfflineConversion\Format;
use Prosper202\OfflineConversion\LegacyGoogleFeed;
use Prosper202\OfflineConversion\LegacyGoogleFeedFetches;

/**
 * A fetch of paid 1.9.56's retired Google Ads feed is noted, shown to the
 * account whose campaign it names, and moved over with the values its
 * address carried, on a real server.
 *
 * @group integration
 */
final class LegacyGoogleFeedFetchesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 993101;
    private const OTHER = 993102;
    private const STORE = 993111;
    private const TRIAL = 993112;
    private const DELETED = 993113;
    private const OTHERS_CAMPAIGN = 993114;
    private const NOW = 1_800_000_000;

    private static Connection $conn;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::$conn = new Connection(self::$db);
        self::clean();
        foreach ([self::OWNER, self::OTHER] as $user) {
            self::user($user);
        }
        $campaigns = [
            [self::STORE, self::OWNER, 'Online store checkout', 0],
            [self::TRIAL, self::OWNER, 'SaaS trial sign-up', 0],
            [self::DELETED, self::OWNER, 'Spring sale', 1],
            [self::OTHERS_CAMPAIGN, self::OTHER, 'Demo request', 0],
        ];
        foreach ($campaigns as [$id, $user, $name, $deleted]) {
            self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $id, user_id = $user, aff_network_id = 0,"
                . ' aff_campaign_deleted = ' . $deleted . ', aff_campaign_name = ' . self::str($name)
                . ", aff_campaign_url = 'https://shop.example/', aff_campaign_payout = 0, aff_campaign_time = 0, aff_campaign_foreign_payout = 0, payout_mode = 'replace'");
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::clean();
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DELETE FROM 202_legacy_google_feed_fetches');
        foreach (['202_offline_conversion_feeds', '202_offline_conversion_names'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN (" . self::OWNER . ', ' . self::OTHER . ')');
        }
    }

    private static function clean(): void
    {
        self::q('DELETE FROM 202_legacy_google_feed_fetches');
        foreach (['202_offline_conversion_feeds', '202_offline_conversion_names', '202_aff_campaigns', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN (" . self::OWNER . ', ' . self::OTHER . ')');
        }
    }

    private static function fetches(): LegacyGoogleFeedFetches
    {
        return new LegacyGoogleFeedFetches(self::$conn);
    }

    public function testAFetchIsNotedOncePerScheduleAndCounted(): void
    {
        $query = ['cn' => 'Purchase', 'ac' => (string) self::STORE];
        LegacyGoogleFeed::note(self::$db, $query, self::NOW - 7200);
        LegacyGoogleFeed::note(self::$db, $query, self::NOW - 3600);
        // Another capitalisation is another conversion action to Google.
        LegacyGoogleFeed::note(self::$db, ['cn' => 'purchase', 'ac' => (string) self::STORE], self::NOW - 60);
        // Nothing this version can move is noted.
        LegacyGoogleFeed::note(self::$db, ['cn' => '=Purchase', 'ac' => '--'], self::NOW);
        LegacyGoogleFeed::note(self::$db, ['cn' => 'Purchase', 'ac' => '12abc'], self::NOW);
        LegacyGoogleFeed::note(self::$db, ['cn' => 'Purchase'], self::NOW);

        $rows = self::$db->query('SELECT conversion_name, aff_campaign_id, first_fetched_at, last_fetched_at, fetches'
            . ' FROM 202_legacy_google_feed_fetches ORDER BY fetch_id')->fetch_all(MYSQLI_NUM);
        self::assertSame([
            ['Purchase', (string) self::STORE, (string) (self::NOW - 7200), (string) (self::NOW - 3600), '2'],
            ['purchase', (string) self::STORE, (string) (self::NOW - 60), (string) (self::NOW - 60), '1'],
        ], $rows);
    }

    public function testAFetchIsNotedOnTheConnectionOrNotAtAllButNeverThrows(): void
    {
        LegacyGoogleFeed::note(false, ['cn' => 'Purchase', 'ac' => '--'], self::NOW);
        LegacyGoogleFeed::note(null, ['cn' => 'Purchase', 'ac' => '--'], self::NOW);
        self::q('RENAME TABLE 202_legacy_google_feed_fetches TO 202_legacy_google_feed_fetches_away');
        try {
            LegacyGoogleFeed::note(self::$db, ['cn' => 'Purchase', 'ac' => '--'], self::NOW);
        } finally {
            self::q('RENAME TABLE 202_legacy_google_feed_fetches_away TO 202_legacy_google_feed_fetches');
        }
        self::assertSame('0', self::$db->query('SELECT COUNT(*) FROM 202_legacy_google_feed_fetches')->fetch_row()[0]);
    }

    public function testTheTableIsBoundedAndAStaleScheduleMakesRoom(): void
    {
        $fetches = self::fetches();
        for ($i = 1; $i <= LegacyGoogleFeedFetches::MAX_ROWS; $i++) {
            self::assertTrue($fetches->record(['campaign_id' => 0, 'conversion_name' => 'Name ' . $i], self::NOW));
        }
        self::assertFalse($fetches->record(['campaign_id' => 0, 'conversion_name' => 'One more'], self::NOW));
        // One already noted is still counted when the table is full.
        self::assertTrue($fetches->record(['campaign_id' => 0, 'conversion_name' => 'Name 1'], self::NOW + 1));
        self::assertSame((string) LegacyGoogleFeedFetches::MAX_ROWS, self::$db->query('SELECT COUNT(*) FROM 202_legacy_google_feed_fetches')->fetch_row()[0]);

        self::q('UPDATE 202_legacy_google_feed_fetches SET last_fetched_at = ' . (self::NOW - LegacyGoogleFeedFetches::SHOWN_FOR - 1)
            . " WHERE conversion_name = 'Name 2'");
        self::assertTrue($fetches->record(['campaign_id' => 0, 'conversion_name' => 'One more'], self::NOW));
        self::assertSame('0', self::$db->query("SELECT COUNT(*) FROM 202_legacy_google_feed_fetches WHERE conversion_name = 'Name 2'")->fetch_row()[0]);
    }

    public function testAnAccountIsShownItsOwnCampaignsSchedulesAndTheOnesNamingEveryCampaign(): void
    {
        $fetches = self::fetches();
        $fetches->record(['campaign_id' => self::STORE, 'conversion_name' => 'Purchase'], self::NOW - 300);
        $fetches->record(['campaign_id' => 0, 'conversion_name' => 'Lead'], self::NOW - 200);
        $fetches->record(['campaign_id' => self::OTHERS_CAMPAIGN, 'conversion_name' => 'Demo'], self::NOW - 100);
        $fetches->record(['campaign_id' => self::DELETED, 'conversion_name' => 'Sale'], self::NOW - 50);
        $fetches->record(['campaign_id' => 999999999, 'conversion_name' => 'Nobody'], self::NOW - 40);
        $fetches->record(['campaign_id' => self::TRIAL, 'conversion_name' => 'Old'], self::NOW - LegacyGoogleFeedFetches::SHOWN_FOR - 1);

        $mine = $fetches->forAccount(self::OWNER, self::NOW);
        self::assertSame(
            [['Sale', self::DELETED, 'Spring sale', 'gone'], ['Lead', 0, null, 'ready'], ['Purchase', self::STORE, 'Online store checkout', 'ready']],
            array_map(static fn (array $f): array => [$f['conversion_name'], $f['campaign_id'], $f['campaign_name'], $f['state']], $mine)
        );
        self::assertSame(
            [['Demo', 'Demo request'], ['Lead', null]],
            array_map(static fn (array $f): array => [$f['conversion_name'], $f['campaign_name']], $fetches->forAccount(self::OTHER, self::NOW))
        );
    }

    public function testMovingAScheduleSavesItsNameAndMakesItsFetchUrl(): void
    {
        $fetches = self::fetches();
        $fetches->record(['campaign_id' => self::STORE, 'conversion_name' => 'Purchase'], self::NOW - 300);
        $id = $fetches->forAccount(self::OWNER, self::NOW)[0]['fetch_id'];

        // Another account's campaign is not this account's to move, nor a schedule that is not listed.
        self::assertNull($fetches->move(self::OTHER, $id, self::NOW));
        self::assertNull($fetches->move(self::OWNER, $id + 1000, self::NOW));
        self::assertSame([], (new FeedStore(self::$conn))->list(self::OTHER));

        $moved = $fetches->move(self::OWNER, $id, self::NOW);
        self::assertNotNull($moved);
        self::assertTrue(FeedStore::isKey((string) $moved['key']));
        self::assertSame(
            [Format::GOOGLE_UPLOADS, 90, 'Purchase', null, null],
            [$moved['feed']->format, $moved['feed']->days, $moved['feed']->conversionName, $moved['feed']->adUserData, $moved['feed']->adPersonalization]
        );
        $names = (new ConversionNames(self::$conn))->read(self::OWNER)[ConversionNames::GOOGLE];
        self::assertSame(['default' => null, 'campaigns' => [self::STORE => 'Purchase']], $names);
        // The key the move handed out is the one the feed it made is stored under.
        $hash = self::$db->query('SELECT key_hash FROM 202_offline_conversion_feeds WHERE feed_id = ' . $moved['feed']->feedId)->fetch_row()[0];
        self::assertSame(hash('sha256', (string) $moved['key'], true), $hash);

        $after = $fetches->forAccount(self::OWNER, self::NOW)[0];
        self::assertSame(['moved', 'Purchase', $moved['feed']->feedId], [$after['state'], $after['stored_name'], $after['feed']?->feedId]);

        // Moved again, nothing is made twice and no key is handed out.
        $again = $fetches->move(self::OWNER, $id, self::NOW);
        self::assertNotNull($again);
        self::assertNull($again['key']);
        self::assertSame($moved['feed']->feedId, $again['feed']->feedId);
        self::assertCount(1, (new FeedStore(self::$conn))->list(self::OWNER));
    }

    public function testAScheduleForEveryCampaignBecomesTheAccountsNameAndReplacesAnotherOnlyWhenMoved(): void
    {
        $fetches = self::fetches();
        (new ConversionNames(self::$conn))->save(self::OWNER, [ConversionNames::GOOGLE => [0 => 'Sign-up']], self::NOW);
        $fetches->record(['campaign_id' => 0, 'conversion_name' => 'Lead'], self::NOW - 300);

        $listed = $fetches->forAccount(self::OWNER, self::NOW)[0];
        self::assertSame(['ready', 'Sign-up'], [$listed['state'], $listed['stored_name']]);
        self::assertSame('Sign-up', (new ConversionNames(self::$conn))->read(self::OWNER)[ConversionNames::GOOGLE]['default']);

        self::assertNotNull($fetches->move(self::OWNER, $listed['fetch_id'], self::NOW));
        self::assertSame('Lead', (new ConversionNames(self::$conn))->read(self::OWNER)[ConversionNames::GOOGLE]['default']);
        // The other account, shown the same schedule, is untouched by this account's move.
        self::assertSame('ready', $fetches->forAccount(self::OTHER, self::NOW)[0]['state']);
        self::assertNull((new ConversionNames(self::$conn))->read(self::OTHER)[ConversionNames::GOOGLE]['default']);
    }

    public function testADeletedCampaignsScheduleIsNotMovedAndHidingIsTheAccountsOwn(): void
    {
        $fetches = self::fetches();
        $fetches->record(['campaign_id' => self::DELETED, 'conversion_name' => 'Sale'], self::NOW - 300);
        $fetches->record(['campaign_id' => self::STORE, 'conversion_name' => 'Purchase'], self::NOW - 200);
        [$store, $deleted] = array_column($fetches->forAccount(self::OWNER, self::NOW), 'fetch_id');

        self::assertNull($fetches->move(self::OWNER, $deleted, self::NOW));
        self::assertSame([], (new FeedStore(self::$conn))->list(self::OWNER));

        self::assertFalse($fetches->dismiss(self::OTHER, $store, self::NOW));
        self::assertCount(2, $fetches->forAccount(self::OWNER, self::NOW));
        self::assertTrue($fetches->dismiss(self::OWNER, $store, self::NOW));
        self::assertFalse($fetches->dismiss(self::OWNER, $store, self::NOW));
        self::assertSame(['Sale'], array_column($fetches->forAccount(self::OWNER, self::NOW), 'conversion_name'));
        // Fetched again, it is listed again.
        $fetches->record(['campaign_id' => self::STORE, 'conversion_name' => 'Purchase'], self::NOW);
        self::assertCount(2, $fetches->forAccount(self::OWNER, self::NOW));
    }
}
