<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\PpcNetworksController;
use Api\V3\Exception\ValidationException;
use Api\V3\HttpException;
use Api\V3\Support\RemoteApiClient;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Setup\ChatGptAdsSource;

/**
 * p202 sync carries a traffic source's ad platform (ppc_network_platform):
 * the ChatGPT Ads source is the ChatGPT Ads source on the target too, so its
 * links there get the tracking template, and a second sync changes nothing.
 * A source on no platform is sent without the field, which a target from
 * before 1.9.80 does not know.
 *
 * Source and target are two accounts on one scratch database, each served
 * by the real PpcNetworksController; only the HTTP exchange is replaced.
 *
 * @group integration
 */
final class SyncTrafficSourcePlatformIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const SOURCE = 5551;
    private const TARGET = 5552;

    private string $stateDir = '';

    /** @var list<array<string, mixed>> the bodies the target was sent */
    public array $sent = [];

    public static function setUpBeforeClass(): void
    {
        self::connectScratch();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::SOURCE, self::TARGET] as $u) {
            self::$db->query("DELETE v FROM 202_ppc_network_variables v JOIN 202_ppc_networks n ON n.ppc_network_id = v.ppc_network_id WHERE n.user_id = $u");
            foreach (['202_ppc_accounts', '202_ppc_networks', '202_users'] as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = $u");
            }
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        self::user(self::SOURCE);
        self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->stateDir = sys_get_temp_dir() . '/p202-sync-platform-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->stateDir !== '' && is_dir($this->stateDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->stateDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->stateDir);
        }
    }

    private function client(int $userId, bool $isTarget): RemoteApiClient
    {
        $test = $this;

        return new class ($userId, $isTarget, $test) extends RemoteApiClient {
            public function __construct(private readonly int $userId, private readonly bool $isTarget, private readonly SyncTrafficSourcePlatformIntegrationTest $test)
            {
                parent::__construct('http://install-' . $userId . '.example', 'key');
            }

            protected function send(string $method, string $url, array $headers, ?string $body): array
            {
                $parts = parse_url($url);
                $path = substr((string) ($parts['path'] ?? ''), strlen('/api/v3/'));
                parse_str((string) ($parts['query'] ?? ''), $query);
                $payload = $body === null ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if ($this->isTarget && $method !== 'GET') {
                    $this->test->sent[] = $payload;
                }
                $networks = new PpcNetworksController($this->test->db(), $this->userId);
                $segments = explode('/', $path);
                try {
                    $answer = match (true) {
                        $method === 'GET' && $path === 'ppc-networks' => $networks->list($query),
                        $method === 'GET' && count($segments) === 1 => ['data' => [], 'pagination' => ['total' => 0]],
                        $method === 'GET' && $segments[0] === 'ppc-networks' && count($segments) === 2 => $networks->get((int) $segments[1]),
                        $method === 'POST' && $path === 'ppc-networks' => $networks->create($payload),
                        $method === 'PUT' && $segments[0] === 'ppc-networks' && count($segments) === 2 => $networks->update((int) $segments[1], $payload),
                        default => throw new \LogicException("No route for $method $path"),
                    };
                } catch (ValidationException $e) {
                    return [422, (string) json_encode(['message' => $e->getMessage(), 'field_errors' => $e->getFieldErrors()])];
                } catch (HttpException $e) {
                    return [$e->getHttpStatus(), (string) json_encode(['message' => $e->getMessage()])];
                }

                return [200, (string) json_encode($answer)];
            }
        };
    }

    public function db(): \mysqli
    {
        return self::$db;
    }

    /** @return array<string, mixed> the traffic sources' result */
    private function sync(): array
    {
        $engine = new class (new ServerStateStore($this->stateDir), $this->client(self::SOURCE, false), $this->client(self::TARGET, true)) extends SyncEngine {
            public function __construct(
                ServerStateStore $store,
                private readonly RemoteApiClient $sourceClient,
                private readonly RemoteApiClient $targetClient
            ) {
                parent::__construct($store);
            }

            protected function buildClients(array $sourceProfile, array $targetProfile): array
            {
                return [$this->sourceClient, $this->targetClient];
            }
        };
        $profile = ['url' => 'http://unused.example', 'api_key' => 'key'];

        return $engine->execute($profile, $profile, 'ppc-networks', ['force_update' => true, 'skip_errors' => true])['results']['ppc-networks'];
    }

    /** @return array<string, string> name => platform of the target's live sources */
    private static function targetSources(): array
    {
        $result = self::$db->query('SELECT ppc_network_name, ppc_network_platform FROM 202_ppc_networks WHERE ppc_network_deleted = 0 AND user_id = ' . self::TARGET . ' ORDER BY ppc_network_name');

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'ppc_network_platform', 'ppc_network_name');
    }

    public function testThePlatformTravelsAndASecondSyncChangesNothing(): void
    {
        (new ChatGptAdsSource(new Connection(self::$db)))->add(self::SOURCE);
        self::q('INSERT INTO 202_ppc_networks SET user_id = ' . self::SOURCE . ", ppc_network_name = 'Newsletter', ppc_network_time = 0");

        $first = $this->sync();
        self::assertSame([2, 0], [$first['created'] ?? null, $first['failed'] ?? null], (string) json_encode($first));
        self::assertSame(['ChatGPT Ads' => 'chatgpt_ads', 'Newsletter' => ''], self::targetSources());
        $bodies = array_column($this->sent, null, 'ppc_network_name');
        self::assertSame('chatgpt_ads', $bodies['ChatGPT Ads']['ppc_network_platform']);
        self::assertArrayNotHasKey('ppc_network_platform', $bodies['Newsletter'], 'a source on no platform is sent as a target before 1.9.80 takes it');

        $this->sent = [];
        $again = $this->sync();
        self::assertSame([0, 0, 0], [$again['created'] ?? null, $again['updated'] ?? null, $again['failed'] ?? null], (string) json_encode($again));
        self::assertSame(['ChatGPT Ads' => 'chatgpt_ads', 'Newsletter' => ''], self::targetSources());
    }

    public function testTheApiTakesOnlyAPlatformItKnows(): void
    {
        $api = new PpcNetworksController(self::$db, self::SOURCE);
        $made = $api->create(['ppc_network_name' => 'Synced', 'ppc_network_platform' => 'chatgpt_ads'])['data'];
        self::assertSame('chatgpt_ads', $made['ppc_network_platform']);
        self::assertSame('', $api->create(['ppc_network_name' => 'Plain'])['data']['ppc_network_platform']);
        foreach (['openai', 'CHATGPT_ADS', 1, null] as $bad) {
            try {
                $api->create(['ppc_network_name' => 'Bad', 'ppc_network_platform' => $bad]);
                self::fail('took ' . var_export($bad, true));
            } catch (ValidationException $e) {
                self::assertArrayHasKey('ppc_network_platform', $e->getFieldErrors());
            }
        }
    }
}
