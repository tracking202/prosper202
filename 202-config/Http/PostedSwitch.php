<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * A form switch's posted value, read as On or Off: only the two values the
 * form sends are an answer, and anything else is null for the page to refuse
 * by name. Settings' ISP switch compared with `== "true"` and `== "false"`,
 * so any other value changed nothing and said nothing.
 */
final class PostedSwitch
{
    public static function read(mixed $value, string $on, string $off): ?bool
    {
        return match ($value) {
            $on => true,
            $off => false,
            default => null,
        };
    }
}
