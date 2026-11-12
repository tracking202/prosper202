<?php

declare(strict_types=1);

namespace Tests\Setup;

use Api\V3\Controllers\SetupCodeController;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\TooManyRequestsException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\LandingPageCheckLimit;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Database\Connection;
use Prosper202\Setup\LandingPageCheck;
use Prosper202\Setup\LandingPageCheckResult as Result;
use Prosper202\Setup\LandingPageCheckStore;
use Prosper202\Setup\LandingPageCode;
use Prosper202\Setup\LandingPageUrlRepair;

/**
 * A landing page's code check is kept with the URL it fetched: the lists
 * read it back, a page whose URL changed reads as unchecked, another
 * account's page is not checked, and the cron job's pass takes the pages
 * longest unchecked. The 1.9.80 upgrade's URL repair is here too.
 *
 * @group integration
 */
final class LandingPageCheckStoreIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990451;
    private const OTHER = 990452;
    private const NOW = 1794300000;
    /**
     * The address every host resolves to here: a documentation one, which
     * the guard refuses unless the operator's allowlist names its network,
     * so each guard below is given ALLOW. Never connected to.
     */
    private const PUBLIC = '203.0.113.10';
    private const ALLOW = '203.0.113.0/24';

    /** @var list<string> the URLs fetched, in order */
    private array $fetched = [];

    /** The limits' store, in a directory of this test's own. */
    private string $state = '';

    protected function tearDown(): void
    {
        if ($this->state !== '') {
            exec('rm -rf ' . escapeshellarg($this->state));
            $this->state = '';
        }
    }

    private function limit(string $key = 'apikey:0123456789abcdef'): LandingPageCheckLimit
    {
        if ($this->state === '') {
            $this->state = sys_get_temp_dir() . '/p202-lp-check-limit-' . bin2hex(random_bytes(6));
        }

        return new LandingPageCheckLimit(new ServerStateStore($this->state), $key);
    }

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::user(self::USER);
        self::user(self::OTHER);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        foreach (['202_landing_page_checks', '202_landing_pages', '202_users_pref'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN (" . self::USER . ', ' . self::OTHER . ')');
        }
        $this->fetched = [];
    }

    private static function cleanUp(): void
    {
        foreach (['202_landing_page_checks', '202_landing_pages', '202_users_pref', '202_users'] as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id IN (" . self::USER . ', ' . self::OTHER . ')');
        }
    }

    private static function page(int $userId, string $url, int $publicId = 0): int
    {
        $id = self::insert("INSERT INTO 202_landing_pages SET user_id = $userId, aff_campaign_id = 0, landing_page_nickname = 'Spring sale',"
            . ' landing_page_url = ' . self::str($url) . ", leave_behind_page_url = '', landing_page_time = 0, landing_page_type = 0");
        self::q('UPDATE 202_landing_pages SET landing_page_id_public = ' . ($publicId ?: (int) ('7' . $id . '3')) . " WHERE landing_page_id = $id");

        return $id;
    }

    /** A store whose fetches answer $html for every URL, at a public address. */
    private function store(string $html, int $now = self::NOW): LandingPageCheckStore
    {
        $guard = new WebhookGuard(static fn (string $host): array => [self::PUBLIC], self::ALLOW);
        $transport = function (array $options) use ($html): array {
            $this->fetched[] = (string) $options[CURLOPT_URL];

            return ['status' => 200, 'error' => null, 'timed_out' => false, 'primary_ip' => self::PUBLIC,
                'redirect_url' => null, 'body' => $html, 'truncated' => false];
        };

        return new LandingPageCheckStore(new Connection(self::$db), new LandingPageCheck($guard, $transport), static fn (): int => $now);
    }

    private static function html(string $publicId): string
    {
        return '<html><body>' . LandingPageCode::loader('//track.example.com/', $publicId) . '</body></html>';
    }

    public function testACheckIsKeptAndReadBackForThePagesPresentUrl(): void
    {
        $id = self::page(self::USER, 'https://shop.example.com/spring');
        $store = $this->store(self::html('7' . $id . '3'));

        self::assertSame([], $store->forAccount(self::USER), 'nothing is stored before a check');
        $result = $store->run(self::USER, $id, 'track.example.com');
        self::assertSame(Result::PRESENT, $result?->status, (string) $result?->message);
        self::assertSame(['https://shop.example.com/spring'], $this->fetched);
        self::assertSame(
            [$id => ['status' => Result::PRESENT, 'message' => $result->message, 'checked_at' => self::NOW]],
            $store->forAccount(self::USER)
        );
        self::assertSame(Result::PRESENT, $store->forPage(self::USER, $id)['status'] ?? null);

        // A second check replaces the first.
        $absent = $this->store('<html><body>no code</body></html>', self::NOW + 60);
        self::assertSame(Result::ABSENT, $absent->run(self::USER, $id, 'track.example.com')?->status);
        self::assertSame([Result::ABSENT, self::NOW + 60], [$absent->forPage(self::USER, $id)['status'], $absent->forPage(self::USER, $id)['checked_at']]);

        // The URL changes: the stored answer was about another page.
        self::q("UPDATE 202_landing_pages SET landing_page_url = 'https://shop.example.com/summer' WHERE landing_page_id = $id");
        self::assertNull($absent->forPage(self::USER, $id), 'a result for another URL is not this page\'s');
        // Case counts in a URL's path.
        self::q("UPDATE 202_landing_pages SET landing_page_url = 'https://shop.example.com/Spring' WHERE landing_page_id = $id");
        self::assertNull($absent->forPage(self::USER, $id));
        // A removed page has none.
        self::q("UPDATE 202_landing_pages SET landing_page_url = 'https://shop.example.com/spring', landing_page_deleted = 1 WHERE landing_page_id = $id");
        self::assertSame([], $absent->forAccount(self::USER));
        self::assertNull($absent->run(self::USER, $id, ''), 'a removed page is not checked');
    }

    public function testAnotherAccountsPageIsNeitherCheckedNorRead(): void
    {
        $theirs = self::page(self::OTHER, 'https://store.example.net/offer');
        $store = $this->store(self::html('7' . $theirs . '3'));

        self::assertNull($store->run(self::USER, $theirs, ''));
        self::assertSame([], $this->fetched, 'nothing was fetched for a page that is not the account\'s');
        self::assertNotNull($store->run(self::OTHER, $theirs, ''));
        self::assertNull($store->forPage(self::USER, $theirs));
        self::assertSame([], $store->forAccount(self::USER));
    }

    public function testAFetchThatIsRefusedIsStoredAsAnError(): void
    {
        $id = self::page(self::USER, 'ftp://shop.example.com/spring');
        $store = $this->store('');
        self::assertSame(Result::ERROR, $store->run(self::USER, $id, '')?->status);
        self::assertSame([], $this->fetched);
        self::assertSame(Result::ERROR, $store->forPage(self::USER, $id)['status'] ?? null);
    }

    public function testTheCronPassChecksThePagesLongestUnchecked(): void
    {
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER . ", user_tracking_domain = 'https://Track.Example.com/'");
        $fresh = self::page(self::USER, 'https://shop.example.com/fresh');
        $old = self::page(self::USER, 'https://shop.example.com/old');
        $never = self::page(self::USER, 'https://shop.example.com/never');
        $moved = self::page(self::USER, 'https://shop.example.com/moved');
        $gone = self::page(self::USER, 'https://shop.example.com/gone');
        self::q("UPDATE 202_landing_pages SET landing_page_deleted = 1 WHERE landing_page_id = $gone");
        $day = LandingPageCheckStore::RECHECK_SECONDS;
        foreach ([[$fresh, 'https://shop.example.com/fresh', self::NOW - 60], [$old, 'https://shop.example.com/old', self::NOW - $day - 1],
            [$moved, 'https://shop.example.com/before', self::NOW - 30]] as [$page, $url, $at]) {
            self::q('INSERT INTO 202_landing_page_checks SET landing_page_id = ' . $page . ', user_id = ' . self::USER
                . ', checked_url = ' . self::str($url) . ", check_status = 'absent', check_message = 'x', checked_at = $at");
        }

        // The code loads from the account's saved tracking domain, so the host is judged and passes.
        $store = $this->store('<html><body>'
            . '<script src="https://track.example.com/tracking202/static/landing.php?lpip=7' . $old . '3"></script>'
            . '</body></html>');
        $report = $store->recheckDue(self::NOW + 30);

        self::assertSame(['checked' => 3, 'failed' => 0], $report);
        sort($this->fetched);
        self::assertSame(['https://shop.example.com/moved', 'https://shop.example.com/never', 'https://shop.example.com/old'], $this->fetched);
        $stored = $store->forAccount(self::USER);
        self::assertSame(Result::PRESENT, $stored[$old]['status'], $stored[$old]['message']);
        self::assertSame(self::NOW - 60, $stored[$fresh]['checked_at'], 'a page checked within the day is left');
        self::assertSame(self::NOW, $stored[$never]['checked_at']);
        self::assertSame(self::NOW, $stored[$moved]['checked_at']);

        // Nothing is due straight after.
        $this->fetched = [];
        self::assertSame(['checked' => 0, 'failed' => 0], $store->recheckDue(self::NOW + 30));
        self::assertSame([], $this->fetched);

        // Past its deadline the pass checks nothing.
        self::q('DELETE FROM 202_landing_page_checks WHERE user_id = ' . self::USER);
        self::assertSame(['checked' => 0, 'failed' => 0], $store->recheckDue(self::NOW - 1));
    }

    public function testTheCronPassIsBoundedAndLeavesASwitchedOffUsersPages(): void
    {
        for ($i = 0; $i < LandingPageCheckStore::PAGES_PER_RUN + 2; $i++) {
            self::page(self::USER, 'https://shop.example.com/p' . $i);
        }
        self::page(self::OTHER, 'https://store.example.net/offer');
        self::q('UPDATE 202_users SET user_active = 0 WHERE user_id = ' . self::OTHER);
        try {
            $store = $this->store('');
            self::assertSame(LandingPageCheckStore::PAGES_PER_RUN, $store->recheckDue(self::NOW + 30)['checked']);
            self::assertSame(2, $store->recheckDue(self::NOW + 30)['checked'], 'the rest have their turn next');
            self::assertNotContains('https://store.example.net/offer', $this->fetched);
        } finally {
            self::q('UPDATE 202_users SET user_active = 1 WHERE user_id = ' . self::OTHER);
        }
    }

    /** POST /landing-pages/{id}/check: the same check, kept, as the API answers it. */
    public function testTheApiChecksKeepsAndAnswers(): void
    {
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER . ", user_tracking_domain = 'https://track.example.com'");
        $id = self::page(self::USER, 'https://shop.example.com/spring?c=[[c1]]');
        $theirs = self::page(self::OTHER, 'https://store.example.net/offer');
        $public = '7' . $id . '3';
        $guard = new WebhookGuard(static fn (string $host): array => [self::PUBLIC], self::ALLOW);
        $checker = fn (string $html): LandingPageCheck => new LandingPageCheck($guard, function (array $options) use ($html): array {
            $this->fetched[] = (string) $options[CURLOPT_URL];

            return ['status' => 200, 'error' => null, 'timed_out' => false, 'primary_ip' => self::PUBLIC, 'redirect_url' => null, 'body' => $html, 'truncated' => false];
        });
        $api = new SetupCodeController(self::$db, self::USER);
        $server = ['HTTP_HOST' => 'track.example.com', 'HTTPS' => 'on', 'SERVER_PORT' => '443'];

        $found = $api->landingPageCheck($id, [], [], $this->limit(), $server, $checker(
            '<body><script src="https://track.example.com/tracking202/static/landing.php?lpip=' . $public . '"></script></body>'
        ), self::NOW)['data'];
        self::assertSame(['https://shop.example.com/spring?c='], $this->fetched, 'the saved URL is fetched without its placeholders');
        self::assertSame([$id, (int) $public, Result::PRESENT, true, []], [$found['landing_page_id'], $found['landing_page_id_public'], $found['status'], $found['found'], $found['other_landing_page_ids_public']]);
        self::assertSame(Result::PRESENT, (new LandingPageCheckStore(new Connection(self::$db)))->forPage(self::USER, $id)['status'] ?? null, 'the answer is kept for the list');

        $elsewhere = $api->landingPageCheck($id, [], [], $this->limit(), $server, $checker(
            '<body><script src="https://other.example.org/tracking202/static/landing.php?lpip=' . $public . '"></script></body>'
        ), self::NOW + 60)['data'];
        self::assertSame([Result::OTHER_TRACKER, false], [$elsewhere['status'], $elsewhere['found']], 'the host is judged against the tracking domain');

        $empty = $checker('<body></body>');
        $absent = $api->landingPageCheck($id, [], [], $this->limit(), $server, $empty, self::NOW + 120)['data'];
        self::assertSame([Result::ABSENT, false], [$absent['status'], $absent['found']]);
        self::assertStringContainsString('GET /landing-pages/' . $id . '/code', $absent['message']);
        self::assertStringNotContainsString('the code above', $absent['message']);

        $this->fetched = [];
        foreach ([[$theirs, [], []], [999999999, [], []]] as [$page, $query, $body]) {
            try {
                $api->landingPageCheck($page, $query, $body, $this->limit(), $server, $checker(''), self::NOW + 1000);
                self::fail('checked a landing page that is not the caller\'s');
            } catch (NotFoundException) {
            }
        }
        foreach ([[['force' => '1'], []], [[], ['url' => 'https://internal.example/']]] as [$query, $body]) {
            try {
                $api->landingPageCheck($id, $query, $body, $this->limit(), $server, $checker(''), self::NOW + 1000);
                self::fail('took a parameter it does not read');
            } catch (ValidationException) {
            }
        }
        self::assertSame([], $this->fetched, 'a refused request fetches nothing');
    }

    /**
     * A page checked in the last COOLDOWN seconds is answered from that
     * check, fetched and counted by nothing, even past the limit; a new URL,
     * or the cooldown over, fetches again. Each key may have PER_KEY pages
     * fetched a WINDOW, and the next is refused with when to try again.
     */
    public function testARepeatIsAnsweredFromTheLastCheckAndAKeyIsLimited(): void
    {
        $guard = new WebhookGuard(static fn (string $host): array => [self::PUBLIC], self::ALLOW);
        $html = '<body>' . LandingPageCode::loader('//t.example/', '77')
            . LandingPageCode::loader('//t.example/', '78') . '</body>';
        $checker = new LandingPageCheck($guard, function (array $options) use ($html): array {
            $this->fetched[] = (string) $options[CURLOPT_URL];

            return ['status' => 200, 'error' => null, 'timed_out' => false, 'primary_ip' => self::PUBLIC,
                'redirect_url' => null, 'body' => $html, 'truncated' => false];
        });
        $api = new SetupCodeController(self::$db, self::USER);
        $now = time();
        $cooldown = LandingPageCheckLimit::COOLDOWN;
        $check = fn (int $id, int $at, ?LandingPageCheckLimit $limit = null): array
            => $api->landingPageCheck($id, [], [], $limit ?? $this->limit(), [], $checker, $at)['data'];
        $id = self::page(self::USER, 'https://shop.example.com/spring');

        $first = $check($id, $now);
        self::assertSame(
            [true, $now, Result::WRONG_ID, ['77', '78']],
            [$first['fetched'], $first['checked_at'], $first['status'], $first['other_landing_page_ids_public']]
        );
        self::assertArrayNotHasKey('note', $first);
        $again = $check($id, $now + $cooldown - 1);
        self::assertCount(1, $this->fetched, 'a repeat inside the cooldown fetches nothing');
        self::assertSame([false, $now, 1], [$again['fetched'], $again['checked_at'], $again['fetch_again_in_seconds']]);
        self::assertStringContainsString('checked ' . ($cooldown - 1) . ' seconds ago', $again['note']);
        foreach (['status', 'found', 'message', 'other_landing_page_ids_public', 'landing_page_url'] as $field) {
            self::assertSame($first[$field], $again[$field], "the stored answer gives the same $field");
        }
        self::assertTrue($check($id, $now + $cooldown)['fetched'], 'the cooldown over, the page is fetched');
        self::q("UPDATE 202_landing_pages SET landing_page_url = 'https://shop.example.com/summer'"
            . " WHERE landing_page_id = $id");
        self::assertTrue($check($id, $now + $cooldown + 1)['fetched'], 'a new URL is fetched at once');
        self::assertSame(3, count($this->fetched));

        // One key, PER_KEY pages a window: the next is refused, and fetches nothing.
        $perKey = LandingPageCheckLimit::PER_KEY;
        $key = $this->limit('apikey:fedcba9876543210');
        $pages = [];
        for ($i = 0; $i <= $perKey; $i++) {
            $pages[] = self::page(self::USER, 'https://shop.example.com/p' . $i);
        }
        $later = $now + 1000;
        foreach (array_slice($pages, 0, $perKey) as $page) {
            self::assertTrue($check($page, $later, $key)['fetched']);
        }
        $fetched = count($this->fetched);
        try {
            $check($pages[$perKey], $later, $key);
            self::fail('a key past its limit had a page fetched');
        } catch (TooManyRequestsException $e) {
            self::assertSame(429, $e->getHttpStatus());
            self::assertGreaterThanOrEqual(1, $e->retryAfter);
            self::assertLessThanOrEqual(LandingPageCheckLimit::WINDOW, $e->retryAfter);
            self::assertStringContainsString('Try again in ' . $e->retryAfter . ' seconds', $e->getMessage());
            self::assertSame(
                ['limit' => $perKey, 'window_seconds' => LandingPageCheckLimit::WINDOW, 'per' => 'api_key'],
                $e->getDetails()['rate_limit']
            );
        }
        self::assertSame($fetched, count($this->fetched), 'the refused check fetched nothing');
        $answered = 'past the limit, a page just checked is still answered';
        self::assertFalse($check($pages[0], $later + 1, $key)['fetched'], $answered);
        $other = $this->limit('apikey:0000111122223333');
        self::assertTrue($check($pages[$perKey], $later, $other)['fetched'], 'another key has its own count');
    }

    public function testASavedTrackingDomainIsReadAsItsHost(): void
    {
        self::assertSame('track.example.com', LandingPageCheckStore::hostOf('https://Track.Example.com/'));
        self::assertSame('track.example.com:8443', LandingPageCheckStore::hostOf('track.example.com:8443/p202'));
        self::assertSame('', LandingPageCheckStore::hostOf(''));
    }

    public function testTheUpgradeCompletesUrlsStoredWithoutAScheme(): void
    {
        $bare = self::page(self::USER, 'lp.example.com/x');
        $relative = self::page(self::USER, '//lp.example.com/y');
        $whole = self::page(self::USER, 'http://lp.example.com/z');
        $other = self::page(self::USER, 'ftp://lp.example.com/x');
        $long = self::page(self::USER, 'lp.example.com/' . str_repeat('a', 235));
        self::q("UPDATE 202_landing_pages SET leave_behind_page_url = 'offers.example.com/after' WHERE landing_page_id = $whole");

        self::assertSame(3, LandingPageUrlRepair::run(new Connection(self::$db)));
        $urls = [];
        $result = self::$db->query('SELECT landing_page_id, landing_page_url, leave_behind_page_url FROM 202_landing_pages WHERE user_id = ' . self::USER);
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $urls[(int) $row['landing_page_id']] = [$row['landing_page_url'], $row['leave_behind_page_url']];
        }
        self::assertSame(['https://lp.example.com/x', ''], $urls[$bare]);
        self::assertSame(['https://lp.example.com/y', ''], $urls[$relative]);
        self::assertSame(['http://lp.example.com/z', 'https://offers.example.com/after'], $urls[$whole]);
        self::assertSame('ftp://lp.example.com/x', $urls[$other][0], 'a URL that is no bare host is left');
        self::assertSame('lp.example.com/' . str_repeat('a', 235), $urls[$long][0], 'one that would not fit its column is left');
        self::assertSame(0, LandingPageUrlRepair::run(new Connection(self::$db)), 'a second run changes nothing');
    }
}
