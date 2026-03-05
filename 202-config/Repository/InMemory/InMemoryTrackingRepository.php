<?php

declare(strict_types=1);

namespace Prosper202\Repository\InMemory;

use Prosper202\Repository\TrackingRepositoryInterface;
use RuntimeException;

final class InMemoryTrackingRepository implements TrackingRepositoryInterface
{
    private const MAX_VALUE_LENGTH = 350;

    /** @var array<string, int> */
    private array $keywords = [];
    /** @var array<string, int> */
    private array $c1 = [];
    /** @var array<string, int> */
    private array $c2 = [];
    /** @var array<string, int> */
    private array $c3 = [];
    /** @var array<string, int> */
    private array $c4 = [];
    /** @var array<string, int> */
    private array $variables = [];
    /** @var array<string, int> */
    private array $variableSets = [];
    /** @var array<string, int> */
    private array $customVars = [];
    /** @var array<string, int> */
    private array $utms = [];
    private int $nextId = 1;

    public function findOrCreateKeyword(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        return $this->keywords[$name] ??= $this->nextId++;
    }

    public function findOrCreateC1(string $value): int
    {
        $value = substr($value, 0, self::MAX_VALUE_LENGTH);

        return $this->c1[$value] ??= $this->nextId++;
    }

    public function findOrCreateC2(string $value): int
    {
        $value = substr($value, 0, self::MAX_VALUE_LENGTH);

        return $this->c2[$value] ??= $this->nextId++;
    }

    public function findOrCreateC3(string $value): int
    {
        $value = substr($value, 0, self::MAX_VALUE_LENGTH);

        return $this->c3[$value] ??= $this->nextId++;
    }

    public function findOrCreateC4(string $value): int
    {
        $value = substr($value, 0, self::MAX_VALUE_LENGTH);

        return $this->c4[$value] ??= $this->nextId++;
    }

    public function findOrCreateVariable(string $value, int $ppcVariableId): int
    {
        $value = substr($value, 0, self::MAX_VALUE_LENGTH);
        $key = $ppcVariableId . '|' . $value;

        return $this->variables[$key] ??= $this->nextId++;
    }

    public function findOrCreateVariableSet(string $variables): int
    {
        return $this->variableSets[$variables] ??= $this->nextId++;
    }

    public function findOrCreateCustomVar(string $name, string $data): int
    {
        $data = substr($data, 0, self::MAX_VALUE_LENGTH);

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new RuntimeException('Invalid custom variable name: ' . $name);
        }

        $key = $name . '|' . $data;

        return $this->customVars[$key] ??= $this->nextId++;
    }

    public function findOrCreateUtm(string $value, string $type): int
    {
        $value = substr($value, 0, self::MAX_VALUE_LENGTH);

        $allowed = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException('Invalid UTM type: ' . $type);
        }

        $key = $type . '|' . $value;

        return $this->utms[$key] ??= $this->nextId++;
    }
}
