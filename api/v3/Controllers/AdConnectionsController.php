<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\PayloadKeys;
use Prosper202\AdPlatforms\AdConnectionInputException;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi;
use Prosper202\AdPlatforms\OpenAiAds\OpenAiAdsConnections;
use Prosper202\Database\Connection;
use Prosper202\Database\InstallId;
use Prosper202\Destinations\DestinationStore;

/**
 * /ad-connections: the ad platform accounts this account reaches through
 * their own API (ChatGPT Ads today), the check that reads the account again,
 * and the setup that makes the pixel, Conversions API key, destination and
 * event settings there. Keys are taken and never served: a read says
 * whether each is set.
 */
final class AdConnectionsController
{
    private readonly Connection $conn;
    private readonly AdConnectionStore $store;

    public function __construct(private readonly \mysqli $db, private readonly int $userId, private readonly ?AdvertiserApi $api = null)
    {
        $this->conn = new Connection($db);
        $this->store = new AdConnectionStore($this->conn);
    }

    public function list(): array
    {
        return ['data' => $this->store->list($this->userId)];
    }

    public function get(int $id): array
    {
        return ['data' => $this->store->get($this->userId, $id) ?? throw self::notFound($id)];
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['platform', 'api_key', 'ppc_account_id'], 'an ad connection');
        $platform = $payload['platform'] ?? OpenAiAdsConnections::PLATFORM;
        if (!is_string($platform)) {
            throw new ValidationException('Validation failed', ['platform' => 'Must be one of: ' . implode(', ', array_keys(AdConnectionStore::CREDENTIALS)) . '.']);
        }
        $ppcAccountId = self::ppcAccountId($payload);

        return ['data' => $this->refusing(function () use ($platform, $payload, $ppcAccountId): array {
            AdConnectionStore::platform($platform);

            return $this->connections()->connect($this->userId, OpenAiAdsConnections::advertiserKey($payload['api_key'] ?? null), $ppcAccountId, time());
        })];
    }

    /**
     * Replace the key (it must reach the same ad account) or the linked traffic source account.
     *
     * @param array<string, mixed> $payload
     */
    public function update(int $id, array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['api_key', 'ppc_account_id'], 'an ad connection update');
        if ($payload === []) {
            throw new ValidationException('Nothing to update', ['body' => 'Send api_key, ppc_account_id or both.']);
        }
        $ppcAccountId = array_key_exists('ppc_account_id', $payload) ? self::ppcAccountId($payload) : null;
        $this->store->get($this->userId, $id) ?? throw self::notFound($id);

        return ['data' => $this->refusing(function () use ($id, $payload, $ppcAccountId): ?array {
            $now = time();
            // Both are checked before either is written, so a refusal changes nothing.
            $this->store->ownPpcAccount($this->userId, $ppcAccountId);
            $key = array_key_exists('api_key', $payload) ? OpenAiAdsConnections::advertiserKey($payload['api_key']) : null;
            $result = $key === null ? null : $this->connections()->replaceKey($this->userId, $id, $key, $now);
            if (array_key_exists('ppc_account_id', $payload)) {
                $result = $this->store->setPpcAccount($this->userId, $id, $ppcAccountId, $now);
            }

            return $result;
        }) ?? throw self::notFound($id)];
    }

    public function delete(int $id): void
    {
        if (!$this->store->delete($this->userId, $id, time())) {
            throw self::notFound($id);
        }
    }

    public function deletePreview(int $id): array
    {
        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'ad-connections',
            'mode' => 'soft',
            'record' => $this->store->get($this->userId, $id) ?? throw self::notFound($id),
            // A destination setup made keeps its own copy of the pixel and key and goes on sending.
            'cascade' => [],
        ]];
    }

    public function check(int $id): array
    {
        return ['data' => $this->refusing(fn (): ?array => $this->connections()->check($this->userId, $id, time())) ?? throw self::notFound($id)];
    }

    /** @param array<string, mixed> $payload */
    public function setup(int $id, array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['skip', 'pixel_source_id', 'pixel_id', 'source_id', 'conversions_api_key', 'event_types'], 'a setup');
        return ['data' => $this->refusing(fn (): ?array => $this->connections()->setup($this->userId, $id, $payload, time())) ?? throw self::notFound($id)];
    }

    private function connections(): OpenAiAdsConnections
    {
        return new OpenAiAdsConnections($this->store, $this->api ?? AdvertiserApi::fromEnvironment(\Api\V3\Support\ServerStateStore::forInstall($this->db)), new DestinationStore($this->conn), InstallId::of($this->db));
    }

    /** @param array<string, mixed> $payload */
    private static function ppcAccountId(array $payload): ?int
    {
        $value = $payload['ppc_account_id'] ?? null;
        if ($value !== null && (!is_int($value) || $value <= 0)) {
            throw new ValidationException('Validation failed', ['ppc_account_id' => 'A traffic source account id, a whole number greater than 0, or null for none (p202 ppc-account list).']);
        }

        return $value;
    }

    /**
     * @template T
     * @param callable(): T $write
     * @return T
     */
    private function refusing(callable $write): mixed
    {
        try {
            return $write();
        } catch (AdConnectionInputException $e) {
            throw new ValidationException($e->getMessage(), [$e->field => $e->getMessage()], $e);
        }
    }

    private static function notFound(int $id): NotFoundException
    {
        return new NotFoundException('Ad connection ' . $id . ' not found; p202 ad-connection list shows yours.');
    }
}
