<?php

declare(strict_types=1);

namespace Prosper202\Repository;

final class NullTrackingRepository implements TrackingRepositoryInterface
{
    #[\Override]
    public function findOrCreateKeyword(string $name): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateC1(string $value): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateC2(string $value): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateC3(string $value): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateC4(string $value): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateVariable(string $value, int $ppcVariableId): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateVariableSet(string $variables): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateCustomVar(string $name, string $data): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateUtm(string $value, string $type): int
    {
        return 0;
    }
}
