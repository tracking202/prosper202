<?php

declare(strict_types=1);

namespace Tests\Bandit;

use PHPUnit\Framework\TestCase;
use Prosper202\Bandit\PairingClient;
use Prosper202\Bridge\EventBridge;

/**
 * Shape-level tests for the thin SaaS pairing client (E.5): request
 * building via the injected transport — no live HTTP anywhere.
 */
final class PairingClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: ?string}> */
    private array $calls = [];

    private function client(int $status = 200, string $responseBody = '{"status":"ok"}', string $base = 'https://saas.example'): PairingClient
    {
        $this->calls = [];

        return new PairingClient($base, function (string $method, string $url, ?string $body) use ($status, $responseBody): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];

            return [$status, $responseBody];
        });
    }

    public function testPairInitAnnouncesVersionsAndCapabilities(): void
    {
        $client = $this->client(200, '{"site_key":"pk_live_x","hook_url":"https://worker.example/v1/hooks/p202?site=pk_live_x","status":"pending"}');
        $response = $client->pairInit('key-1', 'hash-1', 'https://tracker.example.com');

        self::assertSame('pk_live_x', $response['site_key']);
        self::assertCount(1, $this->calls);
        self::assertSame('POST', $this->calls[0]['method']);
        self::assertSame('https://saas.example/api/v2/bandit/pair/init', $this->calls[0]['url']);

        $body = json_decode((string) $this->calls[0]['body'], true);
        self::assertSame('key-1', $body['customers_api_key']);
        self::assertSame('hash-1', $body['install_hash']);
        self::assertSame('https://tracker.example.com', $body['install_url']);
        self::assertSame(EventBridge::BRIDGE_VERSION, $body['bridge_version']);
        self::assertArrayHasKey('p202_version', $body);
        self::assertSame(['conversion.recorded', 'engagement.recorded'], $body['capabilities']['events']);
        self::assertTrue($body['capabilities']['wildcard_subscribe']);
        self::assertTrue($body['capabilities']['remote_config']);
        self::assertTrue($body['capabilities']['v3_api']);
    }

    public function testPairCompleteCarriesWebhookIdAndSecretOnce(): void
    {
        $client = $this->client(200, '{"status":"active","site_key":"pk_live_x"}');
        $client->pairComplete('key-1', 'hash-1', 12, 'secret-abc');

        self::assertSame('https://saas.example/api/v2/bandit/pair/complete', $this->calls[0]['url']);
        $body = json_decode((string) $this->calls[0]['body'], true);
        self::assertSame(['customers_api_key' => 'key-1', 'install_hash' => 'hash-1', 'p202_webhook_id' => 12, 'webhook_secret' => 'secret-abc'], $body);
    }

    public function testPairDisconnectShape(): void
    {
        $client = $this->client();
        $client->pairDisconnect('key-1', 'hash-1');

        self::assertSame('POST', $this->calls[0]['method']);
        self::assertSame('https://saas.example/api/v2/bandit/pair/disconnect', $this->calls[0]['url']);
        self::assertSame(['customers_api_key' => 'key-1', 'install_hash' => 'hash-1'], json_decode((string) $this->calls[0]['body'], true));
    }

    public function testFetchBridgeConfigIsABodylessSignedConfigGet(): void
    {
        $client = $this->client(200, '{"config":{"enabled_events":["*"]},"sig":"sha256=aa"}');
        $response = $client->fetchBridgeConfig('hash-1');

        self::assertSame(['enabled_events' => ['*']], $response['config']);
        self::assertSame('GET', $this->calls[0]['method']);
        self::assertNull($this->calls[0]['body'], 'the config pull carries no request body');
        self::assertSame(
            'https://saas.example/api/v2/bandit/bridge/config?install_hash=hash-1&bridge_version=' . EventBridge::BRIDGE_VERSION,
            $this->calls[0]['url']
        );
    }

    public function testBaseUrlTrailingSlashIsTrimmed(): void
    {
        $client = $this->client(200, '{"status":"ok"}', 'https://saas.example/');
        $client->pairDisconnect('k', 'h');

        self::assertSame('https://saas.example/api/v2/bandit/pair/disconnect', $this->calls[0]['url']);
    }

    public function testNon2xxResponseThrows(): void
    {
        $client = $this->client(500, '{"error":"boom"}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $client->pairInit('key-1', 'hash-1', 'https://tracker.example.com');
    }

    public function testInvalidJsonResponseThrows(): void
    {
        $client = $this->client(200, 'not json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');
        $client->pairDisconnect('key-1', 'hash-1');
    }

    public function testDefaultBaseUrlIsTheSaas(): void
    {
        self::assertSame('https://my.tracking202.com', PairingClient::saasBaseUrl());
    }
}
