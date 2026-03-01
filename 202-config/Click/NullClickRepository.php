<?php

declare(strict_types=1);

namespace Prosper202\Click;

final class NullClickRepository implements ClickRepositoryInterface
{
    public function recordClick(ClickRecord $click): int
    {
        return 0;
    }
}
