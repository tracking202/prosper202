<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cached;

use Closure;
use Prosper202\Repository\TrackingRepositoryInterface;

final class CachedTrackingRepository implements TrackingRepositoryInterface
{
    private const TTL = 2592000;

    /**
     * @param Closure(string): mixed $cacheGet  getCache($key)
     * @param Closure(string, mixed, int): void $cacheSet  setCache($key, $value, $ttl)
     */
    public function __construct(
        private TrackingRepositoryInterface $inner,
        private Closure $cacheGet,
        private Closure $cacheSet,
        private string $systemHash,
    ) {
    }

    public function findOrCreateKeyword(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $key = md5('keyword-id' . $name . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateKeyword($name);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateC1(string $value): int
    {
        $value = substr($value, 0, 350);
        $key = md5('c1-id' . $value . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateC1($value);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateC2(string $value): int
    {
        $value = substr($value, 0, 350);
        $key = md5('c2-id' . $value . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateC2($value);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateC3(string $value): int
    {
        $value = substr($value, 0, 350);
        $key = md5('c3-id' . $value . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateC3($value);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateC4(string $value): int
    {
        $value = substr($value, 0, 350);
        $key = md5('c4-id' . $value . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateC4($value);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateVariable(string $value, int $ppcVariableId): int
    {
        $value = substr($value, 0, 350);
        $key = md5('variable-id' . $ppcVariableId . '|' . $value . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateVariable($value, $ppcVariableId);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateVariableSet(string $variables): int
    {
        $key = md5('variable_set' . $variables . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateVariableSet($variables);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateCustomVar(string $name, string $data): int
    {
        $data = substr($data, 0, 350);
        $key = md5('customvar-' . $name . '|' . $data . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateCustomVar($name, $data);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateUtm(string $value, string $type): int
    {
        $value = substr($value, 0, 350);
        $key = md5('utm-' . $type . '|' . $value . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateUtm($value, $type);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }
}
