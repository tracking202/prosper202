<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\AffNetworksController;
use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\LandingPagesController;
use Api\V3\Controllers\PpcAccountsController;
use Api\V3\Controllers\PpcNetworksController;
use Api\V3\Controllers\RotatorsController;
use Api\V3\Controllers\TextAdsController;
use Api\V3\Controllers\TrackersController;
use Api\V3\Controllers\UsersController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * `q`, `sort` / `sort_dir` and multi-value filters on the v3 lists, against
 * a real database: each list answers the records the request names, in the
 * order it names, and refuses what it cannot apply.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 5181 and 5182's rows, and
 * users named ev2w_*.
 *
 * @group integration
 */
final class ListSearchSortIntegrationTest extends TestCase
{
    private const USER = 5181;
    private const OTHER = 5182;

    private const TABLES = [
        '202_trackers', '202_text_ads', '202_landing_pages', '202_aff_campaigns', '202_aff_networks',
        '202_ppc_accounts', '202_ppc_networks', '202_rotators',
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
        foreach (self::TABLES as $table) {
            $sql = "DELETE FROM $table WHERE user_id IN (" . self::USER . ', ' . self::OTHER . ')';
            self::assertTrue(self::$db->query($sql), (string) self::$db->error);
        }
        $sql = "DELETE FROM 202_users WHERE user_name LIKE 'ev2w!_%' ESCAPE '!'";
        self::assertTrue(self::$db->query($sql), (string) self::$db->error);
    }

    private function exec(string $sql): int
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);

        return (int) self::$db->insert_id;
    }

    private function network(int $user, string $name = 'net'): int
    {
        $created = (new AffNetworksController(self::$db, $user))->create(['aff_network_name' => $name]);

        return (int) $created['data']['aff_network_id'];
    }

    private function campaign(int $user, int $network, string $name, float $payout = 1.0): int
    {
        return (int) (new CampaignsController(self::$db, $user))->create([
            'aff_campaign_name' => $name,
            'aff_campaign_url' => 'https://o.example',
            'aff_campaign_payout' => $payout,
            'aff_network_id' => $network,
        ])['data']['aff_campaign_id'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private static function ids(array $rows, string $key): array
    {
        return array_map('intval', array_column($rows, $key));
    }

    /** @param array<string, mixed> $params */
    private static function refusedOn(callable $list, array $params, string $field): void
    {
        try {
            $list($params);
            self::fail('the list answered ' . json_encode($params) . ' without applying it');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFieldErrors(), json_encode($e->getFieldErrors()));
        }
    }

    public function testCampaignsAreSearchedSortedAndFilteredByAnyOfSeveralNetworks(): void
    {
        $a = $this->network(self::USER, 'A');
        $b = $this->network(self::USER, 'B');
        $alpha = $this->campaign(self::USER, $a, 'Alpha Offer', 2.0);
        $beta = $this->campaign(self::USER, $a, 'beta offer', 2.0);
        $gamma = $this->campaign(self::USER, $a, 'Gamma 100%_x', 3.0);
        $delta = $this->campaign(self::USER, $b, 'Delta', 1.0);
        $this->campaign(self::OTHER, $this->network(self::OTHER), 'Alpha Offer of another account');
        $campaigns = new CampaignsController(self::$db, self::USER);
        $list = fn(array $p): array => self::ids($campaigns->list($p)['data'], 'aff_campaign_id');

        self::assertEqualsCanonicalizing(
            [$alpha, $beta],
            $list(['q' => 'OFFER']),
            'q matches in any case, in this account only'
        );
        self::assertSame([$alpha], $list(['q' => 'alpha']));
        self::assertSame([$gamma], $list(['q' => '%']), 'a % in q is a percent sign, not a wildcard');
        self::assertSame([$gamma], $list(['q' => '0%_']), 'nor is an underscore');
        self::assertSame([], $list(['q' => 'zzz']));
        $total = $campaigns->list(['q' => 'offer'])['pagination']['total'];
        self::assertSame(2, $total, 'the total counts what q matched');

        $byName = ['sort' => 'aff_campaign_name'];
        self::assertSame([$alpha, $beta, $delta, $gamma], $list($byName), 'ascending by default');
        self::assertSame([$gamma, $delta, $beta, $alpha], $list($byName + ['sort_dir' => 'DESC']));
        self::assertSame([$gamma, $delta, $beta, $alpha], $list($byName + ['sort_dir' => 'desc']));
        $newestFirst = 'without sort, the list keeps its newest-first order';
        self::assertSame([$delta, $gamma, $beta, $alpha], $list([]), $newestFirst);

        // Two campaigns pay 2.00: the primary key orders them, so paging one
        // row at a time answers each campaign once.
        $paged = [];
        for ($offset = 0; $offset < 4; $offset++) {
            $page = $list(['sort' => 'aff_campaign_payout', 'limit' => '1', 'offset' => (string) $offset]);
            $paged = [...$paged, ...$page];
        }
        self::assertSame([$delta, $alpha, $beta, $gamma], $paged);
        self::assertSame(
            [$gamma, $beta, $alpha, $delta],
            $list(['sort' => 'aff_campaign_payout', 'sort_dir' => 'desc']),
            'ties follow the key in the sort\'s direction'
        );

        $bothNetworks = ['filter' => ['aff_network_id' => "$a,$b"]];
        self::assertEqualsCanonicalizing([$alpha, $beta, $gamma, $delta], $list($bothNetworks));
        self::assertSame([$delta], $list(['filter' => ['aff_network_id' => (string) $b]]));
        $someIds = ['filter' => ['aff_campaign_id' => "$alpha,$gamma,999999"]];
        self::assertEqualsCanonicalizing([$alpha, $gamma], $list($someIds));
        $combined = $bothNetworks + ['q' => 'BETA', 'sort' => 'aff_campaign_name'];
        self::assertSame([$beta], $list($combined), 'the three combine');
        $twoNames = ['filter' => ['aff_campaign_name' => 'Alpha Offer,beta offer']];
        self::assertSame([], $list($twoNames), 'a text filter is one value, commas and all');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedRequests(): iterable
    {
        yield 'a sort outside the list' => [['sort' => 'aff_campaign_url'], 'sort'];
        yield 'a sort that is no field' => [['sort' => 'clicks'], 'sort'];
        yield 'a sort given as a list' => [['sort' => ['aff_campaign_name']], 'sort'];
        yield 'a direction that is neither' => [['sort' => 'aff_campaign_name', 'sort_dir' => 'up'], 'sort_dir'];
        yield 'a direction with no sort' => [['sort_dir' => 'asc'], 'sort_dir'];
        yield 'a q given as a list' => [['q' => ['a', 'b']], 'q'];
        yield 'a q past its length' => [['q' => str_repeat('a', 256)], 'q'];
        yield 'a q that is not UTF-8' => [['q' => "\xff"], 'q'];
        yield 'an empty item in a filter' => [['filter' => ['aff_network_id' => '1,,2']], 'filter[aff_network_id]'];
        yield 'a trailing comma in a filter' => [['filter' => ['aff_network_id' => '1,']], 'filter[aff_network_id]'];
        yield 'a space in a filter' => [['filter' => ['aff_network_id' => '1, 2']], 'filter[aff_network_id]'];
        yield 'text in a filter' => [['filter' => ['aff_network_id' => '1,b']], 'filter[aff_network_id]'];
        yield 'too many values' => [
            ['filter' => ['aff_network_id' => implode(',', range(1, 101))]],
            'filter[aff_network_id]',
        ];
        yield 'several numbers in a decimal field' => [
            ['filter' => ['aff_campaign_payout' => '1,2']],
            'filter[aff_campaign_payout]',
        ];
    }

    /**
     * @dataProvider refusedRequests
     * @param array<string, mixed> $params
     */
    public function testARequestTheListCannotApplyIsRefusedNamingIt(array $params, string $field): void
    {
        $campaigns = new CampaignsController(self::$db, self::USER);
        self::refusedOn(fn(array $p) => $campaigns->list($p), $params, $field);
    }

    /**
     * Each list: q finds the record whose name holds it, sort orders by its
     * name both ways, the key filters by several values, and a sort it does
     * not offer is refused.
     *
     * @param callable(array<string, mixed>): array{data: list<array<string, mixed>>} $list
     */
    private static function assertListAnswers(callable $list, string $key, int $alpha, int $bravo, string $sort): void
    {
        $ids = fn(array $p): array => self::ids($list($p)['data'], $key);
        self::assertSame([$alpha], $ids(['q' => 'ALPHA']), "$key: q in any case");
        self::assertEqualsCanonicalizing([$alpha, $bravo], $ids(['q' => 'ev2w']));
        self::assertSame([$alpha, $bravo], $ids(['q' => 'ev2w', 'sort' => $sort]), "$key: sort=$sort");
        $desc = ['q' => 'ev2w', 'sort' => $sort, 'sort_dir' => 'desc'];
        self::assertSame([$bravo, $alpha], $ids($desc), "$key: sort=$sort desc");
        $both = ['filter' => [$key => "$alpha,$bravo"]];
        self::assertEqualsCanonicalizing([$alpha, $bravo], $ids($both), "$key: several values");
        self::assertSame([$bravo], $ids(['filter' => [$key => (string) $bravo]]));
        self::refusedOn($list, ['sort' => 'not_a_field'], 'sort');
        self::refusedOn($list, ['filter' => [$key => "$alpha,"]], 'filter[' . $key . ']');
    }

    public function testEveryOtherListAnswersQSortAndSeveralValues(): void
    {
        $affNetworks = new AffNetworksController(self::$db, self::USER);
        $alpha = $this->network(self::USER, 'EV2W alpha');
        $bravo = $this->network(self::USER, 'ev2w Bravo');
        $this->network(self::OTHER, 'ev2w alpha of another account');
        self::assertListAnswers(
            fn(array $p) => $affNetworks->list($p),
            'aff_network_id',
            $alpha,
            $bravo,
            'aff_network_name'
        );

        $ppcNetworks = new PpcNetworksController(self::$db, self::USER);
        $pNetwork = fn(string $name): int
            => (int) $ppcNetworks->create(['ppc_network_name' => $name])['data']['ppc_network_id'];
        $pAlpha = $pNetwork('EV2W alpha');
        $pBravo = $pNetwork('ev2w Bravo');
        self::assertListAnswers(
            fn(array $p) => $ppcNetworks->list($p),
            'ppc_network_id',
            $pAlpha,
            $pBravo,
            'ppc_network_name'
        );

        $accounts = new PpcAccountsController(self::$db, self::USER);
        $account = fn(string $name): int => (int) $accounts->create(
            ['ppc_account_name' => $name, 'ppc_network_id' => $pAlpha]
        )['data']['ppc_account_id'];
        $aAlpha = $account('EV2W alpha');
        $aBravo = $account('ev2w Bravo');
        self::assertListAnswers(
            fn(array $p) => $accounts->list($p),
            'ppc_account_id',
            $aAlpha,
            $aBravo,
            'ppc_account_name'
        );

        $cAlpha = $this->campaign(self::USER, $alpha, 'EV2W alpha');
        $cBravo = $this->campaign(self::USER, $alpha, 'ev2w Bravo');
        $foreign = $this->campaign(self::OTHER, $this->network(self::OTHER), 'ev2w alpha of another account');

        $pages = new LandingPagesController(self::$db, self::USER);
        $page = fn(string $url, string $name): int => (int) $pages->create([
            'landing_page_url' => $url,
            'aff_campaign_id' => $cAlpha,
            'landing_page_nickname' => $name,
        ])['data']['landing_page_id'];
        $lAlpha = $page('https://lp.example/a', 'EV2W alpha');
        $lBravo = $page('https://lp.example/b', 'ev2w Bravo');
        self::assertListAnswers(
            fn(array $p) => $pages->list($p),
            'landing_page_id',
            $lAlpha,
            $lBravo,
            'landing_page_nickname'
        );

        $ads = new TextAdsController(self::$db, self::USER);
        $ad = ['text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'ad.example'];
        $tAlpha = (int) $ads->create(['text_ad_name' => 'EV2W alpha'] + $ad)['data']['text_ad_id'];
        $tBravo = (int) $ads->create(['text_ad_name' => 'ev2w Bravo'] + $ad)['data']['text_ad_id'];
        self::assertListAnswers(fn(array $p) => $ads->list($p), 'text_ad_id', $tAlpha, $tBravo, 'text_ad_name');

        // A tracker is found by its campaign's name, in this account only.
        $trackers = new TrackersController(self::$db, self::USER);
        $kAlpha = (int) $trackers->create(['aff_campaign_id' => $cAlpha])['data']['tracker_id'];
        $kBravo = (int) $trackers->create(['aff_campaign_id' => $cBravo])['data']['tracker_id'];
        self::assertListAnswers(
            fn(array $p) => $trackers->list($p),
            'tracker_id',
            $kAlpha,
            $kBravo,
            'aff_campaign_id'
        );
        // A tracker row naming another account's campaign is not found by
        // that campaign's name: the name is not this account's to search.
        $this->exec(
            'INSERT INTO 202_trackers SET user_id = ' . self::USER . ", aff_campaign_id = $foreign,"
            . ' ppc_account_id = 0, text_ad_id = 0, landing_page_id = 0, rotator_id = 0, click_cpc = 0,'
            . ' click_cloaking = -1, tracker_id_public = 1, tracker_time = 0'
        );
        self::assertSame([], self::ids($trackers->list(['q' => 'another account'])['data'], 'tracker_id'));

        $rotator = fn(int $publicId, int $user, string $name): int => $this->exec(
            "INSERT INTO 202_rotators SET public_id = $publicId, user_id = $user, name = '$name'"
        );
        $rAlpha = $rotator(1, self::USER, 'EV2W alpha');
        $rBravo = $rotator(2, self::USER, 'ev2w Bravo');
        $rotator(3, self::OTHER, 'ev2w alpha of another account');
        $rotators = new RotatorsController(self::$db, self::USER);
        self::assertListAnswers(fn(array $p) => $rotators->list($p), 'id', $rAlpha, $rBravo, 'name');
        self::assertSame(2, $rotators->list(['q' => 'ev2w'])['pagination']['total']);
        $otherOwner = ['filter' => ['user_id' => (string) self::OTHER]];
        self::refusedOn(fn(array $p) => $rotators->list($p), $otherOwner, 'filter[user_id]');

        $user = fn(string $name, string $email): int => $this->exec(
            "INSERT INTO 202_users SET user_name = '$name', user_pass = '', user_email = '$email',"
            . " user_dash_email = '', user_time_register = 0, install_hash = '', user_hash = ''"
        );
        $uAlpha = $user('ev2w_Alpha', 'a@ev2w.example');
        $uBravo = $user('ev2w_bravo', 'b@ev2w.example');
        $users = new UsersController(self::$db);
        self::assertListAnswers(fn(array $p) => $users->list($p), 'user_id', $uAlpha, $uBravo, 'user_name');
        $byEmail = self::ids($users->list(['q' => 'B@EV2W'])['data'], 'user_id');
        self::assertSame([$uBravo], $byEmail, 'q matches the email too');
    }

    public function testAListWithNoTextToSearchRefusesQAndSortsOnlyByItsKey(): void
    {
        $apps = new \Api\V3\Controllers\AppRegistrationsController(self::$db, self::USER);
        self::refusedOn(fn(array $p) => $apps->list($p), ['q' => 'a'], 'q');
        self::refusedOn(fn(array $p) => $apps->list($p), ['sort' => 'app_name'], 'sort');
        $byKey = ['sort' => 'registration_id', 'sort_dir' => 'asc'];
        $byKey['filter'] = ['registration_id' => '1,2'];
        self::assertSame([], $apps->list($byKey)['data']);
    }
}
