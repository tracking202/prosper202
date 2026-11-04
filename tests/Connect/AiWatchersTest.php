<?php

declare(strict_types=1);

namespace Tests\Connect;

use Api\V3\Support\AiConnect;
use Api\V3\Support\AiWatchers;
use PHPUnit\Framework\TestCase;

/**
 * AiWatchers over real curl, against a stub of Tracking202's MCP server on
 * loopback (StubRelay): what it sends, how it reads each answer, and that a
 * server that is slow, down or redirecting costs a page one short wait.
 */
final class AiWatchersTest extends TestCase
{
    private const KEY = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    /** AiConnect::watcherToken(KEY), as Node's createHmac('sha256', KEY) gives it: the server's derivation. */
    private const TOKEN = 'e9fb362e4976bebd211daf001936177f4213e9ae5865d96e517f4aeb294657ff';
    private const W1 = 'wch_0123456789abcdef01234567';
    private const W2 = 'wch_fedcba9876543210fedcba98';

    private static ?StubRelay $relay = null;
    private string $log = '';
    private string $previousLog = '';

    public static function setUpBeforeClass(): void
    {
        self::$relay = StubRelay::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$relay?->stop();
        self::$relay = null;
    }

    protected function setUp(): void
    {
        self::relay()->forget();
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-ai-watchers-');
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog);
        $logged = (string) file_get_contents($this->log);
        unlink($this->log);
        self::assertStringNotContainsString(self::KEY, $logged, 'the key is never logged');
        self::assertStringNotContainsString(self::TOKEN, $logged, 'nor its token');
    }

    private static function relay(): StubRelay
    {
        self::assertNotNull(self::$relay);

        return self::$relay;
    }

    private function client(): AiWatchers
    {
        return new AiWatchers(self::relay()->origin());
    }

    public function testTheTokenIsTheServersDerivationOfTheKey(): void
    {
        self::assertSame(self::TOKEN, AiConnect::watcherToken(self::KEY));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', AiConnect::watcherToken('x'));
    }

    public function testTheListIsReadWithTheTokenInTheAuthorizationHeaderOnly(): void
    {
        $done = StubRelay::watcher(self::W2, 43, ['status' => 'completed', 'alerts' => 0, 'lastAlert' => null]);
        self::relay()->answer('GET /api/watchers', 200, ['data' => [StubRelay::watcher(self::W1, 42), $done]]);

        $watchers = $this->client()->list(self::TOKEN);

        self::assertNotNull($watchers);
        self::assertCount(2, $watchers);
        self::assertSame(self::W1, $watchers[0]['id']);
        self::assertSame(42, $watchers[0]['campaignId']);
        self::assertSame('running', $watchers[0]['status']);
        self::assertSame(1762419200000, $watchers[0]['endsAt']);
        self::assertSame(1, $watchers[0]['alerts']);
        self::assertStringStartsWith('landing_page lp one', (string) $watchers[0]['lastAlert']);
        self::assertSame('pctBelowBreakeven', $watchers[0]['rule']['thresholdKind']);
        self::assertSame('completed', $watchers[1]['status']);
        self::assertNull($watchers[1]['lastAlert']);

        $requests = self::relay()->requests();
        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0]['method']);
        self::assertSame('/api/watchers', $requests[0]['uri'], 'no query string: the token is not in the URL');
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]['authorization']);
        self::assertSame('', $requests[0]['body']);
    }

    public function testAServerThatPredatesWatchersHasNone(): void
    {
        // Nothing answered: the stub's plain-text 404, as such a server gives.
        self::assertSame([], $this->client()->list(self::TOKEN));
        self::assertTrue($this->client()->stopAll(self::TOKEN), 'a server that runs no watchers has none to stop');
    }

    public function testAKeyWithNoWatchersListsNone(): void
    {
        self::relay()->answer('GET /api/watchers', 200, ['data' => []]);
        self::assertSame([], $this->client()->list(self::TOKEN));
    }

    /**
     * A refusal, a limit or an answer that cannot be read is "could not be
     * read", never "no watchers": an empty list would say nothing watches.
     *
     * @dataProvider unreadableAnswers
     */
    public function testAnAnswerThatCannotBeReadIsNotAnEmptyList(int $status, mixed $body): void
    {
        self::relay()->answer('GET /api/watchers', $status, $body);
        $client = $this->client();

        self::assertNull($client->list(self::TOKEN));
        self::assertNull($client->list(self::TOKEN), 'asked again');
        self::assertCount(2, self::relay()->requests(), 'an answer, even a refusal, does not stop the next call');
    }

    /** @return array<string, array{0: int, 1: mixed}> */
    public static function unreadableAnswers(): array
    {
        return [
            'refused' => [401, ['error' => 'send the key']],
            'limited' => [429, ['error' => 'too many requests for this key; wait a minute']],
            'server error' => [500, 'oops'],
            'not JSON' => [200, '<html>maintenance</html>'],
            'no data list' => [200, ['data' => ['id' => self::W1]]],
            'a watcher without an id' => [200, ['data' => [StubRelay::watcher('', 42)]]],
            'a watcher id of another shape' => [200, ['data' => [StubRelay::watcher('wch_1', 42)]]],
            'a campaign id that is no number' => [
                200,
                ['data' => [StubRelay::watcher(self::W1, 42, ['campaignId' => '42'])]],
            ],
        ];
    }

    public function testASlowServerIsGivenUpOnAndNotAskedAgain(): void
    {
        self::relay()->answer('GET /api/watchers', 200, ['data' => []], 6000);
        $client = $this->client();

        $started = microtime(true);
        self::assertNull($client->list(self::TOKEN));
        $waited = microtime(true) - $started;
        self::assertLessThan(AiWatchers::TIMEOUT + 1.5, $waited, 'the page waited past the timeout');
        self::assertGreaterThanOrEqual(AiWatchers::TIMEOUT - 0.5, $waited);

        $started = microtime(true);
        self::assertNull($client->list(self::TOKEN), 'a second key on the same page');
        self::assertFalse($client->stopAll(self::TOKEN));
        self::assertLessThan(0.5, microtime(true) - $started, 'no second wait');
        self::assertCount(1, self::relay()->requests(), 'nothing more was sent');
    }

    public function testAServerThatIsDownCostsNoWait(): void
    {
        $client = new AiWatchers('http://127.0.0.1:' . StubRelay::freePort());
        $started = microtime(true);
        self::assertNull($client->list(self::TOKEN));
        self::assertFalse($client->stopAll(self::TOKEN));
        self::assertSame('failed', $client->stop(self::TOKEN, self::W1));
        self::assertLessThan(AiWatchers::CONNECT_TIMEOUT + 1, microtime(true) - $started);
    }

    public function testARedirectIsNotFollowed(): void
    {
        self::relay()->answer('GET /api/watchers', 302, '', 0, ['Location: ' . self::relay()->origin() . '/elsewhere']);
        self::relay()->answer('GET /elsewhere', 200, ['data' => [StubRelay::watcher(self::W1, 42)]]);

        self::assertNull($this->client()->list(self::TOKEN));
        self::assertSame(['/api/watchers'], array_column(self::relay()->requests(), 'uri'));
    }

    /** The key would cross the network in clear text: nothing is sent. */
    public function testPlainHttpIsOnlyForLoopback(): void
    {
        $client = new AiWatchers('http://relay.example');
        self::assertNull($client->list(self::TOKEN));
        self::assertFalse($client->stopAll(self::TOKEN));
        self::assertStringContainsString('not https', (string) file_get_contents($this->log));
    }

    public function testStopNamesOneWatcher(): void
    {
        self::relay()->answer('POST /api/watchers/stop', 200, ['stopped' => [self::W1]]);

        self::assertSame('stopped', $this->client()->stop(self::TOKEN, self::W1));

        $requests = self::relay()->requests();
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('/api/watchers/stop', $requests[0]['uri']);
        self::assertSame('Bearer ' . self::TOKEN, $requests[0]['authorization']);
        self::assertSame('application/json', $requests[0]['content_type']);
        self::assertSame(['watcherId' => self::W1], json_decode($requests[0]['body'], true));
    }

    public function testStopSaysWhatTheServerSaid(): void
    {
        self::relay()->answer('POST /api/watchers/stop', 404, ['error' => 'there is no such watcher for this key']);
        self::assertSame('not_found', $this->client()->stop(self::TOKEN, self::W1));

        self::relay()->answer('POST /api/watchers/stop', 400, ['error' => 'name a watcherId']);
        self::assertSame('failed', $this->client()->stop(self::TOKEN, self::W1));

        self::relay()->answer('POST /api/watchers/stop', 200, ['stopped' => 'all of them']);
        $unsaid = $this->client()->stop(self::TOKEN, self::W1);
        self::assertSame('failed', $unsaid, 'an answer that does not say what stopped');

        self::relay()->forget();
        self::assertSame('not_found', $this->client()->stop(self::TOKEN, 'wch_../../x'), 'an id of no watcher');
        self::assertSame([], self::relay()->requests(), 'is not sent');
    }

    public function testStopAllAsksForAllAndIsConfirmedOnlyByTheAnswer(): void
    {
        self::relay()->answer('POST /api/watchers/stop', 200, ['stopped' => [self::W1, self::W2]]);
        self::assertTrue($this->client()->stopAll(self::TOKEN));
        self::assertSame(['all' => true], json_decode(self::relay()->requests()[0]['body'], true));

        $refusals = [[429, ['error' => 'wait']], [500, 'oops'], [200, 'not json'], [401, ['error' => 'key']]];
        foreach ($refusals as [$status, $body]) {
            self::relay()->answer('POST /api/watchers/stop', $status, $body);
            self::assertFalse($this->client()->stopAll(self::TOKEN), "HTTP $status");
        }
    }

    public function testStopAllOfAsksForEveryKeyAndConfirmsOnlyWhenEachWas(): void
    {
        self::relay()->answer('POST /api/watchers/stop', 200, ['stopped' => []]);
        $other = str_repeat('f', 64);
        self::assertTrue($this->client()->stopAllOf([self::TOKEN, $other]));
        self::assertSame(
            ['Bearer ' . self::TOKEN, 'Bearer ' . $other],
            array_column(self::relay()->requests(), 'authorization')
        );
        self::assertTrue($this->client()->stopAllOf([]), 'nothing to stop');
        self::assertCount(2, self::relay()->requests(), 'and nothing asked');
    }

    /** The page's delete and the revoke route ask the server before their DELETE. */
    public function testEveryDeleteOfAKeyAsksTheServerFirst(): void
    {
        $root = dirname(__DIR__, 2);
        $page = (string) file_get_contents($root . '/202-account/account.php');
        $stop = strpos($page, '->stopBeforeDelete($db, $keyUserId, $removeKey)');
        $delete = strpos($page, "'DELETE FROM 202_api_keys WHERE api_key = ? AND user_id = ?'");
        self::assertIsInt($stop, 'the page\'s revoke asks the server');
        self::assertIsInt($delete);
        self::assertLessThan($delete, $stop);

        $router = (string) file_get_contents($root . '/api/v3/index.php');
        $stop = strpos($router, '->stopBeforeDelete($db, $userId, Auth::bearerKey($headers), true)');
        $revoke = strpos($router, 'AiConnect::revoke($db, Auth::bearerKey($headers))');
        self::assertIsInt($stop, 'POST /connect/revoke asks the server');
        self::assertIsInt($revoke);
        self::assertLessThan($revoke, $stop);
    }
}
