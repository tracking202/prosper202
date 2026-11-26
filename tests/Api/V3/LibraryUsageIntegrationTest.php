<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\AffNetworksController;
use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\LandingPagesController;
use Api\V3\Controllers\PpcAccountsController;
use Api\V3\Controllers\PpcNetworksController;
use Api\V3\Controllers\TextAdsController;
use Api\V3\Controllers\TrackersController;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\LandingPageLastSeen;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;

/**
 * Where Library records are used (LibraryUsage), against a real database:
 * each list's `?with=usage` counts, `GET /{id}/uses` and a delete preview's
 * `in_use_by` name the account's live records pointing at a record, and
 * nothing of another account's or anything deleted.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 6191 and 6192's rows.
 *
 * @group integration
 */
final class LibraryUsageIntegrationTest extends TestCase
{
    private const USER = 6191;
    private const OTHER = 6192;

    private const TABLES = [
        '202_trackers', '202_text_ads', '202_landing_pages', '202_aff_campaigns', '202_aff_networks',
        '202_ppc_accounts', '202_ppc_networks', '202_rotators', '202_ad_connections', '202_landing_page_checks',
        '202_landing_page_last_seen',
    ];

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null)'
                . ' { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        $users = self::USER . ', ' . self::OTHER;
        $this->exec("DELETE d FROM 202_rotator_rules_redirects AS d JOIN 202_rotator_rules AS ru ON ru.id = d.rule_id"
            . " JOIN 202_rotators AS r ON r.id = ru.rotator_id WHERE r.user_id IN ($users)");
        $this->exec("DELETE ru FROM 202_rotator_rules AS ru JOIN 202_rotators AS r ON r.id = ru.rotator_id"
            . " WHERE r.user_id IN ($users)");
        foreach (self::TABLES as $table) {
            $this->exec("DELETE FROM $table WHERE user_id IN ($users)");
        }
    }

    private function exec(string $sql): int
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);

        return (int) self::$db->insert_id;
    }

    private function network(int $user, string $name = 'net'): int
    {
        return (int) (new AffNetworksController(self::$db, $user))
            ->create(['aff_network_name' => $name])['data']['aff_network_id'];
    }

    private function campaign(int $user, int $network, string $name, int $deleted = 0): int
    {
        $id = (int) (new CampaignsController(self::$db, $user))->create([
            'aff_campaign_name' => $name,
            'aff_campaign_url' => 'https://o.example',
            'aff_campaign_payout' => 1.0,
            'aff_network_id' => $network,
        ])['data']['aff_campaign_id'];
        if ($deleted === 1) {
            $this->exec("UPDATE 202_aff_campaigns SET aff_campaign_deleted = 1 WHERE aff_campaign_id = $id");
        }

        return $id;
    }

    private function landingPage(int $user, int $campaign, string $name, int $deleted = 0): int
    {
        return $this->exec('INSERT INTO 202_landing_pages (user_id, aff_campaign_id, landing_page_nickname,'
            . ' landing_page_url, landing_page_deleted, landing_page_time)'
            . " VALUES ($user, $campaign, '$name', 'https://lp.example/$name', $deleted, 1)");
    }

    private function textAd(int $user, int $campaign, int $landingPage, string $name, int $deleted = 0): int
    {
        return $this->exec('INSERT INTO 202_text_ads (user_id, aff_campaign_id, landing_page_id, text_ad_deleted,'
            . ' text_ad_name, text_ad_headline, text_ad_description, text_ad_display_url, text_ad_time)'
            . " VALUES ($user, $campaign, $landingPage, $deleted, '$name', 'h', 'd', 'u', 1)");
    }

    private function ppcNetwork(int $user): int
    {
        return (int) (new PpcNetworksController(self::$db, $user))
            ->create(['ppc_network_name' => 'src'])['data']['ppc_network_id'];
    }

    private function ppcAccount(int $user, int $network, string $name, int $deleted = 0): int
    {
        return $this->exec('INSERT INTO 202_ppc_accounts (user_id, ppc_network_id, ppc_account_name,'
            . " ppc_account_deleted, ppc_account_time) VALUES ($user, $network, '$name', $deleted, 1)");
    }

    private function tracker(int $user, int $campaign, int $account = 0, int $landingPage = 0, int $textAd = 0): int
    {
        static $public = 61910000;
        $public++;

        return $this->exec('INSERT INTO 202_trackers (user_id, tracker_id_public, aff_campaign_id, text_ad_id,'
            . ' ppc_account_id, landing_page_id, click_cloaking, tracker_time)'
            . " VALUES ($user, $public, $campaign, $textAd, $account, $landingPage, 0, 1)");
    }

    private function rotator(int $user, string $name, ?int $defaultCampaign = null, ?int $defaultLp = null): int
    {
        static $public = 61920000;
        $public++;
        $campaign = $defaultCampaign ?? 'NULL';
        $lp = $defaultLp ?? 'NULL';

        return $this->exec('INSERT INTO 202_rotators (public_id, user_id, name, default_campaign, default_lp)'
            . " VALUES ($public, $user, '$name', $campaign, $lp)");
    }

    private function redirect(int $rotator, ?int $campaign = null, ?int $lp = null): void
    {
        $rule = $this->exec("INSERT INTO 202_rotator_rules (rotator_id, rule_name) VALUES ($rotator, 'r')");
        $this->exec('INSERT INTO 202_rotator_rules_redirects (rule_id, redirect_campaign, redirect_lp, name)'
            . " VALUES ($rule, " . ($campaign ?? 'NULL') . ', ' . ($lp ?? 'NULL') . ", 'to')");
    }

    private function adConnection(int $user, int $account, string $name, ?int $deletedAt = null): int
    {
        return $this->exec('INSERT INTO 202_ad_connections (user_id, platform, ppc_account_id, account_ref, name,'
            . ' currency, timezone, credentials, setup, status, created_at, updated_at, deleted_at)'
            . " VALUES ($user, 'meta', $account, 'ref-$name', '$name', 'USD', 'UTC', '', '', 'ok', 1, 1, "
            . ($deletedAt ?? 'NULL') . ')');
    }

    /**
     * @param array{data: list<array<string, mixed>>} $list
     * @return array<int, mixed> id => the row's key
     */
    private static function byId(array $list, string $idColumn, string $key): array
    {
        $out = [];
        foreach ($list['data'] as $row) {
            $out[(int) $row[$idColumn]] = $row[$key] ?? null;
        }

        return $out;
    }

    public function testAnOffersUsesAreCountedAndNamedAcrossEveryKind(): void
    {
        $net = $this->network(self::USER);
        $offer = $this->campaign(self::USER, $net, 'Offer A');
        $idle = $this->campaign(self::USER, $net, 'Idle');
        $lp = $this->landingPage(self::USER, $offer, 'lpA');
        $this->landingPage(self::USER, $offer, 'lpGone', 1);
        $ad = $this->textAd(self::USER, $offer, $lp, 'adA');
        $this->textAd(self::USER, $offer, $lp, 'adGone', 1);
        $t1 = $this->tracker(self::USER, $offer);
        $t2 = $this->tracker(self::USER, $offer, 0, $lp);
        // One rotator by default and in two redirects counts once; one by a redirect alone.
        $both = $this->rotator(self::USER, 'both', $offer);
        $this->redirect($both, $offer);
        $this->redirect($both, $offer);
        $byRule = $this->rotator(self::USER, 'byRule');
        $this->redirect($byRule, $offer);
        $this->rotator(self::USER, 'elsewhere', $idle);

        $campaigns = new CampaignsController(self::$db, self::USER);
        $usage = self::byId($campaigns->list(['with' => 'usage']), 'aff_campaign_id', 'usage');
        self::assertSame(
            ['trackers' => 2, 'landing_pages' => 1, 'text_ads' => 1, 'rotators' => 2, 'total' => 6],
            $usage[$offer]
        );
        self::assertSame(
            ['trackers' => 0, 'landing_pages' => 0, 'text_ads' => 0, 'rotators' => 1, 'total' => 1],
            $usage[$idle]
        );

        $uses = $campaigns->uses($offer)['data'];
        self::assertSame(
            ['resource' => 'campaigns', 'id' => $offer, 'total' => 6],
            array_diff_key($uses, ['uses' => 1])
        );
        self::assertSame([
            'trackers' => ['count' => 2, 'records' => [
                ['id' => $t1, 'name' => null, 'path' => "/trackers/$t1"],
                ['id' => $t2, 'name' => null, 'path' => "/trackers/$t2"],
            ]],
            'landing_pages' => ['count' => 1, 'records' => [
                ['id' => $lp, 'name' => 'lpA', 'path' => "/landing-pages/$lp"],
            ]],
            'text_ads' => ['count' => 1, 'records' => [['id' => $ad, 'name' => 'adA', 'path' => "/text-ads/$ad"]]],
            'rotators' => ['count' => 2, 'records' => [
                ['id' => $both, 'name' => 'both', 'path' => "/rotators/$both"],
                ['id' => $byRule, 'name' => 'byRule', 'path' => "/rotators/$byRule"],
            ]],
        ], $uses['uses']);

        $preview = $campaigns->deletePreview($offer)['data'];
        self::assertSame($uses['uses'], $preview['in_use_by']);
        self::assertSame('soft', $preview['mode']);
    }

    public function testAnotherAccountsRecordsNeverCount(): void
    {
        $net = $this->network(self::USER);
        $offer = $this->campaign(self::USER, $net, 'Mine');
        $lp = $this->landingPage(self::USER, $offer, 'mine');
        $theirNet = $this->network(self::OTHER);
        $theirs = $this->campaign(self::OTHER, $theirNet, 'Theirs');
        // Rows of another account naming this account's ids, as a forged or stale row would.
        $this->tracker(self::OTHER, $offer, 0, $lp);
        $this->landingPage(self::OTHER, $offer, 'theirs');
        $this->textAd(self::OTHER, $offer, $lp, 'theirs');
        $other = $this->rotator(self::OTHER, 'theirs', $offer, $lp);
        $this->redirect($other, $offer, $lp);
        // This account's rotator with a rule row: still counts once.
        $mine = $this->rotator(self::USER, 'mine');
        $this->redirect($mine, null, $lp);

        $campaigns = new CampaignsController(self::$db, self::USER);
        self::assertSame(
            ['trackers' => 0, 'landing_pages' => 1, 'text_ads' => 0, 'rotators' => 0, 'total' => 1],
            self::byId($campaigns->list(['with' => 'usage']), 'aff_campaign_id', 'usage')[$offer]
        );
        $lps = new LandingPagesController(self::$db, self::USER);
        self::assertSame(
            ['trackers' => 0, 'text_ads' => 0, 'rotators' => 1, 'total' => 1],
            self::byId($lps->list(['with' => 'usage']), 'landing_page_id', 'usage')[$lp]
        );
        $named = $campaigns->uses($offer)['data']['uses'];
        self::assertSame(
            [['id' => $lp, 'name' => 'mine', 'path' => "/landing-pages/$lp"]],
            $named['landing_pages']['records']
        );
        self::assertSame([], $named['trackers']['records']);
        self::assertSame([], $named['rotators']['records']);
        self::assertSame(
            [['id' => $mine, 'name' => 'mine', 'path' => "/rotators/$mine"]],
            $lps->uses($lp)['data']['uses']['rotators']['records']
        );
        // Another account's record is not this account's to ask about.
        $this->expectException(NotFoundException::class);
        $campaigns->uses($theirs);
    }

    public function testNetworksSourcesAndTextAdsCountTheirOwnKinds(): void
    {
        $net = $this->network(self::USER);
        $offer = $this->campaign(self::USER, $net, 'o1');
        $this->campaign(self::USER, $net, 'o2');
        $this->campaign(self::USER, $net, 'gone', 1);
        $src = $this->ppcNetwork(self::USER);
        $acct = $this->ppcAccount(self::USER, $src, 'acct');
        $this->ppcAccount(self::USER, $src, 'acctGone', 1);
        $this->tracker(self::USER, $offer, $acct);
        $this->adConnection(self::USER, $acct, 'live');
        $this->adConnection(self::USER, $acct, 'gone', 5);
        $lp = $this->landingPage(self::USER, $offer, 'lp');
        $ad = $this->textAd(self::USER, $offer, $lp, 'ad');
        $this->tracker(self::USER, $offer, $acct, $lp, $ad);

        self::assertSame(['campaigns' => 2, 'total' => 2], self::byId(
            (new AffNetworksController(self::$db, self::USER))->list(['with' => 'usage']),
            'aff_network_id',
            'usage'
        )[$net]);
        self::assertSame(['ppc_accounts' => 1, 'total' => 1], self::byId(
            (new PpcNetworksController(self::$db, self::USER))->list(['with' => 'usage']),
            'ppc_network_id',
            'usage'
        )[$src]);
        self::assertSame(['trackers' => 2, 'ad_connections' => 1, 'total' => 3], self::byId(
            (new PpcAccountsController(self::$db, self::USER))->list(['with' => 'usage']),
            'ppc_account_id',
            'usage'
        )[$acct]);
        self::assertSame(['trackers' => 1, 'total' => 1], self::byId(
            (new TextAdsController(self::$db, self::USER))->list(['with' => 'usage']),
            'text_ad_id',
            'usage'
        )[$ad]);
    }

    public function testALandingPagesHealthIsItsCheckOfItsPresentUrl(): void
    {
        $net = $this->network(self::USER);
        $offer = $this->campaign(self::USER, $net, 'o');
        $found = $this->landingPage(self::USER, $offer, 'found');
        $moved = $this->landingPage(self::USER, $offer, 'moved');
        $never = $this->landingPage(self::USER, $offer, 'never');
        $this->exec('INSERT INTO 202_landing_page_checks (landing_page_id, user_id, checked_url, check_status,'
            . ' check_message, checked_at) VALUES'
            . " ($found, " . self::USER . ", 'https://lp.example/found', 'present', 'ok', 100),"
            . " ($moved, " . self::USER . ", 'https://lp.example/before', 'absent', 'no code', 90)");

        $list = (new LandingPagesController(self::$db, self::USER))->list(['with' => 'health,usage']);
        $health = self::byId($list, 'landing_page_id', 'health');
        self::assertSame(
            ['status' => 'present', 'level' => 'good', 'label' => 'Code found', 'message' => 'ok', 'checked_at' => 100],
            $health[$found]
        );
        // A check of the URL the page had before says nothing of the one it has now.
        $unchecked = [
            'status' => 'unchecked', 'level' => 'unchecked', 'label' => 'Not checked yet',
            'message' => null, 'checked_at' => null,
        ];
        self::assertSame($unchecked, $health[$moved]);
        self::assertSame($unchecked, $health[$never]);
        self::assertArrayHasKey('usage', $list['data'][0]);

        $plain = (new LandingPagesController(self::$db, self::USER))->list([]);
        self::assertArrayNotHasKey('health', $plain['data'][0]);
        self::assertArrayNotHasKey('usage', $plain['data'][0]);
    }

    /**
     * `with=last_seen` answers when each page last had a click as the click
     * writer stored it, null for a page with none, and never another
     * account's time for a page of the same id range.
     */
    public function testALandingPagesLastSeenIsItsLatestStoredClick(): void
    {
        $net = $this->network(self::USER);
        $offer = $this->campaign(self::USER, $net, 'o');
        $busy = $this->landingPage(self::USER, $offer, 'busy');
        $quiet = $this->landingPage(self::USER, $offer, 'quiet');
        $theirOffer = $this->campaign(self::OTHER, $this->network(self::OTHER), 't');
        $theirs = $this->landingPage(self::OTHER, $theirOffer, 'theirs');
        $seen = new LandingPageLastSeen(new Connection(self::$db));
        self::assertTrue($seen->stamp(self::USER, $busy, 1_700_000_000));
        self::assertTrue($seen->stamp(self::OTHER, $theirs, 1_700_000_500));
        // The click's account does not own the page: nothing is written for it.
        self::assertFalse($seen->stamp(self::USER, $theirs, 1_700_000_900));
        // A row another account's click left for this page id answers nothing for this account.
        $this->exec('INSERT INTO 202_landing_page_last_seen (landing_page_id, user_id, last_click_at)'
            . " VALUES ($quiet, " . self::OTHER . ', 1700000700)');

        $list = (new LandingPagesController(self::$db, self::USER))->list(['with' => 'last_seen']);
        $times = self::byId($list, 'landing_page_id', 'last_seen_at');
        ksort($times);
        self::assertSame([$busy => 1_700_000_000, $quiet => null], $times);
        foreach ($list['data'] as $row) {
            self::assertArrayHasKey('last_seen_at', $row);
            self::assertArrayNotHasKey('usage', $row);
        }
        $other = (new LandingPagesController(self::$db, self::OTHER))->list(['with' => 'last_seen,usage']);
        self::assertSame([$theirs => 1_700_000_500], self::byId($other, 'landing_page_id', 'last_seen_at'));

        $plain = (new LandingPagesController(self::$db, self::USER))->list([]);
        self::assertArrayNotHasKey('last_seen_at', $plain['data'][0]);
    }

    public function testNamedUsesStopAtTheirLimitAndCountTheRest(): void
    {
        $net = $this->network(self::USER);
        $offer = $this->campaign(self::USER, $net, 'busy');
        $values = [];
        for ($i = 1; $i <= 30; $i++) {
            $values[] = '(' . self::USER . ', ' . (61930000 + $i) . ", $offer, 0, 0, 0, 0, 1)";
        }
        $this->exec('INSERT INTO 202_trackers (user_id, tracker_id_public, aff_campaign_id, text_ad_id,'
            . ' ppc_account_id, landing_page_id, click_cloaking, tracker_time) VALUES ' . implode(', ', $values));

        $uses = (new CampaignsController(self::$db, self::USER))->uses($offer)['data']['uses']['trackers'];
        self::assertSame(30, $uses['count']);
        self::assertCount(25, $uses['records']);
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function refusedWith(): iterable
    {
        yield 'unknown' => ['campaigns', 'usage,clicks'];
        yield 'empty' => ['campaigns', ''];
        yield 'empty item' => ['campaigns', 'usage,'];
        yield 'repeated' => ['landing-pages', 'usage,usage'];
        yield 'health off a landing page' => ['campaigns', 'health'];
        yield 'last seen off a landing page' => ['campaigns', 'last_seen'];
        yield 'last seen as its field' => ['landing-pages', 'last_seen_at'];
        yield 'case' => ['campaigns', 'Usage'];
        yield 'list' => ['campaigns', ['usage']];
    }

    /** @dataProvider refusedWith */
    public function testAWithItCannotAnswerIsRefusedNamingWith(string $resource, mixed $with): void
    {
        $class = [
            'campaigns' => CampaignsController::class,
            'landing-pages' => LandingPagesController::class,
        ][$resource];
        try {
            (new $class(self::$db, self::USER))->list(['with' => $with]);
            self::fail('with=' . json_encode($with) . ' was answered');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('with', $e->getFieldErrors());
            self::assertStringStartsWith('A comma list of: usage', $e->getFieldErrors()['with']);
        }
    }

    public function testATrackerListKeepsWithAsItWas(): void
    {
        $net = $this->network(self::USER);
        $this->tracker(self::USER, $this->campaign(self::USER, $net, 'o'));
        $list = (new TrackersController(self::$db, self::USER))->list(['with' => 'usage']);
        self::assertArrayNotHasKey('usage', $list['data'][0]);
    }
}
