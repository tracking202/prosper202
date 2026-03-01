<?php

declare(strict_types=1);

namespace Prosper202\Click;

final class InMemoryClickRepository implements ClickRepositoryInterface
{
    /** @var array<int, ClickRecord> */
    public array $clicks = [];
    private int $nextId = 1;

    public function recordClick(ClickRecord $click): int
    {
        $id = $this->nextId++;
        $this->clicks[$id] = clone $click;

        return $id;
    }
}
