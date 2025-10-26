<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository;

use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Settings\Setting;

interface SettingsRepositoryInterface
{
    public function findByScope(int $userId, ScopeType $scopeType, ?int $scopeId): ?Setting;

    /**
     * @param list<array{type: ScopeType, id: ?int}> $scopes
     * @return array<string, Setting>
     */
    public function findForScopes(int $userId, array $scopes): array;

    public function save(Setting $setting): Setting;

    /**
     * @return Setting[]
     */
    public function findDisabledSettings(): array;
}
