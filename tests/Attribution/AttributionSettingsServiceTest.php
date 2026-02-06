<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Repository\JourneyMaintenanceRepositoryInterface;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Attribution\Repository\SettingsRepositoryInterface;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Service\AttributionSettingsService;
use Prosper202\Attribution\Settings\Setting;

final class AttributionSettingsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        AttributionSettingsService::resetRequestCache();
    }

    public function testIsMultiTouchEnabledHonoursScopePrecedence(): void
    {
        $settings = new InMemorySettingsRepository(
            new Setting(
                settingId: 1,
                userId: 42,
                scopeType: ScopeType::GLOBAL,
                scopeId: null,
                modelId: 9,
                multiTouchEnabled: false,
                multiTouchEnabledAt: null,
                multiTouchDisabledAt: 50,
                effectiveAt: 50,
                createdAt: 10,
                updatedAt: 50
            ),
            new Setting(
                settingId: 2,
                userId: 42,
                scopeType: ScopeType::ADVERTISER,
                scopeId: 7,
                modelId: 9,
                multiTouchEnabled: true,
                multiTouchEnabledAt: 60,
                multiTouchDisabledAt: null,
                effectiveAt: 60,
                createdAt: 10,
                updatedAt: 60
            ),
            new Setting(
                settingId: 3,
                userId: 42,
                scopeType: ScopeType::CAMPAIGN,
                scopeId: 99,
                modelId: 9,
                multiTouchEnabled: false,
                multiTouchEnabledAt: null,
                multiTouchDisabledAt: 70,
                effectiveAt: 70,
                createdAt: 10,
                updatedAt: 70
            )
        );

        $modelRepository = new FakeModelRepository();
        $journeyRepository = new FakeJourneyMaintenanceRepository();
        $service = new AttributionSettingsService($settings, $modelRepository, $journeyRepository);

        $scope = [
            'user_id' => 42,
            'advertiser_id' => 7,
            'campaign_id' => 99,
        ];

        $this->assertFalse($service->isMultiTouchEnabled($scope));

        // Remove campaign to ensure advertiser overrides global state.
        $this->assertTrue($service->isMultiTouchEnabled([
            'user_id' => 42,
            'advertiser_id' => 7,
        ]));
    }

    public function testCacheInvalidatedAfterToggle(): void
    {
        $initialSetting = new Setting(
            settingId: 10,
            userId: 77,
            scopeType: ScopeType::GLOBAL,
            scopeId: null,
            modelId: 5,
            multiTouchEnabled: false,
            multiTouchEnabledAt: null,
            multiTouchDisabledAt: 100,
            effectiveAt: 100,
            createdAt: 10,
            updatedAt: 100
        );

        $settings = new InMemorySettingsRepository($initialSetting);
        $modelRepository = new FakeModelRepository();
        $journeyRepository = new FakeJourneyMaintenanceRepository();
        $service = new AttributionSettingsService($settings, $modelRepository, $journeyRepository, fn (): int => 200);

        $scope = ['user_id' => 77];
        $this->assertFalse($service->isMultiTouchEnabled($scope));

        $service->enableMultiTouch([
            'user_id' => 77,
            'scope_type' => ScopeType::GLOBAL,
        ]);

        $this->assertTrue($service->isMultiTouchEnabled($scope));
        $this->assertSame(1, $journeyRepository->hydrateCalls);
    }

    public function testDisableInvokesJourneyPurge(): void
    {
        $initialSetting = new Setting(
            settingId: 20,
            userId: 88,
            scopeType: ScopeType::CAMPAIGN,
            scopeId: 501,
            modelId: 11,
            multiTouchEnabled: true,
            multiTouchEnabledAt: 150,
            multiTouchDisabledAt: null,
            effectiveAt: 150,
            createdAt: 10,
            updatedAt: 150
        );

        $settings = new InMemorySettingsRepository($initialSetting);
        $modelRepository = new FakeModelRepository();
        $journeyRepository = new FakeJourneyMaintenanceRepository();
        $service = new AttributionSettingsService($settings, $modelRepository, $journeyRepository, fn (): int => 250);

        $service->disableMultiTouch([
            'user_id' => 88,
            'scope_type' => ScopeType::CAMPAIGN,
            'scope_id' => 501,
        ]);

        $this->assertSame(1, $journeyRepository->purgeCalls);
        $updated = $settings->findByScope(88, ScopeType::CAMPAIGN, 501);
        $this->assertInstanceOf(Setting::class, $updated);
        $this->assertFalse($updated?->multiTouchEnabled);
    }

    public function testPixelAndBackfillObeyToggleState(): void
    {
        $setting = new Setting(
            settingId: 30,
            userId: 99,
            scopeType: ScopeType::ADVERTISER,
            scopeId: 12,
            modelId: 15,
            multiTouchEnabled: true,
            multiTouchEnabledAt: 120,
            multiTouchDisabledAt: null,
            effectiveAt: 120,
            createdAt: 10,
            updatedAt: 120
        );

        $settings = new InMemorySettingsRepository($setting);
        $modelRepository = new FakeModelRepository();
        $journeyRepository = new FakeJourneyMaintenanceRepository();
        $service = new AttributionSettingsService($settings, $modelRepository, $journeyRepository, fn (): int => 300);

        $scope = [
            'user_id' => 99,
            'advertiser_id' => 12,
            'campaign_id' => 700,
        ];

        $this->assertTrue($service->isMultiTouchEnabled($scope));

        $service->disableMultiTouch([
            'user_id' => 99,
            'scope_type' => ScopeType::ADVERTISER,
            'scope_id' => 12,
        ]);

        $this->assertFalse($service->isMultiTouchEnabled($scope));
    }
}

final class InMemorySettingsRepository implements SettingsRepositoryInterface
{
    /** @var array<string, Setting> */
    private array $settings = [];
    private int $nextId = 1000;

    public function __construct(Setting ...$settings)
    {
        foreach ($settings as $setting) {
            $this->store($setting);
        }
    }

    public function findByScope(int $userId, ScopeType $scopeType, ?int $scopeId): ?Setting
    {
        $key = $this->key($userId, $scopeType, $scopeId);

        return $this->settings[$key] ?? null;
    }

    public function findForScopes(int $userId, array $scopes): array
    {
        $results = [];
        foreach ($scopes as $scope) {
            $scopeType = $scope['type'];
            $scopeId = $scope['id'];
            if (!$scopeType instanceof ScopeType) {
                continue;
            }

            $setting = $this->findByScope($userId, $scopeType, $scopeId);
            if ($setting !== null) {
                $results[sprintf('%s:%s', $scopeType->value, $scopeId === null ? 'null' : $scopeId)] = $setting;
            }
        }

        return $results;
    }

    public function save(Setting $setting): Setting
    {
        if ($setting->settingId === null) {
            $setting = new Setting(
                settingId: $this->nextId++,
                userId: $setting->userId,
                scopeType: $setting->scopeType,
                scopeId: $setting->scopeId,
                modelId: $setting->modelId,
                multiTouchEnabled: $setting->multiTouchEnabled,
                multiTouchEnabledAt: $setting->multiTouchEnabledAt,
                multiTouchDisabledAt: $setting->multiTouchDisabledAt,
                effectiveAt: $setting->effectiveAt,
                createdAt: $setting->createdAt,
                updatedAt: $setting->updatedAt
            );
        }

        $this->store($setting);

        return $setting;
    }

    public function findDisabledSettings(): array
    {
        return array_values(array_filter(
            $this->settings,
            static fn (Setting $setting): bool => $setting->multiTouchEnabled === false
        ));
    }

    private function store(Setting $setting): void
    {
        $key = $this->key($setting->userId, $setting->scopeType, $setting->scopeId);
        $this->settings[$key] = $setting;
    }

    private function key(int $userId, ScopeType $scopeType, ?int $scopeId): string
    {
        return implode(':', [$userId, $scopeType->value, $scopeId === null ? 'null' : (string) $scopeId]);
    }
}

final class FakeModelRepository implements ModelRepositoryInterface
{
    private ?ModelDefinition $default;

    public function __construct()
    {
        $this->default = new ModelDefinition(
            modelId: 500,
            userId: 1,
            name: 'Last Touch',
            slug: 'last-touch',
            type: ModelType::LAST_TOUCH,
            weightingConfig: [],
            isActive: true,
            isDefault: true,
            createdAt: 1,
            updatedAt: 1
        );
    }

    public function findById(int $modelId): ?ModelDefinition
    {
        return $this->default;
    }

    public function findDefaultForUser(int $userId): ?ModelDefinition
    {
        return $this->default;
    }

    public function findForUser(int $userId, ?ModelType $type = null, bool $onlyActive = true): array
    {
        return $this->default !== null ? [$this->default] : [];
    }

    public function findBySlug(int $userId, string $slug): ?ModelDefinition
    {
        return $this->default;
    }

    public function save(ModelDefinition $model): ModelDefinition
    {
        return $model;
    }

    public function promoteToDefault(ModelDefinition $model): void
    {
    }

    public function delete(int $modelId, int $userId): void
    {
    }

    public function setAsDefault(int $userId, int $modelId): bool
    {
        return true;
    }
}

final class FakeJourneyMaintenanceRepository implements JourneyMaintenanceRepositoryInterface
{
    public int $purgeCalls = 0;
    public int $hydrateCalls = 0;

    public function purgeByScope(int $userId, ScopeType $scopeType, ?int $scopeId = null): int
    {
        $this->purgeCalls++;

        return 0;
    }

    public function hydrateScope(
        int $userId,
        ScopeType $scopeType,
        ?int $scopeId = null,
        ?int $startTime = null,
        ?int $endTime = null,
        int $batchSize = 500
    ): int {
        $this->hydrateCalls++;

        return 0;
    }
}
