<?php

declare(strict_types=1);

namespace Prosper202\Repository\InMemory;

use Prosper202\Repository\Mysql\MysqlTrackingRepository;
use Prosper202\Repository\TrackingRepositoryInterface;
use RuntimeException;

final class InMemoryTrackingRepository implements TrackingRepositoryInterface
{
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

    #[\Override]
    public function findOrCreateKeyword(string $name): int
    {
        if ($name === '') {
            return 0;
        }
        $name = MysqlTrackingRepository::storedKeyword($name);

        return $this->keywords[$name] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateC1(string $value): int
    {
        $value = MysqlTrackingRepository::storedValue($value);

        return $this->c1[$value] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateC2(string $value): int
    {
        $value = MysqlTrackingRepository::storedValue($value);

        return $this->c2[$value] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateC3(string $value): int
    {
        $value = MysqlTrackingRepository::storedValue($value);

        return $this->c3[$value] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateC4(string $value): int
    {
        $value = MysqlTrackingRepository::storedValue($value);

        return $this->c4[$value] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateVariable(string $value, int $ppcVariableId): int
    {
        $value = MysqlTrackingRepository::storedValue($value);
        $key = $ppcVariableId . '|' . $value;

        return $this->variables[$key] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateVariableSet(string $variables): int
    {
        return $this->variableSets[$variables] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateCustomVar(string $name, string $data): int
    {
        $data = MysqlTrackingRepository::storedValue($data);

        if (preg_match('/\A[a-zA-Z0-9_]+\z/', $name) !== 1) {
            throw new RuntimeException('Invalid custom variable name: ' . $name);
        }

        $key = $name . '|' . $data;

        return $this->customVars[$key] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateUtm(string $value, string $type): int
    {
        $value = MysqlTrackingRepository::storedValue($value);

        $allowed = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException('Invalid UTM type: ' . $type);
        }

        $key = $type . '|' . $value;

        return $this->utms[$key] ??= $this->nextId++;
    }
}
