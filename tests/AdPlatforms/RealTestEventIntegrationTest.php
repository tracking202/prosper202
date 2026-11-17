<?php

declare(strict_types=1);

namespace Tests\AdPlatforms;

use Api\V3\Exception\TooManyRequestsException;
use Api\V3\Exception\UpstreamUnavailableException;
use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\AdConnectionInputException;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi;
use Prosper202\AdPlatforms\OpenAiAds\OpenAiAdsConnections;
use Prosper202\AdPlatforms\OpenAiAds\OpenAiAdsTestEvent;
use Prosper202\Database\Connection;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\OpenAiAdsDestination;
use Tests\Apps\Android\AndroidDatabase;

/**
 * "Send a real test event" end to end over the real transports: a
 * connection set up against tests/fixtures/openai-ads/fake_openai_ads.py
 * (TLS, verified against its CA), the destination it made sends a real event
 * to the stand-in's Conversions API host, and OpenAI's recent events are
 * read back through the connection.
 *
 * @group integration
 */
final class RealTestEventIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as private databaseSetUp;
        setUpBeforeClass as private databaseSetUpBeforeClass;
        tearDownAfterClass as private databaseTearDownAfterClass;
    }

    private const KEY = 'sk-ads-live-real-test-01';
    /** @var resource|null */
    private static $server = null;
    private static string $origin = '';
    private static string $tls = '';

    public static function setUpBeforeClass(): void
    {
        self::databaseSetUpBeforeClass();
        self::$tls = sys_get_temp_dir() . '/p202-fake-openai-rt-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $process = proc_open(['python3', dirname(__DIR__) . '/fixtures/openai-ads/fake_openai_ads.py', '--port', '0', '--tls-dir', self::$tls, '--api-key', self::KEY], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($process) && preg_match('/^READY (\d+)/', (string) fgets($pipes[1]), $m) === 1) {
            self::$server = $process;
            self::$origin = 'https://127.0.0.1:' . $m[1];
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        foreach (['cert.pem', 'key.pem'] as $file) {
            @unlink(self::$tls . '/' . $file);
        }
        @rmdir(self::$tls);
        putenv(AdvertiserApi::ENV_ENDPOINT);
        putenv(AdvertiserApi::ENV_CA_FILE);
        self::databaseTearDownAfterClass();
    }

    protected function setUp(): void
    {
        $this->databaseSetUp();
        if (self::$server === null) {
            self::markTestSkipped('The fake Advertiser API did not start (python3 and openssl are needed).');
        }
        foreach (['202_ad_connections', '202_destinations', '202_destination_deliveries'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        self::fixture('DELETE FROM 202_users_pref WHERE user_id = 1');
        self::fixture("INSERT INTO 202_users_pref SET user_id=1, user_account_currency='USD'");
        putenv(AdvertiserApi::ENV_ENDPOINT . '=' . self::$origin);
        putenv(AdvertiserApi::ENV_CA_FILE . '=' . self::$tls . '/cert.pem');
        $this->control('reset', []);
    }

    /** @param array<string, mixed> $body */
    private function control(string $what, array $body): void
    {
        $context = stream_context_create(['http' => ['method' => 'POST', 'content' => (string) json_encode($body), 'header' => 'Content-Type: application/json'],
            'ssl' => ['cafile' => self::$tls . '/cert.pem', 'verify_peer' => true]]);
        file_get_contents(self::$origin . '/control/' . $what, false, $context);
    }

    private function testEvent(): OpenAiAdsTestEvent
    {
        $conn = new Connection(self::$db);

        return new OpenAiAdsTestEvent($conn, new AdConnectionStore($conn), new DestinationStore($conn), AdvertiserApi::fromEnvironment(),
            new OpenAiAdsDestination(AdvertiserApi::eventsSender()), static function (int $seconds): void {
            });
    }

    /** The connection set up in full; the id of the destination it made. */
    private function setUpConnection(): int
    {
        $conn = new Connection(self::$db);
        $connections = new OpenAiAdsConnections(new AdConnectionStore($conn), AdvertiserApi::fromEnvironment(), new DestinationStore($conn), str_repeat('b', 64));
        $id = (int) $connections->connect(1, self::KEY, null, time())['connection_id'];
        $run = $connections->setup(1, $id, [], time());

        return (int) array_column($run['steps'], null, 'step')['destination']['destination_id'];
    }

    public function testARealEventIsSentAndReadBackAsAServerEvent(): void
    {
        $destination = $this->setUpConnection();
        $answer = $this->testEvent()->send(1, $destination, time());

        self::assertTrue($answer['sent'], $answer['message']);
        self::assertTrue($answer['arrived'], $answer['message']);
        self::assertSame('order_created', $answer['event_type']);
        self::assertStringContainsString('a server event (server_to_server) of type order_created', $answer['message']);
        self::assertStringContainsString('names no event id', $answer['message'], 'it says what the match rests on');
        $logged = self::$db->query('SELECT kind, status, sent_at, summary FROM 202_destination_deliveries'
            . ' WHERE destination_id = ' . $destination)->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $logged, 'the send is in the delivery log');
        $row = $logged[0];
        $expected = ['test', 'sent', (string) $answer['sent_at']];
        self::assertSame($expected, [$row['kind'], $row['status'], $row['sent_at']]);
        self::assertTrue(json_decode((string) $row['summary'], true, 8, JSON_THROW_ON_ERROR)['real']);
        $lists = static fn (array $r): bool => $r['path'] === '/v1/conversions/events';
        $read = array_values(array_filter($this->requests(), $lists));
        self::assertNotSame([], $read);
        self::assertStringContainsString('limit=50', $read[0]['query'], 'its largest page: the list does not page');
    }

    /** @return list<array<string, mixed>> */
    private function requests(): array
    {
        $context = stream_context_create(['ssl' => ['cafile' => self::$tls . '/cert.pem', 'verify_peer' => true]]);
        $body = (string) file_get_contents(self::$origin . '/control/requests', false, $context);

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR)['requests'];
    }

    public function testAnAccountWithoutTheEventStreamIsToldSo(): void
    {
        $destination = $this->setUpConnection();
        $this->control('disable', ['events' => true]);
        $answer = $this->testEvent()->send(1, $destination, time());

        self::assertSame([true, false, 'stream_off'], [$answer['sent'], $answer['arrived'], $answer['state']]);
        self::assertStringContainsString('event stream is not switched on for this ad account', $answer['message']);
    }

    public function testWithoutAConnectionItIsRefusedAndSaysWhatToDo(): void
    {
        $destination = $this->setUpConnection();
        self::$db->query('UPDATE 202_ad_connections SET deleted_at = 1');
        $this->expectException(AdConnectionInputException::class);
        $this->expectExceptionMessage('needs a ChatGPT Ads connection');
        $this->testEvent()->send(1, $destination, time());
    }

    /** Over the API a real, counted event is sent only when the request says it knows it is counted. */
    public function testOverTheApiARealEventNeedsItsConfirmation(): void
    {
        $destination = $this->setUpConnection();
        $controller = new \Api\V3\Controllers\DestinationsController(self::$db, 1);
        $requests = function (): int {
            $context = stream_context_create(['ssl' => ['cafile' => self::$tls . '/cert.pem', 'verify_peer' => true]]);
            $read = (string) file_get_contents(self::$origin . '/control/requests', false, $context);
            $seen = json_decode($read, true, 512, JSON_THROW_ON_ERROR);

            return count($seen['requests']);
        };
        $before = $requests();
        $refusals = [
            'real alone' => [['real' => true], 'confirm_counted'],
            'real with a false confirmation' => [['real' => true, 'confirm_counted' => false], 'confirm_counted'],
            'a confirmation without real' => [['confirm_counted' => true], 'confirm_counted'],
        ];
        foreach ($refusals as $what => [$body, $field]) {
            try {
                $controller->test($destination, $body);
                self::fail($what . ' was sent');
            } catch (\Api\V3\Exception\ValidationException $e) {
                self::assertArrayHasKey($field, $e->getFieldErrors(), $what);
                if ($what === 'real alone') {
                    self::assertStringContainsString('counted as a conversion', $e->getFieldErrors()[$field]);
                }
            }
        }
        self::assertSame($before, $requests(), 'nothing was asked of OpenAI for a refused test');

        $answer = $controller->test($destination, ['real' => true, 'confirm_counted' => true])['data'];
        self::assertSame([true, true], [$answer['sent'], $answer['arrived']], $answer['message']);
    }

    /** OpenAI not reached or limiting requests is no verdict on the event: a 503 or 429, never a 200 "not sent". */
    public function testOverTheApiARealEventOpenAiDidNotTakeIsAFailureStatus(): void
    {
        $destination = $this->setUpConnection();
        $controller = new \Api\V3\Controllers\DestinationsController(self::$db, 1);
        $body = ['real' => true, 'confirm_counted' => true];
        $cases = [
            'an outage' => [503, UpstreamUnavailableException::class, 'unreachable', 'could not be reached'],
            'its rate limit' => [429, TooManyRequestsException::class, 'rate_limited', 'is limiting requests'],
        ];
        foreach ($cases as $what => [$status, $class, $reason, $said]) {
            $this->control('answer', ['status' => $status, 'body' => '{"error":{"message":"Try later"}}']);
            try {
                $controller->test($destination, $body);
                self::fail($what . ' was answered as a verdict');
            } catch (\Api\V3\HttpException $e) {
                self::assertInstanceOf($class, $e, $what);
                self::assertSame($status, $e->getHttpStatus(), $what);
                self::assertSame(60, $e->retryAfter, $what);
                $details = $e instanceof TooManyRequestsException ? $e->getDetails()['details'] : $e->getDetails();
                self::assertSame(['upstream' => 'openai_ads', 'reason' => $reason], $details, $what);
                self::assertStringContainsString($said . ', so no test event was sent', $e->getMessage(), $what);
                self::assertStringNotContainsString('did not take', $e->getMessage(), $what . ': not a refusal');
            }
        }

        // A refusal of the event is OpenAI's verdict on it: the 200 that says so.
        $this->control('answer', ['status' => 400, 'body' => '{"error":{"message":"bad event"}}']);
        $answer = $controller->test($destination, $body)['data'];
        self::assertSame([false, false, 400], [$answer['sent'], $answer['arrived'], $answer['status_code']]);
        self::assertStringContainsString('OpenAI did not take the test event', $answer['message']);
    }

    /** Requests seen by the fake API: [event POSTs, event-list GETs]. */
    private function seen(): array
    {
        $context = stream_context_create(['ssl' => ['cafile' => self::$tls . '/cert.pem', 'verify_peer' => true]]);
        $body = (string) file_get_contents(self::$origin . '/control/requests', false, $context);
        $read = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $posts = $reads = 0;
        foreach ($read['requests'] as $request) {
            $posts += ($request['method'] === 'POST' && $request['path'] === '/v1/events') ? 1 : 0;
            $reads += ($request['method'] === 'GET' && $request['path'] === '/v1/conversions/events') ? 1 : 0;
        }

        return [$posts, $reads];
    }

    /** A check reads the list again for the event already sent and never sends another, which OpenAI would count. */
    public function testACheckReadsTheListAgainAndSendsNothing(): void
    {
        $destination = $this->setUpConnection();
        $now = time();
        $sent = $this->testEvent()->send(1, $destination, $now);
        self::assertSame([true, 'arrived', $now], [$sent['arrived'], $sent['state'], $sent['sent_at']]);
        [$postsAfterSend, $readsAfterSend] = $this->seen();
        self::assertSame(1, $postsAfterSend, 'one real event was sent');

        $checked = $this->testEvent()->check(1, $destination, $sent['sent_at'], $now + 30);

        $got = [$checked['sent'], $checked['arrived'], $checked['state'], $checked['sent_at']];
        self::assertSame([true, true, 'arrived', $now], $got, $checked['message']);
        [$posts, $reads] = $this->seen();
        self::assertSame($postsAfterSend, $posts, 'a check sends no event');
        self::assertGreaterThan($readsAfterSend, $reads, 'it read the list');
    }

    /** An event OpenAI took but does not list is "not listed yet": no failure, no advice to send another. */
    public function testAnEventNotListedIsNotListedYetAndTheCheckSendsNothing(): void
    {
        $destination = $this->setUpConnection();
        $now = time();
        $this->testEvent()->send(1, $destination, $now);
        // The stand-in forgets its events, as a list that does not show the event yet.
        $this->control('reset', []);

        $checked = $this->testEvent()->check(1, $destination, $now, $now + 5);

        $got = [$checked['sent'], $checked['arrived'], $checked['state']];
        self::assertSame([true, false, 'not_listed'], $got, $checked['message']);
        self::assertStringContainsString('a sample of at most 50 recent events', $checked['message']);
        self::assertStringContainsString('Do not send another test event', $checked['message']);
        self::assertStringNotContainsString('try again in a minute', strtolower($checked['message']));
        self::assertSame(0, $this->seen()[0], 'the check sent nothing');
    }

    /** Past the 15 minutes OpenAI's list reaches, nothing can be read: "too late", not "did not arrive". */
    public function testACheckPastFifteenMinutesIsTooLateAndReadsNothing(): void
    {
        $destination = $this->setUpConnection();
        $now = time();
        $sentAt = $now - OpenAiAdsTestEvent::RECENT_SECONDS - 1;
        $this->testEvent()->send(1, $destination, $sentAt);
        $seen = $this->seen();

        $checked = $this->testEvent()->check(1, $destination, $sentAt, $now);

        self::assertSame([true, false, 'too_late'], [$checked['sent'], $checked['arrived'], $checked['state']]);
        self::assertStringContainsString('Ads Manager › Conversions', $checked['message']);
        self::assertSame($seen, $this->seen(), 'nothing was sent or read');
    }

    public function testACheckRefusesATimeThatIsNotInThePast(): void
    {
        $destination = $this->setUpConnection();
        foreach ([0, -1, time() + 600] as $sentAt) {
            try {
                $this->testEvent()->check(1, $destination, $sentAt, time());
                self::fail('a check at ' . $sentAt . ' was read');
            } catch (\Prosper202\AdPlatforms\AdConnectionInputException $e) {
                self::assertStringContainsString('sent_at', $e->getMessage());
            }
        }
        $other = $this->testEvent()->check(2, $destination, time() - 5, time());
        self::assertNull($other, 'another account has no such destination');
    }

    /** Every read of OpenAI's event list answers $status, until the next reset. */
    private function failList(int $status, string $message): void
    {
        $body = (string) json_encode(['error' => ['message' => $message]]);
        $this->control('answer', ['status' => $status, 'body' => $body, 'path' => '/v1/conversions/events']);
    }

    /** A check vouches only for an event this destination logged as a real test sent at that second. */
    public function testACheckRefusesATimeNoRealTestWasSentAt(): void
    {
        $destination = $this->setUpConnection();
        $now = time();
        $conn = new Connection(self::$db);
        // A validation test at that second is not a real event: OpenAI saved nothing.
        $queue = new \Prosper202\Destinations\DestinationQueue($conn, new DestinationStore($conn));
        $queue->testFire(1, $destination, $now - 5);
        [$posts, $reads] = $this->seen();
        foreach (['no send at all' => $now - 10, 'a validation test' => $now - 5] as $what => $sentAt) {
            try {
                $this->testEvent()->check(1, $destination, $sentAt, $now);
                self::fail($what . ' was answered as a real test event');
            } catch (AdConnectionInputException $e) {
                self::assertSame('check_sent_at', $e->field, $what);
                self::assertStringContainsString('shows no real test event sent at', $e->getMessage(), $what);
            }
        }
        self::assertSame([$posts, $reads], $this->seen(), 'nothing was sent or read');

        $controller = new \Api\V3\Controllers\DestinationsController(self::$db, 1);
        try {
            $controller->test($destination, ['check_sent_at' => $now - 10]);
            self::fail('the API answered a time no real test was sent at');
        } catch (\Api\V3\Exception\ValidationException $e) {
            self::assertArrayHasKey('check_sent_at', $e->getFieldErrors());
        }
    }

    /**
     * A check whose reads of OpenAI's list all fail knows nothing about the event: a failure that says so, never
     * not_listed, and nothing is sent.
     */
    public function testACheckWhoseListCannotBeReadIsAFailureNotNotListed(): void
    {
        $destination = $this->setUpConnection();
        $now = time();
        $this->testEvent()->send(1, $destination, $now);
        [$posts] = $this->seen();
        $cases = [
            'an outage' => [503, 'unreachable'],
            'a server error' => [500, 'unreachable'],
            'its rate limit' => [429, 'rate_limited'],
            'a refused request' => [400, 'unreadable_answer'],
        ];
        foreach ($cases as $what => [$status, $reason]) {
            $this->failList($status, 'Try later');
            try {
                $checked = $this->testEvent()->check(1, $destination, $now, $now + 30);
                self::fail($what . ' was answered as ' . $checked['state']);
            } catch (\Prosper202\AdPlatforms\AdPlatformUnavailable $e) {
                self::assertSame($reason, $e->reason, $what);
                $said = 'could not be read, so nothing about the event is known';
                self::assertStringContainsString($said, $e->getMessage(), $what);
            }
        }
        $this->failList(401, 'Invalid API key');
        try {
            $this->testEvent()->check(1, $destination, $now, $now + 30);
            self::fail('a refused key was answered');
        } catch (AdConnectionInputException $e) {
            self::assertSame('connection', $e->field);
            self::assertStringContainsString('refused the Advertiser API key', $e->getMessage());
        }

        $controller = new \Api\V3\Controllers\DestinationsController(self::$db, 1);
        try {
            $controller->test($destination, ['check_sent_at' => $now]);
            self::fail('over the API a refused key was answered');
        } catch (\Api\V3\Exception\ValidationException $e) {
            $named = array_keys($e->getFieldErrors());
            self::assertSame(['connection'], $named, 'the refusal names the key, not the time');
        }
        $this->failList(503, 'down');
        try {
            $controller->test($destination, ['check_sent_at' => $now]);
            self::fail('over the API an outage was answered');
        } catch (UpstreamUnavailableException $e) {
            self::assertSame(503, $e->getHttpStatus());
        }
        self::assertSame($posts, $this->seen()[0], 'no check sent anything');
    }

    /** A send whose read-back fails says the event went and nothing is known of the list; a later check reads it. */
    public function testASendWhoseListCannotBeReadIsUnreadAndCanBeCheckedAgain(): void
    {
        $destination = $this->setUpConnection();
        $now = time();
        $this->failList(503, 'down');

        $sent = $this->testEvent()->send(1, $destination, $now);

        self::assertSame([true, false, 'unread'], [$sent['sent'], $sent['arrived'], $sent['state']], $sent['message']);
        self::assertStringContainsString('could not be read, so whether it is listed is not known', $sent['message']);
        self::assertStringContainsString('Do not send another test event', $sent['message']);
        self::assertSame(1, $this->seen()[0], 'one event was sent');

        $this->control('answer', ['status' => 200, 'body' => '', 'path' => '/nothing']);
        $checked = $this->testEvent()->check(1, $destination, $sent['sent_at'], $now + 30);
        self::assertSame([true, 'arrived'], [$checked['arrived'], $checked['state']], $checked['message']);
        self::assertSame(1, $this->seen()[0], 'the check sent nothing');
    }

    /** Over the API a check only reads: no confirmation, and it never goes with real. */
    public function testOverTheApiACheckNeedsNoConfirmationAndNeverSends(): void
    {
        $destination = $this->setUpConnection();
        $controller = new \Api\V3\Controllers\DestinationsController(self::$db, 1);
        $now = time();
        $sent = $controller->test($destination, ['real' => true, 'confirm_counted' => true])['data'];
        [$posts] = $this->seen();

        $checked = $controller->test($destination, ['check_sent_at' => $sent['sent_at']])['data'];
        self::assertSame([true, 'arrived'], [$checked['arrived'], $checked['state']], $checked['message']);
        self::assertSame($posts, $this->seen()[0], 'the check sent nothing');

        $refusals = [
            'with real' => [['check_sent_at' => $sent['sent_at'], 'real' => true], 'real'],
            'with a confirmation' => [
                ['check_sent_at' => $sent['sent_at'], 'confirm_counted' => true],
                'confirm_counted',
            ],
            'a string' => [['check_sent_at' => '12abc'], 'check_sent_at'],
            'a float' => [['check_sent_at' => 1.5], 'check_sent_at'],
            'zero' => [['check_sent_at' => 0], 'check_sent_at'],
            'null' => [['check_sent_at' => null], 'check_sent_at'],
            'the future' => [['check_sent_at' => $now + 3600], 'check_sent_at'],
        ];
        foreach ($refusals as $what => [$body, $field]) {
            try {
                $controller->test($destination, $body);
                self::fail($what . ' was accepted');
            } catch (\Api\V3\Exception\ValidationException $e) {
                self::assertArrayHasKey($field, $e->getFieldErrors(), $what);
            }
        }
        self::assertSame($posts, $this->seen()[0], 'no refused check sent anything');
    }

    public function testAnotherAccountsDestinationIsNone(): void
    {
        $destination = $this->setUpConnection();

        self::assertNull($this->testEvent()->send(2, $destination, time()));
    }
}
