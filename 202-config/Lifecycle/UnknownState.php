<?php

declare(strict_types=1);

namespace Prosper202\Lifecycle;

/** A stored state value no case of the lifecycle names. */
final class UnknownState extends \UnexpectedValueException
{
    /** @param class-string $lifecycle */
    public static function of(string $lifecycle, mixed $value, string $where): self
    {
        $shown = is_scalar($value) ? '"' . mb_substr((string) $value, 0, 40) . '"' : get_debug_type($value);

        return new self($where . ' holds the state ' . $shown . ', which ' . $lifecycle . ' does not name.');
    }
}
