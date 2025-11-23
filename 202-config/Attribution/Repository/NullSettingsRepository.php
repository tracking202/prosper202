<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Settings\Setting;

final class NullSettingsRepository implements SettingsRepositoryInterface
{
    public function findByScope(int $userId, ScopeType $scopeType, ?int $scopeId): ?Setting
    {
        return null;
    }

    public function findForScopes(int $userId, array $scopes): array
    {
        return [];
    }

    public function save(Setting $setting): Setting
    {
        return $setting;
    }

    public function findDisabledSettings(): array
    {
        return [];
    }
}
