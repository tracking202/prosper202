<?php

declare(strict_types=1);

namespace Tests\AdPlatforms;

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

    /** @param array<string, bool> $body */
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
        self::assertStringContainsString('received the test event from this server (server_to_server', $answer['message']);
    }

    public function testAnAccountWithoutTheEventStreamIsToldSo(): void
    {
        $destination = $this->setUpConnection();
        $this->control('disable', ['events' => true]);
        $answer = $this->testEvent()->send(1, $destination, time());

        self::assertSame([true, false], [$answer['sent'], $answer['arrived']]);
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

    public function testAnotherAccountsDestinationIsNone(): void
    {
        $destination = $this->setUpConnection();

        self::assertNull($this->testEvent()->send(2, $destination, time()));
    }
}
