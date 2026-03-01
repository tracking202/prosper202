<?php

declare(strict_types=1);

namespace Prosper202\Click;

final class InMemoryClickRepository implements ClickRepositoryInterface
{
    /** @var array<int, ClickRecord> */
    public array $clicks = [];
    private int $nextId = 1;

    public function allocateClickId(): int
    {
        return $this->nextId++;
    }

    public function recordClick(ClickRecord $click): int
    {
        if ($click->clickId > 0) {
            $id = $click->clickId;
        } else {
            $id = $this->nextId++;
        }
        $this->clicks[$id] = clone $click;

        return $id;
    }
}
