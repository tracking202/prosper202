<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Service;

use Closure;
use InvalidArgumentException;
use Prosper202\Attribution\Repository\JourneyMaintenanceRepositoryInterface;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Attribution\Repository\SettingsRepositoryInterface;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Settings\Setting;

final class AttributionSettingsService
{
    /**
     * @var array<string, bool>
     */
    private static array $requestCache = [];

    private readonly Closure $clock;

    public function __construct(
        private readonly SettingsRepositoryInterface $settingsRepository,
        private readonly ModelRepositoryInterface $modelRepository,
        private readonly ?JourneyMaintenanceRepositoryInterface $journeyRepository = null,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Evaluates whether multi-touch journeys should be persisted for the supplied conversion scope.
     *
     * @param array<string, mixed> $scope
     */
    public function isMultiTouchEnabled(array $scope): bool
    {
        $userId = $this->requirePositiveInt($scope, ['user_id', 'userId'], 'user identifier');
        $advertiserId = $this->optionalPositiveInt($scope, ['advertiser_id', 'advertiserId']);
        $campaignId = $this->optionalPositiveInt($scope, ['campaign_id', 'campaignId']);

        $cacheKey = $this->buildCacheKey($userId, $advertiserId, $campaignId);
        if (array_key_exists($cacheKey, self::$requestCache)) {
            return self::$requestCache[$cacheKey];
        }

        $scopes = [['type' => ScopeType::GLOBAL, 'id' => null]];
        if ($advertiserId !== null) {
            $scopes[] = ['type' => ScopeType::ADVERTISER, 'id' => $advertiserId];
        }
        if ($campaignId !== null) {
            $scopes[] = ['type' => ScopeType::CAMPAIGN, 'id' => $campaignId];
        }

        $settings = $this->settingsRepository->findForScopes($userId, $scopes);

        $enabled = true;
        $globalKey = $this->buildScopeLookupKey(ScopeType::GLOBAL, null);
        if (isset($settings[$globalKey])) {
            $enabled = $settings[$globalKey]->multiTouchEnabled;
        }

        if ($advertiserId !== null) {
            $advertiserKey = $this->buildScopeLookupKey(ScopeType::ADVERTISER, $advertiserId);
            if (isset($settings[$advertiserKey])) {
                $enabled = $settings[$advertiserKey]->multiTouchEnabled;
            }
        }

        if ($campaignId !== null) {
            $campaignKey = $this->buildScopeLookupKey(ScopeType::CAMPAIGN, $campaignId);
            if (isset($settings[$campaignKey])) {
                $enabled = $settings[$campaignKey]->multiTouchEnabled;
            }
        }

        self::$requestCache[$cacheKey] = $enabled;

        return $enabled;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function enableMultiTouch(array $scope): Setting
    {
        $setting = $this->persistToggle($scope, true);

        if ($this->journeyRepository !== null) {
            $userId = $this->requirePositiveInt($scope, ['user_id', 'userId'], 'user identifier');
            [$scopeType, $scopeId] = $this->resolveScopeDescriptor($scope);
            $this->journeyRepository->hydrateScope($userId, $scopeType, $scopeId);
        }

        return $setting;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function disableMultiTouch(array $scope): Setting
    {
        $setting = $this->persistToggle($scope, false);

        if ($this->journeyRepository !== null) {
            $userId = $this->requirePositiveInt($scope, ['user_id', 'userId'], 'user identifier');
            [$scopeType, $scopeId] = $this->resolveScopeDescriptor($scope);
            $this->journeyRepository->purgeByScope($userId, $scopeType, $scopeId);
        }

        return $setting;
    }

    public static function resetRequestCache(): void
    {
        self::$requestCache = [];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function persistToggle(array $scope, bool $enabled): Setting
    {
        $userId = $this->requirePositiveInt($scope, ['user_id', 'userId'], 'user identifier');
        [$scopeType, $scopeId] = $this->resolveScopeDescriptor($scope);

        $existing = $this->settingsRepository->findByScope($userId, $scopeType, $scopeId);
        $timestamp = ($this->clock)();

        if ($existing === null) {
            $setting = new Setting(
                settingId: null,
                userId: $userId,
                scopeType: $scopeType,
                scopeId: $scopeId,
                modelId: $this->resolveModelId($userId),
                multiTouchEnabled: $enabled,
                multiTouchEnabledAt: $enabled ? $timestamp : null,
                multiTouchDisabledAt: $enabled ? null : $timestamp,
                effectiveAt: $timestamp,
                createdAt: $timestamp,
                updatedAt: $timestamp
            );
        } else {
            $setting = $existing->withMultiTouchState($enabled, $timestamp);
        }

        $saved = $this->settingsRepository->save($setting);
        $this->invalidateCacheForUser($userId);

        return $saved;
    }

    private function buildCacheKey(int $userId, ?int $advertiserId, ?int $campaignId): string
    {
        return implode(':', [
            $userId,
            $advertiserId !== null ? $advertiserId : 'null',
            $campaignId !== null ? $campaignId : 'null',
        ]);
    }

    private function buildScopeLookupKey(ScopeType $scopeType, ?int $scopeId): string
    {
        return sprintf('%s:%s', $scopeType->value, $scopeId === null ? 'null' : $scopeId);
    }

    private function resolveModelId(int $userId): int
    {
        $default = $this->modelRepository->findDefaultForUser($userId);
        if ($default !== null && $default->modelId !== null) {
            return (int) $default->modelId;
        }

        $fallback = $this->modelRepository->findForUser($userId);
        if ($fallback !== []) {
            $first = $fallback[0];
            if ($first->modelId !== null) {
                return (int) $first->modelId;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{ScopeType, ?int}
     */
    private function resolveScopeDescriptor(array $scope): array
    {
        $typeValue = $scope['scope_type'] ?? $scope['scopeType'] ?? null;
        if ($typeValue instanceof ScopeType) {
            $scopeType = $typeValue;
        } elseif (is_string($typeValue) && $typeValue !== '') {
            $scopeType = ScopeType::tryFrom(strtolower($typeValue));
            if ($scopeType === null) {
                throw new InvalidArgumentException('Unknown scope type supplied.');
            }
        } else {
            throw new InvalidArgumentException('Scope type is required for toggle operations.');
        }

        if ($scopeType->requiresIdentifier()) {
            $scopeId = $this->requirePositiveInt($scope, ['scope_id', 'scopeId'], 'scope identifier');
        } else {
            $scopeId = null;
        }

        return [$scopeType, $scopeId];
    }

    /**
     * @param array<string, mixed> $scope
     * @param string[] $keys
     */
    private function requirePositiveInt(array $scope, array $keys, string $label): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $scope) && is_numeric($scope[$key])) {
                $value = (int) $scope[$key];
                if ($value > 0) {
                    return $value;
                }
            }
        }

        throw new InvalidArgumentException('Invalid ' . $label . ' supplied.');
    }

    /**
     * @param array<string, mixed> $scope
     * @param string[] $keys
     */
    private function optionalPositiveInt(array $scope, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $scope) && is_numeric($scope[$key])) {
                $value = (int) $scope[$key];
                if ($value > 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function invalidateCacheForUser(int $userId): void
    {
        $prefix = $userId . ':';
        foreach (array_keys(self::$requestCache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$requestCache[$key]);
            }
        }
    }
}
