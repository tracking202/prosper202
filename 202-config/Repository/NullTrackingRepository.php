<?php

declare(strict_types=1);

namespace Prosper202\Repository;

final class NullTrackingRepository implements TrackingRepositoryInterface
{
    public function findOrCreateKeyword(string $name): int
    {
        return 0;
    }

    public function findOrCreateC1(string $value): int
    {
        return 0;
    }

    public function findOrCreateC2(string $value): int
    {
        return 0;
    }

    public function findOrCreateC3(string $value): int
    {
        return 0;
    }

    public function findOrCreateC4(string $value): int
    {
        return 0;
    }

    public function findOrCreateVariable(string $value, int $ppcVariableId): int
    {
        return 0;
    }

    public function findOrCreateVariableSet(string $variables): int
    {
        return 0;
    }

    public function findOrCreateCustomVar(string $name, string $data): int
    {
        return 0;
    }

    public function findOrCreateUtm(string $value, string $type): int
    {
        return 0;
    }
}
