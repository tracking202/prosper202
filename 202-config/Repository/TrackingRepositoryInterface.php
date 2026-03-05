<?php

declare(strict_types=1);

namespace Prosper202\Repository;

interface TrackingRepositoryInterface
{
    public function findOrCreateKeyword(string $name): int;

    public function findOrCreateC1(string $value): int;

    public function findOrCreateC2(string $value): int;

    public function findOrCreateC3(string $value): int;

    public function findOrCreateC4(string $value): int;

    public function findOrCreateVariable(string $value, int $ppcVariableId): int;

    public function findOrCreateVariableSet(string $variables): int;

    public function findOrCreateCustomVar(string $name, string $data): int;

    public function findOrCreateUtm(string $value, string $type): int;
}
