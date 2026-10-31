<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cached;

use Prosper202\Repository\Cache\CachedIds;
use Prosper202\Repository\Cache\IdCache;
use Prosper202\Repository\Mysql\MysqlTrackingRepository;
use Prosper202\Repository\TrackingRepositoryInterface;

/**
 * Keys on the value as it is stored, so a value past its column and its cut
 * form share one entry, as they share one row.
 */
final readonly class CachedTrackingRepository implements TrackingRepositoryInterface
{
    private CachedIds $ids;

    public function __construct(
        private TrackingRepositoryInterface $inner,
        IdCache $cache,
        string $namespace,
    ) {
        $this->ids = new CachedIds($cache, $namespace);
    }

    #[\Override]
    public function findOrCreateKeyword(string $name): int
    {
        if ($name === '') {
            return 0;
        }
        $name = MysqlTrackingRepository::storedKeyword($name);

        return $this->ids->remember(
            'keyword-id' . $name,
            fn (): int => $this->inner->findOrCreateKeyword($name)
        );
    }

    #[\Override]
    public function findOrCreateC1(string $value): int
    {
        $value = MysqlTrackingRepository::storedValue($value);

        return $this->ids->remember(
            'c1-id' . $value,
            fn (): int => $this->inner->findOrCreateC1($value)
        );
    }

    #[\Override]
    public function findOrCreateC2(string $value): int
    {
        $value = MysqlTrackingRepository::storedValue($value);

        return $this->ids->remember(
            'c2-id' . $value,
            fn (): int => $this->inner->findOrCreateC2($value)
        );
    }

    #[\Override]
    public function findOrCreateC3(string $value): int
    {
        $value = MysqlTrackingRepository::storedValue($value);

        return $this->ids->remember(
            'c3-id' . $value,
            fn (): int => $this->inner->findOrCreateC3($value)
        );
    }

    #[\Override]
    public function findOrCreateC4(string $value): int
    {
        $value = MysqlTrackingRepository::storedValue($value);

        return $this->ids->remember(
            'c4-id' . $value,
            fn (): int => $this->inner->findOrCreateC4($value)
        );
    }

    #[\Override]
    public function findOrCreateVariable(string $value, int $ppcVariableId): int
    {
        $value = MysqlTrackingRepository::storedValue($value);

        return $this->ids->remember(
            'variable-id' . $ppcVariableId . '|' . $value,
            fn (): int => $this->inner->findOrCreateVariable($value, $ppcVariableId)
        );
    }

    #[\Override]
    public function findOrCreateVariableSet(string $variables): int
    {
        return $this->ids->remember(
            'variable_set' . $variables,
            fn (): int => $this->inner->findOrCreateVariableSet($variables)
        );
    }

    #[\Override]
    public function findOrCreateCustomVar(string $name, string $data): int
    {
        // Read before it is keyed: a name holding the key's `|` would share
        // another name's entry and be answered without being read.
        $name = MysqlTrackingRepository::customVarName($name);
        $data = MysqlTrackingRepository::storedValue($data);

        return $this->ids->remember(
            'customvar-' . $name . '|' . $data,
            fn (): int => $this->inner->findOrCreateCustomVar($name, $data)
        );
    }

    #[\Override]
    public function findOrCreateUtm(string $value, string $type): int
    {
        $type = MysqlTrackingRepository::utmType($type);
        $value = MysqlTrackingRepository::storedValue($value);

        return $this->ids->remember(
            'utm-' . $type . '|' . $value,
            fn (): int => $this->inner->findOrCreateUtm($value, $type)
        );
    }
}
